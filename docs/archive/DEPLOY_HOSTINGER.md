# 🚀 Guía de Despliegue a Producción - Hostinger

## 📋 Índice
1. [Preparación del Proyecto](#preparación)
2. [Configuración de Base de Datos](#base-de-datos)
3. [Subida de Archivos](#subida-archivos)
4. [Configuración de Producción](#configuración)
5. [Verificación y Pruebas](#verificación)
6. [Seguridad Adicional](#seguridad)
7. [Mantenimiento](#mantenimiento)

---

## 1️⃣ Preparación del Proyecto {#preparación}

### Archivos a Excluir (NO subir)
Crea un archivo `.deployignore` o simplemente NO subas estos archivos:

```
# NO subir a producción
/demo-*.html
/docs/
/.git/
/.vscode/
/node_modules/
composer.lock
package-lock.json
*.log
.env.example
```

### Checklist Pre-Deploy
- [ ] Verificar que todas las rutas sean relativas o absolutas desde raíz
- [ ] Cambiar credenciales de base de datos
- [ ] Activar modo producción (desactivar errores en pantalla)
- [ ] Verificar permisos de carpetas uploads/
- [ ] Probar localmente una última vez

---

## 2️⃣ Configuración de Base de Datos {#base-de-datos}

### Paso 1: Crear Base de Datos en Hostinger

1. **Accede a hPanel** (panel de control de Hostinger)
2. Ve a **"Bases de datos MySQL"**
3. Crea una nueva base de datos:
   - **Nombre**: `u123456789_botikitpedidos` (Hostinger agrega prefijo automático)
   - **Usuario**: `u123456789_admin` (o el que prefieras)
   - **Contraseña**: Genera una contraseña fuerte
   - **Guarda estos datos** ✍️

### Paso 2: Exportar Base de Datos Local

En tu ordenador, abre **phpMyAdmin** de Laragon:
1. Selecciona la base de datos `botikitpedidos`
2. Ve a la pestaña **"Exportar"**
3. Selecciona **"Método personalizado"**
4. Opciones importantes:
   - ✅ Estructura
   - ✅ Datos
   - ✅ CREATE TABLE
   - ✅ IF NOT EXISTS
   - ❌ DROP TABLE (para evitar problemas)
5. Formato: **SQL**
6. Click en **"Continuar"** y guarda el archivo `botikitpedidos.sql`

### Paso 3: Importar en Hostinger

1. En hPanel, click en **"phpMyAdmin"** junto a tu base de datos
2. Selecciona tu base de datos en el panel izquierdo
3. Ve a la pestaña **"Importar"**
4. Click en **"Elegir archivo"** y selecciona `botikitpedidos.sql`
5. Click en **"Continuar"**
6. ✅ Verifica que se importaron todas las tablas

### Paso 4: Verificar Estructura

Verifica que existen estas tablas:
```
✅ usuarios
✅ clientes
✅ categorias
✅ productos
✅ pedidos
✅ detalle_pedidos
✅ mensajes_pedidos
```

---

## 3️⃣ Subida de Archivos {#subida-archivos}

### Opción A: FileZilla (Recomendado)

#### Instalación
1. Descarga FileZilla desde: https://filezilla-project.org/
2. Instala y abre FileZilla

#### Configuración FTP
En hPanel de Hostinger:
1. Ve a **"Archivos" → "Administrador de archivos FTP"**
2. Copia las credenciales FTP:
   - **Host**: ftp.tudominio.com
   - **Usuario**: u123456789@tudominio.com
   - **Contraseña**: (tu contraseña de FTP)
   - **Puerto**: 21

#### Conexión y Subida
1. En FileZilla, ingresa las credenciales en la barra superior
2. Click en **"Conexión rápida"**
3. En el panel derecho (servidor), navega a: `/public_html/`
4. En el panel izquierdo (local), navega a: `C:\laragon\www\botikitpedidos\`
5. Selecciona **TODOS** los archivos y carpetas
6. Click derecho → **"Subir"**
7. ⏳ Espera a que termine (puede tomar 10-20 minutos)

### Opción B: Administrador de Archivos de Hostinger

1. En hPanel, ve a **"Administrador de archivos"**
2. Navega a `/public_html/`
3. Comprimir tu proyecto localmente:
   - Selecciona todo en `C:\laragon\www\botikitpedidos\`
   - Click derecho → **"Enviar a" → "Carpeta comprimida"**
   - Nombra: `botikitpedidos.zip`
4. En el Administrador de Archivos de Hostinger:
   - Click en **"Subir archivos"**
   - Selecciona `botikitpedidos.zip`
   - Espera a que termine
5. Click derecho en `botikitpedidos.zip` → **"Extraer"**
6. Elimina el archivo ZIP después de extraer

---

## 4️⃣ Configuración de Producción {#configuración}

### Paso 1: Actualizar config/database.php

Conéctate por FTP o usa el Administrador de Archivos y edita:
`/public_html/botikitpedidos/config/database.php`

```php
<?php
class Database {
    // CONFIGURACIÓN DE PRODUCCIÓN - HOSTINGER
    private $host = "localhost";  // En Hostinger siempre es localhost
    private $db_name = "u123456789_botikitpedidos";  // Tu nombre de BD
    private $username = "u123456789_admin";  // Tu usuario de BD
    private $password = "TU_CONTRASEÑA_SEGURA";  // Tu contraseña de BD
    private $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            // En producción NO mostrar el error real
            error_log("Error de conexión: " . $exception->getMessage());
            die("Error de conexión a la base de datos. Por favor contacta al administrador.");
        }
        
        return $this->conn;
    }
}
?>
```

⚠️ **IMPORTANTE**: Cambia `u123456789`, `TU_CONTRASEÑA_SEGURA` por tus datos reales.

### Paso 2: Configurar php.ini (Opcional)

Si necesitas cambiar configuraciones de PHP, en hPanel:
1. Ve a **"Configuración avanzada" → "Configuración de PHP"**
2. Ajusta según necesites:
   ```ini
   upload_max_filesize = 10M
   post_max_size = 10M
   max_execution_time = 300
   memory_limit = 256M
   display_errors = Off
   log_errors = On
   error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
   ```

### Paso 3: Configurar .htaccess (Seguridad)

Crea o edita `/public_html/botikitpedidos/.htaccess`:

```apache
# Configuración de seguridad para producción

# Deshabilitar listado de directorios
Options -Indexes

# Proteger archivos sensibles
<FilesMatch "\.(env|sql|log|md|json|lock)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Proteger carpeta config
<Directory "config">
    Order deny,allow
    Deny from all
</Directory>

# Habilitar compresión
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>

# Cache de recursos estáticos
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>

# Proteger contra inyección SQL
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{QUERY_STRING} (\<|%3C).*script.*(\>|%3E) [NC,OR]
    RewriteCond %{QUERY_STRING} GLOBALS(=|\[|\%[0-9A-Z]{0,2}) [OR]
    RewriteCond %{QUERY_STRING} _REQUEST(=|\[|\%[0-9A-Z]{0,2})
    RewriteRule .* index.php [F,L]
</IfModule>

# Forzar HTTPS (Recomendado)
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

### Paso 4: Configurar Permisos de Carpetas

Vía FTP o Administrador de Archivos:

```
uploads/                          → 755
uploads/comprobantes_pago/        → 755
uploads/comprobantes_envio/       → 755
uploads/productos/                → 755

config/                           → 755
config/database.php               → 644 (NO 777)
```

**Para cambiar permisos en FileZilla:**
1. Click derecho en la carpeta
2. **"Permisos de archivo"**
3. Ingresa el número (ej: 755)
4. ✅ Marca "Recurse into subdirectories"
5. Click OK

---

## 5️⃣ Verificación y Pruebas {#verificación}

### Checklist de Verificación

1. **Acceso Principal**
   ```
   https://tudominio.com/botikitpedidos/
   ```
   - [ ] ✅ Se carga la página principal
   - [ ] ✅ Se muestran productos
   - [ ] ✅ Imágenes cargando correctamente

2. **Login Admin**
   ```
   https://tudominio.com/botikitpedidos/admin/
   Usuario: admin
   Contraseña: (tu contraseña)
   ```
   - [ ] ✅ Login funciona
   - [ ] ✅ Dashboard carga
   - [ ] ✅ Todas las secciones accesibles

3. **Funcionalidades Cliente**
   - [ ] ✅ Agregar productos al carrito
   - [ ] ✅ Crear pedido
   - [ ] ✅ Subir comprobante de pago
   - [ ] ✅ Ver seguimiento
   - [ ] ✅ Chat funciona

4. **Funcionalidades Admin**
   - [ ] ✅ Kanban de pedidos
   - [ ] ✅ Cambiar estados
   - [ ] ✅ Subir guía de envío
   - [ ] ✅ Chat con clientes
   - [ ] ✅ CRUD productos

5. **Notificaciones Push**
   - [ ] ✅ Botón de activación aparece
   - [ ] ✅ Permisos se pueden otorgar
   - [ ] ✅ Notificaciones funcionan

### Probar con Pedido Completo

1. **Como Cliente:**
   - Agrega productos al carrito
   - Completa el pedido con tu teléfono
   - Sube un comprobante de pago (imagen de prueba)
   - Verifica en `seguimiento.php?telefono=TU_TELEFONO`

2. **Como Admin:**
   - Ve al Kanban
   - Mueve el pedido a "Confirmado"
   - Sube una guía de envío (imagen de prueba)
   - Mueve a "En Ruta"
   - Verifica que el cliente pueda ver la guía

---

## 6️⃣ Seguridad Adicional {#seguridad}

### Cambiar Contraseña Admin

En phpMyAdmin de Hostinger, ejecuta:

```sql
-- Generar nueva contraseña (cambia 'nueva_contraseña_segura')
UPDATE usuarios 
SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE username = 'admin';

-- Para generar el hash de tu contraseña, usa este código PHP:
-- <?php echo password_hash('tu_nueva_contraseña', PASSWORD_DEFAULT); ?>
```

### Proteger Directorio Admin

Agrega autenticación HTTP en `/admin/.htaccess`:

```apache
AuthType Basic
AuthName "Area Restringida"
AuthUserFile /home/u123456789/public_html/botikitpedidos/.htpasswd
Require valid-user
```

Genera `.htpasswd` con: https://hostingcanada.org/htpasswd-generator/

### SSL/HTTPS (Certificado Gratuito)

En hPanel de Hostinger:
1. Ve a **"Seguridad" → "SSL"**
2. Click en **"Instalar SSL"**
3. Selecciona tu dominio
4. Espera 5-10 minutos
5. ✅ Tu sitio ahora es HTTPS

### Backup Automático

Hostinger incluye backups automáticos, pero también:

1. **Backup de Base de Datos** (Semanal):
   - phpMyAdmin → Exportar → Guardar en tu PC

2. **Backup de Archivos** (Mensual):
   - FileZilla → Descargar carpeta completa

---

## 7️⃣ Mantenimiento {#mantenimiento}

### Monitoreo

#### Logs de Errores
Ubicación en Hostinger: `/public_html/botikitpedidos/error_log`

Revisa periódicamente para detectar problemas.

#### Espacio en Disco
En hPanel → **"Archivos"** → Verifica uso de espacio
- Si uploads/ crece mucho, considera limpiar archivos antiguos

### Optimización

#### Imágenes
- Comprimir imágenes antes de subir
- Herramientas: TinyPNG, ImageOptim
- Tamaño recomendado: < 500KB por imagen

#### Base de Datos
```sql
-- Limpiar pedidos cancelados antiguos (más de 6 meses)
DELETE FROM pedidos 
WHERE estado = 'cancelado' 
AND fecha_creacion < DATE_SUB(NOW(), INTERVAL 6 MONTH);

-- Optimizar tablas
OPTIMIZE TABLE pedidos, detalle_pedidos, mensajes_pedidos;
```

### Actualizaciones

Cuando hagas cambios localmente:

1. **Probar en Local** (Laragon)
2. **Exportar solo cambios**:
   - Archivos modificados vía FTP
   - SQL de cambios vía phpMyAdmin
3. **Verificar en producción**
4. **Backup antes de actualizar**

---

## 📊 Checklist Final de Deploy

### Pre-Deploy
- [ ] Base de datos exportada
- [ ] Credenciales de producción listas
- [ ] Archivos comprimidos o listos para FTP
- [ ] Backup local guardado

### Deploy
- [ ] Base de datos creada en Hostinger
- [ ] Base de datos importada correctamente
- [ ] Archivos subidos a `/public_html/botikitpedidos/`
- [ ] `config/database.php` actualizado
- [ ] Permisos de carpetas configurados (755)
- [ ] `.htaccess` configurado

### Post-Deploy
- [ ] Sitio principal carga correctamente
- [ ] Login admin funciona
- [ ] Prueba de pedido completo exitosa
- [ ] Notificaciones push funcionan
- [ ] SSL/HTTPS activo
- [ ] Contraseña admin cambiada
- [ ] Backup inicial creado

### Opcional
- [ ] Google Analytics configurado
- [ ] Dominio personalizado conectado
- [ ] Email profesional configurado
- [ ] Monitoreo de uptime activo

---

## 🆘 Solución de Problemas Comunes

### Error: "Could not connect to database"
**Solución:**
1. Verifica credenciales en `config/database.php`
2. Confirma que el host sea `localhost`
3. Verifica que el usuario tenga permisos en la BD

### Error 500 - Internal Server Error
**Solución:**
1. Verifica permisos de archivos (no usar 777)
2. Revisa `error_log` en el servidor
3. Verifica sintaxis PHP (errores de código)

### Imágenes no cargan
**Solución:**
1. Verifica permisos de `/uploads/` (755)
2. Confirma rutas absolutas: `/botikitpedidos/uploads/`
3. Verifica que las imágenes se subieron

### Notificaciones no funcionan
**Solución:**
1. Requiere HTTPS (SSL activo)
2. Verifica que `api/check-notifications.php` exista
3. Confirma rutas en `js/notifications.js`

---

## 📞 Soporte

### Hostinger
- **Panel**: https://hpanel.hostinger.com/
- **Soporte 24/7**: Chat en vivo en el panel
- **Base de conocimientos**: https://support.hostinger.com/

### Documentación
- Todos los archivos en `/docs/`
- RESUMEN_NOTIFICACIONES.md
- VISUALIZACION_GUIA_ENVIO.md

---

## 🎉 ¡Felicidades!

Tu sistema **BotiKit Pedidos** está ahora en producción en Hostinger. 

**URL de acceso:**
- Principal: `https://tudominio.com/botikitpedidos/`
- Admin: `https://tudominio.com/botikitpedidos/admin/`

**Credenciales por defecto:**
- Usuario admin: `admin`
- Contraseña: `(tu contraseña configurada)`

**🚀 ¡A vender!**

---

**Desarrollado con ❤️ para BotiKit Pedidos**
**Versión 1.0 - Octubre 2025**
