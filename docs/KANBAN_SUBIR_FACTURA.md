# 🧾 Subir Factura desde Kanban - Guía Rápida

## 📋 Descripción
Funcionalidad para que el administrador suba las facturas electrónicas (PDF y XML) desde el tablero Kanban.

## ✨ Características

### 1. **Visibilidad en el Kanban**
- Badge "🧾 Requiere Factura" visible en tarjetas desde **Confirmado** en adelante
- Estados donde aparece: `confirmado`, `en_ruta`, `entregado`
- Muestra estado de la factura:
  - ⏳ Factura pendiente (no subida)
  - ✅ Enlaces a PDF y XML (cuando están subidos)

### 2. **Botón "Subir Factura"**
- Aparece en estados: `confirmado`, `en_ruta`, `entregado`
- Solo visible si:
  - El pedido tiene `requiere_factura = 1`
  - NO tiene archivos subidos (`factura_pdf` y `factura_xml` vacíos)
- Se oculta automáticamente cuando se suben los archivos

### 3. **Modal de Subida**
- Permite subir PDF, XML, o ambos
- Validaciones:
  - PDF: Solo archivos .pdf (máx. 5MB)
  - XML: Solo archivos .xml (máx. 5MB)
  - Al menos 1 archivo requerido
- Mensajes claros de éxito/error

## 🎯 Flujo de Trabajo

```
1. Cliente marca "Requiero Factura" en procesar-pago.php ✅
   └─> requiere_factura = 1 en pedido

2. Administrador ve badge "🧾 Requiere Factura" en Kanban 👀
   └─> Desde columna "Confirmado"

3. Administrador genera factura en su sistema externo (PAC) 🖥️
   └─> Obtiene PDF y XML

4. Administrador hace clic en "🧾 Subir Factura" 📤
   └─> Abre modal

5. Administrador sube archivos 📎
   └─> Selecciona PDF y/o XML
   └─> Click en "Subir Factura"

6. Sistema guarda archivos ✅
   └─> uploads/facturas/factura_pdf_[pedido_id]_[timestamp].pdf
   └─> uploads/facturas/factura_xml_[pedido_id]_[timestamp].xml
   └─> Actualiza campos en base de datos

7. Badge cambia a mostrar enlaces 🔗
   └─> ✅ Ver PDF
   └─> ✅ Descargar XML

8. Botón "Subir Factura" desaparece ✨
```

## 📊 Estructura de Archivos

### Base de Datos
```sql
-- Tabla: pedidos
requiere_factura  TINYINT(1)   -- 1 si requiere, 0 si no
factura_pdf       VARCHAR(255) -- Nombre del archivo PDF
factura_xml       VARCHAR(255) -- Nombre del archivo XML
```

### Carpeta de Upload
```
uploads/
  └─ facturas/
     ├─ factura_pdf_13_1729123456.pdf
     ├─ factura_xml_13_1729123456.xml
     ├─ factura_pdf_14_1729123789.pdf
     └─ ...
```

## 🎨 Interfaz Visual

### Badge en Tarjeta
```
┌──────────────────────────────────┐
│ Pedido #0013                     │
│ 17/10 14:30              $500.00 │
├──────────────────────────────────┤
│ 👤 Cliente:                      │
│ 5530601753                       │
├──────────────────────────────────┤
│ 📦 3 productos                   │
├──────────────────────────────────┤
│ ┌─────────────────────────────┐  │
│ │ 🧾 Requiere Factura         │  │
│ │ ⏳ Factura pendiente        │  │ <- Sin archivos
│ └─────────────────────────────┘  │
├──────────────────────────────────┤
│ [🧾 Subir Factura]               │ <- Botón visible
│ [📤 Subir Guía → En Ruta]        │
└──────────────────────────────────┘
```

### Badge con Archivos Subidos
```
┌──────────────────────────────────┐
│ ┌─────────────────────────────┐  │
│ │ 🧾 Requiere Factura         │  │
│ │ ✅ Ver PDF                  │  │ <- Enlaces activos
│ │ ✅ Descargar XML            │  │
│ └─────────────────────────────┘  │
├──────────────────────────────────┤
│ [📤 Subir Guía → En Ruta]        │ <- Sin botón de factura
└──────────────────────────────────┘
```

