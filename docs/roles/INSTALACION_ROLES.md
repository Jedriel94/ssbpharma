# 🚀 Guía Rápida: Instalación del Sistema de Roles

## ✅ Checklist de Instalación

### 1️⃣ Verificar estructura de roles en la base

```bash
# Opción A: Desde terminal
mysql -u root -D solumedic_dbshop -e "SHOW TABLES LIKE 'roles';"

# Opción B: Desde PHPMyAdmin
# 1. Abrir PHPMyAdmin
# 2. Seleccionar base de datos: solumedic_dbshop
# 3. Ir a pestaña "SQL"
# 4. Ejecutar: SHOW TABLES LIKE 'roles';
# 5. Confirmar que existe la tabla
```

### 2️⃣ Verificar Tablas Creadas

Debe haber 4 nuevas tablas:
- ✅ `roles` - 5 registros (admin, director_general, director_unidad, gerente, representante)
- ✅ `usuarios_sistema` - 1 registro (usuario admin por defecto)
- ✅ `sesiones_sistema` - Vacía inicialmente
- ✅ `logs_actividad_sistema` - Vacía inicialmente

```sql
-- Verificar que las tablas existen
SHOW TABLES LIKE '%roles%';
SHOW TABLES LIKE '%usuarios_sistema%';
SHOW TABLES LIKE '%sesiones_sistema%';
SHOW TABLES LIKE '%logs_actividad%';

-- Verificar roles creados
SELECT * FROM roles ORDER BY nivel_jerarquico;

-- Verificar usuario admin creado
SELECT id, username, nombre, email, activo FROM usuarios_sistema;
```

### 3️⃣ Primer Login

**URL:** `http://localhost/solumedic-shop/login-sistema.php`

**Credenciales por defecto:**
- 👤 Usuario: `admin`
- 🔑 Contraseña: `password`

⚠️ **IMPORTANTE:** Cambiar esta contraseña inmediatamente después del primer login.

### 4️⃣ Crear Primer Usuario Real

1. Login con credenciales por defecto
2. Ir a: **Admin → Usuarios del Sistema**
3. Clic en "Nuevo Usuario"
4. Llenar formulario:
   - Username: `tu_usuario`
   - Password: `contraseña_segura`
   - Nombre: Tu nombre completo
   - Email: tu_email@solumedic.com
   - Rol: Administrador
   - Estado: Activo
5. Guardar

### 5️⃣ Cambiar Password del Admin por Defecto

```sql
-- Opción A: Desactivar usuario admin por defecto (recomendado)
UPDATE usuarios_sistema SET activo = 0 WHERE username = 'admin';

-- Opción B: Cambiar password del admin
-- El password debe hashearse con password_hash() en PHP
-- Ejemplo para generar hash:
```

```php
<?php
// Generar hash para nueva contraseña
$nueva_password = 'MiPasswordSeguro123!';
$hash = password_hash($nueva_password, PASSWORD_DEFAULT);
echo $hash;
// Copiar el hash resultante y usarlo en el UPDATE
?>
```

```sql
UPDATE usuarios_sistema 
SET password = '$2y$10$HASH_AQUI' 
WHERE username = 'admin';
```

### 6️⃣ Configurar Estructura Organizacional

Crear usuarios según la jerarquía:

```
1. Director General
   └─ 2. Director de Unidad de Negocio (reporta a #1)
      └─ 3. Gerente (reporta a #2)
         └─ 4. Representante (reporta a #3)
```

**Ejemplo:**

```sql
-- 1. Director General (sin superior)
INSERT INTO usuarios_sistema (username, password, nombre, email, rol_id, superior_id)
VALUES ('dgomez', '$2y$10$...', 'David Gómez', 'dgomez@solumedic.com', 2, NULL);

-- 2. Director de Unidad (reporta al DG)
INSERT INTO usuarios_sistema (username, password, nombre, email, rol_id, superior_id)
VALUES ('mlopez', '$2y$10$...', 'María López', 'mlopez@solumedic.com', 3, 2);

-- 3. Gerente (reporta al Director de Unidad)
INSERT INTO usuarios_sistema (username, password, nombre, email, rol_id, superior_id)
VALUES ('jperez', '$2y$10$...', 'Juan Pérez', 'jperez@solumedic.com', 4, 3);

-- 4. Representante (reporta al Gerente)
-- Si existe en tabla representantes, vincular con representante_id
INSERT INTO usuarios_sistema (username, password, nombre, email, rol_id, superior_id, representante_id)
VALUES ('asanchez', '$2y$10$...', 'Ana Sánchez', 'asanchez@solumedic.com', 5, 4, 1);
```

