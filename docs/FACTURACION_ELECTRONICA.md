# 🧾 Facturación Electrónica

## 📋 Descripción General

Sistema de gestión de datos fiscales para facturación electrónica integrado en el proceso de pago. Permite a los clientes solicitar factura y mantener sus datos fiscales guardados para futuros pedidos.

## ✨ Características Implementadas

### 1. **Checkbox "Requiero Factura Electrónica"**
- Ubicación: Página `procesar-pago.php` después de la sección "Datos de Envío"
- Al marcar el checkbox, se despliega un formulario completo con datos fiscales
- Animación suave de entrada (slideDown)

### 2. **Formulario de Datos Fiscales**
Campos incluidos:
- 🆔 **RFC** (13 caracteres, automáticamente en mayúsculas)
- 🏢 **Razón Social** (Nombre completo o razón social)
- 📧 **Email para Factura** (Correo donde se enviará la factura)
- 📮 **Código Postal** (5 dígitos)
- 📋 **Régimen Fiscal** (Dropdown con opciones del SAT)
  - 601 - General de Ley Personas Morales
  - 612 - Personas Físicas con Actividades Empresariales
  - 621 - Incorporación Fiscal
  - 626 - Régimen Simplificado de Confianza
  - Y más...
- 📄 **Uso de CFDI** (Dropdown con claves del SAT)
  - G01 - Adquisición de mercancías
  - G03 - Gastos en general (por defecto)
  - P01 - Por definir
  - D01-D10 - Deducciones personales
  - Y más...

### 3. **Funcionalidades**

#### ✅ Campos Prellenados
- Si el cliente ya tiene datos fiscales guardados, se cargan automáticamente
- Provenientes de la tabla `clientes`

#### ✏️ Edición de Datos
- Todos los campos son editables
- Validación en tiempo real
- Conversión automática de RFC a mayúsculas
- Solo números en código postal

#### 💾 Guardar para Futuros Pedidos
- Botón "Guardar Datos Fiscales para Futuros Pedidos"
- Actualiza la tabla `clientes` con los datos fiscales
- Mensaje de confirmación al guardar exitosamente

#### ⚠️ Notificación de Datos Faltantes
- Si marca "Requiero Factura" pero faltan datos
- Muestra alerta: "⚠️ Faltan datos fiscales. Por favor complétalos o el proveedor te los solicitará."
- **NO bloquea** el proceso de pago
- El proveedor puede solicitar los datos posteriormente

## 🗄️ Estructura de Base de Datos

### Tabla `pedidos`
```sql
-- Campos de facturación
requiere_factura    TINYINT(1)      DEFAULT 0   -- Indica si solicita factura
rfc                 VARCHAR(13)     NULL        -- RFC del cliente
razon_social        VARCHAR(255)    NULL        -- Razón social
email_factura       VARCHAR(255)    NULL        -- Email para factura
codigo_postal       VARCHAR(5)      NULL        -- CP fiscal
uso_cfdi            VARCHAR(10)     NULL        -- Clave de uso CFDI
factura_pdf         VARCHAR(255)    NULL        -- Ruta del PDF (NUEVO)
factura_xml         VARCHAR(255)    NULL        -- Ruta del XML (NUEVO)
```

**Campos eliminados:**
- ❌ `fecha_factura` - Se maneja externamente
- ❌ `folio_factura` - Se maneja externamente

### Tabla `clientes`
```sql
-- Campos fiscales del cliente
rfc                 VARCHAR(13)     NULL        -- RFC
razon_social        VARCHAR(255)    NULL        -- Razón social
email_factura       VARCHAR(255)    NULL        -- Email para facturas
codigo_postal       VARCHAR(5)      NULL        -- CP fiscal
uso_cfdi            VARCHAR(10)     NULL        -- Uso CFDI preferido
regimen_fiscal      VARCHAR(10)     NULL        -- Régimen fiscal (601, 612, etc)
```

## 🔄 Flujo de Trabajo

### Para el Cliente:

1. **En la página de pago (`procesar-pago.php`)**
   - Marca el checkbox "Requiero Factura Electrónica"
   
2. **Se despliega el formulario fiscal**
   - Campos prellenados si ya tiene datos guardados
   - O campos vacíos para completar
   
3. **Completa/Edita los datos**
   - RFC, Razón Social, Email, etc.
   
4. **Guarda para futuros pedidos (opcional)**
   - Click en "Guardar Datos Fiscales"
   - Los datos se actualizan en su perfil
   
5. **Confirma el pedido**
   - Los datos fiscales se guardan en el pedido
   - `requiere_factura = 1`

### Para el Proveedor/Administrador:

1. **Ve los pedidos con factura requerida**
   - En panel de administración
   - Filtro por `requiere_factura = 1`
   
2. **Revisa los datos fiscales del pedido**
   - Todos los campos disponibles en la tabla `pedidos`
   
3. **Genera la factura externamente**
   - Usa su propio sistema de facturación (PAC)
   - Con los datos fiscales del pedido
   
4. **Sube los archivos al sistema** (Próximamente)
   - PDF de la factura → `factura_pdf`
   - XML de la factura → `factura_xml`
   
