<?php
/**
 * API para Gestión de Usuarios del Sistema
 * Solo accesible para Administradores
 */
header('Content-Type: application/json');

http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'Endpoint legacy deshabilitado. Usa api/usuarios.php con la tabla administradores.'
]);
exit;

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AuthMiddleware.php';
require_once __DIR__ . '/../models/UsuarioSistema.php';
require_once __DIR__ . '/../models/Role.php';

$database = Database::getInstance();
$db = $database->getConnection();
$auth = new AuthMiddleware($db);

// Verificar autenticación y permisos de admin
$usuario_actual = $auth->requireAuth();
$auth->requireAdmin();

$usuarioSistema = new UsuarioSistema($db);
$roleModel = new Role($db);

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($usuarioSistema, $roleModel, $auth);
            break;
            
        case 'POST':
            handlePost($usuarioSistema, $auth);
            break;
            
        case 'PUT':
            handlePut($usuarioSistema, $auth);
            break;
            
        case 'DELETE':
            handleDelete($usuarioSistema, $auth);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}

/**
 * GET - Obtener usuarios
 */
function handleGet($usuarioSistema, $roleModel, $auth) {
    // Si hay ID específico
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $usuario = $usuarioSistema->getById($id);
        
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
            return;
        }
        
        // No devolver password
        unset($usuario['password']);
        
        echo json_encode(['success' => true, 'usuario' => $usuario]);
        return;
    }
    
    // Obtener subordinados de un usuario
    if (isset($_GET['subordinados'])) {
        $id = intval($_GET['subordinados']);
        $subordinados = $usuarioSistema->getSubordinados($id);
        echo json_encode(['success' => true, 'subordinados' => $subordinados]);
        return;
    }
    
    // Obtener jerarquía completa
    if (isset($_GET['jerarquia'])) {
        $id = intval($_GET['jerarquia']);
        $jerarquia = $usuarioSistema->getJerarquia($id);
        echo json_encode(['success' => true, 'jerarquia' => $jerarquia]);
        return;
    }
    
    // Obtener todos los usuarios con filtros
    $filtros = [];
    if (isset($_GET['rol_id'])) {
        $filtros['rol_id'] = intval($_GET['rol_id']);
    }
    if (isset($_GET['activo'])) {
        $filtros['activo'] = intval($_GET['activo']);
    }
    if (isset($_GET['superior_id'])) {
        $filtros['superior_id'] = intval($_GET['superior_id']);
    }
    
    $usuarios = $usuarioSistema->getAll($filtros);
    
    echo json_encode([
        'success' => true,
        'usuarios' => $usuarios,
        'total' => count($usuarios)
    ]);
}

/**
 * POST - Crear nuevo usuario
 */
function handlePost($usuarioSistema, $auth) {
    $datos = [];
    
    // Obtener datos del POST
    $required = ['username', 'password', 'nombre', 'email', 'rol_id'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "El campo $field es requerido"
            ]);
            return;
        }
        $datos[$field] = $_POST[$field];
    }
    
    // Campos opcionales
    $optional = ['telefono', 'superior_id', 'representante_id', 'activo'];
    foreach ($optional as $field) {
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $datos[$field] = $_POST[$field];
        }
    }
    
    // Validar que el username no exista
    $existente = $usuarioSistema->getByUsername($datos['username']);
    if ($existente) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El nombre de usuario ya existe'
        ]);
        return;
    }
    
    // Validar longitud de password
    if (strlen($datos['password']) < 6) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La contraseña debe tener al menos 6 caracteres'
        ]);
        return;
    }
    
    // Crear usuario
    $nuevo_id = $usuarioSistema->crear($datos);
    
    if ($nuevo_id) {
        // Log de actividad
        $auth->logActividad(
            'crear',
            'usuarios_sistema',
            'usuario_sistema',
            $nuevo_id,
            null,
            $datos
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Usuario creado exitosamente',
            'id' => $nuevo_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al crear usuario'
        ]);
    }
}

/**
 * PUT - Actualizar usuario
 */
function handlePut($usuarioSistema, $auth) {
    // Leer datos PUT
    parse_str(file_get_contents("php://input"), $_PUT);
    
    if (empty($_PUT['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID requerido']);
        return;
    }
    
    $id = intval($_PUT['id']);
    
    // Obtener datos actuales
    $usuario_anterior = $usuarioSistema->getById($id);
    if (!$usuario_anterior) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        return;
    }
    
    // Preparar datos para actualizar
    $datos = [];
    $campos_actualizables = ['username', 'nombre', 'email', 'telefono', 'rol_id', 'superior_id', 'representante_id', 'activo'];
    
    foreach ($campos_actualizables as $campo) {
        if (isset($_PUT[$campo])) {
            $datos[$campo] = $_PUT[$campo];
        }
    }
    
    if (empty($datos)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No hay datos para actualizar']);
        return;
    }
    
    // Actualizar
    $resultado = $usuarioSistema->actualizar($id, $datos);
    
    if ($resultado) {
        // Log de actividad
        $auth->logActividad(
            'actualizar',
            'usuarios_sistema',
            'usuario_sistema',
            $id,
            $usuario_anterior,
            $datos
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Usuario actualizado exitosamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al actualizar usuario'
        ]);
    }
}

/**
 * DELETE - Eliminar/Desactivar usuario
 */
function handleDelete($usuarioSistema, $auth) {
    parse_str(file_get_contents("php://input"), $_DELETE);
    
    if (empty($_DELETE['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID requerido']);
        return;
    }
    
    $id = intval($_DELETE['id']);
    $usuario_actual = $auth->getUsuarioActual();
    
    // No puede eliminarse a sí mismo
    if ($id == $usuario_actual['usuario_id']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No puedes eliminar tu propio usuario'
        ]);
        return;
    }
    
    // Obtener datos del usuario
    $usuario = $usuarioSistema->getById($id);
    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        return;
    }
    
    // Por seguridad, mejor desactivar que eliminar
    $accion = $_DELETE['permanente'] ?? false ? 'eliminar' : 'desactivar';
    
    if ($accion === 'eliminar') {
        $resultado = $usuarioSistema->eliminar($id);
    } else {
        $resultado = $usuarioSistema->desactivar($id);
    }
    
    if ($resultado) {
        // Log de actividad
        $auth->logActividad(
            $accion,
            'usuarios_sistema',
            'usuario_sistema',
            $id,
            $usuario,
            null
        );
        
        echo json_encode([
            'success' => true,
            'message' => $accion === 'eliminar' ? 'Usuario eliminado' : 'Usuario desactivado'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al ' . $accion . ' usuario'
        ]);
    }
}
