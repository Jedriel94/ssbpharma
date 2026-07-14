# 🏷️ Sistema de Tags - Implementación Completa

## Resumen
Sistema de filtrado de productos por tags para representantes. Los representantes pueden tener acceso a todos los productos (*) o solo a productos con tags específicos.

---

## ✅ Cambios Implementados

### 1. Base de Datos
**Archivo:** `database/migrations/add_tags_system.sql`

Ejecutar en producción:
```sql
ALTER TABLE productos ADD COLUMN tags VARCHAR(500) DEFAULT NULL;
CREATE FULLTEXT INDEX idx_productos_tags ON productos(tags);
ALTER TABLE representantes ADD COLUMN tags_permitidos VARCHAR(500) DEFAULT NULL;
```

**Estado:** ✅ Ejecutado en desarrollo

---

### 2. Archivos Modificados

#### Backend - Models
- ✅ **models/Producto.php**
  - Nuevo: `getAllActivosByTags($tags_permitidos)` - Filtra productos por tags
  - Nuevo: `getAllTags()` - Obtiene todos los tags únicos
  - Actualizado: `create()` - Incluye parámetro `$tags`
  - Actualizado: `update()` - Incluye parámetro `$tags`

- ✅ **models/Representante.php**
  - Actualizado: `create()` - Incluye parámetro `$tags_permitidos`
  - Actualizado: `update()` - Incluye parámetro `$tags_permitidos`

#### Frontend - Catálogo
- ✅ **crear-pedido.php**
  - Lee `tags_permitidos` del representante desde cookie
  - Filtra productos con `getAllActivosByTags()`
  - Muestra indicador visual con tags activos
  - Muestra contador de productos filtrados

#### Admin - Gestión
- ✅ **admin/productos.php**
  - Campo HTML: Input para tags con placeholder
  - Backend: Recibe y guarda tags en create/update
  - Endpoint: `?action=getTags` para obtener sugerencias
  - JavaScript: 
    * `cargarSugerenciasTags()` - Carga badges clickeables
    * `agregarTagSugerido()` - Agrega tag al input
    * Actualiza `editarProducto()` para poblar campo tags

- ✅ **admin/representantes.php**
  - Campo HTML: Input para `tags_permitidos` en crear/editar
  - Backend: Recibe y guarda `tags_permitidos`
  - JavaScript:
    * `cargarSugerenciasTagsRep()` - Carga tags + botón "* (todos)"
    * `agregarTagSugeridoRep()` - Maneja wildcard "*"
    * Actualiza `modalCrear()` y `modalEditar()`

---

## 🎯 Cómo Funciona

### Para Administradores

#### 1. Productos - Asignar Tags
1. Ir a **Admin → Productos**
2. Crear o editar producto
3. En campo "Tags/Etiquetas" escribir: `cosmetico,natural,vegano`
4. Guardar

**Sugerencias:** Click en badges de tags existentes para agregar rápidamente

#### 2. Representantes - Configurar Acceso
1. Ir a **Admin → Representantes**
2. Crear o editar representante
3. En "Tags Permitidos":
   - Escribir `*` para **todos los productos**
   - O escribir tags: `natural,vegano` para productos con esos tags
4. Guardar

**Sugerencias:** Click en "* (todos)" o en tags individuales

### Para Clientes

Cuando un cliente accede con enlace de representante:
1. URL: `https://botikit.shop/r/REP001`
2. Se establece cookie con ID del representante
3. En `crear-pedido.php`:
   - Se lee `tags_permitidos` del representante
   - Se filtran productos automáticamente
   - Se muestra indicador visual: **"Catálogo Personalizado - Representante: María García"**
   - Si hay tags específicos, muestra badges con los tags activos
   - Muestra contador: "Mostrando 15 producto(s)"

---

## 📋 Ejemplos de Uso

### Ejemplo 1: Representante de Cosméticos Naturales
```
Representante: María García (REP001)
Tags Permitidos: natural,vegano,organico

Productos Visibles:
✅ Crema Facial Natural (tags: natural,vegano)
✅ Champú Orgánico (tags: organico,natural)
✅ Jabón Vegano (tags: vegano,natural)
❌ Maquillaje Premium (tags: cosmetico,premium)
```

### Ejemplo 2: Representante General
```
Representante: Juan Pérez (REP002)
Tags Permitidos: *

Productos Visibles:
✅ Todos los productos activos
```

### Ejemplo 3: Sin Representante
```
Cliente directo (sin cookie)
Tags Permitidos: (ninguno)

Productos Visibles:
✅ Todos los productos activos
```

---

## 🚀 Despliegue en Producción

### Checklist de Archivos a Subir

```bash
# 1. Migraciones
database/migrations/add_tags_system.sql

# 2. Modelos
models/Producto.php
models/Representante.php

# 3. Frontend
crear-pedido.php

# 4. Admin
admin/productos.php
admin/representantes.php

# 5. Configuración (ya subidos previamente)
r.php
.htaccess-prod (renombrar a .htaccess)
```

### Pasos de Instalación

1. **Respaldar BD de Producción**
   ```bash
   mysqldump -u usuario -p botikit_prod > backup_antes_tags.sql
   ```

2. **Ejecutar Migración**
   ```sql
   -- En phpMyAdmin o línea de comandos
   SOURCE /path/to/add_tags_system.sql;
   
   -- Verificar
   DESCRIBE productos;
   DESCRIBE representantes;
   ```

