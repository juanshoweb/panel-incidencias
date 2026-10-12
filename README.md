# Panel de incidencias

Tablero para registrar incidencias, seguir su estado y dejar un historial. El flujo normal es `open → review → blocked | resolved`, y desde `blocked` se vuelve a `review`. Una incidencia `resolved` no vuelve a abrirse por ese flujo: la reapertura es una acción aparte, con motivo, y escribe su propio log.

La única ruta de la aplicación es `/`. El detalle, los filtros y el alta viven en esa misma página.

## Requisitos

Entorno de referencia: Linux/Ubuntu, sin Docker.

- PHP 8.2 o superior, con la extensión `pdo_mysql`.
- Composer 2.x.
- MySQL 8.0 o compatible.
- Node.js y npm no son necesarios para ejecutar la aplicación, ya que Bootstrap se carga desde CDN y Livewire gestiona sus propios recursos.
- Para ejecutar los tests, se necesita también la extensión `pdo_sqlite`.

La aplicación utiliza Laravel, Blade, Livewire y Bootstrap 5. No requiere autenticación para acceder al panel.

## Esquema

```text
app/
├── Livewire/Incidents/
│   ├── IncidentBoard.php
│   ├── CreateIncident.php
│   ├── IncidentDetail.php
│   └── IncidentHistory.php
├── Models/
│   ├── Incident.php
│   ├── IncidentLog.php
│   └── IncidentComment.php
├── Services/
│   ├── IncidentWorkflowService.php
│   ├── IncidentIndex.php
│   └── IncidentIntegrity/
│       ├── IncidentIntegrityDiagnoser.php
│       ├── IncidentIntegrityQueries.php
│       ├── IntegrityFinding.php
│       └── IntegrityReport.php
├── Enums/
│   ├── IncidentStatus.php
│   ├── IncidentPriority.php
│   ├── IncidentLogAction.php
│   └── IntegrityIssue.php
├── Validation/IncidentValidation.php
├── Exceptions/InvalidIncidentTransitionException.php
└── Console/Commands/DiagnoseIncidents.php
database/
├── migrations/
│   ├── 2026_10_10_193400_create_incidents_table.php
│   ├── 2026_10_10_193401_create_incident_logs_table.php
│   └── 2026_10_10_193402_create_incident_comments_table.php
├── seeders/
│   ├── DatabaseSeeder.php
│   ├── IncidentSeeder.php
│   └── IncidentIntegrityDemoSeeder.php
└── factories/
    ├── IncidentFactory.php
    ├── IncidentLogFactory.php
    └── IncidentCommentFactory.php
resources/views/
├── components/
│   ├── layouts/app.blade.php
│   └── incident-log-entry.blade.php
└── livewire/incidents/
    ├── incident-board.blade.php
    ├── create-incident.blade.php
    ├── incident-detail.blade.php
    └── incident-history.blade.php
routes/web.php
tests/Feature/
├── IncidentBoardTest.php
├── IncidentWorkflowTest.php
├── IncidentValidationTest.php
└── DiagnoseIncidentsTest.php
docs/integridad-incidencias.md
```

La vista `resources/views/welcome.blade.php` es la de la plantilla de Laravel y no tiene ruta.

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env.example` no contiene credenciales reales: `APP_KEY` va vacío, `DB_PASSWORD` va vacío y las claves de AWS están en blanco. El archivo `.env` local está en `.gitignore` y no debe versionarse. `php artisan key:generate` escribe la clave solo en ese `.env`.

`.env.example` ya apunta a MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=panel_incidencias
DB_USERNAME=panel
DB_PASSWORD=
```

`DB_PASSWORD` vacío vale porque el usuario local `panel` no tiene contraseña. No pongas una contraseña real en `.env.example`. Si tu cuenta de MySQL usa otra, cámbiala solo en `.env`.

Hay que crear la base y ese usuario antes de migrar. En MySQL 8, `127.0.0.1` y `localhost` son cuentas distintas, y Laravel conecta por TCP a `127.0.0.1`. Como root:

```sql
CREATE DATABASE IF NOT EXISTS panel_incidencias CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'panel'@'127.0.0.1' IDENTIFIED BY '';
CREATE USER IF NOT EXISTS 'panel'@'localhost' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON panel_incidencias.* TO 'panel'@'127.0.0.1';
GRANT ALL PRIVILEGES ON panel_incidencias.* TO 'panel'@'localhost';
FLUSH PRIVILEGES;
```

Las sesiones, la caché y las colas usan el driver `database`, así que `php artisan migrate` también aplica las tablas de `sessions`, `cache` y `jobs`. Ya vienen en `database/migrations`.

## Migraciones y datos de ejemplo

```bash
php artisan migrate
php artisan db:seed
```

`DatabaseSeeder` crea un usuario de fábrica (`test@example.com`; la contraseña de desarrollo del factory es `password`) y llama a `IncidentSeeder`. Ese usuario no inicia sesión: el panel no tiene autenticación y el operador se escribe a mano en cada acción.

