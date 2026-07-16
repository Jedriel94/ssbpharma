<?php
/**
 * API para gestión de Kits
 * Endpoint: /api/kits.php
 */

// Configurar manejo de errores para evitar HTML en respuesta JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
require_once '../includes/auth_admin.php';
require_once '../models/Kit.php';

$kitModel = new Kit();

try {
    // Obtener método y acción
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Si es GET con action
if ($method === 'GET') {
    switch ($action) {
        case 'listar':
            $kits = $kitModel->obtenerKitsDisponibles();
            echo json_encode(['success' => true, 'kits' => $kits]);
            break;
            
        case 'detalle':
            $kit_id = filter_input(INPUT_GET, 'kit_id', FILTER_VALIDATE_INT) ?: 0;
            if ($kit_id <= 0) {
                echo json_encode(['success' => false, 'mensaje' => 'ID de kit inválido']);
                break;
            }
            $kit = $kitModel->obtenerKitPorId($kit_id);
            
            if ($kit) {
                $productos = $kitModel->obtenerProductosDeKit($kit_id);
                
                // Debug log
                error_log("API detalle kit #{$kit_id}: imagen='" . ($kit['imagen'] ?? 'NULL') . "'");
                
                echo json_encode([
                    'success' => true,
                    'kit' => $kit,
                    'productos' => $productos
                ]);
            } else {
                echo json_encode(['success' => false, 'mensaje' => 'Kit no encontrado']);
            }
            break;
            
        case 'estadisticas':
            $kit_id = filter_input(INPUT_GET, 'kit_id', FILTER_VALIDATE_INT) ?: null;
            $fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : null;
            $fecha_fin = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : null;
            
            // Validar formato de fechas si se proporcionan
            if ($fecha_inicio && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) {
                echo json_encode(['success' => false, 'mensaje' => 'Formato de fecha_inicio inválido']);
                break;
            }
            if ($fecha_fin && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) {
                echo json_encode(['success' => false, 'mensaje' => 'Formato de fecha_fin inválido']);
                break;
            }
            
            $estadisticas = $kitModel->obtenerEstadisticasVentas($kit_id, $fecha_inicio, $fecha_fin);
            echo json_encode(['success' => true, 'estadisticas' => $estadisticas]);
            break;
            
        case 'verificar_disponibilidad':
            $kit_id = filter_input(INPUT_GET, 'kit_id', FILTER_VALIDATE_INT) ?: 0;
            $cantidad = filter_input(INPUT_GET, 'cantidad', FILTER_VALIDATE_INT) ?: 1;
            
            if ($kit_id <= 0 || $cantidad <= 0) {
                echo json_encode(['success' => false, 'mensaje' => 'Parámetros inválidos']);
                break;
            }
            
            $disponibilidad = $kitModel->verificarDisponibilidad($kit_id, $cantidad);
            echo json_encode(['success' => true, 'disponibilidad' => $disponibilidad]);
            break;
            
        default:
            echo json_encode(['success' => false, 'mensaje' => 'Acción no válida']);
    }
}

// Si es POST
elseif ($method === 'POST') {
    // Verificar si es una subida de archivo
    if (isset($_POST['action']) && $_POST['action'] === 'upload_imagen') {
        // Manejar subida de imagen
        if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'mensaje' => 'No se recibió ninguna imagen']);
            exit;
        }
        
        $file = $_FILES['imagen'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        // Validar tipo de archivo
        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['success' => false, 'mensaje' => 'Tipo de archivo no permitido. Solo JPG, PNG, GIF o WEBP']);
            exit;
        }
        
        // Validar tamaño
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'mensaje' => 'El archivo es muy grande. Máximo 2MB']);
            exit;
        }
        
        // Crear carpeta si no existe
        $uploadDir = uploads_dir('kits') . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generar nombre único
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $nombreArchivo = 'kit_' . time() . '_' . uniqid() . '.' . $extension;
        $rutaDestino = $uploadDir . $nombreArchivo;
        
        // Mover archivo
        if (move_uploaded_file($file['tmp_name'], $rutaDestino)) {
            echo json_encode([
                'success' => true,
                'mensaje' => 'Imagen subida exitosamente',
                'nombre_archivo' => $nombreArchivo
            ]);
        } else {
            echo json_encode(['success' => false, 'mensaje' => 'Error al guardar la imagen']);
        }
        exit;
    }
    
    // Leer datos JSON del body para otras operaciones
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        // Si no hay JSON, intentar con POST normal
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';

    switch ($action) {
        case 'reordenar':
            $ids = $data['ids'] ?? [];
            if (is_string($ids)) $ids = json_decode($ids, true);
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['success' => false, 'mensaje' => 'Orden inválido']);
                break;
            }
            $kitModel->actualizarOrden($ids);
            echo json_encode(['success' => true, 'mensaje' => 'Orden guardado']);
            break;

        case 'create':
            // Validar campos requeridos
            if (empty($data['nombre']) || !isset($data['precio_kit'])) {
                echo json_encode(['success' => false, 'mensaje' => 'Nombre y precio son requeridos']);
                break;
            }
            
            // Sanitizar y validar datos
            $nombre = trim($data['nombre']);
            $descripcion = isset($data['descripcion']) ? trim($data['descripcion']) : null;
            $precio_kit = filter_var($data['precio_kit'], FILTER_VALIDATE_FLOAT);
            $activo = filter_var($data['activo'] ?? 1, FILTER_VALIDATE_INT);
            $orden = filter_var($data['orden'] ?? 0, FILTER_VALIDATE_INT);
            $en_carrusel = isset($data['en_carrusel']) ? (int)(bool)$data['en_carrusel'] : 0;
            
            if ($precio_kit === false || $precio_kit < 0) {
                echo json_encode(['success' => false, 'mensaje' => 'Precio inválido']);
                break;
            }
            
            // Validar productos
            $productos = [];
            if (!empty($data['productos']) && is_array($data['productos'])) {
                foreach ($data['productos'] as $prod) {
                    $producto_id    = filter_var($prod['producto_id'] ?? 0, FILTER_VALIDATE_INT);
                    $cantidad       = filter_var($prod['cantidad'] ?? 0, FILTER_VALIDATE_INT);
                    $precio_unitario = filter_var($prod['precio_unitario'] ?? 0, FILTER_VALIDATE_FLOAT);
                    
                    if ($producto_id > 0 && $cantidad > 0) {
                        $productos[] = [
                            'producto_id'    => $producto_id,
                            'cantidad'       => $cantidad,
                            'precio_unitario' => $precio_unitario !== false ? round($precio_unitario, 2) : 0.00,
                        ];
                    }
                }
            }
            
            if (empty($productos)) {
                echo json_encode(['success' => false, 'mensaje' => 'Debe incluir al menos un producto']);
                break;
            }
            
            // Manejar imagen (convertir string vacío a null)
            $imagen = isset($data['imagen']) && !empty($data['imagen']) ? $data['imagen'] : null;
            
            // Debug log
            error_log("Creando kit con imagen: " . ($imagen ?? 'NULL'));
            
            $resultado = $kitModel->crearKit([
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'imagen' => $imagen,
                'precio_kit' => $precio_kit,
                'activo' => $activo ? 1 : 0,
                'orden' => $orden,
                'en_carrusel' => $en_carrusel,
                'productos' => $productos
            ]);
            
            // Debug resultado
            error_log("Resultado crear kit: " . json_encode($resultado));
            
            echo json_encode($resultado);
            break;
            
        case 'update':
            $kit_id = filter_var($data['kit_id'] ?? 0, FILTER_VALIDATE_INT);
            
            if ($kit_id <= 0) {
                error_log("Kit update error: ID inválido - " . print_r($data, true));
                echo json_encode(['success' => false, 'mensaje' => 'ID de kit inválido']);
                break;
            }
            
            // Validar y sanitizar igual que en create
            if (empty($data['nombre']) || !isset($data['precio_kit'])) {
                error_log("Kit update error: Datos incompletos - " . print_r($data, true));
                echo json_encode(['success' => false, 'mensaje' => 'Nombre y precio son requeridos']);
                break;
            }
            
            $nombre = trim($data['nombre']);
            $descripcion = isset($data['descripcion']) ? trim($data['descripcion']) : null;
            $precio_kit = filter_var($data['precio_kit'], FILTER_VALIDATE_FLOAT);
            $activo = filter_var($data['activo'] ?? 1, FILTER_VALIDATE_INT);
            $orden = filter_var($data['orden'] ?? 0, FILTER_VALIDATE_INT);
            $en_carrusel = isset($data['en_carrusel']) ? (int)(bool)$data['en_carrusel'] : 0;
            
            if ($precio_kit === false || $precio_kit < 0) {
                echo json_encode(['success' => false, 'mensaje' => 'Precio inválido']);
                break;
            }
            
            // Validar productos si se proporcionan
            $productos = null;
            if (isset($data['productos']) && is_array($data['productos'])) {
                $productos = [];
                foreach ($data['productos'] as $prod) {
                    $producto_id    = filter_var($prod['producto_id'] ?? 0, FILTER_VALIDATE_INT);
                    $cantidad       = filter_var($prod['cantidad'] ?? 0, FILTER_VALIDATE_INT);
                    $precio_unitario = filter_var($prod['precio_unitario'] ?? 0, FILTER_VALIDATE_FLOAT);
                    
                    if ($producto_id > 0 && $cantidad > 0) {
                        $productos[] = [
                            'producto_id'    => $producto_id,
                            'cantidad'       => $cantidad,
                            'precio_unitario' => $precio_unitario !== false ? round($precio_unitario, 2) : 0.00,
                        ];
                    }
                }
            }
            
            // Manejar imagen (convertir string vacío a null)
            $imagen = isset($data['imagen']) && !empty($data['imagen']) ? $data['imagen'] : null;
            
            $datosActualizar = [
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'imagen' => $imagen,
                'precio_kit' => $precio_kit,
                'activo' => $activo ? 1 : 0,
                'orden' => $orden,
                'en_carrusel' => $en_carrusel
            ];
            
            if ($productos !== null) {
                $datosActualizar['productos'] = $productos;
            }
            
            // Debug log
            error_log("Actualizando kit #$kit_id con datos: " . json_encode($datosActualizar));
            
            $resultado = $kitModel->actualizarKit($kit_id, $datosActualizar);
            echo json_encode($resultado);
            break;
            
        case 'toggle_estado':
            $kit_id = filter_var($data['kit_id'] ?? 0, FILTER_VALIDATE_INT);
            $activo = filter_var($data['activo'] ?? 1, FILTER_VALIDATE_INT);
            
            if ($kit_id <= 0) {
                echo json_encode(['success' => false, 'mensaje' => 'ID de kit inválido']);
                break;
            }
            
            // Obtener datos actuales del kit
            $kitActual = $kitModel->obtenerKitPorId($kit_id);
            if (!$kitActual) {
                echo json_encode(['success' => false, 'mensaje' => 'Kit no encontrado']);
                break;
            }
            
            $resultado = $kitModel->actualizarKit($kit_id, [
                'nombre' => $kitActual['nombre'],
                'descripcion' => $kitActual['descripcion'],
                'precio_kit' => $kitActual['precio_kit'],
                'orden' => $kitActual['orden'],
                'activo' => $activo ? 1 : 0
            ]);
            
            echo json_encode($resultado);
            break;
            
        case 'delete':
            $kit_id = filter_var($data['kit_id'] ?? 0, FILTER_VALIDATE_INT);
            
            if ($kit_id <= 0) {
                echo json_encode(['success' => false, 'mensaje' => 'ID de kit inválido']);
                break;
            }
            
            $resultado = $kitModel->eliminarKit($kit_id);
            echo json_encode($resultado);
            break;
            
        case 'vender':
            $kit_id = filter_var($data['kit_id'] ?? 0, FILTER_VALIDATE_INT);
            $pedido_id = filter_var($data['pedido_id'] ?? 0, FILTER_VALIDATE_INT);
            $cantidad = filter_var($data['cantidad'] ?? 1, FILTER_VALIDATE_INT);
            
            if ($kit_id <= 0 || $pedido_id <= 0 || $cantidad <= 0) {
                echo json_encode(['success' => false, 'mensaje' => 'Parámetros inválidos']);
                break;
            }
            
            $resultado = $kitModel->venderKit($kit_id, $pedido_id, $cantidad);
            echo json_encode($resultado);
            break;
            
        default:
            echo json_encode(['success' => false, 'mensaje' => 'Acción no válida']);
    }
}

else {
    echo json_encode(['success' => false, 'mensaje' => 'Método no soportado']);
}

} catch (Exception $e) {
    error_log("Error en API kits.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'mensaje' => 'Error en el servidor: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    error_log("Error fatal en API kits.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'mensaje' => 'Error fatal en el servidor'
    ]);
}
