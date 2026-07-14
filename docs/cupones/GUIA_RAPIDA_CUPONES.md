# 🚀 Guía Rápida de Implementación - Sistema de Cupones

## 📋 Pasos de Instalación

### 1. Verificar que la base ya fue creada con el esquema oficial ⚡

Opción A - phpMyAdmin:
```
1. Abrir phpMyAdmin
2. Seleccionar base de datos: solumedic_dbshop
3. Confirmar que existen las tablas `cupones` y `cupones_uso`
4. Confirmar que `pedidos` tiene `cupon_codigo` y `cupon_descuento`
```

Opción B - Línea de comandos:
```bash
mysql -u root -D solumedic_dbshop -e "SHOW TABLES LIKE 'cupones';"
```

### 2. Verificar Instalación ✅

Ejecutar en phpMyAdmin:
```sql
-- Verificar tablas
SHOW TABLES LIKE 'cupo%';
-- Debe mostrar: cupones, cupones_uso

-- Verificar campos en pedidos
SHOW COLUMNS FROM pedidos LIKE 'cupon%';
-- Debe mostrar: cupon_codigo, cupon_descuento
```

### 3. Acceder al Sistema 🎟️

```
URL: http://localhost/solumedic-shop/admin/cupones.php
```

---

## ⚡ Crear Primer Cupón (Ejemplo)

1. Ir a `admin/cupones.php`
2. Click "Nuevo Cupón"
3. Llenar:
   - **Código**: PROMO10
   - **Descripción**: Cupón de prueba 10% descuento
   - **Tipo descuento**: Porcentaje
   - **Valor**: 10
   - **Aplicar a**: General
   - **Mínimo compra**: 100
   - **Fecha inicio**: Hoy
   - **Fecha expiración**: Dentro de 1 mes
   - **Usos máximos**: 100
   - ✅ Marcar "Cupón Activo"
4. Click "Guardar Cupón"

---

## 🧪 Probar Cupón

1. Abrir: `crear-pedido.php?telefono=5512345678`
2. Agregar productos al carrito
3. Click "Confirmar Pedido"
4. En modal, campo "¿Tienes un cupón?"
5. Escribir: `PROMO10`
6. Click "Aplicar"
7. ✅ Debe mostrar: "✓ Cupón aplicado correctamente"
8. Ver descuento en resumen
9. Confirmar pedido

---

## 📁 Archivos del Sistema

### Creados
```
✅ database/schema_produccion_unificado.sql       - Esquema BD consolidado
✅ models/Cupon.php                               - Modelo de cupones
✅ admin/cupones.php                              - Admin de cupones
✅ api/cupones.php                                - API validación
✅ SISTEMA_CUPONES.md                             - Documentación
✅ GUIA_RAPIDA_CUPONES.md                         - Esta guía
```

### Modificados
```
✅ models/Pedido.php       - Soporte para cupones
✅ crear-pedido.php        - Campo cupón + validación
```

---

## 🎯 Tipos de Cupones Disponibles

### 1. General
```
Aplica a: TODO (productos y kits)
Ejemplo: BIENVENIDA10 → 10% en toda la tienda
```

### 2. Productos Específicos
```
Aplica a: Productos seleccionados
Ejemplo: PRODUCTO15 → 15% en 3 productos específicos
```

### 3. Tags/Categorías
```
Aplica a: Productos con tags específicos
Ejemplo: NATURAL100 → $100 en productos naturales
```

### 4. Kits
```
Aplica a: Kits seleccionados
Ejemplo: KIT20 → 20% en kits específicos
```

### 5. Representantes
```
Aplica a: Pedidos de representantes específicos
Ejemplo: REP50OFF → $50 para representante REP001
```

---

## 🔍 Validaciones Automáticas

El sistema valida automáticamente:
- ✅ Código existe
- ✅ Estado activo
- ✅ Vigencia (fechas)
- ✅ Límite de usos no alcanzado
- ✅ Mínimo de compra cumplido
- ✅ Aplicabilidad (productos, tags, kits, representante)
- ✅ Descuento no mayor al subtotal

---

## 📊 Características

### Admin (`admin/cupones.php`)
- ➕ Crear cupones
- ✏️ Editar cupones
- 🗑️ Eliminar cupones
- 📊 Ver historial de uso
- 📈 Estadísticas en tiempo real
- 🔍 Filtrar por estado

### Cliente (`crear-pedido.php`)
- 🎟️ Campo para ingresar cupón
- ✅ Validación en tiempo real
- 💰 Descuento visible en resumen
- ❌ Remover cupón aplicado
- 🔄 Recálculo automático de totales

### Registro
- 📝 Cada uso se registra con auditoría completa
- 📊 Contador de usos actualizado automáticamente
- 🔢 Relación con pedido, cliente y representante

---

## ⚠️ Reglas Importantes

1. **No Acumulables**: Solo 1 cupón por pedido
2. **Mayúsculas**: Códigos se convierten automáticamente a mayúsculas
3. **Sin Espacios**: Códigos no pueden tener espacios
4. **Único por Subtotal**: El descuento se calcula sobre productos, no sobre envío
5. **Validación Server**: La validación final es en el servidor, no solo frontend

---

## 🐛 Solución de Problemas

### Error: "Tabla cupones no existe"
```sql
-- Recrear o alinear la base con el esquema oficial
USE solumedic_dbshop;
SOURCE database/schema_produccion_unificado.sql;
```

### Error: "Cupón no se aplica"
- Verificar fechas de vigencia
- Confirmar que está activo
- Revisar mínimo de compra
- Verificar que productos/kits aplican

### Error: "No aparece en admin"
- Verificar ruta: `/admin/cupones.php`
- Confirmar sesión de admin activa
- Revisar permisos de archivos

---

## 📈 Estadísticas Disponibles

En `admin/cupones.php` verás:
```
📊 Total Cupones:    50
🟢 Activos:          12
🔢 Usos Totales:    234
💰 Total Descontado: $12,450.00
```

---

## 💡 Consejos de Uso

### Para Campañas
1. Crear cupones con duración limitada
2. Usar límite de usos para crear urgencia
3. Combinar con mínimo de compra para aumentar ticket

### Para Segmentación
1. Cupones por tags para impulsar categorías
2. Cupones por representante para incentivar ventas
3. Cupones por producto para liquidar inventario

### Para Fidelización
1. Cupones sin límite de usos para clientes frecuentes
2. Cupones progresivos (5%, 10%, 15% según historial)
3. Cupones de bienvenida para nuevos clientes

---

## ✅ Checklist Post-Instalación

- [ ] Migración ejecutada correctamente
- [ ] Tablas cupones y cupones_uso existen
- [ ] Campos cupon_codigo y cupon_descuento en pedidos
- [ ] `/admin/cupones.php` accesible
- [ ] Creado cupón de prueba
- [ ] Probado cupón en crear-pedido.php
- [ ] Verificado que se registra uso
- [ ] Verificado historial funciona
- [ ] Verificado estadísticas funcionan

---

## 🎉 ¡Listo!

El sistema de cupones está completamente funcional. 

**Siguiente paso**: Crear tu primer cupón real en `admin/cupones.php`

---

## 📞 Soporte

Para dudas o problemas:
1. Revisar documentación completa: `SISTEMA_CUPONES.md`
2. Verificar logs de PHP
3. Revisar console del navegador (F12)
4. Verificar configuración de base de datos

---

**Fecha**: 12 Enero 2026  
**Versión**: 1.0  
**Estado**: ✅ Production Ready
