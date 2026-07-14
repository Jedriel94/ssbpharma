<?php
/**
 * API de Cupones
 * Endpoints para validar y aplicar cupones en el proceso de compra
 */

// Capturar errores y evitar output no deseado
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../models/Cupon.php';
require_once '../models/Producto.php';
require_once '../models/Kit.php';

// Limpiar cualquier output buffer
ob_end_clean();

session_start();

try {
    $cuponModel = new Cupon();
    $productoModel = new Producto();
    $kitModel = new Kit();

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'validar':
            validarCupon();
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida'
            ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error en la API: ' . $e->getMessage()
    ]);
}

/**
 * Validar cupón antes de aplicar
 */
function validarCupon() {
    global $cuponModel, $productoModel, $kitModel;
    
    try {
        // Obtener datos del request
        $codigo = trim($_POST['codigo'] ?? '');
        $carrito = json_decode($_POST['carrito'] ?? '[]', true);
        $representante_admin_id = $_POST['representante_admin_id'] ?? $_COOKIE['botikit_rep_admin'] ?? null;
        $representante_admin_id = $representante_admin_id ? (int)$representante_admin_id : null;
    
    if (empty($codigo)) {
        echo json_encode([
            'success' => false,
            'message' => 'Debes ingresar un código de cupón'
        ]);
        return;
    }
    
    if (empty($carrito)) {
        echo json_encode([
            'success' => false,
            'message' => 'El carrito está vacío'
        ]);
        return;
    }
    
    // Calcular subtotal y preparar productos con información completa
    $subtotal = 0;
    $productos_info = [];
    
    foreach ($carrito as $item) {
        $item_id = $item['id'] ?? 0;
        $cantidad = $item['cantidad'] ?? 0;
        $es_kit = $item['es_kit'] ?? false;
        
        if ($es_kit) {
            // Es un kit
            $kit = $kitModel->obtenerKitPorId($item_id);
            if ($kit) {
                $precio_unitario = floatval($kit['precio_kit']);
                $subtotal += $precio_unitario * $cantidad;
                $productos_info[] = [
                    'id' => $item_id,
                    'cantidad' => $cantidad,
                    'precio' => $precio_unitario,
                    'es_kit' => true,
                    'nombre' => $kit['nombre']
                ];
            }
        } else {
            // Es un producto
            $producto = $productoModel->getById($item_id);
            if ($producto) {
                // Obtener precio según cantidad
                $precio_unitario = $productoModel->getPrecioByQuantity($item_id, $cantidad);
                if (!$precio_unitario) {
                    // Si no hay rango de precios, usar 0 o saltar el producto
                    continue;
                }
                $subtotal += $precio_unitario * $cantidad;
                $productos_info[] = [
                    'id' => $item_id,
                    'cantidad' => $cantidad,
                    'precio' => $precio_unitario,
                    'es_kit' => false,
                    'tags' => $producto['tags'] ?? '',
                    'nombre' => $producto['producto']
                ];
            }
        }
    }
    
    // Validar cupón
    $resultado = $cuponModel->validar($codigo, $subtotal, $productos_info, null, $representante_admin_id);
    
    if ($resultado['valido']) {
        $cupon = $resultado['cupon'];
        $descuento = $resultado['descuento'];
        $total = max(0, $subtotal - $descuento);
        
        echo json_encode([
            'success' => true,
            'valido' => true,
            'message' => '✓ Cupón aplicado correctamente',
            'cupon' => [
                'id' => $cupon['id'],
                'codigo' => $cupon['codigo'],
                'descripcion' => $cupon['descripcion'],
                'tipo_descuento' => $cupon['tipo_descuento'],
                'valor_descuento' => $cupon['valor_descuento']
            ],
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'descuento' => number_format($descuento, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
            'descuento_texto' => $cupon['tipo_descuento'] === 'porcentaje' 
                ? $cupon['valor_descuento'] . '%' 
                : '$' . number_format($cupon['valor_descuento'], 2)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'valido' => false,
            'message' => $resultado['mensaje'],
            'descuento' => 0
        ]);
    }
    
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error al validar cupón: ' . $e->getMessage(),
            'error_line' => $e->getLine(),
            'error_file' => basename($e->getFile())
        ]);
    }
}
