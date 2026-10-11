# Diagnóstico de integridad de incidencias

Comando: `php artisan incidents:diagnose`

Modo solo lectura: `php artisan incidents:diagnose --dry-run`

Datos para reproducir las inconsistencias, sin tocar el seed normal:

```bash
php artisan db:seed --class=IncidentIntegrityDemoSeeder
```

`DatabaseSeeder` no llama a esa clase. El tablero, el workflow y `IncidentSeeder` siguen siendo coherentes. La corrupción de la demo se escribe con el query builder, fuera de `IncidentWorkflowService`.

El código ejecutable de las consultas está en `app/Services/IncidentIntegrity/IncidentIntegrityQueries.php`. Este documento describe esas mismas sentencias, el criterio que se aplica encima y lo que el comando se niega a reparar.

## Qué cuenta como cambio de estado

Un log de cambio de estado es una fila de `incident_logs` con `action` `status_changed` o `reopened`.

La reapertura tiene que entrar en esa definición. Si solo se mirara `status_changed`, toda incidencia reabierta parecería divergente: su último `status_changed` seguiría diciendo `resolved` y el estado real sería `open`.

El estado que deja el log es `new_value`. `old_value` es el estado anterior.

El último log es el de mayor `created_at`. Si hay empate, gana el `id` mayor. El índice existente es `(incident_id, created_at)`; el desempate por `id` no está cubierto por ese índice y, con el volumen de un panel, el coste es irrelevante.

El alta desde el panel escribe un log `created`, con el solicitante y la prioridad. No es una transición de estado. Una incidencia `open` sin logs sigue siendo coherente: es el caso de las altas anteriores a ese registro. La ausencia de historial no demuestra que el estado esté mal.

Un log de resolución es exactamente `action = status_changed` y `new_value = resolved`. Es lo único que escribe el workflow al resolver. Una reapertura no es una resolución.

## Consultas que ejecuta el comando

La fase de lectura solo hace `SELECT`, dentro de una transacción para que las cuatro lecturas vean el mismo snapshot. `--dry-run` termina ahí.

### 1. Resuelta sin log de resolución

```sql
SELECT i.id AS incident_id, i.title, i.closed_at
FROM incidents AS i
WHERE i.status = 'resolved'
  AND NOT EXISTS (
      SELECT 1
      FROM incident_logs AS l
      WHERE l.incident_id = i.id
        AND l.action = 'status_changed'
        AND l.new_value = 'resolved'
  )
```

Si la incidencia se resolvió, se reabrió y se volvió a resolver, el segundo cierre deja otro log `status_changed` hacia `resolved`. Esta consulta no salta: el log antiguo sigue existiendo. El caso peligroso —el estado volvió a `resolved` sin un log nuevo— lo coge la consulta del último log, no esta.

### 2. Último log de cambio de estado

```sql
SELECT
    i.id AS incident_id,
    i.title,
    i.status AS current_status,
    i.closed_at,
    latest.id AS log_id,
    latest.action AS logged_action,
    latest.old_value,
    latest.new_value AS logged_status,
    latest.created_at AS logged_at
FROM incidents AS i
INNER JOIN incident_logs AS latest
    ON latest.id = (
        SELECT l.id
        FROM incident_logs AS l
        WHERE l.incident_id = i.id
          AND l.action IN ('status_changed', 'reopened')
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 1
    )
```

La comparación `current_status !== logged_status` se hace en PHP, no con `<>` en SQL. En SQL, `NULL <> 'review'` no es verdadero, así que un `new_value` nulo desaparecería del resultado. En PHP sí cuenta como divergencia.

### 3. Incidencias, para el `closed_at` de las que no tienen logs

```sql
SELECT id AS incident_id, title, status, closed_at
FROM incidents
ORDER BY id ASC
```

### 4. Barrido de logs

```sql
SELECT id, incident_id, action, old_value, new_value, user_name, metadata, created_at
FROM incident_logs
ORDER BY incident_id ASC, created_at ASC, id ASC
```

El duplicado exacto equivale a este agrupamiento, pero no se ejecuta así porque `GROUP BY metadata` compara el texto del JSON y falla si las claves vienen en otro orden:

```sql
SELECT
    incident_id,
    action,
    old_value,
    new_value,
    user_name,
    metadata,
    created_at,
    COUNT(*) AS copies,
    MIN(id) AS keep_id
FROM incident_logs
GROUP BY incident_id, action, old_value, new_value, user_name, metadata, created_at
HAVING COUNT(*) > 1
```

