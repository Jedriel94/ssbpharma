# 🚀 Despliegue a Producción - https://solumedic.shop/columbia/

## ✅ Cambios Realizados (Fecha: 2026-01-07)

### 1. **Configuración de Rutas** (`config/paths.php`)
- ✅ Actualizado `BASE_PATH` para producción: `/columbia/`
- ✅ Detección automática de entorno (localhost vs producción)
- ✅ Funciones helper `url()` y `asset()` configuradas

### 2. **Configuración de Apache** (`.htaccess`)
- ✅ `RewriteBase` actualizado a `/columbia/`
- ✅ Redirección HTTP a HTTPS activada
- ✅ Reglas de representantes funcionando: `/columbia/r/CODIGO`
- ✅ Protección de archivos sensibles habilitada

### 3. **JavaScript y Assets**
- ✅ `window.BASE_PATH` expuesto en `includes/header.php`
- ✅ `notifications.js` configurado para usar `window.BASE_PATH`
- ✅ Referencias de imágenes en JavaScript actualizadas
- ✅ Logo y assets usando función `asset()`

### 4. **Archivos Verificados Sin Hardcodeos**
- ✅ `index.php` - Logo usando `asset()`
- ✅ `crear-pedido.php` - Imágenes usando `window.BASE_PATH`
- ✅ `seguimiento.php` - Rutas usando `url()`
- ✅ `includes/header.php` - Logo usando `asset()`
- ✅ `includes/auth_admin.php` - Redirecciones usando `url()`
- ✅ `r.php` - Redirecciones dinámicas funcionando
- ✅ Todos los archivos admin usan rutas relativas

---

## 🔧 Configuración de Base de Datos en Producción

### Archivo: `config/database.php`

**IMPORTANTE:** Antes de subir, actualiza las credenciales de base de datos:

```php
// Configuración de la base de datos
define('DB_HOST', 'localhost'); // O el host que provea Hostinger
define('DB_NAME', 'botikit_dbp'); // Nombre de tu base de datos
define('DB_USER', 'tu_usuario'); // Usuario de MySQL
define('DB_PASS', 'tu_password'); // Contraseña segura
define('DB_CHARSET', 'utf8mb4');
```

---

## 📋 Checklist Pre-Despliegue

### Archivos de Configuración
- [ ] Actualizar credenciales de base de datos en `config/database.php`
- [ ] Verificar que `.htaccess` tenga `RewriteBase /columbia/`
- [ ] Verificar que `config/paths.php` tenga `BASE_PATH = '/columbia/'`

### Base de Datos
- [ ] Crear base de datos en el servidor de producción
- [ ] Importar estructura: `database/schema_produccion_unificado.sql`
- [ ] Usar `database/migrations/` solo si vas a actualizar una base existente
- [ ] Crear usuario administrador (usuario: admin, password: admin123)

### Archivos a Subir
- [ ] Todos los archivos `.php`
- [ ] Carpeta `config/` (con database.php actualizado)
- [ ] Carpeta `includes/`
- [ ] Carpeta `models/`
- [ ] Carpeta `admin/`
- [ ] Carpeta `assets/`
- [ ] Carpeta `css/`
- [ ] Carpeta `js/`
- [ ] Carpeta `api/`
- [ ] `.htaccess` (con RewriteBase correcto)

### Carpetas a Crear con Permisos de Escritura (755 o 777)
- [ ] `uploads/`
- [ ] `uploads/productos/`
- [ ] `uploads/comprobantes/`
- [ ] `uploads/comprobantes_envio/`
- [ ] `uploads/fiscales/`
- [ ] `uploads/facturas/`

### Verificaciones Post-Despliegue
- [ ] Acceder a: `https://solumedic.shop/columbia/`
- [ ] Verificar que cargue el index sin errores
- [ ] Probar login admin: `https://solumedic.shop/columbia/login-admin.php`
- [ ] Verificar que los assets carguen (logo, CSS, JS)
- [ ] Probar enlaces de representantes: `https://solumedic.shop/columbia/r/CODIGO`
- [ ] Verificar redirección HTTP → HTTPS
- [ ] Probar creación de pedido
- [ ] Probar chat
- [ ] Verificar notificaciones

---

## 🌐 URLs de la Aplicación

### Público
- **Inicio:** https://solumedic.shop/columbia/
- **Crear Pedido:** https://solumedic.shop/columbia/crear-pedido.php?telefono=5512345678
- **Seguimiento:** https://solumedic.shop/columbia/seguimiento.php?telefono=5512345678
- **Mis Datos:** https://solumedic.shop/columbia/mis-datos.php?telefono=5512345678
- **Representante:** https://solumedic.shop/columbia/r/CODIGO

### Administración
- **Login:** https://solumedic.shop/columbia/login-admin.php
- **Kanban:** https://solumedic.shop/columbia/admin/kanban.php
- **Productos:** https://solumedic.shop/columbia/admin/productos.php
- **Clientes:** https://solumedic.shop/columbia/admin/clientes.php
- **Representantes:** https://solumedic.shop/columbia/admin/representantes.php
- **Configuración:** https://solumedic.shop/columbia/admin/configuracion.php

---

## 🔒 Seguridad

### Headers Configurados
- ✅ X-Frame-Options: SAMEORIGIN
- ✅ X-Content-Type-Options: nosniff
- ✅ X-XSS-Protection: 1; mode=block
- ✅ Redirección forzada a HTTPS

### Protecciones Activas
- ✅ Archivos `.sql`, `.md`, `.log` bloqueados
- ✅ Listado de directorios deshabilitado
- ✅ ServerSignature oculto
- ✅ Upload limitado a 10MB

### Carpetas Protegidas
- ✅ `uploads/` - `.htaccess` limita acceso directo
- ✅ `uploads/fiscales/` - Solo acceso vía script PHP
- ✅ `uploads/facturas/` - Solo acceso vía script PHP

---

## 🐛 Troubleshooting

### Error 404 en todas las páginas
**Causa:** RewriteBase incorrecto en `.htaccess`
**Solución:** Verificar que sea `/columbia/`

### Imágenes no cargan
**Causa:** Rutas hardcodeadas o BASE_PATH incorrecto
**Solución:** Verificar que `window.BASE_PATH` esté definido y sea `/columbia/`

### Error de conexión a base de datos
**Causa:** Credenciales incorrectas en `config/database.php`
**Solución:** Actualizar con las credenciales provistas por el hosting

### Estilos no se aplican
**Causa:** Tailwind CDN bloqueado o BASE_PATH incorrecto
**Solución:** Verificar conexión a internet y que `asset()` esté funcionando

### Archivos no se suben
**Causa:** Permisos incorrectos en carpeta `uploads/`
**Solución:** Dar permisos 755 o 777 a la carpeta y subcarpetas

---

## 📞 Contacto y Soporte

Para problemas durante el despliegue, revisar:
- `deploy/verificar.php` - Script de verificación de instalación
- `database/README_MIGRACION.md` - Guía de migración de base de datos
- `SOLUCION_404_PRODUCCION.md` - Soluciones a errores comunes

---

## ✨ Estado del Proyecto

**Versión:** 1.0.0  
**Fecha de Actualización:** 2026-01-07  
**Entorno:** Producción  
**Dominio:** https://solumedic.shop/columbia/  
**Estado:** ✅ Listo para despliegue
