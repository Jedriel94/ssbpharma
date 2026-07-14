# 🎨 Guía de Temas de Color - BotiKit Pedidos

## 📋 Paletas Disponibles

### 1. **Terracotta & Sage** (Tema Actual - Por Defecto)
```
Cream:       #FDFBF7, #F9F5ED, #F3EAD8, #ECDFC3, #E6D4AE
Sage:        #A8B5A0, #8FA88B, #6D8B69
Terracotta:  #E89B7E, #E07856, #D86F4D
```

**Características:**
- ✨ Cálido y acogedor
- 🍂 Colores tierra naturales
- 👍 Ideal para productos artesanales/orgánicos

---

### 2. **Blue Modern** (Nueva Paleta Personalizada)
```
Blanco:      #FFFFFF
Azul Claro:  #8FABD4
Azul Medio:  #4A70A9
Azul Oscuro: #161A30
```

**Características:**
- 💼 Profesional y moderno
- 🌊 Fresco y confiable
- 👔 Ideal para negocios corporativos

---

## 🔧 Cómo Cambiar de Tema

### Opción 1: En el archivo `includes/header.php` (Línea ~238)

**Para usar Terracotta (por defecto):**
```html
<body class="antialiased">
```

**Para usar Blue Modern:**
```html
<body class="antialiased theme-blue">
```

---

## 🎨 Uso de Colores en Tailwind

### Tema Terracotta:
```html
<!-- Botones -->
<button class="bg-terracotta-500 hover:bg-terracotta-600">Botón Principal</button>
<button class="bg-sage-500 hover:bg-sage-600">Botón Secundario</button>

<!-- Fondos -->
<div class="bg-cream-50">Fondo Claro</div>
<div class="bg-cream-200">Fondo Medio</div>

<!-- Textos -->
<p class="text-terracotta-600">Texto Destacado</p>
<p class="text-sage-500">Texto Secundario</p>

<!-- Bordes -->
<div class="border-terracotta-400">Con Borde</div>
```

### Tema Blue Modern:
```html
<!-- Botones -->
<button class="bg-blue-500 hover:bg-blue-700">Botón Principal</button>
<button class="bg-blue-100 hover:bg-blue-500">Botón Secundario</button>

<!-- Fondos -->
<div class="bg-blue-50">Fondo Blanco</div>
<div class="bg-blue-100">Fondo Azul Claro</div>

<!-- Textos -->
<p class="text-blue-500">Texto Destacado Medio</p>
<p class="text-blue-700">Texto Oscuro</p>

<!-- Bordes -->
<div class="border-blue-500">Con Borde Azul</div>
```

---

## 💾 Paleta de Respaldo (Terracotta Original)

La paleta original de Terracotta está guardada en `includes/header.php` como comentario:

```javascript
/* terracotta_original: {
    400: '#E89B7E',
    500: '#E07856',
    600: '#D86F4D',
}, */
```

**Para restaurarla:** Simplemente descomenta estas líneas y elimina la nueva.

---

## 🚀 Cambios Rápidos

### Probar Blue Modern (Sin afectar el código):

1. Abre tu navegador (Chrome/Firefox)
2. Presiona `F12` para abrir DevTools
3. En la consola escribe:
   ```javascript
   document.body.classList.add('theme-blue');
   ```
4. Para volver a Terracotta:
   ```javascript
   document.body.classList.remove('theme-blue');
   ```

---

## 📝 Notas Importantes

- ✅ Los cambios en `header.php` afectan **toda la aplicación**
- ✅ Puedes mezclar colores de ambas paletas si lo necesitas
- ✅ El tema Blue Modern mantiene la compatibilidad con todos los componentes existentes
- ⚠️ Regenera los QR codes de representantes después de cambiar tema (para que tengan colores consistentes)

---

## 🎯 Recomendaciones

**Usa Terracotta si:**
- Vendes productos artesanales, orgánicos o naturales
- Quieres un ambiente cálido y acogedor
- Tu marca es femenina o familiar

**Usa Blue Modern si:**
- Vendes productos tecnológicos o profesionales
- Quieres proyectar confianza y seriedad
- Tu marca es corporativa o formal

---

Última actualización: 7 de Noviembre, 2025
