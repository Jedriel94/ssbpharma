<?php
/**
 * EcartPay webhook handler.
 * Receives payment notifications from EcartPay, verifies via API and updates pedido.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/MetodoPago.php';
require_once __DIR__ . '/../models/MensajePedido.php';
require_once __DIR__ . '/../includes/EcartPayClient.php';

header('Content-Type: application/json');

$db  = Database::getInstance();
$pdo = $db->getConnection();

$action = $_GET['action'] ?? '';

if ($action !== 'webhook') {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida']);
    exit;
}

$metodoPagoModel = new MetodoPago();
$config = $metodoPagoModel->getByMetodo('ecartpay');

if (!$config || !$config['activo']) {
    http_response_code(503);
    echo json_encode(['error' => 'EcartPay no está disponible']);
    exit;
}

$client = new EcartPayClient(
    publicKey:  $config['ecartpay_public_key'],
    privateKey: $config['ecartpay_private_key'],
    sandbox:    (bool) $config['ecartpay_sandbox'],
    cacheGet:   function () use ($config) {
        if (!empty($config['ecartpay_token_cache']) && !empty($config['ecartpay_token_expires'])) {
            if (time() < (int) $config['ecartpay_token_expires']) {
                return $config['ecartpay_token_cache'];
            }
        }
        return null;
    },
    cacheSet:   function (string $token, int $expiresAt) use ($pdo) {
        $pdo->prepare(
            "UPDATE metodos_pago SET ecartpay_token_cache = :t, ecartpay_token_expires = :e WHERE metodo = 'ecartpay'"
        )->execute(['t' => $token, 'e' => $expiresAt]);
    },
);

// EcartPay POSTs a JSON body with at minimum an order id
$body = file_get_contents('php://input');
$data = json_decode($body, true) ?? [];

$ecartpayOrderId = $data['id'] ?? $data['order_id'] ?? '';

if (!$ecartpayOrderId) {
    http_response_code(400);
    echo json_encode(['error' => 'No order ID in payload']);
    exit;
}

// Verify order status directly with EcartPay API
$order = $client->getOrder($ecartpayOrderId);
if (!$order) {
    error_log("EcartPay webhook: could not fetch order {$ecartpayOrderId}");
    http_response_code(422);
    echo json_encode(['error' => 'No se pudo verificar la orden en EcartPay']);
    exit;
}

$status = $order['status'] ?? '';

// Only confirm on paid/approved status
if (!in_array($status, ['paid', 'approved'], true)) {
    echo json_encode(['received' => true, 'status' => $status]);
    exit;
}

// Find pedido by ecartpay_order_id
$stmt = $pdo->prepare("SELECT id FROM pedidos WHERE ecartpay_order_id = :oid LIMIT 1");
$stmt->execute(['oid' => $ecartpayOrderId]);
$pedidoRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedidoRow) {
    error_log("EcartPay webhook: pedido not found for order {$ecartpayOrderId}");
    http_response_code(404);
    echo json_encode(['error' => 'Pedido no encontrado']);
    exit;
}

$pedidoId = $pedidoRow['id'];

$pdo->prepare("
    UPDATE pedidos
    SET estado = 'confirmado',
        metodo_pago = 'ecartpay',
        fecha_pago = NOW()
    WHERE id = :pid
")->execute(['pid' => $pedidoId]);

$mensajeModel = new MensajePedido($pdo);
$mensajeModel->create(
    $pedidoId,
    'cliente',
    "Pago realizado con EcartPay\nOrder ID: {$ecartpayOrderId}\nEstado: {$status}"
);

echo json_encode(['received' => true]);
