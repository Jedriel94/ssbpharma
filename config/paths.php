<?php
/**
 * Configuracion de rutas del proyecto.
 *
 * BASE_PATH se detecta automaticamente desde la ubicacion real del proyecto:
 * - C:\laragon\www\proceso                    => /proceso/
 * - /home/user/domains/site/public_html       => /
 * - /home/user/domains/site/public_html/test  => /test/
 */

if (!defined('BASE_PATH')) {
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $projectRoot  = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $basePath     = '/';

    if ($documentRoot !== '' && stripos($projectRoot, $documentRoot) === 0) {
        $relative = trim(str_replace('\\', '/', substr($projectRoot, strlen($documentRoot))), '/');
        $basePath = $relative === '' ? '/' : '/' . $relative . '/';
    } else {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $baseDir    = trim(dirname($scriptName), '/');
        $parts      = $baseDir === '' ? [] : explode('/', $baseDir);

        if (!empty($parts) && in_array(end($parts), ['admin', 'api', 'representante'], true)) {
            array_pop($parts);
        }

        $basePath = empty($parts) ? '/' : '/' . implode('/', $parts) . '/';
    }

    define('BASE_PATH', $basePath);
}

// Funcion helper para generar URLs
function url($path = '') {
    $path = ltrim($path, '/');
    return BASE_PATH . $path;
}

// URL absoluta con dominio (para correos, redirecciones externas, etc.)
function absUrl($path = '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

// Funcion para assets (CSS, JS, imagenes)
function asset($path) {
    $fullPath = __DIR__ . '/../' . ltrim($path, '/');
    $version  = file_exists($fullPath) ? filemtime($fullPath) : 1;
    return url($path) . '?v=' . $version;
}

/**
 * ── ARCHIVOS SUBIDOS (imagenes de productos/kits, comprobantes, facturas) ──
 *
 * Por defecto viven dentro del proyecto (uploads/), igual que siempre.
 *
 * En el servidor se pueden sacar FUERA de la carpeta que despliega git,
 * para que un redespliegue NUNCA pueda borrarlos. Para eso, define estas
 * constantes en config/database.local.php (ese archivo no se despliega):
 *
 *   define('UPLOADS_DIR', '/home/uXXXXXX/domains/botikit.shop/public_html/ssbpharma_uploads');
 *   define('UPLOADS_URL', '/ssbpharma_uploads/');
 *
 * Si no se definen, todo sigue funcionando como hasta ahora.
 */

// Ruta en disco de la carpeta de subidas. Ej: uploads_dir('productos')
function uploads_dir($sub = '') {
    $base = (defined('UPLOADS_DIR') && UPLOADS_DIR !== '')
        ? rtrim(UPLOADS_DIR, '/\\')
        : rtrim(__DIR__ . '/../uploads', '/\\');
    $sub = trim($sub, '/\\');
    return $sub === '' ? $base : $base . '/' . $sub;
}

// URL publica de la carpeta de subidas. Ej: uploads_url('productos/foto.jpg')
function uploads_url($sub = '') {
    $base = (defined('UPLOADS_URL') && UPLOADS_URL !== '')
        ? rtrim(UPLOADS_URL, '/')
        : rtrim(url('uploads'), '/');
    $sub = ltrim($sub, '/');
    return $sub === '' ? $base . '/' : $base . '/' . $sub;
}
