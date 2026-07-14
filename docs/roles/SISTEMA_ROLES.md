# Sistema de Roles y Usuarios

Sistema jerárquico de roles para usuarios internos (staff/empleados) de Solumedic.

## 📋 Estructura de Roles

### Jerarquía Organizacional

```
┌─────────────────────────────────────┐
│         Administrador (Nivel 0)     │
│         - Acceso Total CRUD         │
└─────────────────────────────────────┘
                  │
┌─────────────────────────────────────┐
│      Director General (Nivel 1)     │
│         - Solo Lectura              │
└─────────────────────────────────────┘
                  │
┌─────────────────────────────────────┐
│  Director Unidad Negocio (Nivel 2) │
│         - Solo Lectura              │
└─────────────────────────────────────┘
                  │
┌─────────────────────────────────────┐
│         Gerente (Nivel 3)           │
│         - Solo Lectura              │
└─────────────────────────────────────┘
                  │
┌─────────────────────────────────────┐
│      Representante (Nivel 4)        │
│         - Solo Lectura              │
└─────────────────────────────────────┘
```

## 🔐 Roles y Permisos

### 1. Administrador (admin)
- **Nivel Jerárquico:** 0
- **Permisos:**
  - ✅ Crear (todos los registros)
  - ✅ Leer (todos los registros)
  - ✅ Actualizar (todos los registros)
  - ✅ Eliminar (todos los registros)
  - ✅ Administrar usuarios
  - ✅ Administrar roles
  - ✅ Ver reportes completos
  - ✅ Acceso total al sistema

### 2. Director General (director_general)
- **Nivel Jerárquico:** 1
- **Reporta a:** Nadie
- **Permisos:**
  - ❌ Crear
  - ✅ Leer (toda la organización)
  - ❌ Actualizar
  - ❌ Eliminar
  - ✅ Ver reportes completos
  - ✅ Ver subordinados

### 3. Director de Unidad de Negocio (director_unidad)
- **Nivel Jerárquico:** 2
- **Reporta a:** Director General
- **Permisos:**
  - ❌ Crear
  - ✅ Leer (su unidad)
  - ❌ Actualizar
  - ❌ Eliminar
  - ✅ Ver reportes de unidad
  - ✅ Ver subordinados

### 4. Gerente (gerente)
- **Nivel Jerárquico:** 3
- **Reporta a:** Director de Unidad de Negocio
- **Permisos:**
  - ❌ Crear
  - ✅ Leer (su gerencia)
  - ❌ Actualizar
  - ❌ Eliminar
  - ✅ Ver reportes de gerencia
  - ✅ Ver subordinados

### 5. Representante (representante)
- **Nivel Jerárquico:** 4
- **Reporta a:** Gerente
- **Permisos:**
  - ❌ Crear
  - ✅ Leer (solo sus datos)
  - ❌ Actualizar
  - ❌ Eliminar
  - ✅ Ver propios datos
  - ✅ Ver propios clientes
  - ✅ Ver propios pedidos

### 6. Cliente (No es un usuario del sistema)
- **Tipo:** Usuario de la tienda
- **Permisos actuales:** Se mantienen sin cambios
  - Comprar productos
  - Ver sus pedidos
  - Actualizar sus datos
  - etc.

## 📊 Tablas de Base de Datos

### `roles`
Almacena los roles del sistema con su jerarquía.

```sql
- id (PK)
- nombre
- codigo (UNIQUE)
- nivel_jerarquico
- descripcion
- permisos (JSON)
- created_at
- updated_at
```

### `usuarios_sistema`
Usuarios internos (staff/empleados) que acceden al sistema administrativo.

```sql
- id (PK)
- username (UNIQUE)
- password (hashed)
- nombre
- email (UNIQUE)
- telefono
- rol_id (FK -> roles)
- superior_id (FK -> usuarios_sistema) -- Jefe directo
- representante_id (FK -> representantes)
- activo
- ultimo_acceso
- created_at
- updated_at
```

### `sesiones_sistema`
Control de sesiones de usuarios del sistema.

```sql
- id (PK)
- usuario_sistema_id (FK)
- token (UNIQUE)
- ip_address
- user_agent
- expires_at
- created_at
```

### `logs_actividad_sistema`
Auditoría de acciones de usuarios del sistema.

```sql
- id (PK)
- usuario_sistema_id (FK)
- accion
- modulo
- entidad_tipo
- entidad_id
- datos_anteriores (JSON)
- datos_nuevos (JSON)
- ip_address
- user_agent
- created_at
```

## 🚀 Instalación

### 1. Verificar estructura de roles

```bash
# Consultar la base activa
mysql -u root -D solumedic_dbshop -e "SHOW TABLES LIKE 'roles';"
```

### 2. Credenciales por Defecto

**Usuario Administrador:**
- **Username:** `admin`
- **Password:** `password` (debe cambiarse inmediatamente)
- **Email:** admin@solumedic.com

⚠️ **IMPORTANTE:** Cambiar la contraseña después del primer login.

## 💻 Uso del Sistema

### Autenticación

