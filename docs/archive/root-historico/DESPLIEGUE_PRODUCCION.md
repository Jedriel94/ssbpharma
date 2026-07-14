# 🚀 Checklist de Despliegue a Producción

## 📦 Paquete de Archivos Modificados

### ✅ Archivos a Subir (Via FTP/SFTP)

```
📁 RAÍZ
├── crear-pedido.php                    ← Filtrado por tags
├── r.php                                ← Fix URLs producción + cookies HTTPS
└── .htaccess                            ← Rewrite rules (renombrar .htaccess-prod)

📁 admin/
├── productos.php                        ← Gestión tags productos
└── representantes.php                   ← Gestión tags_permitidos

📁 models/
├── Producto.php                         ← getAllActivosByTags(), getAllTags()
└── Representante.php                    ← create/update con tags_permitidos

📁 database/migrations/
└── add_tags_system.sql                  ← Migración BD (ejecutar manualmente)

📁 docs/ (nuevo)
├── IMPLEMENTACION_TAGS.md               ← Documentación completa
└── DESPLIEGUE_PRODUCCION.md             ← Este archivo
```

---

## 🗄️ Base de Datos

### 1. Respaldar Base de Datos Actual

```bash
# En servidor de producción
mysqldump -u usuario_bd -p nombre_bd > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Ejecutar Migración

**Via phpMyAdmin:**
1. Ir a phpMyAdmin
2. Seleccionar base de datos de producción
3. Ir a "SQL"
4. Copiar y ejecutar:

```sql
-- Agregar tags a productos
ALTER TABLE productos 
ADD COLUMN tags VARCHAR(500) DEFAULT NULL 
COMMENT 'Tags separados por comas: cosmetico,natural,vegano';

-- Índice para búsquedas (opcional pero recomendado)
CREATE FULLTEXT INDEX idx_productos_tags ON productos(tags);

-- Agregar tags_permitidos a representantes
ALTER TABLE representantes 
ADD COLUMN tags_permitidos VARCHAR(500) DEFAULT NULL 
COMMENT 'Tags permitidos (* = todos, o lista separada por comas)';
```

**Via SSH/Terminal:**
```bash
mysql -u usuario_bd -p nombre_bd < database/migrations/add_tags_system.sql
```

### 3. Verificar Migración

```sql
-- Verificar estructura de productos
DESCRIBE productos;
-- Debe aparecer: tags | varchar(500) | YES | | NULL

-- Verificar estructura de representantes  
DESCRIBE representantes;
-- Debe aparecer: tags_permitidos | varchar(500) | YES | | NULL

-- Verificar índice
SHOW INDEX FROM productos WHERE Key_name = 'idx_productos_tags';
```

---

## 📂 Subir Archivos

### Via FTP (FileZilla, WinSCP, etc.)

```
Conectar a: ftp.botikit.shop (o tu servidor)
Usuario: tu_usuario
Puerto: 21 (FTP) o 22 (SFTP)

Subir archivos manteniendo estructura:
/public_html/
  ├── crear-pedido.php
  ├── r.php
  ├── admin/
  │   ├── productos.php
  │   └── representantes.php
  └── models/
      ├── Producto.php
      └── Representante.php
```

### ⚠️ Importante: .htaccess

```bash
# Renombrar archivo en servidor
mv .htaccess .htaccess.backup_old
mv .htaccess-prod .htaccess

# O subir .htaccess-prod y renombrarlo manualmente
```

**Verificar contenido de .htaccess:**
```apache
RewriteEngine On
RewriteBase /        ← DEBE SER / en producción (no /botikitpedidos/)

# Representantes
RewriteRule ^r/([A-Za-z0-9]+)$ r.php?c=$1 [L,QSA]
RewriteRule ^r/?$ index.php [L]
```

---

## 🧪 Testing Post-Deployment

### Test 1: URLs de Representantes ✅
```bash
# Probar que no da 404
https://botikit.shop/r/REP001

# Debe:
✅ Redirigir a index.php con cookie establecida
✅ No mostrar "Page Not Found"
✅ URL debe ser: https://botikit.shop/index.php?ref=1
```

### Test 2: Crear Producto con Tags ✅
```
1. Login admin: https://botikit.shop/admin/
2. Ir a Productos
3. Crear nuevo producto:
   - Nombre: "Test Tags Producción"
   - Precio: 100
   - Existencia: 10
   - Tags: natural,test
4. Guardar

✅ Debe guardar sin errores
✅ Debe aparecer en listado
✅ Al editar, campo tags debe mostrar: natural,test
```

### Test 3: Crear Representante con Tags ✅
```
1. Ir a Representantes
2. Crear nuevo:
   - Código: TEST001
   - Nombre: "Test Producción"
   - Tags Permitidos: natural,vegano
3. Guardar
4. Copiar enlace (ícono 🔗)

✅ Debe copiar: https://botikit.shop/r/TEST001
```

### Test 4: Filtrado Funcional ✅
```
1. Abrir navegador en modo incógnito
2. Visitar: https://botikit.shop/r/TEST001
3. Ir a crear pedido

✅ Debe mostrar banner: "Catálogo Personalizado - Representante: Test Producción"
✅ Debe mostrar badges: [natural] [vegano]
✅ Debe mostrar solo productos con tags natural o vegano
✅ Debe mostrar contador: "Mostrando X producto(s)"
```

### Test 5: Wildcard (*) ✅
```
1. Admin → Representantes → Editar TEST001
2. Tags Permitidos: *
3. Guardar
4. Abrir enlace en incógnito

