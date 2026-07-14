<?php
/**
 * API de PayPal - Integración con PayPal Checkout
 * Gestiona la creación de órdenes y captura de pagos
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/MetodoPago.php';

header('Content-Type: application/json');

// Obtener configuración de PayPal desde la BD
$metodoPagoModel = new MetodoPago();
$configPayPal = $metodoPagoModel->getByMetodo('paypal');

if (!$configPayPal || !$configPayPal['activo']) {
    http_response_code(503);
    echo json_encode(['error' => 'PayPal no está disponible']);
    exit;
}

$CLIENT_ID = $configPayPal['paypal_client_id'];
$SECRET = $configPayPal['paypal_secret'];
$MODE = $configPayPal['paypal_mode'] ?? 'sandbox';
$BASE_URL = $MODE === 'production' 
    ? 'https://api-m.paypal.com' 
    : 'https://api-m.sandbox.paypal.com';

/**
 * Obtener Access Token de PayPal
 */
function getAccessToken($clientId, $secret, $baseUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v1/oauth2/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Accept-Language: es_ES'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("PayPal Auth Error: " . $response);
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

/**
 * Crear Orden de PayPal
 */
function createPayPalOrder($accessToken, $baseUrl, $pedidoData) {
    $orderData = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'PEDIDO_' . $pedidoData['pedido_id'],
            'description' => 'Pedido #' . $pedidoData['pedido_id'] . ' - Solumedic Shop',
            'amount' => [
                'currency_code' => 'MXN',
                'value' => number_format($pedidoData['monto'], 2, '.', '')
            ]
        ]],
        'application_context' => [
            'brand_name' => 'Solumedic Shop',
            'locale' => 'es-MX',
            'landing_page' => 'BILLING',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => $pedidoData['return_url'],
            'cancel_url' => $pedidoData['cancel_url']
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v2/checkout/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 201) {
        error_log("PayPal Create Order Error: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Capturar Pago de PayPal
 */
function capturePayPalOrder($accessToken, $baseUrl, $orderId) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . "/v2/checkout/orders/{$orderId}/capture");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 201) {
        error_log("PayPal Capture Order Error: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

// ============================================
// ENDPOINTS
// ============================================

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    
    case 'create_order':
        // Crear orden de PayPal
        $pedido_id = $_POST['pedido_id'] ?? 0;
        $monto = floatval($_POST['monto'] ?? 0);
        
        if (!$pedido_id || $monto <= 0) {
            echo json_encode(['error' => 'Datos inválidos']);
            exit;
        }
        
        $accessToken = getAccessToken($CLIENT_ID, $SECRET, $BASE_URL);
        if (!$accessToken) {
            echo json_encode(['error' => 'Error de autenticación con PayPal']);
            exit;
        }
        
        $pedidoData = [
            'pedido_id' => $pedido_id,
            'monto' => $monto,
            'return_url' => $_POST['return_url'] ?? '',
            'cancel_url' => $_POST['cancel_url'] ?? ''
        ];
        
        $order = createPayPalOrder($accessToken, $BASE_URL, $pedidoData);
        
        if (!$order) {
            echo json_encode(['error' => 'Error al crear orden en PayPal']);
            exit;
        }
        
        // Guardar order_id en la base de datos
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $stmt = $pdo->prepare("
            UPDATE pedidos 
            SET paypal_order_id = :order_id 
            WHERE id = :pedido_id
        ");
        $stmt->execute([
            'order_id' => $order['id'],
            'pedido_id' => $pedido_id
        ]);
        
        echo json_encode([
            'success' => true,
            'order_id' => $order['id']
        ]);
        break;
        
    case 'capture_order':
        // Capturar pago de PayPal
        $order_id = $_POST['order_id'] ?? '';
        $pedido_id = $_POST['pedido_id'] ?? 0;
        
        if (!$order_id || !$pedido_id) {
            echo json_encode(['error' => 'Datos inválidos']);
            exit;
        }
        
        $accessToken = getAccessToken($CLIENT_ID, $SECRET, $BASE_URL);
        if (!$accessToken) {
            echo json_encode(['error' => 'Error de autenticación con PayPal']);
            exit;
        }
        
        $capture = capturePayPalOrder($accessToken, $BASE_URL, $order_id);
        
        if (!$capture || $capture['status'] !== 'COMPLETED') {
            echo json_encode(['error' => 'Error al capturar el pago']);
            exit;
        }
        
        // Actualizar pedido en la base de datos
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $transactionId = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE pedidos 
            SET 
                estado_pago = 'pagado',
                metodo_pago = 'paypal',
                paypal_transaction_id = :transaction_id,
                fecha_pago = NOW()
            WHERE id = :pedido_id
        ");
        $stmt->execute([
            'transaction_id' => $transactionId,
            'pedido_id' => $pedido_id
        ]);
        
        echo json_encode([
            'success' => true,
            'transaction_id' => $transactionId
        ]);
        break;
        
    case 'get_config':
        // Obtener configuración pública (solo Client ID y modo)
        echo json_encode([
            'client_id' => $CLIENT_ID,
            'mode' => $MODE
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
        break;
}

