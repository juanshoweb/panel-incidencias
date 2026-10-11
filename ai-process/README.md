# Uso de IA

El panel se construyó en Cursor, por fases. Cada fase se planificó, se revisó y solo entonces se implementó. La IA escribió código; las transiciones, la transacción del historial y qué inconsistencias no se reparan solas se fijaron en la revisión.

## Herramientas utilizadas

- Cursor, para inspeccionar el proyecto, proponer el diseño e implementar PHP, Laravel, Blade y Livewire.
- Entorno de esas sesiones: PHP 8.2.4, Laravel 12.69.3, Livewire 3.8.10, Bootstrap 5.3.3 por CDN.
- El desarrollo y `php artisan test` usaron SQLite. La entrega arranca en MySQL 8: base `panel_incidencias`, usuario `panel`, sin contraseña. PHPUnit sigue en SQLite en memoria y no toca esa base.

## Prompts principales

Resumen de las instrucciones, en el orden en que se dieron:

1. Inspeccionar el esqueleto de Laravel 12 y decir qué faltaba para Livewire 3 y Bootstrap 5, sin instalar nada hasta confirmarlo.
2. Modelo de datos: migraciones, modelos, enums, claves foráneas e índices de `incidents`, `incident_logs` e `incident_comments`. Sin autenticación.
3. Workflow: cambios de estado solo por transiciones permitidas, incidencia y log en la misma transacción, `lockForUpdate`, reapertura explícita con motivo, validación en español en el servidor.
4. Revisar de forma expresa que, si el log falla, el cambio de estado se revierte.
5. Interfaz en `/` con Livewire y Bootstrap: contadores, filtros, alta, historial, comentarios y confirmación antes de bloquear o resolver, sin recargar la página.
6. Diagnóstico `incidents:diagnose` con `--dry-run`. Tres inconsistencias de ejemplo. Corregir solo con evidencia; no inventar actor, fecha ni estado.
7. Corrección posterior: dos logs iguales no bastan para borrar uno.
8. README, `.env.example` sin credenciales y revisión final, separando lo ejecutado de lo leído en el código.

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
