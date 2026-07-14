# 🔧 Guía de Solución: Error 404 en botikit.shop

## 🐛 Error Actual:
```
GET https://botikit.shop/botikitpedidos/login-admin.php 404 (Not Found)
```

## 📊 Diagnóstico

### Problema Principal:
El `.htaccess` está configurado con `RewriteBase /botikitpedidos/` para desarrollo local, pero necesita ajustarse para producción.

---

## ✅ SOLUCIÓN PASO A PASO

### PASO 1: Identifica dónde están tus archivos

Accede a tu **File Manager** en hPanel de Hostinger y verifica:

#### Opción A: Archivos en subcarpeta
```
/home/u123456/domains/botikit.shop/public_html/
└── botikitpedidos/
    ├── login-admin.php
    ├── index.php
    ├── admin/
    └── ...
```
**URL esperada:** `https://botikit.shop/botikitpedidos/login-admin.php`

#### Opción B: Archivos en raíz
```
/home/u123456/domains/botikit.shop/public_html/
├── login-admin.php
├── index.php
├── admin/
└── ...
```
**URL esperada:** `https://botikit.shop/login-admin.php`

---

### PASO 2: Actualiza el `.htaccess` según tu estructura

#### Si elegiste OPCIÓN A (subcarpeta):

Usa el archivo `.htaccess-subcarpeta` que generé:

1. En tu File Manager de Hostinger
2. Edita el archivo `.htaccess` en `/public_html/botikitpedidos/.htaccess`
3. Asegúrate que tenga esta línea:
   ```apache
   RewriteBase /botikitpedidos/
   ```

#### Si elegiste OPCIÓN B (raíz):

Usa el archivo `.htaccess` principal actualizado:

1. En tu File Manager de Hostinger
2. Edita el archivo `.htaccess` en `/public_html/.htaccess`
3. Cambia esta línea a:
   ```apache
   RewriteBase /
   ```

---

### PASO 3: Actualiza `config/database.php`

Necesitas cambiar las credenciales de la base de datos a las de Hostinger:

**ACTUAL (desarrollo local):**
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'botikit_dbp');
define('DB_USER', 'root');
define('DB_PASS', '');
```

**DEBE SER (producción Hostinger):**
```php
define('DB_HOST', 'localhost');  // O la IP que te dio Hostinger
define('DB_NAME', 'u123456_botikit');  // Tu nombre de BD en Hostinger
define('DB_USER', 'u123456_admin');  // Tu usuario de BD en Hostinger
define('DB_PASS', 'tu_password_seguro');  // La contraseña que estableciste
```

**¿Cómo obtener estos datos?**
1. Ve a tu **hPanel de Hostinger**
2. Click en **Bases de Datos** → **MySQL Databases**
3. Ahí verás:
   - Nombre de la base de datos
   - Usuario
   - Host (generalmente `localhost`)
4. La contraseña la definiste al crear la BD

---

### PASO 4: Verifica los permisos de archivos

En Hostinger File Manager, verifica que los archivos tengan estos permisos:

```
Carpetas: 755
Archivos PHP: 644
```

**Archivos importantes que deben ser 644:**
- `login-admin.php`
- `index.php`
- Todos los archivos `.php`

**Carpetas que deben ser 755:**
- `admin/`
- `uploads/`
- `includes/`

**Carpeta que debe ser 777 (escribible):**
- `uploads/` y todas sus subcarpetas
- `uploads/comprobantes/`
- `uploads/facturas/`
- `uploads/comprobantes_envio/`

**Cómo cambiar permisos:**
1. Click derecho en el archivo/carpeta
2. Selecciona "Permissions"
3. Establece el número correcto

---

### PASO 5: Prueba las URLs

Después de hacer los cambios, prueba estas URLs:

#### Si instalaste en SUBCARPETA:
- ✅ `https://botikit.shop/botikitpedidos/` (debe mostrar la página principal)
- ✅ `https://botikit.shop/botikitpedidos/login-admin.php` (debe mostrar login)
- ✅ `https://botikit.shop/botikitpedidos/admin/` (debe redirigir a login)

#### Si instalaste en RAÍZ:
- ✅ `https://botikit.shop/` (debe mostrar la página principal)
- ✅ `https://botikit.shop/login-admin.php` (debe mostrar login)
- ✅ `https://botikit.shop/admin/` (debe redirigir a login)

---

## 🔍 Troubleshooting Adicional

### Error 404 persiste