El comando agrupa en PHP con esa misma clave después de decodificar el JSON, ordenar las claves de los objetos y volver a codificarlo. Las listas conservan su orden. `NULL` y `[]` no son el mismo metadato.

## Criterios de detección

| Criterio | Cuándo salta | ¿Se corrige solo? |
| --- | --- | --- |
| Resuelta sin log de resolución | `status = resolved` y no existe ningún `status_changed` hacia `resolved` | No |
| Estado distinto del último log | El `status` de la fila no es el `new_value` del último log de cambio de estado | No |
| Log duplicado exacto | Misma incidencia, acción, `old_value`, `new_value`, usuario, `created_at` y metadatos canónicos | No. Coincidir no demuestra que una fila esté de más |
| Log posiblemente duplicado | Dos logs de cambio de estado consecutivos con la misma acción, valores y usuario, pero distinta fecha o distintos metadatos | No |
| Log incoherente | En la secuencia, sin contar las copias exactas: estado desconocido, transición que no cambia nada, reapertura que no es `resolved → open`, `status_changed` que el workflow actual no permite, o `old_value` distinto del `new_value` del log anterior | No |
| `closed_at` vacío con resolución demostrada | El estado es `resolved`, el último log es un `status_changed` hacia `resolved` y `closed_at` es nulo | Sí: `closed_at` copia el `created_at` de ese log |
| `closed_at` residual | El estado no es `resolved`, `closed_at` tiene valor, y el último log coincide con el estado y sale de `resolved` | Sí: `closed_at` pasa a nulo |
| `closed_at` distinto del log | Estado y último log coinciden en `resolved`, pero las dos fechas difieren en más de 5 segundos | No |
| `closed_at` sin respaldo | Hay `closed_at` y el estado no es `resolved`, pero no se cumple la condición del residual | No |

Una misma fila puede aparecer en varios criterios. El caso típico es un reintento con otra fecha: es un posible duplicado y, además, rompe la cadena. Se reportan los dos y no se borra.

Las filas de un grupo idéntico no se vuelven a clasificar como eslabón roto. Hacerlo señalaría al `id` mayor como el paso falso, y ese desempate no está en los datos. El hallazgo es el grupo completo. La fila de `id` menor sí se compara con el log anterior de verdad, porque esa comparación no elige una víctima dentro del grupo.

## Qué se corrige y por qué

### `closed_at` vacío cuando la resolución está demostrada

El workflow escribe `closed_at` y el log en la misma operación. Si el estado actual y el último log dicen `resolved`, el cierre de este episodio está demostrado: hay actor (`user_name` del log) y fecha (`created_at` del log). La reparación copia esa fecha. No usa `now()`, no crea un log y no toca `status`.

Si el último log no es esa resolución, no se rellena `closed_at` aunque exista un cierre antiguo. Un log de resolución previo a una reapertura no demuestra que el estado `resolved` de ahora sea ese cierre.

La revalidación no exige el mismo `id` visto en la lectura. Vuelve a tomar el último log que siga cumpliendo la condición. Si otro log pasó a ser el último, y ya no es una resolución que coincida con el estado, no se escribe `closed_at`.

### `closed_at` residual cuando ambas fuentes ya salieron de `resolved`

`closed_at` significa "está resuelta ahora". Lo pone el workflow al resolver y lo anula al reabrir. Si la fila y el último log coinciden en un estado que no es `resolved`, y ese log tiene `old_value = resolved`, las dos fuentes dicen que se abandonó el cierre y la fecha sobra. Anularla no inventa una fecha: quita una que contradice el evento ya registrado.

Si no coinciden, la fecha se queda. Puede ser el único rastro de un cierre cuyo log se perdió, y borrar ese rastro sería peor que la inconsistencia.

## Qué no se corrige

### No se fabrica el log de resolución

Una incidencia `resolved` sin ese log no dice quién la resolvió. `closed_at`, si existe, tampoco: es una fecha, no un actor. Insertar un log con un usuario `system` o con la fecha de hoy afirmaría un hecho que no está en los datos. Se reporta y se deja.

Tampoco se revierte el estado a `open` por falta de log. El log puede haberse perdido y el estado puede ser el dato bueno. Las dos hipótesis implican reparaciones opuestas.

### No se cambia `status` para que case con el historial

O la fila se actualizó después del último log, o el log más reciente no llegó a escribirse. El comando no puede saber cuál de las dos es. Cambiar el estado movería la incidencia de columna en el tablero. Añadir un log compensatorio inventaría el actor. Las dos se dejan para revisión.

