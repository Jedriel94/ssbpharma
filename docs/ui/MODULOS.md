# Arquitectura de Módulos — Proceso (Gestión de Pedidos)

## Módulo 1 — Sistema (Admin)
**Ruta:** `admin/`  
**Usuarios:** Admin, Director General, Director de Unidad, Gerente  
**Dispositivo prioritario:** 🖥️ Escritorio  
**Acceso:** `login-admin.php` → sesión `$_SESSION['admin_id']`

### Páginas
- `dashboard.php` — KPIs y métricas por rol
- `usuarios-sistema.php` — Gestión de usuarios y jerarquía de roles
- `pedidos.php` — Lista de pedidos
- `pedidos-historial.php` — Historial completo de pedidos
- `pedido-detalle.php` — Detalle individual de pedido
- `kanban.php` — Vista kanban de pedidos
- `kanban-card.php` — Componente de tarjeta kanban (parcial)
- `kits.php` — Catálogo de kits de productos
- `productos.php` — Catálogo de productos individuales
- `cupones.php` — Gestión de cupones de descuento
- `cupon-form.php` — Formulario creación/edición de cupón
- `metodos-pago.php` — Configuración de métodos de pago
- `configuracion.php` — Configuración global del sistema
- `clientes.php` — Directorio de clientes
- `representantes-qr.php` — QR de acceso para representantes
- `solicitudes-consignacion.php` — Solicitudes de inventario en consignación
- `chat-admin.php` — Chat interno del pedido (vista admin)

### Archivos de prueba (no producción)
- `test-badge.php`, `test-grid.php`, `test-productos-api.php`

---

## Módulo 2 — Representantes
**Ruta:** `representante/`  
**Usuarios:** Representantes de ventas (Nivel 4)  
**Dispositivo prioritario:** 📱 Móvil / Tableta  
**Acceso:** `login-sistema.php` → sesión `$_SESSION['representante_admin_id']`

### Páginas
- `index.php` — Dashboard del representante
- `venta.php` — Crear nueva venta directa (productos individuales)
- `vender-kit.php` — Crear venta desde kit
- `ventas.php` — Historial de ventas del representante
- `inventario.php` — Ver inventario disponible en consignación
- `solicitar-inventario.php` — Solicitar reposición de inventario
- `solicitudes.php` — Estado de solicitudes de inventario
- `entrar-tienda.php` — Generar link de tienda para cliente

### Consideraciones de diseño
- Navegación inferior (bottom nav) o hamburguesa
- Botones grandes, táctiles (mínimo 44px)
- Formularios simples, un campo por línea
- Carga rápida, imágenes optimizadas

---

## Módulo 3 — Cliente
**Ruta:** `/` (raíz del proyecto)  
**Usuarios:** Clientes finales  
**Dispositivo prioritario:** 📱 Móvil / Tableta  
**Acceso:** Público o con link de representante (sin login complejo)

### Páginas
- `index.php` — Catálogo / entrada principal
- `crear-pedido.php` — Flujo de compra
- `procesar-pago.php` — Selección y confirmación de método de pago
- `chat-pedido.php` — Chat del pedido (cliente)
- `seguimiento.php` — Seguimiento de envío
- `mis-datos.php` — Perfil y datos del cliente
- `login-sistema.php` — Login de representantes (también usado por clientes)
- `recuperar-password.php` — Recuperación de contraseña
- `restablecer-password.php` — Restablecimiento de contraseña
- `descargar-fiscal.php` — Descarga de constancia fiscal
- `r.php` — Redirección de links cortos de representante

### Consideraciones de diseño
- Mobile-first obligatorio
- Mínimo de pasos en el flujo de compra
- Feedback visual inmediato (spinners, toasts)
- Sin login complejo (acceso por token o teléfono)

---

## Diseño y Temas

### Sistema de temas (colores y tipografía)
- **Aplica a:** Módulo Admin + Módulo Cliente
- **No aplica a:** Módulo Representantes (tiene su propia tipografía fija, sin temas)
- Los temas permiten cambiar paleta de colores y tipografía de forma global para Admin y Cliente
- Referencia: `TEMAS-COLORES.md`, `THEMES.md`

### Prioridad de dispositivo por módulo

| Módulo        | Dispositivo prioritario | Observaciones                              |
|---------------|-------------------------|--------------------------------------------|
| Admin         | 🖥️ Escritorio           | Tablas, kanban, formularios complejos      |
| Representantes | 📱 Móvil / Tableta     | Bottom nav, botones ≥44px, sin temas       |
| Cliente       | 📱 Móvil / Tableta      | Mobile-first, flujos cortos, con temas     |

### Reglas por módulo

**Admin**
- Usa sistema de temas (colores + tipografía intercambiables)
- Diseño orientado a escritorio con soporte responsive básico

**Representantes**
- Tipografía propia fija (no usa el sistema de temas)
- 100% mobile-first: navegación inferior o hamburguesa, botones táctiles ≥44px
- Formularios de una columna, carga rápida

**Cliente**
- Usa sistema de temas (colores + tipografía intercambiables)
- 100% mobile-first obligatorio
- Feedback visual inmediato (spinners, toasts)
- Acceso sin login complejo (token o teléfono)

---

## API (`api/`)
Endpoints AJAX consumidos por los módulos:

- `ubicaciones.php` — Estados y municipios de México
- `cupones.php` — Validación y aplicación de cupones
- `kits.php` — Datos de kits (imágenes, detalle)
- `usuarios.php` — Gestión de usuarios representante
- `usuarios-sistema.php` — Gestión de usuarios admin
- `dashboard-stats.php` — Estadísticas para dashboard
- `check-notifications.php` — Notificaciones en tiempo real
- `solicitudes-estado.php` — Estado de solicitudes (rep)
- `solicitudes-estado-admin.php` — Estado de solicitudes (admin)
- `ecartpay.php` — Integración pasarela EcartPay
- `paypal.php` — Integración PayPal

---

## Flujos de Venta del Representante

### Flujo A — Venta Directa (entrega en mano)

```
representante/index.php
  → [Nueva Venta] → representante/venta.php
      POST: productos + datos cliente, metodo_pago forzado vacío
      → RepresentanteVenta::crearPorAdmin() → pedido con estado='pendiente'
      → Redirige a procesar-pago.php?pedido_id=X&telefono=Y&modo=rep

procesar-pago.php ($modo_rep = true)
  Métodos visibles: Efectivo, Transferencia, OXXO, Liga de Pago
  Oculto: EcartPay, PayPal, Mercado Pago
  Oculto: bloque de Datos de Envío (entrega directa, no hay envío)
  Liga de Pago: forzada activa aunque esté desactivada en BD
  Efectivo: añadido manualmente (no existe en tabla metodos_pago)
```

**Venta desde Kit:** mismo flujo pero inicia en `representante/vender-kit.php`
→ usa `RepresentanteVenta::crearDesdeKitPorAdmin()`

---

### Flujo B — Representante Opera la Tienda (envío por administración)

```
representante/index.php
  → [Ir a Tienda] → representante/entrar-tienda.php (abre en nueva pestaña)
      Setea: $_SESSION['_rep_modo'] = true
             $_SESSION['_rep_admin_id'] = $representanteAdminId
      Redirige a: r/{codigo}

r.php
  Valida código del representante
  Setea cookies permanentes (10 años):
    botikit_rep_admin, botikit_rep_codigo, botikit_rep_nombre
  Redirige a: index.php (tienda cliente)

index.php / crear-pedido.php
  Flujo idéntico al de un cliente normal
  El pedido queda vinculado al rep por cookie botikit_rep_admin

procesar-pago.php ($rep_en_tienda = true, via $_SESSION['_rep_modo'])
  Layout: completo de cliente (con Datos de Envío, Datos Fiscales)
  Métodos visibles: todos los activos EXCEPTO EcartPay
  Liga de Pago: forzada activa aunque esté desactivada en BD
  EcartPay: siempre oculto en este modo
```

**Variable de sesión `_rep_modo`:**
- Se activa en `entrar-tienda.php`
- Se limpia en `logout-admin.php` al cerrar sesión el representante
- Su único propósito: indicar a `procesar-pago.php` que fuerce Liga de Pago y desactive EcartPay

---

### Flujo C — Cliente entra a la Tienda vía QR del Representante

```
r/{codigo}  (URL del QR)
  r.php valida código → setea cookies permanentes (10 años):
    botikit_rep_admin, botikit_rep_codigo, botikit_rep_nombre
  Redirige a: index.php (tienda cliente)

index.php / crear-pedido.php
  El cliente navega y compra de forma autónoma
  El pedido queda vinculado al rep por las cookies seteadas
  No hay sesión de representante activa ($_SESSION['_rep_modo'] = false)

procesar-pago.php (cliente normal con cookie de rep)
  Layout: completo de cliente (con Datos de Envío, Datos Fiscales)
  Métodos: todos los activos según configuración en BD
  El rep queda acreditado por la cookie botikit_rep_admin
```

---

### Flujo D — Cliente entra a la Tienda sin QR (sin representante)

```
index.php (acceso directo, sin pasar por r/{codigo})
  No hay cookies de representante
  El pedido NO queda vinculado a ningún representante

procesar-pago.php (cliente puro)
  Layout: completo de cliente (con Datos de Envío, Datos Fiscales)
  Métodos: todos los activos según configuración en BD
```

---

### Comparativa de modos en `procesar-pago.php`

| Modo | Detectado por | Datos de Envío | EcartPay | PayPal/MP | Efectivo | Liga de Pago |
|---|---|---|---|---|---|---|
| Cliente sin QR (Flujo D) | (ninguno) | ✅ | ✅ si activo | ✅ si activo | ❌ | ✅ si activo |
| Cliente vía QR rep (Flujo C) | cookie `botikit_rep_admin` | ✅ | ✅ si activo | ✅ si activo | ❌ | ✅ si activo |
| Rep en tienda (Flujo B) | `$_SESSION['_rep_modo']` | ✅ | ❌ forzado | ✅ si activo | ❌ | ✅ forzado |
| Venta directa rep (Flujo A) | `?modo=rep` | ❌ | ❌ | ❌ | ✅ forzado | ✅ forzado |

---

## Notas de trabajo

| Módulo        | Estado UI      | Prioridad siguiente     |
|---------------|----------------|-------------------------|
| Sistema       | ✅ Activo      | Mejoras internas        |
| Representante | ⚠️ Revisar     | Rediseño mobile         |
| Cliente       | ⚠️ Revisar     | Rediseño mobile         |
