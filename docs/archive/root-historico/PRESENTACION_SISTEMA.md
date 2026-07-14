# Presentación Ejecutiva del Sistema

## Diapositiva 1: Título

**Sistema de Gestión de Pedidos**

Plataforma para administrar pedidos, ventas de representantes, clientes, pagos, seguimiento y operación comercial desde un solo sistema.

Notas:
Presentar el sistema como una herramienta operativa que conecta cliente, representante y administración.

---

## Diapositiva 2: Objetivo del sistema

**Centralizar la operación de pedidos**

- Permite que el cliente cree y consulte pedidos.
- Permite que representantes vendan, consulten inventario y acrediten ventas.
- Permite que administración controle pedidos, productos, pagos, usuarios y métricas.
- Reduce seguimiento manual mediante estados, chat y notificaciones.

Notas:
Enfatizar que el sistema no es solo una tienda: también es una herramienta de control operativo.

---

## Diapositiva 3: Módulos principales

**El sistema está dividido en tres módulos**

1. **Cliente**
   Compra, seguimiento, pagos y chat.

2. **Representantes**
   Ventas directas, inventario, solicitudes y enlaces de tienda.

3. **Sistema / Admin**
   Control operativo, pedidos, productos, usuarios, métodos de pago y métricas.

Notas:
Explicar que cada módulo está diseñado para un tipo de usuario y dispositivo.

---

## Diapositiva 4: Módulo Cliente

**Experiencia de compra y seguimiento**

- Acceso público desde la raíz del sistema.
- Entrada por teléfono o enlace de representante.
- Creación de pedidos desde catálogo.
- Selección y confirmación de método de pago.
- Seguimiento del estado del pedido.
- Chat con el proveedor.
- Descarga o solicitud de datos fiscales cuando aplica.

Notas:
Este módulo está optimizado para móvil y tableta porque es el punto de contacto directo con el cliente.

---

## Diapositiva 5: Flujo del cliente

**Del catálogo al seguimiento**

1. El cliente ingresa su teléfono.
2. Selecciona productos o kits.
3. Confirma el pedido.
4. Selecciona método de pago.
5. Sube comprobante o paga mediante liga/pasarela.
6. Consulta el estado del pedido.
7. Se comunica por chat si necesita soporte.

Notas:
Explicar que el teléfono funciona como identificador simple, evitando un login complejo.

---

## Diapositiva 6: Módulo Representantes

**Herramienta comercial en campo**

- Dashboard móvil del representante.
- Venta directa de productos.
- Venta desde kits.
- Historial de ventas.
- Consulta de inventario en consignación.
- Solicitud de reposición de inventario.
- Generación de enlaces o QR para clientes.

Notas:
Este módulo prioriza uso móvil, botones grandes y rapidez en campo.

---

## Diapositiva 7: Flujos de venta del representante

**Cuatro formas de operar ventas**

1. **Venta directa**
   El representante captura la venta y entrega en mano.

2. **Representante opera la tienda**
   El representante entra a la tienda para generar pedido del cliente.

3. **Cliente entra por QR**
   El cliente compra por su cuenta y la venta queda acreditada al representante.

4. **Cliente sin representante**
   El cliente compra directamente sin acreditación a representante.

Notas:
La diferencia clave está en cómo se vincula la venta y qué métodos de pago se muestran.

---

## Diapositiva 8: Módulo Admin / Sistema

**Centro de control operativo**

- Dashboard con KPIs y métricas.
- Gestión de pedidos y estados.
- Vista kanban para seguimiento operativo.
- Historial de pedidos.
- Catálogo de productos y kits.
- Cupones y promociones.
- Métodos de pago.
- Clientes.
- Usuarios, roles y jerarquía.
- Solicitudes de consignación.
- Chat administrativo por pedido.

Notas:
Este módulo está diseñado para escritorio porque concentra tablas, reportes y configuración.

---

## Diapositiva 9: Gestión de pagos

**Pagos configurables según flujo**

- Transferencia.
- OXXO.
- Liga de pago.
- PayPal.
- Mercado Pago.
- EcartPay.
- Efectivo en venta directa de representante.

El sistema muestra u oculta métodos según el contexto del pedido.

Notas:
Recalcar que los métodos de pago no son iguales para todos los flujos: dependen del origen del pedido.

---

## Diapositiva 10: Seguimiento y comunicación

**Visibilidad para cliente y administración**

- Estados de pedido: pendiente, por verificar, confirmado, en ruta, entregado.
- Historial completo de pedidos.
- Chat entre cliente y proveedor.
- Notificaciones de mensajes no leídos.
- Comprobantes de pago.
- Guías de envío.
- Solicitud y descarga de factura cuando aplica.

Notas:
El objetivo es reducir llamadas o mensajes externos porque el cliente puede revisar el avance desde el sistema.

---

## Diapositiva 11: Roles y control

**Acceso diferenciado por usuario**

- Admin.
- Director General.
- Director de Unidad.
- Gerente.
- Representante.
- Cliente.

Cada perfil accede solo a las herramientas necesarias para su operación.

Notas:
Mencionar que la jerarquía permite separar operación, supervisión y administración.

---

## Diapositiva 12: Beneficio operativo

**Qué resuelve el sistema**

- Ordena el proceso de venta.
- Vincula clientes, pedidos y representantes.
- Centraliza pagos, comprobantes y seguimiento.
- Facilita operación móvil en campo.
- Da visibilidad a administración.
- Reduce trabajo manual y dispersión de información.

Notas:
Cerrar con la idea de que el sistema mejora control, trazabilidad y velocidad de atención.

---

## Anexo: Archivos clave por módulo

**Cliente**

- `index.php`
- `crear-pedido.php`
- `procesar-pago.php`
- `seguimiento.php`
- `chat-pedido.php`
- `mis-datos.php`

**Representantes**

- `representante/index.php`
- `representante/venta.php`
- `representante/vender-kit.php`
- `representante/inventario.php`
- `representante/solicitudes.php`

**Admin**

- `admin/dashboard.php`
- `admin/pedidos.php`
- `admin/kanban.php`
- `admin/productos.php`
- `admin/kits.php`
- `admin/metodos-pago.php`
- `admin/usuarios-sistema.php`

**API**

- `api/ubicaciones.php`
- `api/cupones.php`
- `api/kits.php`
- `api/dashboard-stats.php`
- `api/check-notifications.php`
- `api/paypal.php`
- `api/ecartpay.php`
