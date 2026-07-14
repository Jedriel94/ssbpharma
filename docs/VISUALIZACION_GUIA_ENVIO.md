# 📦 Visualización de Guía de Envío para Clientes

## 📋 Resumen de Implementación

Se ha implementado la **visualización completa de la guía de envío** para que los clientes puedan ver el comprobante que sube el proveedor cuando el pedido está "En Ruta".

---

## 🎯 Funcionalidad Implementada

### Para el Cliente en `seguimiento.php`

Cuando el pedido está en estado **"en_ruta"** o **"entregado"** y el proveedor ha subido una guía de envío, el cliente puede:

1. ✅ **Ver imágenes de la guía** (JPG, PNG, GIF, WEBP)
   - Miniatura embebida en la página
   - Click para ver en tamaño completo (modal)
   - Descarga opcional

2. ✅ **Ver PDFs de la guía**
   - Visor embebido en la página
   - Botón para abrir en nueva pestaña
   - Vista previa directa

3. ✅ **Descargar otros formatos**
   - Botón de descarga directo
   - Compatible con cualquier tipo de archivo

---

## 📂 Archivos Modificados/Creados

### ✨ Nuevos Archivos

1. **`css/guia-envio.css`**
   - Estilos para modal de imagen
   - Animaciones suaves
   - Efectos hover mejorados
   - Soporte para Safari (backdrop-filter)

### 🔧 Archivos Modificados

1. **`seguimiento.php`**
   - Nueva sección para pedidos **confirmado**
   - Nueva sección para pedidos **en_ruta** con visualizador
   - Nueva sección mejorada para pedidos **entregado**
   - Modal de imagen en tamaño completo
   - Funciones JavaScript para modal

---

## 🎨 Diseño Visual

### Estado: Confirmado ✅
```
┌─────────────────────────────────────────┐
│ ✅ Pago Confirmado                      │
│ Tu pago ha sido verificado              │
│ exitosamente. Estamos preparando        │
│ tu pedido para el envío.                │
└─────────────────────────────────────────┘
```

### Estado: En Ruta 🚚 (CON GUÍA)

#### Opción 1: Imagen
```
┌─────────────────────────────────────────┐
│ 🚚 Tu Pedido Está En Camino             │
│ Hemos enviado tu pedido.                │
│ Aquí está la guía de envío:             │
│                                         │
│ ┌───────────────────────────────────┐   │
│ │                                   │   │
│ │     [IMAGEN DE LA GUÍA]           │   │
│ │     (Click para ampliar)          │   │
│ │                                   │   │
│ └───────────────────────────────────┘   │
│                                         │
│ 👆 Haz clic en la imagen para verla     │
│    en tamaño completo                   │
└─────────────────────────────────────────┘
```

#### Opción 2: PDF
```
┌─────────────────────────────────────────┐
│ 🚚 Tu Pedido Está En Camino             │
│ Hemos enviado tu pedido.                │
│ Aquí está la guía de envío:             │
│                                         │
│ ┌─────────────────────────────────┐     │
│ │ 📄 Guía de Envío (PDF)  [Abrir] │     │
│ └─────────────────────────────────┘     │
│ ┌─────────────────────────────────┐     │
│ │                                 │     │
│ │   [VISOR PDF EMBEBIDO]          │     │
│ │                                 │     │
│ └─────────────────────────────────┘     │
└─────────────────────────────────────────┘
```

### Estado: Entregado 📦
```
┌─────────────────────────────────────────┐
│ 📦 ¡Pedido Entregado!                   │
│ Tu pedido ha sido entregado             │
│ exitosamente. ¡Gracias por tu compra!   │
│                                         │
│ ───────────────────────────────────     │
│ 📄 Información de Envío:                │
│ 🖼️ Ver guía de envío →                  │
└─────────────────────────────────────────┘
```

---

## 💻 Código Implementado

### Detección de Tipo de Archivo
```php
<?php 
$extension = strtolower(pathinfo($pedido['comprobante_envio'], PATHINFO_EXTENSION));
$rutaComprobante = '/botikitpedidos/uploads/comprobantes_envio/' . htmlspecialchars($pedido['comprobante_envio']);

if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    // Mostrar imagen con modal
} elseif ($extension === 'pdf') {
    // Mostrar visor de PDF
} else {
    // Mostrar botón de descarga
}
?>
```

### Modal de Imagen Completa
```javascript
function abrirImagenCompleta(url) {
    const modal = document.getElementById('modalImagen');
    const imagen = document.getElementById('imagenCompleta');
    imagen.src = url;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function cerrarModalImagen() {
    const modal = document.getElementById('modalImagen');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Cerrar con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalImagen();
    }
});
```

---

## 🎬 Flujo de Usuario

### 1. Cliente en Seguimiento
```
Cliente visita seguimiento.php
         ↓
  Ve estado "En Ruta"
         ↓
  Visualiza guía de envío
         ↓
  [IMAGEN] → Click → Modal ampliado
  [PDF] → Scroll → Visor embebido
```

### 2. Interacción con Imagen
```
Click en imagen
       ↓
Modal a pantalla completa
       ↓
Fondo oscuro + blur
       ↓
Imagen centrada y grande
       ↓
Click fuera o ESC → Cierra modal
```

---

## 📱 Responsive Design

### Desktop (>768px)
- Imagen: Máximo ancho completo
- PDF: Altura 384px (h-96)
- Modal: Pantalla completa con padding

### Mobile (<768px)
- Imagen: Ajustada al contenedor
- PDF: Altura 300px
- Modal: Pantalla completa sin padding

