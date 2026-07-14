# 📋 Checklist de Deployment a Producción

## Pre-Deployment (En Local)

- [x] Crear config/paths.php con auto-detección
- [x] Actualizar .htaccess (RewriteBase /)
- [x] Actualizar includes/header.php (todos los links)
- [x] Actualizar includes/auth_admin.php (redirect)
- [x] Actualizar seguimiento.php (CSS, JS, comprobantes)
- [x] Actualizar js/notifications.js (todas las rutas)
- [x] Actualizar chat-pedido.php (JS include)
- [x] Actualizar admin/kanban.php (JS y API calls)
- [x] Actualizar admin/chat-admin.php (JS include)
- [ ] Probar todo en localhost antes de subir

## Deployment a Hostinger

### 1. Archivos a Subir
- [ ] Subir `config/paths.php` → `public_html/config/`
- [ ] Subir `.htaccess` → `public_html/`
- [ ] Subir `includes/header.php` → `public_html/includes/`
- [ ] Subir `includes/auth_admin.php` → `public_html/includes/`
- [ ] Subir `seguimiento.php` → `public_html/`
- [ ] Subir `chat-pedido.php` → `public_html/`
- [ ] Subir `js/notifications.js` → `public_html/js/`
- [ ] Subir `admin/kanban.php` → `public_html/admin/`
- [ ] Subir `admin/chat-admin.php` → `public_html/admin/`

### 2. Configuración Base de Datos
- [ ] Acceder a hPanel > MySQL Databases
- [ ] Copiar:
  - [ ] Nombre de base de datos
  - [ ] Usuario de base de datos
  - [ ] Host (normalmente localhost)
- [ ] Editar `config/database.php` en producción
- [ ] Actualizar las 4 constantes (DB_HOST, DB_NAME, DB_USER, DB_PASS)

### 3. Verificar Permisos
Ejecutar en File Manager de Hostinger o por SSH:
```bash
chmod 755 public_html/uploads
chmod 755 public_html/uploads/productos
chmod 755 public_html/uploads/comprobantes
chmod 755 public_html/uploads/comprobantes_envio
chmod 755 public_html/uploads/facturas
```

### 4. Verificar .htaccess
- [ ] Confirmar que está en `public_html/.htaccess`
- [ ] Confirmar que `RewriteBase /` (NO /botikitpedidos/)
- [ ] Confirmar que `RewriteEngine On` está activo

## Post-Deployment Testing

### Pruebas Funcionales
- [ ] Abrir `https://botikit.shop/`
- [ ] Verificar que el home carga correctamente
- [ ] Probar `https://botikit.shop/login-admin.php`
- [ ] Login con credenciales de admin
- [ ] Verificar que todos los links del menú funcionan:
  - [ ] Productos
  - [ ] Kanban
  - [ ] Lista Pedidos
  - [ ] Clientes
  - [ ] Logout
  - [ ] Mis Datos
  - [ ] Seguimiento
- [ ] Crear un pedido de prueba
- [ ] Verificar que las imágenes de productos cargan
- [ ] Procesar pago de prueba
- [ ] Verificar comprobante de pago
- [ ] Mover pedido en Kanban
- [ ] Probar chat cliente-admin
- [ ] Probar notificaciones (si navegador lo permite)

### Verificar Assets
- [ ] CSS carga correctamente
- [ ] JavaScript funciona sin errores (F12 > Console)
- [ ] Imágenes de productos visibles
- [ ] Logo/assets generales cargan

### Verificar APIs
- [ ] Notificaciones funcionan
- [ ] Chat funciona en tiempo real
- [ ] Kanban actualiza estados correctamente
- [ ] Subida de archivos funciona

## Troubleshooting

### Si aparecen 404:
1. Verificar .htaccess en raíz
2. Verificar RewriteBase /
3. Limpiar caché del navegador (Ctrl+Shift+R)
4. Verificar que mod_rewrite esté activo

### Si las imágenes no cargan:
1. Verificar ruta en navegador (F12 > Network)
2. Verificar permisos de carpeta uploads/
3. Verificar que los archivos existan en el servidor

### Si hay errores de JavaScript:
1. Abrir F12 > Console
2. Verificar que window.BASE_PATH esté definido
3. Verificar que notifications.js cargue correctamente
4. Limpiar caché y recargar

### Si no conecta a la base de datos:
1. Verificar credenciales en config/database.php
2. Verificar que la base de datos existe en hPanel
3. Verificar permisos del usuario en la BD
4. Revisar error_log en hPanel

## Rollback (Si algo falla)

Si algo sale mal, tener backup de:
- [ ] .htaccess original
- [ ] config/database.php con credenciales correctas
- [ ] Todos los archivos modificados

## Finalización

- [ ] Todo funciona correctamente en producción
- [ ] Base de datos conecta sin problemas
- [ ] Todos los links funcionan
- [ ] Chat y notificaciones operativas
- [ ] Kanban funcional
- [ ] Sin errores en consola del navegador
- [ ] Sin errores en error_log de Apache
- [ ] Sistema completamente operativo

---

**Fecha Deployment:** _______________  
**Realizado por:** _______________  
**Estado:** [ ] Exitoso [ ] Con problemas [ ] Requiere ajustes
