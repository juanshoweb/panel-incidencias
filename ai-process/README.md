# Uso de IA

El panel se construyó en Cursor, por fases. Cada fase se planificó, se revisó y solo entonces se implementó. La IA escribió código; las transiciones, la transacción del historial y qué inconsistencias no se reparan solas se fijaron en la revisión.

## Herramientas utilizadas

- Cursor, para inspeccionar el proyecto, proponer el diseño e implementar PHP, Laravel, Blade y Livewire.
- Entorno de esas sesiones: PHP 8.2.4, Laravel 12.69.3, Livewire 3.8.10, Bootstrap 5.3.3 por CDN.
- El desarrollo y `php artisan test` usaron SQLite. La entrega arranca en MySQL 8: base `panel_incidencias`, usuario `panel`, sin contraseña. PHPUnit sigue en SQLite en memoria y no toca esa base.

## Prompts principales

Resumen de las instrucciones, en el orden en que se dieron. En cada una: qué se pidió, qué quedaba fuera y qué debía quedar al terminar.

### 1. Esqueleto

Inspeccionar el esqueleto de Laravel 12 y listar lo que faltaba para Livewire 3 y Bootstrap 5.

La instalación de paquetes y cualquier cambio de archivos quedaban para después de confirmar ese diagnóstico.

Al terminar debía quedar la lista de lo que faltaba, sin paquetes nuevos instalados.

### 2. Modelo de datos

Migraciones, modelos, enums, claves foráneas e índices de `incidents`, `incident_logs` e `incident_comments`. Estados y prioridades como `string` casteados a enum PHP. Solicitante, responsable y operador como texto.

Sin autenticación y sin columnas `ENUM` de MySQL.

Al terminar, las tres tablas debían poder migrarse con esas relaciones y esos índices.

### 3. Workflow

Cambios de estado solo por las transiciones `open → review → blocked | resolved` y `blocked → review`. Una incidencia `resolved` no se reabre por ese flujo. `reopen()` exige motivo y escribe su propio log. La incidencia y el log van en la misma transacción, con `lockForUpdate`. El estado que autoriza el cambio es el de la fila bloqueada, no el que llega del navegador. La validación, en español, corre en el servidor.

Quedaba fuera aceptar un estado enviado por el formulario si la fila bloqueada no lo permite, y reabrir una resuelta con el flujo normal.

Al terminar, cada transición permitida debía dejar la incidencia y su log escritos juntos, o no dejar ninguno de los dos.

### 4. Rollback

Revisar de forma expresa que, si el `INSERT` del log falla, el `UPDATE` del estado se revierte.

Pedía comprobar esa reversión, sin rediseñar el flujo.

Al terminar, un test debía demostrar que el estado vuelve atrás cuando el log no se escribe.

### 5. Tablero

Una sola ruta `/`, con Livewire y Bootstrap 5 por CDN, en español y sin recargar la página. Cabecera, contadores por estado que filtran al pulsarlos, filtros de estado, prioridad y texto libre, y tabla con título, prioridad, badge de estado, fecha, solicitante, responsable y «Ver historial». Paginación de Bootstrap. Si no hay resultados, una fila vacía.

Tailwind salió del esqueleto para no mezclar dos sistemas de estilos. Los contadores son totales de la base: el resto de filtros no los cambia.

Al terminar, el listado, los filtros y los contadores debían vivir en `/` y actualizarse sin recargar.

### 6. Alta

Modal «Nueva incidencia», abierto desde el botón de la cabecera. Campos: título, prioridad, descripción, solicitante y responsable. Errores de validación en español, junto al campo. Se cierra con el botón, con Escape o pulsando el fondo.

La incidencia se crea abierta. El formulario no deja elegir estado ni fecha de cierre.

Al terminar, un alta válida debía aparecer en el tablero ya en `open`, y un alta incompleta debía mostrar el error en el campo.

### 7. Ficha

Offcanvas lateral al pulsar el título. Muestra estado, prioridad, solicitante, responsable y descripción. El operador se escribe a mano. Solo aparecen los botones de la transición permitida. Hay confirmación antes de bloquear o de resolver. La reapertura solo se ofrece si está resuelta, con motivo obligatorio y confirmación. Escape y el fondo cierran el panel.

Quedaban fuera los botones de transiciones que el estado actual no permite, y reabrir sin motivo.

Al terminar, el cambio de estado y la reapertura debían hacerse desde ese panel, sin salir de `/`.

### 8. Línea de tiempo

Historial plegable dentro de la ficha. Cada entrada muestra el actor, la frase («creó la incidencia», «cambió el estado», «reabrió la incidencia»), los badges del estado anterior y el nuevo, la prioridad en el alta, el motivo de la reapertura y el tiempo transcurrido respecto a la entrada anterior.

Si todavía no hay cambios, el bloque muestra ese vacío. No se inventan entradas.

Al terminar, el historial de la ficha debía leer los logs reales, en orden, y poder plegarse.

### 9. Modal de historial