5. **Cliente recibe notificación** (Próximamente)
   - Email con la factura adjunta

## 📁 Archivos Modificados

### Backend:
1. **`procesar-pago.php`**
   - Nuevo endpoint AJAX: `guardar_datos_fiscales`
   - Sección HTML de facturación
   - Funciones JavaScript
   
2. **`models/Cliente.php`**
   - Método `updateDatos()` actualizado
   - Soporte para nuevos campos fiscales

### Base de Datos:
3. **`database/schema_produccion_unificado.sql`**
   - Esquema consolidado vigente
   - Ya incluye los campos fiscales usados por `pedidos` y `clientes`
   - Sustituye scripts de migración históricos

### Documentación:
4. **`docs/FACTURACION_ELECTRONICA.md`** (este archivo)

## 🎨 Interfaz de Usuario

### Diseño:
- Card con bordes redondeados
- Checkbox destacado con hover effect
- Formulario desplegable con animación suave
- Campos organizados en grid responsivo
- Botón de guardar en color terracota
- Alertas informativas en azul y amarillo

### Validaciones:
- RFC: 13 caracteres máximo, mayúsculas automáticas
- Email: Validación HTML5 tipo email
- Código Postal: Solo 5 dígitos
- Campos requeridos marcados con asterisco rojo

### Feedback Visual:
- ℹ️ Información sobre el proceso
- ⚠️ Alerta de datos faltantes
- ✅ Mensaje de éxito al guardar
- ❌ Mensaje de error si falla

## 🚀 Próximas Funcionalidades

### Fase 1: Upload de Facturas (Administrador)
- [ ] Interfaz para subir PDF y XML
- [ ] Validación de archivos (solo PDF y XML)
- [ ] Almacenamiento en `uploads/facturas/`
- [ ] Vinculación con el pedido

### Fase 2: Visualización para Cliente
- [ ] Sección "Mis Facturas" en el perfil
- [ ] Descarga de PDF
- [ ] Descarga de XML
- [ ] Historial de facturas

### Fase 3: Notificaciones
- [ ] Email automático cuando se sube la factura
- [ ] Push notification en la app
- [ ] Badge en el menú del cliente

### Fase 4: Integración con PAC (Opcional)
- [ ] API para timbrado automático
- [ ] Generación automática de factura
- [ ] Cancelación de facturas
- [ ] Reportes al SAT

## 📊 Catálogos del SAT

### Régimen Fiscal (Principales):
- **601** - General de Ley Personas Morales
- **603** - Personas Morales con Fines no Lucrativos
- **605** - Sueldos y Salarios
- **606** - Arrendamiento
- **612** - Personas Físicas con Actividades Empresariales
- **616** - Sin obligaciones fiscales
- **621** - Incorporación Fiscal
- **626** - Régimen Simplificado de Confianza (ReSiCo)

### Uso de CFDI (Principales):
- **G01** - Adquisición de mercancías
- **G02** - Devoluciones, descuentos o bonificaciones
- **G03** - Gastos en general
- **I01-I08** - Inversiones
- **D01-D10** - Deducciones personales
- **S01** - Sin efectos fiscales
- **P01** - Por definir

## 🔒 Seguridad

- ✅ Validación de teléfono y pedido_id
- ✅ Solo el cliente dueño del pedido puede editar
- ✅ Sanitización de inputs (htmlspecialchars)
- ✅ Prepared statements en SQL
- ✅ Validación de RFC en mayúsculas
- ✅ Límites de caracteres en campos

## 📝 Notas Importantes

1. **El sistema NO genera facturas**
   - Solo almacena datos fiscales
   - El proveedor usa su propio sistema de facturación

2. **Los datos fiscales NO son obligatorios**
   - El cliente puede continuar sin completarlos
   - El proveedor los solicitará si es necesario

3. **Los archivos PDF/XML se suben manualmente**
   - Interfaz administrativa próximamente
   - Por ahora se gestionan fuera del sistema

4. **Campos compatibles con SAT 2025**
   - Catálogos actualizados
   - Régimen Simplificado de Confianza incluido

## 🐛 Troubleshooting

### Error: "No se pueden guardar datos fiscales"
- **Causa**: Campos de la tabla `clientes` no existen
- **Solución**: recrear o alinear la base usando `database/schema_produccion_unificado.sql`

### Error: "Column 'factura_pdf' doesn't exist"
- **Causa**: Tabla `pedidos` no actualizada
- **Solución**: recrear o alinear la base usando `database/schema_produccion_unificado.sql`

### Los datos no se prellenan
- **Causa**: Cliente no tiene datos fiscales guardados
- **Solución**: Normal, debe completarlos por primera vez

### El formulario no se muestra
- **Causa**: JavaScript no cargado o error en consola
- **Solución**: Revisar consola del navegador (F12)

## 📞 Soporte

Para dudas o problemas con la facturación electrónica:
1. Revisar este documento
2. Verificar que el script SQL se ejecutó correctamente
3. Revisar logs de JavaScript en la consola del navegador
4. Verificar que los campos existen en ambas tablas

---

**Última actualización:** 17 de Octubre, 2025  
**Versión:** 1.0  
**Autor:** Sistema BotikitPedidos
