# 🎟️ Sistema de Cupones - Documentación Completa

## 📋 Resumen
Sistema completo de cupones de descuento con asignación flexible, validación automática y registro de uso.

---

## ✅ Características Implementadas

### 1. Asignación Flexible
Los cupones se pueden aplicar a:
- ✅ **General**: Todos los productos y kits
- ✅ **Productos específicos**: Selección múltiple de productos
- ✅ **Tags/Etiquetas**: Grupos de productos con etiquetas específicas
- ✅ **Kits específicos**: Selección múltiple de kits
- ✅ **Representantes**: Solo válidos para representantes específicos

### 2. Tipos de Descuento
- ✅ **Porcentaje**: Descuento del X% sobre el subtotal
- ✅ **Monto fijo**: Descuento de $X pesos

### 3. Restricciones de Uso
- ✅ **Mínimo de compra**: Monto mínimo requerido para aplicar el cupón
- ✅ **Fecha de inicio**: Cuándo empieza a ser válido
- ✅ **Fecha de expiración**: Cuándo deja de ser válido
- ✅ **Límite de usos**: Número máximo de veces que se puede usar (opcional: ilimitado)
- ✅ **No acumulable**: Solo se puede aplicar un cupón por pedido

### 4. Validación Automática
El sistema valida automáticamente:
- Estado activo del cupón
- Vigencia (fecha actual entre inicio y expiración)
- Límite de usos no alcanzado
- Mínimo de compra cumplido
- Aplicabilidad según tipo (productos, tags, kits, representantes)

---

## 📁 Archivos Creados/Modificados

### Base de Datos
**`database/schema_produccion_unificado.sql`**
- Tabla `cupones`: Almacena todos los cupones
- Tabla `cupones_uso`: Registra cada uso de cupón
- Tabla `pedidos`: Ya incluye `cupon_codigo` y `cupon_descuento`

### Backend
**`models/Cupon.php`**
- `create()`: Crear nuevo cupón
- `update()`: Actualizar cupón existente
- `delete()`: Eliminar cupón
- `validar()`: Validación completa de cupón
- `registrarUso()`: Registrar uso de cupón
- `getHistorialUso()`: Obtener historial de usos
- `getEstadisticas()`: Estadísticas generales

**`models/Pedido.php`**
- Modificado método `create()` para aceptar cupón

### API
**`api/cupones.php`**
- Endpoint `validar`: Valida cupón antes de aplicar

### Admin
**`admin/cupones.php`**
- Interfaz completa de gestión de cupones
- CRUD: Crear, Leer, Actualizar, Eliminar
- Vista de historial de uso por cupón
- Estadísticas: Total cupones, activos, usos, monto descontado

### Frontend
**`crear-pedido.php`** (Modificado)
- Campo de cupón en modal de confirmación
- Validación en tiempo real
- Cálculo automático de descuento
- Envío de datos de cupón al crear pedido
- Línea de descuento en resumen

---

## 🚀 Instalación

### 1. Verificar esquema de base de datos

```sql
USE solumedic_dbshop;

-- Si la base se creó desde database/schema_produccion_unificado.sql,
-- el sistema de cupones ya debe existir.
SHOW TABLES LIKE 'cupones';
SHOW TABLES LIKE 'cupones_uso';
SHOW COLUMNS FROM pedidos LIKE 'cupon%';
```

### 2. Verificar Tablas Creadas

```sql
-- Verificar tabla cupones
DESCRIBE cupones;

-- Verificar tabla cupones_uso
DESCRIBE cupones_uso;

-- Verificar campos agregados a pedidos
SHOW COLUMNS FROM pedidos LIKE 'cupon%';
```

---

## 📖 Uso del Sistema

### Para Administradores

#### 1. Crear Cupón General
1. Ir a **Admin → Cupones** (nuevo link en menú)
2. Click en "Nuevo Cupón"
3. Llenar formulario:
   - **Código**: `BIENVENIDA10` (mayúsculas)
   - **Tipo descuento**: Porcentaje
   - **Valor**: 10
   - **Aplicar a**: General
   - **Mínimo compra**: 500
   - **Fechas**: Inicio y expiración
   - **Usos máximos**: 1000 (o vacío para ilimitado)
4. Marcar "Activo"
5. Click "Guardar Cupón"

#### 2. Crear Cupón para Productos Específicos
1. Nuevo Cupón
2. **Aplicar a**: Productos Específicos
3. Seleccionar productos de la lista con checkboxes
4. Resto igual que anterior

#### 3. Crear Cupón por Tags
1. Nuevo Cupón
2. **Aplicar a**: Grupo de Productos (Tags)
3. Escribir o hacer click en tags sugeridos: `natural,vegano`
4. Resto igual

#### 4. Crear Cupón para Representante
1. Nuevo Cupón
2. **Aplicar a**: Representantes Específicos
3. Seleccionar representantes
4. Resto igual

#### 5. Ver Historial de Uso
1. En listado de cupones
2. Click en icono de historial (reloj) 📊
3. Ver tabla con:
   - Fecha de uso
   - Pedido
   - Cliente
   - Representante
   - Subtotal
   - Descuento aplicado

