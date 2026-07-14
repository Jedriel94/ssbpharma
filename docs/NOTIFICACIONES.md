# 🔔 Sistema de Notificaciones Push

## Descripción
Sistema de notificaciones push del navegador que alerta a clientes y administradores sobre eventos importantes en tiempo real.

## Características

### Para Clientes (seguimiento.php, chat-pedido.php)
- ✅ **Notificaciones de Chat**: Recibe alertas cuando el admin envía un mensaje
- 📦 **Cambios de Estado**: Se notifica cuando el estado del pedido cambia
  - Pendiente → Por Verificar
  - Por Verificar → Confirmado ✅
  - Confirmado → En Ruta 🚚
  - En Ruta → Entregado 📦

### Para Administradores (admin/kanban.php, admin/chat-admin.php)
- 💬 **Mensajes de Clientes**: Recibe alertas cuando un cliente envía un mensaje
- 🔔 **Monitoreo del Kanban**: Monitorea todos los pedidos activos automáticamente

## Componentes

### 1. NotificationManager (js/notifications.js)
Clase JavaScript que gestiona las notificaciones push.

#### Métodos principales:
- `requestPermission()`: Solicita permiso al usuario
- `show(title, options)`: Muestra una notificación
- `startChatMonitoring(pedidoId, userType)`: Monitorea mensajes nuevos cada 10 segundos
- `startStatusMonitoring(pedidoId)`: Monitorea cambios de estado cada 30 segundos
- `showPermissionButton()`: Muestra botón flotante para activar notificaciones

### 2. API de Verificación (api/check-notifications.php)
Endpoint que verifica eventos nuevos.

#### Acciones disponibles:
- `check_new_messages`: Verifica mensajes nuevos desde última verificación
- `check_status_change`: Verifica cambios de estado del pedido

## Uso

### Activar Notificaciones (Cliente)
```javascript
// En chat-pedido.php o seguimiento.php
notificationManager.showPermissionButton(); // Muestra botón de activación
notificationManager.startChatMonitoring(pedidoId, 'cliente'); // Monitorea chat
notificationManager.startStatusMonitoring(pedidoId); // Monitorea estado
```

### Activar Notificaciones (Admin)
```javascript
// En admin/chat-admin.php
notificationManager.startChatMonitoring(pedidoId, 'admin'); // Monitorea cliente

// En admin/kanban.php
// Se monitorean todos los pedidos activos automáticamente
```

## Flujo de Trabajo

### 1. Solicitud de Permisos
```
Usuario visita página → Botón flotante "🔔 Activar Notificaciones"
                      ↓
              Usuario hace clic
                      ↓
         Navegador solicita permiso
                      ↓
    Si acepta → Notificaciones activadas ✅
```

### 2. Monitoreo de Mensajes
```
Intervalo 10s → API check_new_messages → ¿Hay mensajes nuevos?
                                                ↓
                                              SI → Notificación + Sonido
                                                ↓
                                         NO → Esperar 10s
```

### 3. Monitoreo de Estado
```
Intervalo 30s → API check_status_change → ¿Cambió el estado?
                                                ↓
                                              SI → Notificación + Sonido
                                                ↓
                                         NO → Esperar 30s
```

## Características Técnicas

### Intervalos de Verificación
- **Chat**: Cada 10 segundos
- **Estado**: Cada 30 segundos
- **Kanban (Admin)**: Cada 15 segundos para todos los pedidos

### Sonido de Notificación
- Audio WAV embebido en base64
- Volumen: 30%
- Duración: ~200ms

### Prevención de Spam
- **sessionStorage**: Evita notificaciones duplicadas del mismo pedido
- **localStorage**: Registra última verificación por pedido
- Auto-cierre: Notificaciones se cierran después de 5 segundos

### Interacción con Notificaciones
Al hacer clic en una notificación:
- Enfoca la ventana del navegador
- Redirige a la página correspondiente (chat o seguimiento)
- Cierra la notificación

## Permisos del Navegador

### Estados de Permiso
- `default`: No se ha solicitado (muestra botón)
- `granted`: Permiso concedido (notificaciones activas)
- `denied`: Permiso denegado (no muestra botón)

### Navegadores Compatibles
- ✅ Chrome 22+
- ✅ Firefox 22+
- ✅ Safari 7+
- ✅ Edge 14+
- ✅ Opera 25+
- ❌ Internet Explorer (no soportado)

## Integración

### Archivos que usan Notificaciones:
1. `chat-pedido.php` (Cliente)
   - Monitorea mensajes del admin
   - Monitorea cambios de estado
   
2. `seguimiento.php` (Cliente)
   - Monitorea cambios de estado de todos los pedidos

3. `admin/chat-admin.php` (Admin)
   - Monitorea mensajes del cliente

4. `admin/kanban.php` (Admin)
   - Monitorea mensajes de todos los pedidos activos

## Mejoras Futuras
- [ ] Service Worker para notificaciones en background
- [ ] Notificaciones cuando la página está cerrada
- [ ] Personalización de sonidos
- [ ] Historial de notificaciones
- [ ] Configuración de frecuencia de verificación
- [ ] Soporte para imágenes en notificaciones
- [ ] Acciones rápidas desde la notificación

## Notas Importantes
⚠️ Las notificaciones solo funcionan cuando:
- El navegador está abierto
- El usuario ha concedido permisos
- La conexión a internet está activa

⚠️ Algunos navegadores requieren HTTPS en producción para las notificaciones push.

## Privacidad
- No se envían datos personales a servicios externos
- Las verificaciones se hacen directamente a tu servidor
- No se usan servicios de terceros (FCM, OneSignal, etc.)