✅ Debe mostrar TODOS los productos activos
✅ Banner debe decir: "Tiene acceso a todo el catálogo"
```

### Test 6: Sin Representante ✅
```
1. Abrir incógnito (sin cookies)
2. Visitar: https://botikit.shop/crear-pedido.php

✅ Debe mostrar todos los productos
✅ NO debe mostrar banner de catálogo personalizado
```

---

## 🐛 Troubleshooting

### Error: "Page Not Found" en /r/REP001

**Causa:** .htaccess no está configurado correctamente

**Solución:**
1. Verificar que .htaccess existe en raíz del sitio
2. Verificar `RewriteBase /` (no /botikitpedidos/)
3. Verificar que mod_rewrite está activo:
   ```bash
   # En SSH
   apache2ctl -M | grep rewrite
   ```
4. Verificar permisos del archivo .htaccess (644)

---

### Error: Tags no se guardan

**Causa:** Migración no ejecutada o fallida

**Solución:**
```sql
-- Verificar campos existen
DESCRIBE productos;
DESCRIBE representantes;

-- Si no existen, ejecutar manualmente:
ALTER TABLE productos ADD COLUMN tags VARCHAR(500);
ALTER TABLE representantes ADD COLUMN tags_permitidos VARCHAR(500);
```

---

### Error: No filtra productos

**Causa:** Cookie no se establece o modelo no filtra

**Solución:**
1. **Verificar cookie:**
   - F12 → Application → Cookies
   - Debe existir: `botikit_rep` con valor numérico
   - Domain: `.botikit.shop`
   - Secure: Yes (en HTTPS)
   - HttpOnly: No

2. **Verificar representante existe:**
   ```sql
   SELECT * FROM representantes WHERE codigo = 'REP001';
   ```

3. **Verificar tags_permitidos tiene valor:**
   ```sql
   SELECT id, codigo, nombre, tags_permitidos 
   FROM representantes 
   WHERE codigo = 'REP001';
   ```

4. **Probar query directa:**
   ```sql
   SELECT * FROM productos 
   WHERE activo = 1 
   AND FIND_IN_SET('natural', tags);
   ```

---

### Error: Sugerencias de tags no aparecen

**Causa:** Endpoint getTags no responde

**Solución:**
1. Probar endpoint directo:
   ```
   https://botikit.shop/admin/productos.php?action=getTags
   ```
   Debe retornar JSON:
   ```json
   {"success":true,"tags":["natural","vegano","cosmetico"]}
   ```

2. Verificar consola del navegador (F12)
   - Debe hacer fetch a getTags
   - No debe mostrar errores 404 o 500

3. Verificar que existen productos con tags:
   ```sql
   SELECT tags FROM productos WHERE tags IS NOT NULL AND tags != '';
   ```

---

### Error: URL redirect malformado

**Causa:** Variable $_SERVER['SCRIPT_NAME'] en r.php

**Solución:**
Ya corregido en r.php con rutas absolutas:
```php
// Línea 20 de r.php
header('Location: /index.php?ref=' . $id);

// NO usar:
// $base = dirname($_SERVER['SCRIPT_NAME']);
```

---

## 📋 Rollback Plan

Si algo sale mal, rollback inmediato:

### 1. Restaurar Base de Datos
```bash
mysql -u usuario -p nombre_bd < backup_FECHA.sql
```

### 2. Restaurar Archivos
```bash
# Si guardaste backup de archivos antiguos
cp /backups/crear-pedido.php.old /public_html/crear-pedido.php
cp /backups/productos.php.old /public_html/admin/productos.php
# etc...
```

### 3. Restaurar .htaccess
```bash
mv .htaccess .htaccess.new
mv .htaccess.backup_old .htaccess
```

---

## ✅ Checklist Final

Antes de dar por terminado el despliegue:

- [ ] Backup de BD creado y descargado
- [ ] Migración SQL ejecutada exitosamente
- [ ] Campos `tags` y `tags_permitidos` verificados con DESCRIBE
- [ ] Archivos subidos a producción
- [ ] .htaccess configurado con RewriteBase /
- [ ] Test 1: URL /r/REP001 funciona (no 404)
- [ ] Test 2: Crear producto con tags guarda correctamente
- [ ] Test 3: Crear representante con tags_permitidos funciona
- [ ] Test 4: Filtrado de catálogo funciona con tags específicos
- [ ] Test 5: Wildcard (*) muestra todos los productos
- [ ] Test 6: Sin representante muestra todos los productos
- [ ] Sugerencias de tags aparecen en admin
- [ ] Cookies HTTPS funcionan correctamente
- [ ] No hay errores en consola del navegador
- [ ] No hay errores PHP en logs del servidor

---

## 📞 Contacto Post-Deployment

**Si todo funciona:**
✅ Marcar este checklist como completado
✅ Documentar fecha y hora del despliegue
✅ Informar a usuarios admin sobre nuevas funcionalidades

**Si hay problemas:**
❌ Ejecutar Rollback Plan
❌ Documentar error específico
❌ Revisar logs: `/var/log/apache2/error.log` o `/logs/php_errors.log`
❌ Contactar soporte técnico si es necesario

---

## 📚 Documentación Relacionada

- **IMPLEMENTACION_TAGS.md** - Documentación técnica completa
- **README.md** - Documentación general del proyecto
- **database/migrations/** - Historial de migraciones

---

**Fecha de creación:** Noviembre 2024  
**Responsable:** Desarrollo BotiKit  
**Versión:** 1.0  
**Estado:** ✅ Listo para producción