#### 6. Editar/Eliminar Cupones
- Click en icono de editar ✏️ para modificar
- Click en icono de eliminar 🗑️ (confirmación requerida)

### Para Clientes

#### 1. Aplicar Cupón al Crear Pedido
1. Agregar productos al carrito
2. Click "Confirmar Pedido"
3. En modal de confirmación, ver campo "¿Tienes un cupón?"
4. Escribir código: `BIENVENIDA10`
5. Click "Aplicar"
6. Si es válido:
   - ✓ Ver mensaje de confirmación en verde
   - Ver badge con cupón aplicado
   - Ver descuento en resumen (línea en verde)
   - Ver total actualizado
7. Confirmar pedido

#### 2. Remover Cupón
1. Click en "X" en el badge del cupón aplicado
2. Totales se recalculan automáticamente

---

## 🎯 Ejemplos de Cupones

### Ejemplo 1: Bienvenida 10% (General)
```
Código: BIENVENIDA10
Tipo: Porcentaje
Valor: 10%
Aplicación: General
Mínimo: $500
Usos: 1000
Vigencia: Todo 2026
```
**Uso**: Cliente con carrito de $600 obtiene $60 de descuento

### Ejemplo 2: Productos Naturales $100 (Tags)
```
Código: NATURAL100
Tipo: Monto
Valor: $100
Aplicación: Tags → natural,organico
Mínimo: $300
Usos: 500
Vigencia: Q1 2026
```
**Uso**: Solo aplica si hay productos con tag "natural" u "organico"

### Ejemplo 3: Descuento para Representante (Representantes)
```
Código: REP50OFF
Tipo: Monto
Valor: $50
Aplicación: Representantes → REP001
Mínimo: $0
Usos: Ilimitado
Vigencia: Todo 2026
```
**Uso**: Solo válido para pedidos del representante REP001

### Ejemplo 4: Oferta en Kits (Kits)
```
Código: KIT20
Tipo: Porcentaje
Valor: 20%
Aplicación: Kits → Kit Básico, Kit Premium
Mínimo: $1000
Usos: 200
Vigencia: Enero 2026
```
**Uso**: Solo aplica si hay kits específicos en el carrito

---

## 🔍 Validaciones del Sistema

### Al Aplicar Cupón
1. ✅ Cupón existe en base de datos
2. ✅ Cupón está activo
3. ✅ Fecha actual está entre inicio y expiración
4. ✅ No ha alcanzado límite de usos
5. ✅ Subtotal cumple con mínimo de compra
6. ✅ Aplica según tipo:
   - **General**: Siempre válido
   - **Productos**: Al menos un producto del carrito está en la lista
   - **Tags**: Al menos un producto tiene un tag de la lista
   - **Kits**: Al menos un kit del carrito está en la lista
   - **Representantes**: El pedido es del representante correcto

### Mensajes de Error
- "El cupón no existe"
- "El cupón no está activo"
- "El cupón aún no es válido. Válido desde: DD/MM/YYYY"
- "El cupón ha expirado. Expiró el: DD/MM/YYYY"
- "El cupón ha alcanzado su límite de usos"
- "El monto mínimo de compra para este cupón es $XXX"
- "El cupón no aplica a los productos en tu carrito"
- "El cupón no aplica a las categorías de productos en tu carrito"
- "El cupón no aplica a los kits en tu carrito"
- "Este cupón solo es válido para compras a través de representante"
- "Este cupón no es válido para tu representante"

---

## 📊 Estadísticas en Admin

En la parte superior de `admin/cupones.php` se muestran:
- **Total Cupones**: Número total de cupones en el sistema
- **Activos**: Cupones actualmente válidos (activo=1 y fechas vigentes)
- **Usos Totales**: Número de veces que se han usado cupones
- **Total Descontado**: Suma de todos los descuentos aplicados

---

## 🎨 Estados de Cupones

### En Listado Admin
Los cupones se clasifican automáticamente:
- 🟢 **Activo**: Cupón válido y dentro de vigencia
- ⚪ **Inactivo**: Cupón desactivado manualmente
- 🔴 **Expirado**: Fecha actual > fecha expiración
- 🟡 **Programado**: Fecha actual < fecha inicio
- 🟠 **Agotado**: Usos actuales ≥ usos máximos

---

## 🔒 Seguridad

### Prevención de Abuso
1. ✅ Solo un cupón por pedido
2. ✅ Validación en servidor (no solo frontend)
3. ✅ Verificación de stock y existencia
4. ✅ Registro de uso con auditoría completa
5. ✅ No se puede usar cupón expirado o agotado
6. ✅ Descuento nunca mayor al subtotal

### Auditoría
Cada uso de cupón registra:
- ID del cupón
- ID del pedido
- ID del cliente
- ID del representante (si aplica)
- Monto descontado
- Subtotal del pedido
- Fecha y hora de uso

---

## 💡 Casos de Uso

### Caso 1: Campaña de Lanzamiento
- Crear cupón "LANZAMIENTO25" de 25% general
- Vigencia: 1 mes
- Usos: 500 primeros clientes
- Mínimo: $1000

