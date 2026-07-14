# Flujos de Venta — Sistema BotiKit

## Flujo 1 — Venta Directa desde inventario del representante

**Actor:** Representante  
**Stock usado:** Inventario en consignación del representante  
**Entrega:** El representante entrega físicamente el producto

### Pasos
1. El representante inicia sesión → `login-sistema.php`
2. Accede a `representante/venta.php` (productos sueltos) o `representante/vender-kit.php` (kits)
3. Busca al cliente por teléfono (autocompletado desde `clientes`)
4. Selecciona productos **de su inventario** y cantidades
5. Envía el formulario → `RepresentanteVenta::crearPorAdmin()` / `crearDesdeKitPorAdmin()`
6. Redirige a `procesar-pago.php?modo=rep` para registrar el cobro
7. El inventario del representante se descuenta (`representante_inventario`)

### Archivos clave
- `representante/venta.php`
- `representante/vender-kit.php`
- `models/RepresentanteVenta.php` → `crearPorAdmin()`, `crearDesdeKitPorAdmin()`
- `procesar-pago.php` (con flag `modo=rep`)

---

## Flujo 2 — Representante opera en la tienda por el cliente

**Actor:** Representante (actuando como comprador)  
**Stock usado:** Almacén principal (no inventario del representante)  
**Entrega:** El proveedor/almacén envía el producto al cliente

### Pasos
1. El representante inicia sesión → `login-sistema.php`
2. Accede a `representante/entrar-tienda.php`
   - Marca `$_SESSION['_rep_modo'] = true`
   - Redirige a `r/{codigo}` → `r.php` escribe cookie `botikit_rep_admin`
3. El representante navega la tienda como si fuera el cliente
4. Agrega productos al carrito y crea el pedido en `crear-pedido.php`
5. Llega a `procesar-pago.php`; el sistema detecta `$_SESSION['_rep_modo']` y `$rep_en_tienda`
6. El representante registra el método de pago y confirma
7. El pedido queda vinculado al representante; el envío lo gestiona el almacén

### Diferencia con Flujo 1
El stock **no** sale del inventario del representante; sale del almacén general. El representante solo gestiona la venta y el cobro.

### Archivos clave
- `representante/entrar-tienda.php`
- `r.php` (escribe cookie permanente)
- `crear-pedido.php` / `index.php`
- `procesar-pago.php` (detecta `$rep_en_tienda = !empty($_SESSION['_rep_modo'])`)

---

## Flujo 3 — Cliente entra con enlace del representante

**Actor:** Cliente final (referido por representante)  
**Stock usado:** Almacén principal  
**Entrega:** El proveedor/almacén envía al cliente

### Pasos
1. El representante comparte su enlace: `solumedic.shop/columbia/r.php?c=REP001`
2. El cliente abre el enlace → `r.php` guarda cookie permanente `botikit_rep_admin` (10 años)
3. El cliente es redirigido a `index.php?ref=1` → aparece banner *"Fuiste referido por [Nombre]"*
4. El cliente navega el catálogo, selecciona productos y crea su pedido en `crear-pedido.php`
5. El pedido queda asociado al representante vía cookie
6. El cliente paga en `procesar-pago.php` (flujo normal cliente)
7. El almacén gestiona el envío

### Característica especial
La cookie dura **10 años**; todas las compras futuras del cliente desde ese dispositivo quedan acreditadas al representante, hasta que el cliente limpie cookies.

### Archivos clave
- `r.php` (cookie permanente)
- `index.php` (detecta `botikit_rep_admin`, muestra banner)
- `crear-pedido.php` → `obtenerRepresentantePublico()` lee la cookie
- `procesar-pago.php` (flujo cliente)

---

## Flujo 4 — Cliente entra directo a la tienda sin enlace

**Actor:** Cliente final (sin referido)  
**Stock usado:** Almacén principal  
**Entrega:** El proveedor/almacén envía al cliente

### Pasos
1. El cliente accede directamente a `index.php` (sin cookie `botikit_rep_admin`)
2. Navega el catálogo y crea su pedido en `crear-pedido.php`
3. El pedido **no** queda vinculado a ningún representante
4. El cliente paga en `procesar-pago.php` (flujo normal cliente)
5. El almacén gestiona el envío

### Archivos clave
- `index.php`
- `crear-pedido.php`
- `procesar-pago.php`

---

## Resumen comparativo

| # | Actor | Stock | Vincula rep | Entrega |
|---|-------|-------|-------------|---------|
| 1 | Representante | Inventario del rep | Sí (directo) | Representante |
| 2 | Representante (modo tienda) | Almacén general | Sí (sesión) | Proveedor/almacén |
| 3 | Cliente (con enlace) | Almacén general | Sí (cookie) | Proveedor/almacén |
| 4 | Cliente (directo) | Almacén general | No | Proveedor/almacén |

## Cómo se plasman en pedidos

| Escenario | `pedidos.canal` | `representante_admin_id` | Observación |
|---|---|---|---|
| Modulo Representantes -> Venta Directa Representante | `representante_directo` | Sí | Se crea desde `RepresentanteVenta::crearPorAdmin()` / `crearDesdeKitPorAdmin()` |
| Modulo Representantes -> Ir a Tienda | `cliente_directo` | Sí | El representante queda asociado por sesión y luego por cookie |
| Modulo Clientes - Venta en Tienda con QR | `cliente_directo` | Sí | El pedido queda vinculado por la cookie `botikit_rep_admin` |
| Modulo Clientes - Venta en Tienda sin QR | `cliente_directo` | No | Pedido de cliente puro, sin representante asociado |

> Nota: el sistema no usa un canal separado para “tienda con QR” o “tienda con representante”. Ambos quedan como `cliente_directo`; la diferencia real está en `representante_admin_id` y en el contexto que originó el pedido.

## Estados del pedido

```
pendiente → por_verificar → confirmado → en_ruta → entregado
                                                  ↘ cancelado
```
