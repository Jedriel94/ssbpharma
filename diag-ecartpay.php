<?php
/**
 * Diagnóstico de eCartPay (un solo uso).
 * Lee la config real de metodos_pago y prueba auth + creación de orden,
 * mostrando el error EXACTO de la API de eCartPay.
 *
 * USO:  botikit.shop/ssbpharma/diag-ecartpay.php?token=ssb-diag-2026
 * ⚠️  BORRA ESTE ARCHIVO cuando termines (trae info sensible en pantalla).
 */

$TOKEN = 'ssb-diag-2026';
header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><meta charset="utf-8"><title>Diag eCartPay</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:2rem auto;padding:0 1rem;color:#1e293b;background:#f8fafc}
pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:13px;white-space:pre-wrap;word-break:break-word}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin:14px 0}
.ok{color:#16a34a;font-weight:700}.err{color:#dc2626;font-weight:700}.k{color:#64748b}
h1{font-size:1.4rem}h2{font-size:1.05rem;margin:.2rem 0}</style>';
echo '<h1>🔍 Diagnóstico eCartPay</h1>';

if (($_GET['token'] ?? '') !== $TOKEN) {
    echo '<div class="card err">Falta token. Usa: diag-ecartpay.php?token=' . htmlspecialchars($TOKEN) . '</div>';
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/MetodoPago.php';

function mask($s) {
    $s = (string)$s;
    $n = strlen($s);
    if ($n === 0) return '(vacío)';
    if ($n <= 10) return substr($s, 0, 2) . str_repeat('•', max(0, $n - 2));
    return substr($s, 0, 6) . str_repeat('•', 8) . substr($s, -4) . "  (len $n)";
}
function curlJson($url, $headers, $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $err];
}

$cfg = (new MetodoPago())->getByMetodo('ecartpay');

echo '<div class="card"><h2>1) Configuración en la BD</h2>';
if (!$cfg) { echo '<span class="err">No existe la fila metodos_pago.metodo=ecartpay</span></div>'; exit; }
$sandbox = (bool)($cfg['ecartpay_sandbox'] ?? 0);
$pub = trim($cfg['ecartpay_public_key'] ?? '');
$priv = trim($cfg['ecartpay_private_key'] ?? '');
$base = $sandbox ? 'https://sandbox.ecartpay.com' : 'https://ecartpay.com';
echo '<div class="k">activo:</div> ' . ((int)($cfg['activo'] ?? 0) === 1 ? '<span class="ok">sí</span>' : '<span class="err">NO (inactivo)</span>');
echo '<br><div class="k">sandbox:</div> ' . ($sandbox ? 'ON (sandbox.ecartpay.com)' : 'OFF (ecartpay.com)');
echo '<br><div class="k">endpoint base:</div> ' . htmlspecialchars($base);
echo '<br><div class="k">public_key:</div> ' . htmlspecialchars(mask($pub));
echo '<br><div class="k">private_key:</div> ' . htmlspecialchars(mask($priv));
echo '</div>';

if ($pub === '' || $priv === '') { echo '<div class="card err">Faltan llaves (public o private vacía). Guárdalas en el admin.</div>'; exit; }

// 2) Auth token
echo '<div class="card"><h2>2) Autenticación (POST /api/authorizations/token)</h2>';
$basic = base64_encode($pub . ':' . $priv);
list($code, $body, $curlErr) = curlJson($base . '/api/authorizations/token', [
    'Authorization: Basic ' . $basic,
    'Accept: application/json',
], '');
echo '<div class="k">HTTP status:</div> ' . ($code === 200 ? "<span class='ok'>$code</span>" : "<span class='err'>$code</span>");
if ($curlErr) echo '<br><span class="err">cURL error: ' . htmlspecialchars($curlErr) . '</span>';
$token = '';
if ($code === 200) {
    $dec = json_decode($body, true);
    $token = $dec['token'] ?? '';
    echo '<br><span class="ok">✅ Token obtenido</span> (' . ($token ? 'presente' : '<span class="err">pero SIN campo token</span>') . ')';
    if (!$token) echo '<pre>' . htmlspecialchars(substr($body, 0, 500)) . '</pre>';
} else {
    echo '<br><span class="err">❌ Falló la autenticación. Respuesta de eCartPay:</span>';
    echo '<pre>' . htmlspecialchars(substr($body, 0, 800)) . '</pre>';
    echo '<div class="k">Causas típicas: llaves incorrectas, o sandbox/llaves no coinciden con el endpoint de arriba.</div>';
}
echo '</div>';

if (!$token) exit;

// 3) Crear orden de prueba
echo '<div class="card"><h2>3) Crear orden de prueba (POST /api/orders)</h2>';
$payload = json_encode([
    'currency'     => 'MXN',
    'email'        => 'diagnostico@test.com',
    'first_name'   => 'Diag',
    'last_name'    => 'Nostico',
    'phone'        => '5555555555',
    'items'        => [['name' => 'Prueba diagnóstico', 'quantity' => 1, 'price' => 10.00]],
    'notify_url'   => 'https://botikit.shop/ssbpharma/api/ecartpay.php?action=webhook',
    'redirect_url' => 'https://botikit.shop/ssbpharma/index.php',
]);
list($code2, $body2, $curlErr2) = curlJson($base . '/api/orders', [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
    'Accept: application/json',
], $payload);
echo '<div class="k">HTTP status:</div> ' . (($code2 >= 200 && $code2 < 300) ? "<span class='ok'>$code2</span>" : "<span class='err'>$code2</span>");
if ($curlErr2) echo '<br><span class="err">cURL error: ' . htmlspecialchars($curlErr2) . '</span>';
$ord = json_decode($body2, true);
if (($code2 >= 200 && $code2 < 300) && !empty($ord['pay_link'])) {
    echo '<br><span class="ok">✅ ¡Orden creada! eCartPay SÍ funciona. pay_link:</span>';
    echo '<pre>' . htmlspecialchars($ord['pay_link']) . '</pre>';
    echo '<div class="k">Si la compra real igual falla, el problema está en el checkout, no en la API.</div>';
} else {
    echo '<br><span class="err">❌ Falló crear la orden. Respuesta de eCartPay:</span>';
    echo '<pre>' . htmlspecialchars(substr($body2, 0, 1200)) . '</pre>';
}
echo '</div>';
echo '<div class="card" style="background:#fef3c7;border-color:#fde68a">⚠️ Borra este archivo (diag-ecartpay.php) al terminar.</div>';