### Caso 2: Promoción de Productos Lentos
- Identificar productos con baja rotación
- Crear cupón con 15% para esos productos específicos
- Sin mínimo de compra
- Vigencia: 2 semanas

### Caso 3: Incentivo para Representantes
- Crear cupones personalizados por representante
- Monto fijo $100
- Válido todo el año
- Para que ofrezcan a sus clientes

### Caso 4: Descuento en Kits
- Crear cupón 20% solo para kits
- Mínimo $1500
- Incentivar venta de kits completos

### Caso 5: Categorías Específicas
- Cupón "NATURAL50" → $50 en productos naturales
- Usando tags: natural,organico,vegano
- Mínimo $300

---

## 🐛 Troubleshooting

### Problema: Cupón no se aplica
**Solución:**
1. Verificar que código esté en mayúsculas
2. Revisar fechas de vigencia
3. Confirmar que hay stock de productos
4. Verificar mínimo de compra
5. Revisar tipo de aplicación vs. carrito

### Problema: Error al crear cupón
**Solución:**
1. Verificar que migración se ejecutó correctamente:
   ```sql
   SHOW TABLES LIKE 'cupo%';
   SHOW COLUMNS FROM pedidos LIKE 'cupon%';
   ```
2. Verificar permisos de usuario en BD
3. Revisar logs de PHP

### Problema: Descuento incorrecto
**Solución:**
1. Verificar tipo de descuento (% vs $)
2. Confirmar que descuento no excede subtotal
3. Revisar que se está calculando sobre subtotal (sin envío)
4. Verificar lógica de envío gratis

### Problema: Historial no carga
**Solución:**
1. Verificar que tabla `cupones_uso` existe
2. Confirmar relaciones FK están correctas
3. Revisar console del navegador (F12)

---

## 🔮 Mejoras Futuras (Opcionales)

1. **Cupones por Cliente**: Limitar uso por cliente específico
2. **Cupones Únicos**: Generar códigos únicos automáticamente
3. **Notificaciones**: Email cuando cupón está por expirar
4. **Análisis**: Dashboard con gráficas de uso de cupones
5. **Combinación**: Permitir acumular ciertos tipos de cupones
6. **QR Codes**: Generar QR para cupones físicos
7. **API Pública**: Endpoint para validar cupón desde apps externas
8. **Auto-desactivación**: Desactivar automáticamente al alcanzar límite

---

## 📝 Notas Técnicas

### Flujo de Validación
```
1. Cliente ingresa código → JavaScript
2. Se envía a API con carrito → api/cupones.php
3. Se llama Cupon->validar() → models/Cupon.php
4. Se valida estado, fechas, usos
5. Se valida aplicabilidad según tipo
6. Se calcula descuento
7. Se retorna resultado a frontend
8. Frontend muestra mensaje y actualiza totales
9. Al confirmar pedido, se envía cupón
10. Backend crea pedido con cupón
11. Se registra uso en cupones_uso
12. Se incrementa contador de usos
```

### Cálculo de Descuento
```php
// Porcentaje
$descuento = ($subtotal * $valor) / 100;

// Monto fijo
$descuento = $valor;

// Límite
$descuento = min($descuento, $subtotal);
```

### Orden de Cálculo en Pedido
```
1. Subtotal = Suma de productos/kits
2. Descuento cupón = Aplicar cupón
3. Subtotal con descuento = Subtotal - Descuento
4. Costo envío = Según subtotal con descuento
5. Total = Subtotal con descuento + Envío
```

---

## ✅ Checklist de Testing

### Testing Básico
- [ ] Crear cupón general 10%
- [ ] Aplicar cupón en pedido
- [ ] Verificar descuento en total
- [ ] Confirmar pedido
- [ ] Verificar que pedido tiene cupón en BD
- [ ] Verificar que se registró uso en cupones_uso
- [ ] Verificar que contador de usos incrementó

### Testing de Restricciones
- [ ] Intentar usar cupón expirado → Debe rechazar
- [ ] Intentar usar cupón inactivo → Debe rechazar
- [ ] Intentar usar cupón sin mínimo → Debe rechazar
- [ ] Intentar usar cupón agotado → Debe rechazar
- [ ] Intentar usar cupón de producto no en carrito → Debe rechazar

### Testing de Tipos
- [ ] Cupón de productos específicos
- [ ] Cupón de tags
- [ ] Cupón de kits
- [ ] Cupón de representante

### Testing de Admin
- [ ] Crear cupón nuevo
- [ ] Editar cupón existente
- [ ] Eliminar cupón
- [ ] Ver historial de uso
- [ ] Verificar estadísticas

---

## 🎉 Conclusión

El sistema de cupones está completamente implementado y listo para usar. Proporciona:
- ✅ Flexibilidad total en asignación
- ✅ Validación robusta
- ✅ Auditoría completa
- ✅ Interfaz intuitiva
- ✅ Seguridad contra abuso
- ✅ Estadísticas en tiempo real

¡Comienza creando tu primer cupón en `admin/cupones.php`!

---

**Fecha de Implementación**: 12 Enero 2026
**Versión**: 1.0
**Estado**: ✅ Producción Ready