**Causa 1: `.htaccess` no se subió**
- Verifica que el archivo `.htaccess` exista en el servidor
- Los archivos que empiezan con `.` son ocultos, activa "Show Hidden Files" en File Manager

**Causa 2: mod_rewrite no habilitado**
- En Hostinger, mod_rewrite está habilitado por defecto
- Si persiste, contacta soporte de Hostinger

**Causa 3: Archivo realmente no existe**
- Verifica que `login-admin.php` esté en la ubicación correcta
- Revisa mayúsculas/minúsculas (Linux es case-sensitive)

### Error "Error de conexión" o página en blanco

Esto significa que el `.htaccess` funciona pero hay problema con la BD:

1. Verifica credenciales en `config/database.php`
2. Asegúrate de haber importado la base de datos
3. Verifica que el usuario tenga permisos en la BD

### Error "Permission Denied"

1. Revisa permisos de archivos (deben ser 644)
2. Revisa permisos de carpetas (deben ser 755)
3. Carpeta `uploads/` debe ser 777

---

## 📋 Checklist Completo

Marca cada elemento cuando lo completes:

### Pre-vuelo
- [ ] Identificar si archivos están en raíz o subcarpeta
- [ ] Tener a mano credenciales de BD de Hostinger
- [ ] Verificar que el dominio apunte correctamente

### Configuración
- [ ] Actualizar `RewriteBase` en `.htaccess` 
- [ ] Actualizar `config/database.php` con credenciales de Hostinger
- [ ] Importar la base de datos en Hostinger
- [ ] Verificar permisos de archivos (644)
- [ ] Verificar permisos de carpetas (755)
- [ ] Verificar permisos de uploads (777)

### Verificación
- [ ] Probar URL principal (index.php)
- [ ] Probar login-admin.php
- [ ] Probar acceso al panel admin
- [ ] Probar login con usuario admin
- [ ] Verificar que no haya errores en consola

### SSL (Opcional pero recomendado)
- [ ] Activar SSL en hPanel
- [ ] Descomentar redirección HTTPS en `.htaccess`
- [ ] Probar con https://

---

## 🚀 Comandos Útiles

### Para encontrar archivos en Hostinger:
```bash
find /home/u123456/domains/botikit.shop/public_html -name "login-admin.php"
```

### Para verificar permisos:
```bash
ls -la /home/u123456/domains/botikit.shop/public_html/
```

### Para ver logs de errores:
```
/home/u123456/domains/botikit.shop/logs/error_log
```

---

## 📞 Archivos Clave a Revisar

1. **`.htaccess`** (raíz del proyecto)
   - Línea crítica: `RewriteBase`

2. **`config/database.php`**
   - DB_HOST, DB_NAME, DB_USER, DB_PASS

3. **`login-admin.php`**
   - Debe existir y tener permisos 644

4. **`includes/auth_admin.php`**
   - Verifica que las rutas sean relativas

---

## ✅ Solución Rápida (Más Probable)

El problema más común es el `.htaccess`. Haz esto:

1. **Conecta por FTP o File Manager a Hostinger**

2. **Edita el archivo `.htaccess`** en la raíz de tu instalación

3. **Cambia esta línea:**
   ```apache
   # DE ESTO:
   RewriteBase /botikitpedidos/
   
   # A ESTO (si instalaste en raíz):
   RewriteBase /
   
   # O DÉJALO ASÍ (si instalaste en subcarpeta):
   RewriteBase /botikitpedidos/
   ```

4. **Guarda y prueba la URL correspondiente**

---

## 🎯 URLs de Prueba

Prueba estas URLs en orden:

1. `https://botikit.shop/` o `https://botikit.shop/botikitpedidos/`
   - ✅ Debe mostrar algo (aunque sea un error de BD)
   - ❌ 404 = Problema con RewriteBase

2. `https://botikit.shop/login-admin.php` o `https://botikit.shop/botikitpedidos/login-admin.php`
   - ✅ Debe mostrar formulario de login
   - ❌ 404 = Archivo no está donde crees

3. Login con: admin / tu_password
   - ✅ Debe entrar al panel
   - ❌ Error de BD = Problema en config/database.php

---

**¿Necesitas ayuda?** 
Envíame:
1. Screenshot del File Manager mostrando la estructura de carpetas
2. El contenido actual de tu `.htaccess`
3. La URL exacta que estás intentando acceder
4. El error específico que ves

---

**Última actualización:** 20 de Octubre, 2025