«Ver historial» abre solo la incidencia de esa fila, en un modal distinto de la ficha. Los logs van en orden cronológico, con la misma línea de tiempo, y los comentarios operativos quedan debajo. Se cierra con Escape, con el botón o pulsando el fondo.

No mezcla el historial de otra fila. Si no hay logs o no hay comentarios, cada bloque lo dice.

Al terminar, cada fila debía abrir su propio historial, independiente de la ficha.

### 10. Comentarios

En la ficha, formulario de autor y texto, con validación en español. El comentario nuevo se ve sin recargar.

En el modal de historial los comentarios solo se leen. No hay alta desde ese modal.

Al terminar, un comentario válido debía guardarse y mostrarse en la ficha, y uno incompleto debía marcar el campo.

### 11. Diagnóstico

Comando `incidents:diagnose` con `--dry-run` de solo lectura. Tres inconsistencias de ejemplo, sembradas aparte del seed normal y fuera del workflow: resuelta sin log de resolución, estado distinto del último log, y logs duplicados o incoherentes.

Corregir solo `closed_at` cuando el estado y el último log ya demuestran el cierre o su abandono. No inventar actor, fecha ni estado. No reescribir `status` ni crear logs.

Al terminar, `--dry-run` debía listar los hallazgos sin escribir, y el modo de corrección debía tocar solo esas fechas con evidencia.

### 12. Corrección del duplicado

Dos logs iguales no bastan para borrar uno. `created_at` guarda segundos y la tabla no tiene clave de idempotencia, así que coincidir no dice cuál fila sobra.

Se retira el borrado que se quedaba con el `id` menor. No se elige una fila para conservarla.

Al terminar, el duplicado debía reportarse y dejarse para revisión.

### 13. Cierre

README de la aplicación, `.env.example` sin credenciales (`APP_KEY` vacío y `DB_PASSWORD` vacío) y revisión final.

La revisión separa lo ejecutado (`php artisan test` y la prueba en el navegador) de lo solo leído en el código. `.env.example` no lleva una contraseña real ni una `APP_KEY` generada.

Al terminar, el README debía bastar para instalar y arrancar el panel, y la nota debía distinguir lo probado de lo leído.

## Partes generadas con ayuda de IA

- Layout Livewire y Bootstrap. Tailwind salió del esqueleto para no mezclar dos sistemas de estilos.
- Migraciones, modelos, enums, factories y seeders.
- `IncidentWorkflowService`, `IncidentValidation` y los tests de transiciones y de rollback.
- `IncidentIndex` y los componentes Livewire del tablero, del alta y del detalle.
- `incidents:diagnose`, el servicio de integridad, `IncidentIntegrityDemoSeeder` y `docs/integridad-incidencias.md`.
- `README.md` y esta nota.

Esas piezas se leyeron antes de darlas por buenas. La pantalla, los filtros y el cambio de estado se probaron en el navegador. `php artisan test` terminó en 31 pruebas en verde.

## Decisiones y correcciones tomadas en la revisión

- Bootstrap por CDN. El Node de la máquina era 18 y Vite 7 pide 20.19 o superior. No hace falta `npm install` para usar el panel.
- Livewire fijado a `^3.6` para no instalar la línea 4. El layout quedó en `components/layouts/app`, que es la ruta por defecto de Livewire 3.8.
- Estados y prioridades como `string` casteados a enums PHP, no como `ENUM` de MySQL. Solicitante, responsable y operador son texto: la prueba no pide autenticación.
- Flujo normal: `open → review → blocked | resolved`, y desde `blocked` se vuelve a `review`. Una resuelta no se reabre por ese flujo. `reopen()` exige motivo y escribe su propio log.
- El estado que autoriza la transición es el de la fila bloqueada en la transacción, no el que llega del navegador. El `UPDATE` y el `INSERT` del log comparten esa transacción.
- El comando no reescribe `status`, no crea logs y no borra duplicados. Solo ajusta `closed_at` cuando el estado y el último log ya demuestran el cierre o su abandono. El detalle está en el README y en `docs/integridad-incidencias.md`.
- Las tres inconsistencias del enunciado se siembran aparte, con el query builder, fuera del workflow. `DatabaseSeeder` no las carga.

## Errores detectados en la salida de la IA

- La primera versión del diagnóstico borraba logs duplicados y se quedaba con el `id` menor. Igualdad de columnas no dice cuál fila sobra: `created_at` solo guarda segundos y no hay clave de idempotencia. Se quitó ese borrado. El duplicado se reporta y se deja para revisión.
- Tras un alta o un comentario correcto, los errores de validación seguían en pantalla. Además, si faltaba el operador, el cambio de estado no llegaba al servicio. Los componentes limpian los errores al empezar la acción y solo llaman al workflow cuando el operador tiene texto.
- Un test del diagnóstico fallaba aunque la reparación de `closed_at` sí se había aplicado: la aserción comparaba un booleano con `1`. Se corrigió la aserción y la prueba pasó.
- En la revisión final, el nombre del operador y el motivo de reapertura solo exigen texto no vacío. La columna admite 255 caracteres y el `maxlength` del HTML no se valida en el servidor. Se detectó y no se cerró en esa pasada.
