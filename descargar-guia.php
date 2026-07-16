<?php
/**
 * Sirve las guias de consignacion validando sesion.
 * Solo el admin, o el representante dueño de la solicitud, pueden descargarlas.
 * La carpeta esta bloqueada por .htaccess, asi que este es el unico camino.
 */

session_start();
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Administrador.php';
require_once __DIR__ . '/models/SolicitudConsignacion.php';

if (!isset($_GET['file'])) {
    http_response_code(400);
    die('Archivo no especificado');
}

$filename = basename($_GET['file']); // Solo el nombre: corta cualquier ../
$filepath = uploads_dir('guias_consignacion') . '/' . $filename;

// El nombre lo genera admin/solicitudes-consignacion.php:
// guia_consignacion_{solicitudId}_{timestamp}.ext
if (!preg_match('/^guia_consignacion_(\d+)_\d+\.[A-Za-z0-9]+$/', $filename, $m)) {
    http_response_code(400);
    die('Formato de archivo invalido');
}
$solicitudId = (int)$m[1];

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die('Acceso no autorizado. Inicia sesion.');
}

$usuario = (new Administrador())->getById($_SESSION['admin_id']);
if (!$usuario) {
    http_response_code(403);
    die('Acceso no autorizado.');
}

$esRepresentante = ($usuario['rol_codigo'] ?? '') === 'representante';

if ($esRepresentante) {
    // Un representante solo ve las guias de sus propias solicitudes.
    $solicitud = (new SolicitudConsignacion())->getById($solicitudId);
    if (!$solicitud || (int)$solicitud['representante_admin_id'] !== (int)$usuario['id']) {
        http_response_code(403);
        die('Acceso no autorizado. Esta guia no pertenece a tus solicitudes.');
    }
}

if (!file_exists($filepath)) {
    http_response_code(404);
    die('Archivo no encontrado');
}

header('Content-Type: ' . mime_content_type($filepath));
header('Content-Length: ' . filesize($filepath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=3600');
readfile($filepath);
exit;
