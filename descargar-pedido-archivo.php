<?php
/**
 * Sirve los archivos adjuntos de un pedido validando sesion.
 *
 *   comprobante    -> comprobante de pago    (admin y representante dueño)
 *   envio          -> comprobante de envio   (+ el cliente del pedido)
 *   factura_pdf    -> factura PDF            (+ el cliente del pedido)
 *   factura_xml    -> factura XML            (+ el cliente del pedido)
 *
 * El nombre del archivo se lee de la base de datos, nunca de la URL: asi no hay
 * forma de pedir un archivo que no sea el de ese pedido.
 *
 * Uso: descargar-pedido-archivo.php?pedido=123&tipo=comprobante
 */

session_start();
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Pedido.php';
require_once __DIR__ . '/models/Administrador.php';

$TIPOS = [
    'comprobante' => ['campo' => 'comprobante_pago',   'carpeta' => 'comprobantes',       'cliente_puede' => false],
    'envio'       => ['campo' => 'comprobante_envio',  'carpeta' => 'comprobantes_envio', 'cliente_puede' => true],
    'factura_pdf' => ['campo' => 'factura_pdf',        'carpeta' => 'facturas',           'cliente_puede' => true],
    'factura_xml' => ['campo' => 'factura_xml',        'carpeta' => 'facturas',           'cliente_puede' => true],
];

$tipo = $_GET['tipo'] ?? '';
$pedidoId = (int)($_GET['pedido'] ?? 0);

if (!isset($TIPOS[$tipo]) || $pedidoId <= 0) {
    http_response_code(400);
    die('Solicitud invalida');
}
$conf = $TIPOS[$tipo];

$pedido = (new Pedido())->getById($pedidoId);
if (!$pedido) {
    http_response_code(404);
    die('Pedido no encontrado');
}

// ── Permisos ──────────────────────────────────────────────────────────────
$permitido = false;

if (isset($_SESSION['admin_id'])) {
    $usuario = (new Administrador())->getById($_SESSION['admin_id']);
    if ($usuario) {
        if (($usuario['rol_codigo'] ?? '') === 'representante') {
            // Un representante solo ve los archivos de sus propios pedidos.
            $permitido = (int)($pedido['representante_admin_id'] ?? 0) === (int)$usuario['id'];
        } else {
            $permitido = true; // admin y demas roles internos
        }
    }
}

if (!$permitido && $conf['cliente_puede']) {
    // El cliente que ya se autentico por telefono en seguimiento.php
    $telefonoSesion = $_SESSION['cliente_autenticado'] ?? $_SESSION['cliente_verificado'] ?? null;
    $permitido = $telefonoSesion !== null
              && isset($pedido['telefono'])
              && hash_equals((string)$pedido['telefono'], (string)$telefonoSesion);
}

if (!$permitido) {
    http_response_code(403);
    die('Acceso no autorizado. Inicia sesion para ver este archivo.');
}

// ── Servir ────────────────────────────────────────────────────────────────
$nombre = trim((string)($pedido[$conf['campo']] ?? ''));
if ($nombre === '') {
    http_response_code(404);
    die('Este pedido no tiene ese archivo');
}

$filepath = uploads_dir($conf['carpeta']) . '/' . basename($nombre);
if (!file_exists($filepath)) {
    http_response_code(404);
    die('El archivo ya no esta disponible');
}

// El XML se fuerza a descarga; lo demas se puede ver en el navegador.
$esXml = strtolower(pathinfo($filepath, PATHINFO_EXTENSION)) === 'xml';
header('Content-Type: ' . ($esXml ? 'application/octet-stream' : mime_content_type($filepath)));
header('Content-Length: ' . filesize($filepath));
header('Content-Disposition: ' . ($esXml ? 'attachment' : 'inline') . '; filename="' . basename($nombre) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($filepath);
exit;
