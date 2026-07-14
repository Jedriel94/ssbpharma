# 🚀 GUÍA RÁPIDA DE DEPLOYMENT A PRODUCCIÓN
# botikit.shop

## ✅ PASO 1: Archivos Listos para Subir

Todos los archivos en `C:\laragon\www\botikitpedidos\` están listos para producción.

**IMPORTANTE**: 
- ✅ `.htaccess` ya está configurado para producción (RewriteBase /)
- ✅ `config/paths.php` detecta automáticamente producción
- ⚠️ Solo falta configurar `config/database.php` con credenciales de Hostinger

---

## 📤 PASO 2: Subir Archivos

### Vía FTP/SFTP (FileZilla o similar):
1. Conectar a: `ftp.botikit.shop` (o IP proporcionada por Hostinger)
2. Usuario: Tu usuario de hosting
3. Contraseña: Tu contraseña de hosting
4. Subir TODO el contenido de `botikitpedidos` a `public_html/`

### Vía File Manager de Hostinger:
1. Ir a hPanel → File Manager
2. Navegar a `public_html/`
3. Subir o pegar todos los archivos

**Estructura esperada en producción:**
```
public_html/
├── .htaccess
├── index.php
├── login-admin.php
├── crear-pedido.php
├── seguimiento.php
├── config/
│   ├── database.php  ⚠️ EDITAR ESTE
│   └── paths.php
├── includes/
├── models/
├── admin/
├── js/
├── css/
├── uploads/
└── api/
```

---

## 🗄️ PASO 3: Configurar Base de Datos

### 3.1 Crear Base de Datos en Hostinger
1. Ir a hPanel → MySQL Databases
2. Crear nueva base de datos:
   - Nombre: `botikit_dbp` (o el que prefieras)
3. Crear usuario:
   - Usuario: `botikit_user`
   - Contraseña: [GENERA UNA SEGURA]
4. Asignar usuario a la base de datos con TODOS los privilegios

### 3.2 Importar Estructura
1. Ir a phpMyAdmin
2. Seleccionar la base de datos creada
3. Importar el archivo SQL con la estructura de tablas
   (Archivo oficial actual: `database/schema_produccion_unificado.sql`)

### 3.3 Actualizar config/database.php en Producción
Editar `public_html/config/database.php`:

```php
<?php
// Configuración de la base de datos

// PRODUCCIÓN - Hostinger
define('DB_HOST', 'localhost');  // O el host que te dé Hostinger
define('DB_NAME', 'botikit_dbp'); // El nombre que creaste
define('DB_USER', 'botikit_user'); // El usuario que creaste
define('DB_PASS', 'TU_CONTRASEÑA_SEGURA'); // La contraseña que creaste
define('DB_CHARSET', 'utf8mb4');

// Crear conexión PDO
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
```

---

## 🧪 PASO 4: Probar en Producción

1. **Abrir**: https://botikit.shop/
   - Debe mostrar la página de inicio

2. **Probar Login Admin**: https://botikit.shop/login-admin.php
   - Usuario: (el que tienes en la BD)
   - Contraseña: (la que configuraste)

3. **Verificar rutas**: https://botikit.shop/deploy/verificar-rutas.php
   - Debe mostrar: `BASE_PATH: /`
   - Debe mostrar: `Entorno: PRODUCCIÓN`

4. **Probar funcionalidades**:
   - Crear pedido
   - Ver Kanban
   - Chat
   - Subir comprobantes

---

## 🔒 PASO 5: Seguridad Post-Deploy

1. **Eliminar archivos de prueba**:
   ```
   deploy/verificar-rutas.php
   deploy/verificar-rutas.php
   ```

2. **Verificar permisos**:
   ```
   uploads/ → 755
   uploads/productos/ → 755
   uploads/comprobantes/ → 755
   uploads/comprobantes_envio/ → 755
   uploads/facturas/ → 755
   ```

3. **SSL/HTTPS**:
   - Hostinger incluye SSL gratuito
   - Activar desde hPanel → SSL
   - El .htaccess ya fuerza HTTPS

---

## 🛠️ TROUBLESHOOTING

### Error 500
- Verificar permisos de archivos (644) y carpetas (755)
- Revisar PHP error log en hPanel
- Verificar que mod_rewrite esté activo

### No carga CSS/JS
- Verificar rutas en navegador (F12 → Network)
- Verificar que BASE_PATH sea `/` o la subcarpeta real configurada

### Error de Base de Datos
- Verificar credenciales en config/database.php
- Verificar que el usuario tenga permisos
- Verificar que las tablas estén importadas

### Imágenes no cargan
- Verificar permisos carpeta uploads/
- Verificar que los archivos se subieron correctamente

---

## 🔙 VOLVER A DESARROLLO LOCAL

Cuando quieras trabajar localmente de nuevo:

1. **Reemplazar .htaccess**:
   ```powershell
   Copy-Item .htaccess-localhost .htaccess -Force
   ```

2. **O editar manualmente**:
   - Cambiar `RewriteBase /` → `RewriteBase /proceso/`
   - Comentar líneas de HTTPS redirect

---

## 📞 SOPORTE

Si algo no funciona:
1. Revisar error_log de Apache/PHP
2. Usar deploy/verificar-rutas.php para diagnóstico
3. Verificar que config/paths.php detecte correctamente el entorno

---

**Fecha de deployment**: <?= date('Y-m-d H:i:s') ?>  
**Versión**: 1.0 - Producción Ready  
**URL Local**: http://localhost/proceso/ (Firefox)  
**URL Producción**: https://botikit.shop/