`IncidentSeeder` deja cuatro incidencias coherentes con el workflow: una abierta, una bloqueada, una resuelta y una resuelta que después se reabrió. No llama a `IncidentIntegrityDemoSeeder`. Esa clase es opcional y mete filas incoherentes a propósito, con el query builder, fuera de `IncidentWorkflowService`.

## Ejecución

```bash
php artisan serve
```

Abrir [http://127.0.0.1:8000](http://127.0.0.1:8000).

Los contadores del encabezado salen de un `GROUP BY status` sobre `incidents`. Los filtros y la búsqueda acotan la tabla; no cambian esos totales.

## Tests

```bash
php artisan test
```

PHPUnit fuerza SQLite en memoria (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` en `phpunit.xml`). No usa la base MySQL del `.env`. En esa SQLite el `FOR UPDATE` se elimina al compilar la consulta; la atomicidad la da la transacción. El bloqueo de fila aplica en MySQL y los tests no lo cubren.

## Diagnóstico de integridad

El comando compara el estado de cada incidencia con `incident_logs`. El detalle de las sentencias y de lo que el comando se niega a tocar está en [docs/integridad-incidencias.md](docs/integridad-incidencias.md).

Cargar la demo, aparte del seed normal:

```bash
php artisan db:seed --class=IncidentIntegrityDemoSeeder
```

Solo lectura. No escribe nada. Si hay reparaciones seguras, las cuenta y no las aplica. El proceso termina con código 1 cuando hay hallazgos pendientes o correcciones retenidas por este modo.

```bash
php artisan incidents:diagnose --dry-run
```

Corrección. Sin `--dry-run` el comando aplica solo las reparaciones que ya tienen evidencia en la base. Se puede volver a ejecutar.

```bash
php artisan incidents:diagnose
```

La tabla muestra, por criterio, detectados, corregidos y pendientes de revisión. Código 0 si no queda nada pendiente ni ninguna corrección segura sin aplicar. Código 1 en cualquier otro caso, también en `--dry-run` cuando hay hallazgos.

## Tres inconsistencias que el comando no repara

Las tres siguientes salen de `IncidentIntegrityDemoSeeder`. El criterio común es no inventar un hecho que no está en los datos: ni un actor, ni un estado, ni qué fila de un duplicado sobra.

### Resuelta sin log de resolución

Título: `Demo · resuelta sin log de resolución`.

La fila se crea ya en `resolved` y no tiene ningún log `status_changed` con `new_value = resolved`. La consulta es la de [docs/integridad-incidencias.md](docs/integridad-incidencias.md): `incidents.status = 'resolved'` y un `NOT EXISTS` sobre ese log.

No se inserta un log de resolución ni se devuelve la incidencia a `open`. Un log diría quién la cerró, y ese dato no existe. `closed_at`, si lo hubiera, sería una fecha, no un actor.

### Estado distinto del último log

Título: `Demo · estado distinto del último log`.

El workflow deja un log `open → review`. Después un `UPDATE` directo pone `status = blocked` y no añade log. El último log de cambio de estado (`status_changed` o `reopened`, el de mayor `created_at` y, si empatan, el de mayor `id`) dice `review`; la fila dice `blocked`. La comparación se hace en PHP, no con `<>` en SQL, para que un `new_value` nulo no desaparezca del resultado.

No se cambia `status` para que case con el historial ni se añade un log compensatorio. Las dos fuentes pueden ser la buena, y las reparaciones serían opuestas.

### Logs duplicados e incoherentes

Título: `Demo · logs duplicados e incoherentes`.

Hay un log real `open → review`. La demo inserta una copia idéntica (misma acción, valores, usuario, fecha y metadatos) y otra igual con `created_at` diez minutos después. El estado de la incidencia sigue en `review`, igual que el último log.

No se borra ninguna fila. Dos eventos distintos pueden coincidir en el mismo segundo, y la tabla no tiene una clave de idempotencia que diga cuál escritura sobra. La fila posterior, además, no continúa la cadena: su `old_value` no es el `new_value` del log anterior de verdad. Se reportan el duplicado exacto, el posible duplicado y el log incoherente, y se dejan para revisión.

## Qué sí corrige el comando

En la misma demo hay dos filas que el modo de corrección sí toca. Son los únicos criterios con `correctable()` en `App\Enums\IntegrityIssue`: `MissingClosedAt` y `StaleClosedAt`. Cada reparación va en su propia transacción, bloquea la incidencia y solo actualiza `closed_at` y `updated_at`. No borra logs ni cambia `status`.

- `Demo · resolución sin closed_at`. El estado y el último log dicen `resolved`, y `closed_at` es nulo. La reparación copia el `created_at` de ese log. No usa `now()` ni crea un log.
- `Demo · reapertura con closed_at residual`. El estado ya no es `resolved`, el último log coincide con ese estado y sale de `resolved`, y `closed_at` sigue informado. La reparación lo pone a nulo.

`Demo · historial coherente` es un control: no debe aparecer en el informe.

En los tests, que corren sobre SQLite en memoria, el `FOR UPDATE` se elimina al compilar la consulta. La atomicidad la da la transacción. El bloqueo de fila aplica en MySQL, que es la base de la aplicación. Los tests no cubren ese bloqueo.