```php
<?php
session_start();
require_once 'config/database.php';
require_once 'includes/AuthMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$auth = new AuthMiddleware($db);

// Requerir autenticación
$usuario_actual = $auth->requireAuth();

// Requerir rol de admin
$auth->requireAdmin();

// Verificar permiso específico
if ($auth->tienePermiso('crear')) {
    // Puede crear registros
}
```

### Gestión de Usuarios

```php
<?php
require_once 'models/UsuarioSistema.php';

$usuarioSistema = new UsuarioSistema($db);

// Crear usuario
$nuevo_id = $usuarioSistema->crear([
    'username' => 'jperez',
    'password' => 'password123',
    'nombre' => 'Juan Pérez',
    'email' => 'jperez@solumedic.com',
    'telefono' => '555-1234',
    'rol_id' => 3, // Gerente
    'superior_id' => 5 // ID del Director
]);

// Obtener subordinados
$subordinados = $usuarioSistema->getSubordinados($usuario_id);

// Obtener jerarquía completa
$jerarquia = $usuarioSistema->getJerarquia($usuario_id);
```

### Verificación de Permisos

```php
<?php
require_once 'models/Role.php';

$roleModel = new Role($db);

// Verificar permiso
if ($roleModel->tienePermiso($rol_id, 'actualizar')) {
    // Puede actualizar
}

// Obtener todos los permisos
$permisos = $roleModel->getPermisos($rol_id);
```

### Log de Actividad

```php
<?php
$auth->logActividad(
    'crear',                    // Acción
    'productos',                // Módulo
    'producto',                 // Tipo de entidad
    123,                        // ID de entidad
    null,                       // Datos anteriores
    ['nombre' => 'Producto X']  // Datos nuevos
);
```

## 🔒 Middleware de Seguridad

El sistema incluye un middleware (`AuthMiddleware.php`) que proporciona:

- ✅ Verificación de autenticación
- ✅ Verificación de permisos
- ✅ Control de acceso basado en roles (RBAC)
- ✅ Control de acceso basado en jerarquía
- ✅ Registro de actividad (auditoría)
- ✅ Gestión de sesiones

## 📱 API Endpoints

### GET `/api/usuarios-sistema.php`
Obtener usuarios del sistema.

**Parámetros:**
- `id` - ID específico
- `rol_id` - Filtrar por rol
- `activo` - Filtrar por estado
- `subordinados` - Obtener subordinados de un usuario
- `jerarquia` - Obtener jerarquía completa

### POST `/api/usuarios-sistema.php`
Crear nuevo usuario del sistema.

**Body:**
```json
{
    "username": "jperez",
    "password": "password123",
    "nombre": "Juan Pérez",
    "email": "jperez@solumedic.com",
    "rol_id": 3,
    "superior_id": 5
}
```

### PUT `/api/usuarios-sistema.php`
Actualizar usuario del sistema.

### DELETE `/api/usuarios-sistema.php`
Eliminar/Desactivar usuario del sistema.

## 🎨 Interfaz de Usuario

### Panel de Administración
`/admin/usuarios-sistema.php`

Características:
- ✅ Lista de usuarios con filtros
- ✅ Crear nuevos usuarios
- ✅ Editar usuarios existentes
- ✅ Activar/Desactivar usuarios
- ✅ Ver jerarquía organizacional
- ✅ Ver último acceso

## ⚠️ Consideraciones de Seguridad

1. **Passwords:** Se almacenan hasheados con `password_hash()`
2. **Sesiones:** Expiran después de 8 horas
3. **Tokens:** Generados con `random_bytes(32)`
4. **Auditoría:** Todas las acciones se registran en logs
5. **Validación:** Todos los inputs son validados y sanitizados
6. **Permisos:** Se verifican en cada operación

## 🔄 Diferencia con Tabla `clientes`

| Característica | usuarios_sistema | clientes |
|---------------|------------------|----------|
| **Tipo** | Staff/Empleados | Compradores |
| **Login** | Username/Password | Teléfono |
| **Permisos** | RBAC con jerarquía | Solo comprar |
| **Admin** | Sí | No |
| **Jerarquía** | Sí | No |
| **Auditoría** | Completa | Básica |

## 📝 Notas Importantes

1. Los **clientes** NO están en esta tabla, siguen usando la tabla `clientes` existente
2. La relación jerárquica se establece con el campo `superior_id`
3. El campo `representante_id` vincula con la tabla `representantes` existente
4. Solo **administradores** pueden crear, modificar o eliminar datos
5. Todos los demás roles tienen acceso de **solo lectura**
6. La jerarquía determina qué datos puede ver cada usuario

## 🔗 Archivos Relacionados

- `database/schema_produccion_unificado.sql` - Esquema SQL consolidado
- `models/Role.php` - Modelo de roles
- `models/UsuarioSistema.php` - Modelo de usuarios
- `includes/AuthMiddleware.php` - Middleware de autenticación
- `admin/usuarios-sistema.php` - Panel de gestión
- `api/usuarios-sistema.php` - API endpoints

## 🆘 Soporte

Para problemas o preguntas sobre el sistema de roles:
1. Verificar logs en `logs_actividad_sistema`
2. Revisar permisos en la tabla `roles`
3. Confirmar jerarquía con `getJerarquia()`