## 🚀 Cómo Usar

### Para el Administrador:

1. **Identifica pedidos con factura**
   - Busca badge "🧾 Requiere Factura" de color amarillo
   - Desde la columna "Confirmado" en adelante

2. **Genera la factura en tu sistema**
   - Usa los datos fiscales del pedido
   - Tu sistema PAC (Proveedor Autorizado de Certificación)
   - Obtén PDF y XML timbrados

3. **Sube los archivos**
   - Click en "🧾 Subir Factura"
   - Selecciona PDF (opcional)
   - Selecciona XML (opcional)
   - Al menos 1 archivo requerido
   - Click en "Subir Factura"

4. **Verifica la subida**
   - Badge cambia a mostrar enlaces
   - Click en "✅ Ver PDF" para verificar
   - Click en "✅ Descargar XML" para obtener archivo

## ⚠️ Validaciones

### Backend
- ✅ Pedido debe tener `requiere_factura = 1`
- ✅ Al menos 1 archivo (PDF o XML) requerido
- ✅ PDF: Solo tipo `application/pdf`
- ✅ XML: Tipos `application/xml`, `text/xml`, `application/octet-stream`
- ✅ Tamaño máximo: 5MB por archivo
- ✅ Nombres únicos con timestamp

### Frontend
- ✅ Validación de archivo seleccionado antes de enviar
- ✅ Botón deshabilitado durante subida
- ✅ Mensajes de éxito/error claros
- ✅ Recarga automática después de subida exitosa

## 📝 Archivos Modificados

### Backend:
1. **admin/kanban.php**
   - Nuevo case `subir_factura` en AJAX handler
   - Validación de archivos
   - Creación de carpeta `uploads/facturas/`
   - Update de campos `factura_pdf` y `factura_xml`

### Frontend:
2. **admin/kanban-card.php**
   - Badge "Requiere Factura" con estado
   - Enlaces a PDF y XML
   - Botón "Subir Factura" condicional

3. **admin/kanban.php** (HTML)
   - Modal `modalFactura`
   - Formulario con 2 inputs de archivo
   - Funciones JavaScript:
     - `abrirModalFactura(pedidoId)`
     - `cerrarModalFactura()`
     - `subirFactura(event)`

## 🔒 Seguridad

- ✅ Validación de tipos MIME
- ✅ Límite de tamaño de archivo
- ✅ Nombres de archivo únicos (evita sobrescritura)
- ✅ Carpeta separada para facturas
- ✅ Verificación de `requiere_factura` en backend
- ✅ Solo administradores pueden subir

## 🐛 Troubleshooting

### "Este pedido no requiere factura"
- **Causa**: `requiere_factura = 0` en el pedido
- **Solución**: El cliente no marcó el checkbox en procesar-pago.php

### "Debe subir al menos un archivo"
- **Causa**: No se seleccionó ningún archivo
- **Solución**: Selecciona PDF, XML, o ambos

### "Error al guardar el PDF/XML"
- **Causa**: Permisos de carpeta o espacio en disco
- **Solución**: Verificar permisos de `uploads/facturas/` (755)

### No aparece el botón "Subir Factura"
- **Causas posibles**:
  1. El pedido no requiere factura
  2. Ya se subieron los archivos
  3. Estado del pedido es "pendiente" o "por_verificar"
- **Solución**: Verificar las condiciones en kanban-card.php

## 📞 Próximas Mejoras

- [ ] Notificar al cliente por email cuando se sube factura
- [ ] Push notification en el chat
- [ ] Permitir re-subir factura (reemplazar archivos)
- [ ] Visualizador de PDF en modal
- [ ] Validar estructura del XML (CFDI 4.0)
- [ ] Descargar ambos archivos en ZIP
- [ ] Historial de facturas del cliente

---

**Última actualización:** 17 de Octubre, 2025  
**Versión:** 1.0  
**Implementado por:** Sistema BotikitPedidos
