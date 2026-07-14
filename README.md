# 🛍️ BotiKit Pedidos

Sistema de gestión de pedidos moderno y minimalista, optimizado para dispositivos móviles.

## 📋 Características

- ✅ Gestión completa de **Productos** con rangos de precios
- ✅ Gestión de **Clientes** basada en teléfono
- ✅ Diseño minimalista con paleta cream/terracotta/sage
- ✅ 100% responsive y optimizado para móviles
- ✅ Menú colapsable con navegación fluida
- ✅ Sistema de precios por rangos de cantidad

## 🎨 Paleta de Colores

- **Cream**: `#FDFBF7`, `#F3EAD8` - Fondos
- **Terracotta**: `#E07856`, `#D86F4D` - Botones primarios
- **Sage**: `#8FA88B`, `#6D8B69` - Botones secundarios
- **Slate**: Textos

## 🗄️ Base de Datos

Base de datos: `solumedic_dbshop` (entorno local actual)

### Tablas:
- **productos**: Catálogo de productos con existencias
- **rangos_precios**: Precios según cantidad comprada
- **clientes**: Identificados por teléfono
- **pedidos**: Órdenes de compra
- **detalle_pedidos**: Items de cada pedido

## 🚀 Instalación

### Requisitos:
- PHP 7.4+
- MySQL 5.7+
- Laragon o similar (XAMPP, WAMP)

### Pasos:

1. **Clonar/Copiar el proyecto** en tu carpeta de Laragon:
   ```
   c:\laragon\www\proceso
   ```

2. **Crear la base de datos**:
   - Opción A: Abre phpMyAdmin (`http://localhost/phpmyadmin`)
   - Importa el archivo: `database/schema_produccion_unificado.sql`
   
   - Opción B: Desde terminal:
   ```bash
   mysql -u root -e "SOURCE c:/laragon/www/proceso/database/schema_produccion_unificado.sql"
   ```

3. **Configurar base de datos** (si es necesario):
   Edita `config/database.php` y ajusta las credenciales:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'solumedic_dbshop');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

4. **Acceder a la aplicación**:
   ```
   http://localhost/proceso
   ```

## 📱 Módulos Disponibles

### ✅ Completados:

1. **Inicio** (`index.php`)
   - Input de teléfono
   - Botón "Crear Pedido"
   - Botón "Seguimiento de Pedidos"

2. **Productos** (`admin/productos.php`)
   - ➕ Crear productos
   - ✏️ Editar productos
   - 🗑️ Eliminar productos
   - 💰 Gestionar rangos de precios

3. **Clientes** (`admin/clientes.php`)
   - ➕ Crear clientes
   - ✏️ Editar clientes
   - 🗑️ Eliminar clientes
   - 🔍 Buscar clientes
   - 📊 Estadísticas

### 🚧 Por Implementar:

- [ ] Crear Pedido
- [ ] Seguimiento de Pedidos
- [ ] Reportes
- [ ] Sistema de autenticación/roles

## 📂 Estructura del Proyecto

```
proceso/
├── admin/                  # Panel administrativo
├── api/                    # Endpoints AJAX/API
├── assets/ css/ js/        # Recursos frontend
├── config/                 # Configuración de entorno y base de datos
├── database/
│   └── schema_produccion_unificado.sql # Esquema oficial de instalación
├── docs/                   # Documentación activa centralizada
├── includes/               # Componentes compartidos
├── models/                 # Modelos de negocio
├── scripts/                # Utilidades y archivos legacy fuera del flujo web principal
├── uploads/                # Archivos subidos
├── worker.php              # Worker de ligas de pago
└── README.md               # Resumen general del proyecto
```

## 📚 Documentación

- Índice general: `docs/README.md`
- Despliegue: `docs/deployment/`
- Roles: `docs/roles/`
- Cupones: `docs/cupones/`
- Worker: `docs/worker/`
- Históricos y fixes viejos: `docs/archive/root-historico/`

## 💡 Sistema de Rangos de Precios

Los productos pueden tener múltiples rangos de precio según la cantidad:

**Ejemplo:**
- 1-10 unidades: $100 c/u
- 11-50 unidades: $90 c/u
- 51+ unidades: $80 c/u

El sistema calcula automáticamente el precio según la cantidad solicitada.

## 📸 Sistema de Imágenes

### Especificaciones
- **Requisito**: Imagen cuadrada (ancho = alto)
- **Ejemplos válidos**: 500x500, 800x800, 1000x1000, 1200x1200, etc.
- **Formatos aceptados**: JPG, PNG, GIF, WEBP
- **Tamaño máximo**: 5MB
- **Visualización**: Contenedor de 250x250 píxeles

### Validación Automática
1. ✅ Validación de formato
2. ✅ Validación de tamaño (máx. 5MB)
3. ✅ **Validación cuadrada** (rechaza si ancho ≠ alto)
4. ✅ Guardado sin modificar (mantiene calidad original)
5. ✅ Nombres únicos automáticos

### Importante
- ⚠️ La imagen **DEBE ser cuadrada** (mismo ancho y alto)
- 📷 Recomendado: 800x800px o 1000x1000px
- 🖼️ Se guarda en tamaño original
- 📐 Solo el **contenedor de visualización** es 250x250px

### Ubicación
- `uploads/productos/` - Imágenes de productos
- Nombres: `prod_xxxxxxxxxxxxx.ext`

## �🔧 Tecnologías

- **Frontend**: HTML5, Tailwind CSS
- **Backend**: PHP 7.4+ (POO)
- **Base de datos**: MySQL con PDO
- **Diseño**: Mobile-first, responsive

## 👨‍💻 Desarrollo

Para continuar el desarrollo, los siguientes pasos sugeridos son:

1. Implementar `crear-pedido.php`
2. Implementar `seguimiento.php`
3. Agregar sistema de autenticación
4. Implementar módulo de reportes
5. Agregar notificaciones/alertas
6. Sistema de estados de pedido

## 📝 Notas

- Los clientes se crean automáticamente al ingresar un teléfono nuevo
- El teléfono es el identificador único de cada cliente
- Los rangos de precio se validan automáticamente al crear pedidos
- Diseño optimizado para uso en tablets/móviles

---

**Desarrollado con ❤️ para BotiKit**