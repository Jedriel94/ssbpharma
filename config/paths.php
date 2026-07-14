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
