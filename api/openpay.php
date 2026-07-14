<?php
/**
 * OpenPay webhook handler.
 * Recibe notificaciones de pago de OpenPay, verifica la firma HMAC y actualiza el pedido.
 *
 * URL a registrar en el dashboard de OpenPay:
 *   https://tudominio.com/api/openpay.php?action=webhook
 *
 * Eventos que maneja: charge.succeeded
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/MetodoPago.php';
require_once __DIR__ . '/../models/MensajePedido.php';
require_once __DIR__ . '/../includes/OpenPayClient.php';

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
$config = $metodoPagoModel->getByMetodo('openpay');

if (!$config || empty($config['openpay_merchant_id']) || empty($config['openpay_private_key'])) {
    http_response_code(503);
    echo json_encode(['error' => 'OpenPay no está configurado']);
    exit;
}

$body      = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_OPENPAY_SIGNATURE'] ?? '';

$client = new OpenPayClient(
    $config['openpay_merchant_id'],
    $config['openpay_private_key'],
    (bool) $config['openpay_sandbox']
);

// Verificar firma HMAC-SHA256 si OpenPay la envía
if (!empty($signature) && !$client->verificarWebhook($body, $signature)) {
    error_log('[OpenPay webhook] Firma HMAC inválida');
    http_response_code(401);
    echo json_encode(['error' => 'Firma inválida']);
    exit;
}

$data        = json_decode($body, true) ?? [];
$type        = $data['type'] ?? '';
$transaction = $data['transaction'] ?? null;

// Manejar verificación de webhook (OpenPay lo envía al registrar el webhook)
if ($type === 'verification') {
    $code = $data['verification_code'] ?? '';
    error_log("[OpenPay webhook] Verification code: {$code}");
    echo json_encode(['received' => true, 'verification_code' => $code]);
    exit;
}

// Solo procesar cargos completados
if ($type !== 'charge.succeeded' || !$transaction) {
    echo json_encode(['received' => true, 'type' => $type]);
    exit;
}

$chargeId = $transaction['id']       ?? '';
$status   = $transaction['status']   ?? '';
$orderId  = $transaction['order_id'] ?? '';

if ($status !== 'completed' || empty($chargeId)) {
    echo json_encode(['received' => true, 'status' => $status]);
    exit;
}

// Verificar el cargo directamente con la API de OpenPay
$verified = $client->obtenerCargo($chargeId);
if (!$verified || ($verified['status'] ?? '') !== 'completed') {
    error_log("[OpenPay webhook] No se pudo verificar cargo {$chargeId}");
    http_response_code(422);
    echo json_encode(['error' => 'No se pudo verificar el cargo']);
    exit;
}

// Buscar el pedido: primero por openpay_charge_id, luego por order_id patrón "PEDIDO_123"
$pedidoId = 0;

$stmtByCharge = $pdo->prepare("SELECT id FROM pedidos WHERE openpay_charge_id = :cid LIMIT 1");
$stmtByCharge->execute(['cid' => $chargeId]);
$row = $stmtByCharge->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $pedidoId = (int) $row['id'];
}

if (!$pedidoId && preg_match('/^PEDIDO_(\d+)$/', $orderId, $m)) {
    $pedidoId = (int) $m[1];
    // Guardar el charge_id si llegamos por order_id
    $pdo->prepare("UPDATE pedidos SET openpay_charge_id = :cid WHERE id = :pid AND openpay_charge_id IS NULL")
        ->execute(['cid' => $chargeId, 'pid' => $pedidoId]);
}

if (!$pedidoId) {
    error_log("[OpenPay webhook] Pedido no encontrado — charge={$chargeId}, order_id={$orderId}");
    http_response_code(404);
    echo json_encode(['error' => 'Pedido no encontrado']);
    exit;
}

$stmtUpdate = $pdo->prepare("
    UPDATE pedidos
    SET estado = 'confirmado',
        metodo_pago = 'openpay',
        openpay_charge_id = :cid,
        fecha_pago = NOW()
    WHERE id = :pid
      AND (estado IS NULL OR estado NOT IN ('confirmado'))
");
$stmtUpdate->execute(['cid' => $chargeId, 'pid' => $pedidoId]);

$mensajeModel = new MensajePedido($pdo);
$mensajeModel->create(
    $pedidoId,
    'cliente',
    "💳 Pago realizado con OpenPay\n✅ Charge ID: {$chargeId}\n📋 Estado: completado (webhook)"
);

echo json_encode(['received' => true]);
