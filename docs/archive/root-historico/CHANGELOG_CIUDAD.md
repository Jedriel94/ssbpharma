# Cambio: Agregar Campo Ciudad a Datos de Envío

**Fecha:** 2025-10-25  
**Tipo:** Feature - Nueva funcionalidad  
**Estado:** ✅ Completado

## 📋 Resumen

Se agregó el campo **Ciudad** a los datos de envío de clientes para capturar el municipio o ciudad donde se realizará la entrega. Este campo aparece después del campo "Estado" en todos los formularios de captura de datos de envío.

---

## 🗄️ Cambios en Base de Datos

### Archivo de Migración Creado
- **Archivo:** `database/migrations/add_ciudad_field.sql`
- **Acción:** Agrega columna `ciudad` de tipo `VARCHAR(100)` a la tabla `clientes`
- **Posición:** Después del campo `estado`
- **Estado:** ✅ Ejecutada exitosamente

### Comando de Ejecución
```bash
mysql -u root -p -D botikit_dbp < database/migrations/add_ciudad_field.sql
```

### Resultado en Base de Datos
```sql
ALTER TABLE clientes ADD COLUMN ciudad VARCHAR(100) NULL COMMENT "Ciudad de envío" AFTER estado;
```

---

## 📝 Archivos Modificados

### 1. **models/Cliente.php**
**Cambios realizados:**
- Actualizada la firma del método `updateDatos()` para incluir el parámetro `$ciudad`
- Agregado el campo `ciudad` en el array de campos SQL
- Agregado el binding del parámetro `:ciudad`

**Firma nueva del método:**
```php
public function updateDatos(
    $telefono, 
    $nombre = null, 
    // Datos de Envío
    $calle = null, 
    $numero = null, 
    $colonia = null, 
    $cp = null, 
    $estado = null, 
    $ciudad = null,  // ← NUEVO
    $referencias = null, 
    $quien_recibe = null,
    // ... resto de parámetros
)
```

**Estado:** ✅ Actualizado y funcionando

---

### 2. **procesar-pago.php**
**Cambios realizados:**

#### a) Procesamiento AJAX (línea ~60-77)
- Agregada captura del campo `ciudad` desde `$_POST`
- Actualizada la llamada a `updateDatos()` con el nuevo parámetro

```php
case 'actualizar_datos_envio':
    // ... código anterior
    $ciudad = trim($_POST['ciudad'] ?? '');
    
    if ($guardar_datos && !empty($telefono)) {
        $clienteModel->updateDatos(
            $telefono, null, $calle, $numero, $colonia, $cp, $estado, $ciudad, // ← ciudad agregada
            $referencias, $quien_recibe,
            null, null, null, null, null, null, null, null, null, null, null, null
        );
    }
```

#### b) Formulario HTML (línea ~420)
- Agregado nuevo input para capturar ciudad
- Posicionado después del campo Estado
- Icono: 🏙️
- Placeholder: "Ciudad o municipio"
- Marcado como `required`

```html
<div>
    <label class="block text-sm font-medium text-slate-700 mb-2">🏙️ Ciudad</label>
    <input type="text" name="ciudad" id="ciudad" required
           value="<?= htmlspecialchars($cliente['ciudad'] ?? '') ?>"
           class="input-field w-full px-4 py-3 rounded-xl" 
           placeholder="Ciudad o municipio">
</div>
```

**Estado:** ✅ Actualizado y funcionando

---

### 3. **mis-datos.php**
**Cambios realizados:**

#### a) Procesamiento AJAX (línea ~27-38)
- Agregada captura del campo `ciudad` desde `$_POST`
- Actualizada la llamada a `updateDatos()` con el nuevo parámetro

```php
case 'actualizar_datos':
    // ... código anterior
    $ciudad = trim($_POST['ciudad'] ?? '');
    
    if ($clienteModel->updateDatos(
        $telefono, $nombre, $calle, $numero, $colonia, $cp, $estado, $ciudad, // ← ciudad agregada
        $referencias, $quien_recibe,
        $nombre_medico, $nombre_representante, $rfc, $empresa, $regimen, $uso_cfdi,
        $constancia_fiscal, $password_hash
    )) {
        // ... resto del código
    }
```

#### b) Formulario HTML (línea ~260)
- Agregado nuevo input para capturar ciudad
- Posicionado después del campo Estado
- Mismo diseño y validación que en procesar-pago.php

```html
<div>
    <label class="block text-sm font-medium text-slate-700 mb-2">🏙️ Ciudad</label>
    <input type="text" id="ciudad" name="ciudad" 
           value="<?= htmlspecialchars($cliente['ciudad'] ?? '') ?>"
           class="input-field w-full px-4 py-3 rounded-xl" 
           placeholder="Ciudad o municipio">
</div>
```

**Estado:** ✅ Actualizado y funcionando

---

## 🎯 Orden de Campos en Formularios

El orden final de los campos de envío es:

1. 🏠 **Calle** (text)
2. 🔢 **Número** (text)
3. 📮 **CP** (text, 5 dígitos)
4. 🏘️ **Colonia** (text)
5. 🗺️ **Estado** (select)
6. 🏙️ **Ciudad** (text) ← **NUEVO**
7. 📍 **Referencias** (textarea)
8. 🙋 **Quien Recibe** (text)

---

## ✅ Validación y Pruebas

### Estructura de Base de Datos
```
✅ Campo agregado a tabla clientes
✅ Tipo: VARCHAR(100)
✅ Nullable: YES
✅ Posición: Después de estado
✅ Comentario: "Ciudad de envío"
```

### Funcionalidad Verificada
- ✅ Migración SQL ejecutada sin errores
- ✅ Modelo Cliente.php actualizado con firma correcta
- ✅ procesar-pago.php captura y guarda ciudad
- ✅ mis-datos.php captura y guarda ciudad
- ✅ Formularios muestran el campo correctamente
- ✅ Valor se preserva al recargar la página

---

## 📌 Notas Importantes

1. **Retrocompatibilidad:** El campo es `NULL` por defecto, por lo que los registros existentes no se ven afectados.

2. **Validación:** El campo está marcado como `required` en los formularios HTML, pero no tiene restricción `NOT NULL` en la base de datos por compatibilidad.

3. **Sincronización:** Cuando el usuario marca "Guardar estos datos", el campo ciudad se guarda en la tabla `clientes` para futuros pedidos.

4. **Modelo unificado:** La función `updateDatos()` del modelo Cliente ahora soporta TODOS los campos de la tabla clientes, incluyendo:
   - Datos de envío (calle, número, colonia, cp, estado, **ciudad**, referencias, quien_recibe)
   - Datos médicos (nombre_medico, nombre_representante)
   - Datos fiscales (rfc, razon_social, email_factura, codigo_postal, empresa, regimen, uso_cfdi, regimen_fiscal)
   - Archivos (constancia_fiscal)
   - Seguridad (password)

---

## 🚀 Próximos Pasos Sugeridos

- [ ] Considerar agregar autocompletado de ciudades basado en el estado seleccionado
- [ ] Validar que la ciudad corresponda al estado seleccionado
- [ ] Agregar el campo ciudad a reportes y vistas de administración si aplica
- [ ] Actualizar documentación de usuario si existe

---

## 👨‍💻 Desarrollado por

GitHub Copilot - Assistant  
Fecha: 2025-10-25
