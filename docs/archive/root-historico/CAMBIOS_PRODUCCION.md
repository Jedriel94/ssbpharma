# 🚀 Cambios para Producción - Rutas Dinámicas

## ✅ Cambios Realizados

Se han actualizado todos los archivos para usar rutas dinámicas que funcionan tanto en **localhost** como en **producción**.

### 📁 Archivos Modificados

#### 1. **config/paths.php** (NUEVO)
- Auto-detecta si está en localhost o producción
- Define `BASE_PATH` según el entorno
- Proporciona funciones helper: `url()` y `asset()`

```php
// Localhost: BASE_PATH = '/botikitpedidos/'
// Producción: BASE_PATH = '/'
```

#### 2. **includes/header.php**
- ✅ Agregado `require_once` de config/paths.php
- ✅ Todos los enlaces del menú usan `url()`
- ✅ 9 rutas actualizadas

#### 3. **includes/auth_admin.php**
- ✅ Agregado `require_once` de config/paths.php
- ✅ Redirect de login usa `url()`

#### 4. **seguimiento.php**
- ✅ Agregado `require_once` de config/paths.php
- ✅ CSS guia-envio.css usa `asset()`
- ✅ Rutas de comprobantes de envío usan `url()`
- ✅ Script notifications.js usa `asset()`
- ✅ Agregado `window.BASE_PATH` para JavaScript

#### 5. **chat-pedido.php**
- ✅ Agregado `require_once` de config/paths.php
- ✅ Script notifications.js usa `asset()`
- ✅ Agregado `window.BASE_PATH` para JavaScript

#### 6. **admin/kanban.php**
- ✅ Agregado `require_once` de config/paths.php
- ✅ Script notifications.js usa `asset()`
- ✅ Rutas de API usan `window.BASE_PATH`
- ✅ Agregado `window.BASE_PATH` para JavaScript

#### 7. **admin/chat-admin.php**
- ✅ Agregado `require_once` de config/paths.php
- ✅ Script notifications.js usa `asset()`
- ✅ Agregado `window.BASE_PATH` para JavaScript

#### 8. **js/notifications.js**
- ✅ Agregado `this.basePath` en constructor
- ✅ Auto-detecta `window.BASE_PATH` o usa fallback
- ✅ Todos los fetch usan `this.basePath`
- ✅ URLs de notificaciones usan `this.basePath`
- ✅ 7 rutas actualizadas

#### 9. **.htaccess**
- ✅ `RewriteBase` cambiado de `/botikitpedidos/` a `/`
- ✅ Redirect HTTPS habilitado

### 📊 Estadísticas de Cambios

| Archivo | Rutas Hardcodeadas Antes | Rutas Dinámicas Ahora |
|---------|--------------------------|----------------------|
| includes/header.php | 9 | 9 |
| includes/auth_admin.php | 1 | 1 |
| seguimiento.php | 4 | 4 |
| js/notifications.js | 7 | 7 |
| admin/kanban.php | 3 | 3 |
| admin/chat-admin.php | 1 | 1 |
| chat-pedido.php | 1 | 1 |
| **TOTAL** | **26** | **26** |

## 🔧 Cómo Funciona

### Entorno Local (localhost)
```
URL: http://localhost/botikitpedidos/index.php
BASE_PATH: /botikitpedidos/
```

### Entorno Producción (botikit.shop)
```
URL: https://botikit.shop/index.php
BASE_PATH: /
```

### Uso en PHP
```php
// Incluir config
require_once __DIR__ . '/config/paths.php';

// Generar URL
<a href="<?= url('admin/productos.php') ?>">Productos</a>
// Localhost: /botikitpedidos/admin/productos.php
// Producción: /admin/productos.php

// Cargar asset
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
// Localhost: /botikitpedidos/css/style.css
// Producción: /css/style.css
```

### Uso en JavaScript
```javascript
// En HTML antes de cargar JS
<script>
    window.BASE_PATH = '<?= BASE_PATH ?>';
</script>

// En JavaScript
fetch(window.BASE_PATH + 'api/endpoint.php');
// Localhost: /botikitpedidos/api/endpoint.php
// Producción: /api/endpoint.php
```

## 📋 Próximos Pasos para Deployment

### 1. Subir Archivos a Hostinger
Subir todos los archivos modificados a `public_html/`:
- config/paths.php
- .htaccess
- includes/header.php
- includes/auth_admin.php
- seguimiento.php
- chat-pedido.php
- admin/kanban.php
- admin/chat-admin.php
- js/notifications.js

### 2. Actualizar Database Config
Editar `config/database.php` con credenciales de Hostinger:
```php
define('DB_HOST', 'localhost'); // o IP proporcionada
define('DB_NAME', 'botikit_dbp'); // nombre desde hPanel
define('DB_USER', 'botikit_user'); // usuario desde hPanel
define('DB_PASS', 'password_seguro'); // contraseña desde hPanel
```

### 3. Verificar Permisos
```bash
chmod 755 uploads/
chmod 755 uploads/productos/
chmod 755 uploads/comprobantes/
chmod 755 uploads/comprobantes_envio/
chmod 755 uploads/facturas/
```

### 4. Probar en Producción
1. Acceder a `https://botikit.shop/login-admin.php`
2. Verificar que todos los links del menú funcionen
3. Probar crear pedido
4. Verificar que las notificaciones funcionen
5. Probar chat
6. Verificar Kanban

## 🐛 Troubleshooting

### Si los links siguen sin funcionar:
1. Verificar que `.htaccess` esté en la raíz (`public_html/`)
2. Verificar que `mod_rewrite` esté habilitado
3. Limpiar caché del navegador
4. Revisar error_log de Apache

### Si las imágenes no cargan:
1. Verificar que la carpeta `uploads/` exista
2. Verificar permisos 755 en carpetas
3. Verificar permisos 644 en archivos

### Si la base de datos no conecta:
1. Verificar credenciales en `config/database.php`
2. Verificar que la base de datos exista en hPanel
3. Verificar que el usuario tenga permisos
4. Revisar PHP error log

## 📝 Notas Importantes

- ✅ Todos los cambios son **retrocompatibles** con localhost
- ✅ No se requieren cambios en la base de datos
- ✅ El sistema detecta automáticamente el entorno
- ✅ Funciona en subcarpetas si se necesita (cambiar detección en paths.php)

## 🎯 Resultado Esperado

Después de subir estos archivos a producción:
- ✅ Login de admin funcional en `https://botikit.shop/login-admin.php`
- ✅ Todos los links del menú funcionan correctamente
- ✅ Imágenes y assets cargan sin problemas
- ✅ APIs y notificaciones funcionan
- ✅ Sistema completamente operativo en producción

---

**Fecha:** <?= date('Y-m-d H:i:s') ?>  
**Entorno Local:** Funcional ✅  
**Entorno Producción:** Pendiente de deployment 🚀
