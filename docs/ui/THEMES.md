# 🎨 Guía de Themes - BotiKit Pedidos

Este documento explica los temas visuales disponibles y cómo usarlos.

---

## 🎯 Themes Disponibles

### THEME 1: Terracotta & Sage (Default)
**Paleta de Colores:**

```
Cream (Fondos):
  50:  #FDFBF7  ░░░░░  Muy claro
  100: #F9F5ED  ░░░░   Claro
  200: #F3EAD8  ░░░    Medio claro
  300: #ECDFC3  ░░     Medio
  400: #E6D4AE  ░      Medio oscuro

Sage (Secundario):
  400: #A8B5A0  🌿     Verde claro
  500: #8FA88B  🌿     Verde medio
  600: #6D8B69  🌿     Verde oscuro

Terracotta (Principal):
  400: #E89B7E  🍂     Terracota claro
  500: #E07856  🍂     Terracota medio
  600: #D86F4D  🍂     Terracota oscuro
```

**Uso:**
- **Fondos**: Gradiente cream (#FDFBF7 → #F3EAD8)
- **Botones principales**: Gradiente terracotta (#E07856 → #D86F4D)
- **Botones secundarios**: Gradiente sage (#8FA88B → #6D8B69)
- **Inputs**: Borde terracotta con transparencia

**Ambiente:** Cálido, acogedor, natural, artesanal

---

### THEME 2: Ocean Blue
**Paleta de Colores:**

```
Ocean (Principal):
  300: #FAFDD6  ☀️     Amarillo muy claro
  400: #AED6CF  🌊     Menta claro
  500: #91ADC8  🌊     Azul medio
  600: #647FBC  🌊     Azul oscuro
  700: #5469A3  🌊     Azul muy oscuro

Mint (Secundario):
  400: #AED6CF  🍃     Menta claro
  500: #9AC9BF  🍃     Menta medio
  600: #86BCB0  🍃     Menta oscuro

Sunshine (Acentos):
  300: #FAFDD6  ☀️     Amarillo muy claro
  400: #F5F9B8  ☀️     Amarillo claro
  500: #F0F59A  ☀️     Amarillo medio
```

**Uso:**
- **Fondos**: Gradiente sunshine → mint (#FAFDD6 → #AED6CF)
- **Botones principales**: Gradiente ocean (#91ADC8 → #647FBC)
- **Botones secundarios**: Gradiente mint (#AED6CF → #86BCB0)
- **Inputs**: Borde ocean con transparencia

**Ambiente:** Fresco, sereno, profesional, moderno

---

## 🔧 Cómo Cambiar de Theme

### Método 1: Desde el Menú (Recomendado)
1. Abre el menú lateral (☰)
2. Baja hasta la sección "Tema Visual"
3. Haz clic en el botón del tema que deseas:
   - **🍂 Terracota** - Cálido y acogedor
   - **🌊 Ocean** - Fresco y moderno

El tema se guardará automáticamente en tu navegador.

### Método 2: Código JavaScript
```javascript
// Cambiar a Terracotta
setTheme('terracotta');

// Cambiar a Ocean
setTheme('ocean');
```

### Método 3: LocalStorage Directo
```javascript
localStorage.setItem('botikit-theme', 'ocean');
location.reload();
```

---

## 🎨 Clases CSS Disponibles

### Botones
```html
<!-- Botón principal (terracotta/ocean según theme) -->
<button class="btn-primary">Guardar</button>

<!-- Botón secundario (sage/mint según theme) -->
<button class="btn-secondary">Cancelar</button>

<!-- Botón de peligro (rojo en ambos themes) -->
<button class="btn-danger">Eliminar</button>
```

### Inputs
```html
<input type="text" class="input-field" placeholder="Texto...">
<textarea class="input-field"></textarea>
<select class="input-field">...</select>
```

### Cards
```html
<div class="card rounded-2xl shadow-lg p-6">
    Contenido de la tarjeta
</div>
```

---

## 🎯 Usar Colores Específicos con Tailwind

### Theme Terracotta
```html
<!-- Textos -->
<p class="text-terracotta-500">Texto terracota</p>
<p class="text-sage-600">Texto sage</p>
<p class="text-cream-300">Texto cream</p>

<!-- Fondos -->
<div class="bg-terracotta-100">Fondo terracota claro</div>
<div class="bg-sage-500">Fondo sage</div>
<div class="bg-cream-50">Fondo cream</div>

<!-- Bordes -->
<div class="border-2 border-terracotta-400">Con borde</div>
```

### Theme Ocean
```html
<!-- Textos -->
<p class="text-ocean-600">Texto azul</p>
<p class="text-mint-500">Texto menta</p>
<p class="text-sunshine-400">Texto amarillo</p>

<!-- Fondos -->
<div class="bg-ocean-500">Fondo azul</div>
<div class="bg-mint-400">Fondo menta</div>
<div class="bg-sunshine-300">Fondo amarillo</div>

<!-- Bordes -->
<div class="border-2 border-ocean-600">Con borde</div>
```

---

## 📝 Ejemplos Prácticos

### Tarjeta con Theme Terracotta
```html
<div class="card rounded-2xl shadow-lg p-6">
    <h2 class="text-2xl font-bold text-slate-900 mb-4">
        Título de la Tarjeta
    </h2>
    <p class="text-slate-600 mb-4">
        Descripción del contenido...
    </p>
    <div class="flex gap-3">
        <button class="btn-primary px-6 py-3 rounded-xl">
            Acción Principal
        </button>
        <button class="btn-secondary px-6 py-3 rounded-xl">
            Acción Secundaria
        </button>
    </div>
</div>
```

### Formulario con Theme Ocean
```html
<body class="theme-ocean">
    <form class="card rounded-2xl shadow-lg p-8">
        <h2 class="text-2xl font-bold text-ocean-600 mb-6">
            Registro
        </h2>
        
        <div class="mb-4">
            <label class="block text-sm font-semibold text-slate-700 mb-2">
                Nombre
            </label>
            <input type="text" 
                   class="input-field w-full px-4 py-3 rounded-xl"
                   placeholder="Tu nombre...">
        </div>
        
        <button type="submit" class="btn-primary w-full py-4 rounded-xl">
            Enviar Formulario
        </button>
    </form>
</body>
```

---

## 🔄 Crear un Nuevo Theme

### Paso 1: Agregar Colores en Tailwind Config
Edita `includes/header.php` y agrega tu paleta:

```javascript
tailwind.config = {
    theme: {
        extend: {
            colors: {
                // Tu nuevo theme
                miTheme: {
                    300: '#XXXXXX',
                    400: '#XXXXXX',
                    500: '#XXXXXX',
                    600: '#XXXXXX',
                }
            }
        }
    }
}
```

### Paso 2: Agregar Estilos CSS
En el `<style>` del header:

```css
body.theme-miTheme {
    background: linear-gradient(135deg, #COLOR1 0%, #COLOR2 100%);
}

body.theme-miTheme .btn-primary {
    background: linear-gradient(135deg, #COLOR3 0%, #COLOR4 100%);
}
```

### Paso 3: Agregar Botón en el Menú
```html
<button onclick="setTheme('miTheme')" 
        id="theme-miTheme"
        class="flex-1 px-3 py-2 rounded-lg text-xs font-semibold transition theme-btn"
        style="background: linear-gradient(135deg, #COLOR3 0%, #COLOR4 100%); color: white;">
    🎨 Mi Theme
</button>
```

---

## 💡 Consejos de Diseño

### Cuando usar Terracotta
✅ Tiendas de productos artesanales  
✅ Negocios de comida/restaurantes  
✅ E-commerce de productos naturales  
✅ Diseños cálidos y acogedores  

### Cuando usar Ocean
✅ Aplicaciones corporativas  
✅ Servicios profesionales  
✅ Plataformas tecnológicas  
✅ Diseños modernos y frescos  

---

## 🐛 Troubleshooting

### El theme no cambia
1. Verifica que JavaScript esté habilitado
2. Limpia el cache del navegador (Ctrl + F5)
3. Revisa la consola del navegador por errores

### Los colores no se ven
1. Asegúrate de incluir `includes/header.php` en tu página
2. Verifica que Tailwind CSS esté cargando correctamente
3. Revisa que la clase del body tenga `theme-ocean` si corresponde

### El theme no persiste
1. Verifica que localStorage esté habilitado
2. El theme se guarda por dominio, verifica que no cambies de URL
3. Modo incógnito borra localStorage al cerrar

---

## 📊 Comparación Visual

```
TERRACOTTA THEME           OCEAN THEME
═══════════════           ═══════════
🍂 Cálido                 🌊 Fresco
🏺 Artesanal              💼 Profesional
🌾 Natural                🌐 Moderno
☕ Acogedor               ⚡ Dinámico

Fondo: Cream              Fondo: Sunshine
Principal: Terracotta     Principal: Ocean Blue
Secundario: Sage          Secundario: Mint
```

---

## 🚀 Próximos Themes (Ideas)

1. **Sunset** - Naranjas y morados
2. **Forest** - Verdes profundos
3. **Lavender** - Violetas y rosas
4. **Monochrome** - Grises elegantes
5. **Neon** - Colores vibrantes

---

**¿Quieres agregar un nuevo theme?**  
Edita `includes/header.php` y sigue la estructura de los themes existentes.

**Última actualización:** Octubre 2025
