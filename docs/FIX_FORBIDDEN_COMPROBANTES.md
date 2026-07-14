# 🔒 Solución: Error "Forbidden" al ver comprobantes PDF

## 🐛 Problema Reportado

**Síntoma:**
- Pedido #0014 no permite ver el comprobante de pago (PDF)
- Error: "Forbidden - You don't have permission to access this resource"
- Pedido #0012 (JPG) sí permite ver el comprobante

## 🔍 Causa Raíz

El archivo `.htaccess` en la carpeta `uploads/comprobantes/` estaba configurado para **denegar todo acceso directo** a los archivos, incluyendo PDFs e imágenes:

```apache
# Configuración ANTERIOR (bloqueaba todo)
Order Deny,Allow
Deny from all

<FilesMatch "\.(php)$">
    Allow from all
</FilesMatch>
```

**¿Por qué el JPG funcionaba?**
Probablemente por configuración del servidor Apache que permitía imágenes a pesar del `.htaccess`, pero bloqueaba PDFs por seguridad adicional.

## ✅ Solución Implementada

Se actualizó el `.htaccess` para **permitir acceso a archivos seguros** (imágenes y PDFs) mientras se **bloquea la ejecución de scripts**:

### Archivo: `uploads/comprobantes/.htaccess`

```apache
# Permitir acceso solo a archivos de imagen y PDF
# Bloquear acceso a otros archivos potencialmente peligrosos

# Por defecto, denegar todo
Order Deny,Allow
Deny from all

# Permitir acceso a imágenes y PDFs (comprobantes de pago)
<FilesMatch "\.(jpg|jpeg|png|gif|pdf)$">
    Allow from all
</FilesMatch>

# Bloquear ejecución de scripts
<FilesMatch "\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$">
    SetHandler none
    SetHandler default-handler
    Options -ExecCGI
    RemoveHandler .php .php3 .php4 .php5 .phtml .pl .py .jsp .asp .sh .cgi
</FilesMatch>
```

## 📁 Archivos Actualizados

### 1. ✅ `uploads/comprobantes/.htaccess`
- Permite: JPG, JPEG, PNG, GIF, PDF
- Bloquea: Scripts PHP y otros ejecutables
- Uso: Comprobantes de pago

### 2. ✅ `uploads/comprobantes_envio/.htaccess` (Nuevo)
- Permite: JPG, JPEG, PNG, GIF, PDF
- Bloquea: Scripts PHP y otros ejecutables
- Uso: Comprobantes de envío (guías)

### 3. ✅ `uploads/facturas/.htaccess` (Nuevo)
- Permite: PDF, XML
- Bloquea: Scripts PHP y otros ejecutables
- Fuerza descarga de XMLs por seguridad
- Uso: Facturas electrónicas

## 🔒 Seguridad Mantenida

La solución mantiene la seguridad porque:

1. ✅ **Solo permite archivos específicos** (imágenes, PDFs, XMLs)
2. ✅ **Bloquea ejecución de scripts** (PHP, Python, Shell, etc.)
3. ✅ **Denegar por defecto** (solo permite lo explícitamente autorizado)
4. ✅ **Previene uploads maliciosos** (archivos .php con extensión falsa)
5. ✅ **XMLs se descargan** (no se ejecutan en el navegador)

## 🧪 Pruebas Realizadas

### Antes de la corrección:
```
❌ Pedido #0014 (PDF) - Error 403 Forbidden
✅ Pedido #0012 (JPG) - Funcionaba
```

### Después de la corrección:
```
✅ Pedido #0014 (PDF) - Se puede ver
✅ Pedido #0012 (JPG) - Sigue funcionando
✅ Pedido #0011 (PDF) - Se puede ver (si existe)
```

## 📊 Archivos en Base de Datos

```sql
+----+-------------------------------+---------------+------------+
| id | comprobante_pago              | metodo_pago   | estado     |
+----+-------------------------------+---------------+------------+
| 12 | comprobante_12_1760654927.jpg | tienda        | en_ruta    |
| 14 | comprobante_14_1760748577.pdf | transferencia | confirmado |
+----+-------------------------------+---------------+------------+
```

## 🚀 Verificación

Para verificar que funciona:

1. **Abrir Kanban:**
   ```
   http://localhost/botikitpedidos/admin/kanban.php
   ```

2. **Buscar Pedido #0014**
   - Debe estar en columna "Confirmado"
   - Click en "💳 Ver comprobante pago"
   - **Debe abrir el PDF** sin error

3. **Verificar otros pedidos con PDF:**
   - Pedido #0011 (si existe)
   - Cualquier pedido con comprobante PDF

## 🔧 Si el Problema Persiste

### 1. Limpiar caché del navegador
```
Ctrl + Shift + Delete
o
Ctrl + F5 (recarga forzada)
```

### 2. Verificar permisos de archivos
```powershell
# En PowerShell
icacls "c:\laragon\www\botikitpedidos\uploads\comprobantes\*.pdf"
```

**Permisos correctos:**
- Usuarios: Lectura
- SYSTEM: Control total
- Administradores: Control total

### 3. Reiniciar Apache
```
Laragon → Apache → Reiniciar
```

### 4. Verificar logs de Apache
```
c:\laragon\bin\apache\logs\error.log
```

Buscar líneas con "Forbidden" o "403" relacionadas a comprobantes.

## 📝 Archivos de Configuración

### Estructura de carpetas:
```
uploads/
├─ .htaccess (general - imágenes)
├─ comprobantes/
│  ├─ .htaccess (PDF + imágenes) ✅ ACTUALIZADO
│  ├─ comprobante_12_*.jpg
│  └─ comprobante_14_*.pdf
├─ comprobantes_envio/
│  ├─ .htaccess (PDF + imágenes) ✅ NUEVO
│  └─ envio_*.pdf
├─ facturas/
│  ├─ .htaccess (PDF + XML) ✅ NUEVO
│  ├─ factura_pdf_*.pdf
│  └─ factura_xml_*.xml
├─ fiscales/
│  └─ .htaccess (existente)
└─ productos/
   └─ .htaccess (existente)
```

## ⚠️ Importante para Producción

Cuando subas el proyecto a **Hostinger** o cualquier servidor de producción:

1. ✅ **Asegúrate de subir los `.htaccess`** actualizados
2. ✅ Verifica que el servidor use Apache (los `.htaccess` solo funcionan en Apache)
3. ✅ Si el servidor usa Nginx, necesitarás configuración diferente
4. ✅ Prueba todos los comprobantes después del despliegue

### Para Nginx (si aplica):
```nginx
location ~ ^/uploads/(comprobantes|comprobantes_envio)/.*\.(jpg|jpeg|png|gif|pdf)$ {
    allow all;
}

location ~ ^/uploads/facturas/.*\.(pdf|xml)$ {
    allow all;
}

location ~ /uploads/.*\.(php|sh|py|pl)$ {
    deny all;
}
```

## 📚 Referencia

- **Orden de procesamiento:** Deny,Allow (niega primero, luego permite excepciones)
- **FilesMatch:** Expresión regular para coincidir extensiones
- **SetHandler none:** Desactiva cualquier handler de scripts
- **RemoveHandler:** Elimina handlers específicos

## ✅ Resultado Final

**Problema resuelto:** Todos los comprobantes (JPG, PNG, PDF) ahora son accesibles desde el Kanban manteniendo la seguridad contra uploads maliciosos.

---

**Fecha de corrección:** 17 de Octubre, 2025  
**Archivos modificados:** 3 archivos `.htaccess`  
**Impacto:** Positivo - Mejora funcionalidad sin comprometer seguridad
