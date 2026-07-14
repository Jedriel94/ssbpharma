<?php
/**
 * Servidor de archivos fiscales con autenticación
 * Solo permite descargar documentos al admin o al cliente propietario
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Cliente.php';

// Verificar que se proporcionó un archivo
if (!isset($_GET['file'])) {
    http_response_code(400);
    die('Archivo no especificado');
}

$filename = basename($_GET['file']); // Seguridad: solo nombre de archivo, no rutas
$filepath = __DIR__ . '/uploads/fiscales/' . $filename;

// Verificar que el archivo existe
if (!file_exists($filepath)) {
    http_response_code(404);
    die('Archivo no encontrado');
}

// Extraer el teléfono del nombre del archivo (formato: constancia_TELEFONO_timestamp.ext)
preg_match('/constancia_(\d{10})_/', $filename, $matches);
$telefono_archivo = $matches[1] ?? null;

if (!$telefono_archivo) {
    http_response_code(400);
    die('Formato de archivo inválido');
}

// Verificar autenticación
$es_admin = isset($_SESSION['admin_id']);
$es_cliente_verificado = isset($_SESSION['cliente_verificado']) && $_SESSION['cliente_verificado'] === $telefono_archivo;

if (!$es_admin && !$es_cliente_verificado) {
    http_response_code(403);
    die('Acceso no autorizado. Debe ser administrador o el cliente propietario del documento.');
}

// Servir el archivo
$mime_type = mime_content_type($filepath);
$file_size = filesize($filepath);

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . $file_size);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=3600');

readfile($filepath);
exit;
