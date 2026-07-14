# Migracion de representantes a usuarios

## Estado actual

La tabla `representantes` queda congelada como compatibilidad legacy.

No deben crearse representantes nuevos desde `admin/representantes.php`.
El alta oficial debe moverse a `admin/usuarios-sistema.php`, usando el rol `Representante`.

## Regla operativa desde este punto

- `administradores` es la tabla principal de usuarios internos.
- Un representante debe existir como usuario con rol `representante`.
- Los datos comerciales del representante viven en `representante_perfiles`, ligado por `admin_id`.
- `representantes` se conserva temporalmente para no romper pedidos, clientes, QR, cupones, inventario y consignacion que todavia referencian `representantes.id`.

## Pasos completados

1. Congelar `admin/representantes.php` como modulo legacy.
2. Crear `representante_perfiles` y migrar usuarios representantes ya vinculados.
3. Conectar `admin/usuarios-sistema.php` y `api/usuarios.php` para crear/actualizar perfiles de representante desde el alta de usuarios.
4. Agregar `representante_admin_id` a tablas operativas y empezar a escribir/leer ventas directas desde el usuario representante.
5. Migrar representantes legacy sin usuario a `administradores` y conciliar `representante_admin_id` en datos existentes.
6. Reorientar inventario y consignacion para consultar, bloquear, actualizar y registrar movimientos por `representante_admin_id`.
7. Migrar dashboards, rankings, metricas operativas y ultimos pedidos para usar `representante_admin_id` como scope principal.
8. Migrar listados administrativos de pedidos, historial, kanban y detalle para detectar/mostrar representantes desde `representante_admin_id`.
9. Migrar cupones y QR a usuarios representantes, agregar soporte `aplicacion_admin_ids`/`representante_admin_id` y cerrar el endpoint legacy `api/usuarios-sistema.php`.
10. Actualizar la ruta publica `/r/{codigo}` para resolver primero `representante_perfiles.admin_id`, emitir `botikit_rep_admin` como cookie principal y dejar `botikit_rep` solo como compatibilidad temporal. Pedidos, clientes, cupones, bienvenida y catalogo filtrado ya consumen primero el usuario representante.
11. Eliminar `administradores.representante_id` y su FK legacy. El usuario interno ya no se vincula a `representantes`; el perfil comercial se obtiene solo desde `representante_perfiles.admin_id`.
12. Migrar inventario, consignacion y venta directa del portal representante para usar metodos `PorAdmin` con `representante_admin_id`. Las columnas legacy `representante_id` de inventario/movimientos/solicitudes ahora aceptan `NULL`, permitiendo operar representantes nuevos sin fila en `representantes`.
13. Dejar pedidos, clientes y cupones escribiendo solo `representante_admin_id` cuando existe identidad de usuario representante. `representante_id` se conserva como dato historico/fallback para cookies antiguas o registros previos, pero ya no se deriva desde `admin_id` para operaciones nuevas.
14. Retirar la emision y consumo publico de `botikit_rep`. La ruta `/r/{codigo}` solo resuelve `representante_perfiles.codigo`, emite `botikit_rep_admin` y expira la cookie legacy; pedidos, clientes, cupones, bienvenida y catalogo ya no aceptan identidad legacy.
15. Eliminar `LEFT JOIN representantes` de superficies administrativas activas: pedidos, kanban, solicitudes de consignacion e historial de cupones ya muestran representante solo desde `representante_admin_id` + `representante_perfiles`.
16. Retirar columnas operativas `representante_id` y sus FKs/indices de `pedidos`, `clientes`, `cupones_uso`, `representante_inventario`, `representante_inventario_movimientos` y `solicitudes_consignacion`. El codigo activo ya escribe solo `representante_admin_id`; `representante_perfiles.legacy_representante_id` queda como unico puente temporal a `representantes`.
17. Eliminar el puente `representante_perfiles.legacy_representante_id`. Los perfiles comerciales ya no guardan relacion con `representantes`; cupones, QR, inventario, consignacion y autenticacion de representante trabajan solo con `administradores.id` / `representante_admin_id`.
18. Separar el seguimiento logistico de consignacion del Kanban de pedidos de clientes. `solicitudes_consignacion` incorpora estado `en_transito` y campos de guia (`paqueteria`, `numero_guia`, `url_rastreo`, `fecha_envio`); el inventario se suma al representante solo al confirmar entrega.
19. Permitir anexar archivo de guia de consignacion (`guia_archivo`) en PDF o imagen. Los archivos se guardan en `uploads/guias_consignacion/` y quedan visibles para administrador y representante.

## Proximo paso

Retirar el modulo legacy `admin/representantes.php` y finalmente borrar la tabla `representantes` cuando ya no sea necesaria.