3. **Subir Archivos**
   - Via FTP/SFTP subir archivos del checklist
   - Verificar permisos de escritura en uploads/

4. **Verificar .htaccess**
   - Renombrar `.htaccess-prod` → `.htaccess`
   - Confirmar `RewriteBase /`

5. **Pruebas en Producción**
   - Crear producto con tags
   - Crear representante con tags_permitidos
   - Generar QR y probar enlace
   - Verificar filtrado en catálogo

---

## 🧪 Testing

### Test 1: Producto con Tags
1. Admin → Productos → Nuevo
2. Nombre: "Crema Natural Test"
3. Tags: `natural,test`
4. Guardar
5. ✅ Verificar que aparece en listado

### Test 2: Representante con Tags Específicos
1. Admin → Representantes → Nuevo
2. Código: `TEST001`
3. Nombre: "Test Natural"
4. Tags Permitidos: `natural,vegano`
5. Guardar
6. Copiar enlace: `https://botikit.shop/r/TEST001`
7. Abrir en incógnito
8. ✅ Verificar que solo aparecen productos con tags natural o vegano
9. ✅ Verificar indicador visual con badges

### Test 3: Representante con Todos los Productos
1. Admin → Representantes → Editar TEST001
2. Tags Permitidos: `*`
3. Guardar
4. Abrir enlace en incógnito
5. ✅ Verificar que aparecen todos los productos activos

### Test 4: Sin Representante
1. Abrir `https://botikit.shop/crear-pedido.php` directamente
2. ✅ Verificar que aparecen todos los productos
3. ✅ No debe aparecer indicador de catálogo personalizado

---

## 🔍 Troubleshooting

### Problema: Tags no se guardan en producto
**Solución:**
- Verificar que migración se ejecutó: `DESCRIBE productos;`
- Verificar que campo `tags` existe en admin/productos.php
- Revisar consola del navegador por errores JS

### Problema: No filtra productos en crear-pedido
**Solución:**
- Verificar que cookie `botikit_rep` existe (Inspeccionar → Application → Cookies)
- Verificar que representante tiene ID válido
- Confirmar que `tags_permitidos` tiene valor en BD
- Revisar logs PHP en `/logs/` o servidor

### Problema: Sugerencias de tags no aparecen
**Solución:**
- Verificar que endpoint responde: `admin/productos.php?action=getTags`
- Debe retornar JSON: `{"success":true,"tags":["natural","vegano"]}`
- Revisar consola del navegador

### Problema: Representante con * ve catálogo vacío
**Solución:**
- Verificar que método `getAllActivos()` funciona
- Confirmar que hay productos con `activo = 1`
- Revisar condición en crear-pedido.php línea ~107

---

## 📊 Base de Datos

### Estructura de Tags

#### productos.tags
```
NULL               → Sin categorización (visible para todos)
"natural"          → Un tag
"natural,vegano"   → Múltiples tags (separados por coma)
```

#### representantes.tags_permitidos
```
NULL               → Todos los productos (default)
"*"                → Todos los productos (explícito)
"natural"          → Solo productos con tag "natural"
"natural,vegano"   → Productos con tag "natural" O "vegano" (OR logic)
```

### Índices
```sql
-- FULLTEXT para búsquedas futuras
idx_productos_tags ON productos(tags)
```

---

## 📝 Notas Técnicas

### Lógica de Filtrado
```php
// En models/Producto.php
if (empty($tags_permitidos) || $tags_permitidos === '*') {
    // Todos los productos
    return $this->getAllActivos();
}

// Filtrar con OR logic
$tags_array = explode(',', $tags_permitidos);
foreach ($tags_array as $tag) {
    // Producto visible si tiene AL MENOS UN tag coincidente
    FIND_IN_SET(tag, producto.tags)
}
```

### Formato de Tags
- **Sin espacios:** ✅ `natural,vegano,organico`
- **Con espacios:** ❌ `natural, vegano, organico` (se eliminan con trim())
- **Case sensitive:** Depende de collation de MySQL
- **Separador:** Siempre coma (`,`)

### Performance
- FULLTEXT index permite búsquedas rápidas
- `FIND_IN_SET()` optimizado para campos cortos
- Cachear tags en memoria para alta concurrencia

---

## 🎉 Ventajas del Sistema

1. **Flexible:** Wildcard (*) o tags específicos
2. **Escalable:** Fácil agregar nuevos tags
3. **User-Friendly:** Sugerencias clickeables en admin
4. **Visual:** Indicadores claros en catálogo
5. **Transparente:** Cliente ve solo productos relevantes
6. **Retrocompatible:** NULL = todos los productos

---

## 🔮 Mejoras Futuras

1. **Búsqueda por Tags:** Usar FULLTEXT index para búsquedas avanzadas
2. **Gestión de Tags:** Panel admin dedicado para CRUD de tags
3. **Estadísticas:** Tags más usados, productos por tag
4. **Multiidioma:** Tags en español e inglés
5. **Categorías Jerárquicas:** Tags con relaciones padre-hijo
6. **Validación:** Autocompletar con tags existentes

---

## 📞 Soporte

Ante dudas o problemas:
1. Revisar esta documentación
2. Verificar logs de PHP y MySQL
3. Probar en ambiente de desarrollo primero
4. Contactar al desarrollador

---

**Última actualización:** Noviembre 2024  
**Versión:** 1.0  
**Estado:** ✅ Completo y Testeado