## 🔐 Proteger Páginas del Admin

### Actualizar páginas existentes

Agregar al inicio de cada página del admin:

```php
<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AuthMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$auth = new AuthMiddleware($db);

// Requerir autenticación
$usuario_actual = $auth->requireAuth();

// Si la página requiere permisos de modificación (crear/editar/eliminar)
$auth->requireModificar(); // Solo admin puede modificar

// Si solo requiere lectura, todos los usuarios autenticados pueden acceder
// (no se necesita nada más)
?>
```

### Ejemplo: Proteger página de productos

```php
<?php
// admin/productos.php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AuthMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$auth = new AuthMiddleware($db);

$usuario_actual = $auth->requireAuth();

// Solo admin puede crear/editar/eliminar productos
$puede_modificar = $auth->puedeModificar();
?>

<!-- En el HTML, mostrar/ocultar botones según permisos -->
<?php if ($puede_modificar): ?>
    <button class="btn btn-primary" id="btnNuevoProducto">
        Nuevo Producto
    </button>
<?php else: ?>
    <div class="alert alert-info">
        Solo tienes permisos de lectura
    </div>
<?php endif; ?>
```

## 📊 Verificar Instalación

### Script de Verificación

```php
<?php
// verificar-roles.php
require_once 'config/database.php';
require_once 'models/Role.php';
require_once 'models/UsuarioSistema.php';

$database = new Database();
$db = $database->getConnection();

$roleModel = new Role($db);
$usuarioModel = new UsuarioSistema($db);

echo "<h2>Verificación del Sistema de Roles</h2>";

// Verificar roles
echo "<h3>Roles Instalados:</h3>";
$roles = $roleModel->getAll();
foreach ($roles as $rol) {
    echo "✅ {$rol['nombre']} (Nivel {$rol['nivel_jerarquico']})<br>";
}

// Verificar usuarios
echo "<h3>Usuarios del Sistema:</h3>";
$usuarios = $usuarioModel->getAll();
foreach ($usuarios as $user) {
    echo "👤 {$user['nombre']} - {$user['rol_nombre']} - ";
    echo ($user['activo'] ? '🟢 Activo' : '🔴 Inactivo') . "<br>";
}

echo "<h3>Estado:</h3>";
echo count($roles) >= 5 ? "✅ Roles OK<br>" : "❌ Faltan roles<br>";
echo count($usuarios) >= 1 ? "✅ Usuarios OK<br>" : "❌ No hay usuarios<br>";
?>
```

## 🐛 Solución de Problemas

### Error: "Table 'roles' doesn't exist"
```bash
# Verificar que la base fue creada desde el esquema oficial
mysql -u root -D solumedic_dbshop -e "SHOW TABLES LIKE 'roles';"
```

### Error: "Call to undefined method Role::..."
```bash
# Verificar que los archivos existen
ls -la models/Role.php
ls -la models/UsuarioSistema.php
ls -la includes/AuthMiddleware.php
```

### No puede hacer login
```sql
-- Verificar que el usuario existe y está activo
SELECT id, username, nombre, activo FROM usuarios_sistema;

-- Verificar que el rol está asignado correctamente
SELECT u.username, r.nombre as rol 
FROM usuarios_sistema u 
INNER JOIN roles r ON u.rol_id = r.id;
```

### Error: "Password incorrecto"
```php
<?php
// Resetear password del admin
$nueva_password = 'Admin123!';
$hash = password_hash($nueva_password, PASSWORD_DEFAULT);
echo "Hash: " . $hash;

// Usar ese hash en:
// UPDATE usuarios_sistema SET password = 'HASH_AQUI' WHERE username = 'admin';
?>
```

## 📚 Próximos Pasos

1. ✅ Crear usuarios del sistema según jerarquía organizacional
2. ✅ Proteger todas las páginas del admin con `AuthMiddleware`
3. ✅ Actualizar menús para mostrar opciones según permisos
4. ✅ Implementar filtros de datos basados en jerarquía
5. ✅ Configurar políticas de contraseña
6. ✅ Configurar expiración de sesiones
7. ✅ Revisar logs de auditoría regularmente

## 🔗 Documentación Completa

Ver: [SISTEMA_ROLES.md](SISTEMA_ROLES.md)

## ✉️ Soporte

Si encuentras problemas durante la instalación:
1. Verificar logs de MySQL/PHP
2. Revisar tabla `logs_actividad_sistema`
3. Verificar permisos de archivos PHP
4. Consultar la documentación completa