---

## ✨ Características Especiales

### 1. **Modal con Animaciones**
- Fade in suave (0.3s)
- Zoom in de imagen
- Backdrop blur para efecto profesional
- Compatible con Safari (`-webkit-backdrop-filter`)

### 2. **Visor de PDF Embebido**
- Vista previa directa en la página
- Botón de apertura en nueva pestaña
- Scroll nativo del PDF
- Bordes redondeados con tema del sistema

### 3. **Efectos Hover**
- Imagen: Shadow elevado al pasar mouse
- Botones: Escala y color más intenso
- Transiciones suaves (0.3s)

### 4. **Accesibilidad**
- Click en fondo para cerrar
- Tecla ESC para cerrar
- Textos descriptivos
- Alt text en imágenes

---

## 🔒 Seguridad

### Validación de Archivos
- ✅ Extensión validada con `pathinfo()`
- ✅ Nombres sanitizados con `htmlspecialchars()`
- ✅ Rutas absolutas para prevenir path traversal
- ✅ Archivos en carpeta segura (`uploads/comprobantes_envio/`)

### Control de Acceso
- ✅ Solo clientes autenticados ven sus pedidos
- ✅ Verificación de teléfono en sesión
- ✅ Contraseña opcional para clientes

---

## 🎯 Estados que Muestran Guía

| Estado | Muestra Guía | Formato |
|--------|-------------|---------|
| `pendiente` | ❌ No | - |
| `por_verificar` | ❌ No | - |
| `confirmado` | ❌ No | - |
| `en_ruta` | ✅ **Sí** | Completo (imagen/PDF embebido) |
| `entregado` | ✅ **Sí** | Link de descarga |
| `cancelado` | ❌ No | - |

---

## 📊 Formatos Soportados

| Formato | Visualización | Interacción |
|---------|--------------|-------------|
| **JPG/JPEG** | ✅ Imagen embebida | Click → Modal ampliado |
| **PNG** | ✅ Imagen embebida | Click → Modal ampliado |
| **GIF** | ✅ Imagen embebida | Click → Modal ampliado |
| **WEBP** | ✅ Imagen embebida | Click → Modal ampliado |
| **PDF** | ✅ Visor embebido | Scroll + botón abrir |
| **Otros** | ⬇️ Botón descarga | Click → Descarga directa |

---

## 🚀 Mejoras Futuras

- [ ] **Zoom y Pan**: Permitir zoom con gestos en modal
- [ ] **Galería**: Si hay múltiples guías, mostrar carrusel
- [ ] **Impresión**: Botón para imprimir guía directamente
- [ ] **Compartir**: Enviar guía por WhatsApp/Email
- [ ] **Tracking**: Integración con APIs de mensajería (FedEx, DHL, etc.)
- [ ] **Notificaciones**: Alertar cuando se sube la guía
- [ ] **Historial**: Ver todas las guías de envíos anteriores

---

## 🎉 Beneficios para el Cliente

1. **Transparencia Total** 🔍
   - Ve exactamente la misma guía que el proveedor tiene
   - Puede verificar número de rastreo
   - Confirma que el envío está en proceso

2. **Comodidad** 📱
   - No necesita pedir la guía por chat
   - Acceso 24/7 desde su dispositivo
   - Puede guardar/compartir la guía

3. **Confianza** ✅
   - Confirma que el pedido fue enviado
   - Puede rastrear su paquete
   - Reduce incertidumbre y ansiedad

4. **Profesionalismo** 💼
   - Experiencia de e-commerce moderna
   - Sistema organizado y transparente
   - Mejora la imagen de la tienda

---

## 📝 Notas Técnicas

### Estructura del Modal
```html
<div id="modalImagen" class="fixed inset-0 bg-black bg-opacity-90 z-50 hidden">
    <button onclick="cerrarModalImagen()">✕</button>
    <img id="imagenCompleta" src="" alt="Guía de envío">
</div>
```

### CSS Clave
```css
#modalImagen {
    -webkit-backdrop-filter: blur(8px);  /* Safari */
    backdrop-filter: blur(8px);          /* Otros */
    animation: fadeIn 0.3s ease;
}
```

### JavaScript Mínimo
- 2 funciones: abrir y cerrar
- 1 event listener: tecla Escape
- Total: ~15 líneas de código

---

## ✅ Checklist de Implementación

- [x] Crear `css/guia-envio.css` con animaciones
- [x] Agregar sección "Confirmado" en seguimiento
- [x] Agregar sección "En Ruta" con visualizador
- [x] Actualizar sección "Entregado" con link
- [x] Crear modal de imagen completa
- [x] Agregar funciones JavaScript
- [x] Soporte para imágenes (JPG, PNG, GIF, WEBP)
- [x] Soporte para PDF embebido
- [x] Soporte para otros formatos (descarga)
- [x] Responsive design (mobile + desktop)
- [x] Accesibilidad (ESC + click fuera)
- [x] Compatibilidad Safari (webkit-backdrop-filter)

---

## 🎊 Conclusión

Los clientes ahora pueden **visualizar la guía de envío** directamente desde la página de seguimiento, proporcionando:

- ✅ **Transparencia total** sobre el estado del envío
- ✅ **Experiencia moderna** con modales y visualizadores
- ✅ **Comodidad** sin necesidad de pedir información por chat
- ✅ **Confianza** al ver la documentación real del envío

Todo integrado perfectamente con el flujo del Kanban y el sistema de notificaciones push! 🚀

---

**Desarrollado con ❤️ para BotiKit Pedidos**