Esto incluye el caso en que hubo una resolución real, luego una reapertura registrada, y alguien volvió a poner `resolved` en la fila. Hay un log de resolución, así que la primera consulta no salta, pero el último log dice `open`. No se copia el `closed_at` del cierre viejo y no se reabre la incidencia.

### No se borra un log porque se parezca a otro

Dos eventos pueden tener la misma acción, los mismos valores, el mismo usuario, la misma fecha y los mismos metadatos. `created_at` solo guarda segundos, así que dos hechos distintos en el mismo segundo producen la misma clave. Conservar el `id` menor sería una convención, no una prueba de cuál ocurrió.

El workflow tampoco aporta esa prueba. Que la aplicación no suela escribir la pareja explica por qué el grupo es sospechoso. No dice cuál fila sobra. Una cadena rota tiene el mismo límite: las dos no pueden ser, a la vez, el paso siguiente, pero el historial no señala a la falsa.

Hace falta una marca del escritor que identifique una sola escritura, como una clave de idempotencia. Esta tabla no la tiene. Sin ella, el duplicado exacto y el duplicado con otra fecha o con otros metadatos se quedan en revisión.

### No se reescribe una cadena rota

Una cadena rota (`old_value` que no continúa el `new_value` anterior), una transición ilegal o una reapertura mal formada se reportan. Corregir el eslabón sería inventar la transición que "debería" haber ocurrido. El workflow usado para juzgar la transición es el actual; si las reglas cambian, un paso que fue legal puede aparecer como incoherente. Es una limitación conocida, no una reparación automática.

### No se sobrescribe un `closed_at` que ya tiene valor

Si las dos fechas difieren en más de 5 segundos, alguien pudo corregir una de las dos a mano. El comando no elige. La tolerancia de 5 segundos absorbe que `closed_at = now()` y el `created_at` del log se graban en la misma petición y pueden caer en segundos distintos. Por debajo de eso no hay hallazgo.

## Transacciones

Cada reparación va en su propia transacción, no en una transacción global. Así, un grupo que ya no cumple la condición no deshace reparaciones anteriores. El comando se puede volver a ejecutar.

Dentro de la transacción se bloquea primero la incidencia y después sus logs, el mismo orden que `IncidentWorkflowService`, para no cruzar el bloqueo con un cambio de estado concurrente. Cada reparación toca una sola fila de `incidents`: `closed_at` y `updated_at`. No hay `DELETE` sobre `incident_logs`.

En SQLite el `FOR UPDATE` se elimina al compilar. La atomicidad la da la transacción; el bloqueo de fila aplica en MySQL y PostgreSQL. Los tests del proyecto usan SQLite en memoria.

La reparación de `closed_at` actualiza `updated_at` a la hora de la reparación. No se retrasa esa columna: la fecha histórica queda en `closed_at`, copiada del log.

## Resumen y código de salida

La tabla muestra, por criterio, detectados, corregidos y pendientes de revisión.

- Detectados: hallazgos de la lectura.
- Corregidos: reparaciones que se aplicaron.
- Pendientes de revisión: lo que no es seguro reparar, más una reparación segura cuya condición ya no se cumplía al bloquear la fila.
- En `--dry-run`, lo corregible no pasa a pendientes de revisión. Se cuenta aparte como corrección segura no aplicada. Sigue sin escribirse.

Salida `0` si no queda nada pendiente ni ninguna corrección segura sin aplicar. Salida `1` en cualquier otro caso, también en `--dry-run` cuando hay hallazgos. Un segundo pase, después de reparar, solo vuelve a listar lo pendiente.

## Limitaciones

- El último log se define por `created_at` e `id`. Un `created_at` manipulado puede ganar a un evento posterior con `id` menor. No se intenta reconstruir el orden "real".
- La precisión de `timestamp` es de un segundo. Dos eventos distintos en el mismo segundo, con el mismo actor y el mismo payload, se listan como duplicado exacto y no se borran. La tabla no guarda una clave que distinga una escritura repetida de dos hechos.
- Un log de resolución antiguo satisface la primera consulta aunque el episodio actual de `resolved` no tenga log propio. La divergencia con el último log cubre ese hueco solo si existe un log posterior. Si alguien borra los logs posteriores, el comando no puede verlos.
- No se audita en otra tabla la reparación. El rastro es la salida del comando y, en `closed_at`, el valor copiado del log que sigue existiendo. No se insertan logs de mantenimiento: serían eventos que no ocurrieron en el workflow.
- El comando no recorre comentarios ni cambia `priority`, asignación o título.
- La demo no se carga sola. Hay que ejecutar `IncidentIntegrityDemoSeeder`.
