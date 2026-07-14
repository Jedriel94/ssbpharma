<?php
// Middleware para proteger páginas de administrador
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cargar configuración de rutas
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../models/Administrador.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . url('login-admin.php?error=acceso_denegado'));
    exit;
}

$authAdminModel = new Administrador();
$authAdminActual = $authAdminModel->getById($_SESSION['admin_id']);

if (!$authAdminActual) {
    session_destroy();
    header('Location: ' . url('login-admin.php?error=acceso_denegado'));
    exit;
}

// Cachear rol en sesión para uso en header.php y otros includes
$_SESSION['admin_rol_codigo'] = $authAdminActual['rol_codigo'] ?? '';

if (($authAdminActual['rol_codigo'] ?? '') === 'representante') {
    header('Location: ' . url('representante/index.php'));
    exit;
}

$rolesSoloMetricas = ['director_general', 'director_unidad', 'gerente', 'viewer'];
$paginasReportesMetricas = ['dashboard.php', 'mi-perfil.php', 'reporte-ventas.php', 'reporte-consignacion.php', 'inventario-consignacion.php'];
$scriptActual = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (in_array($authAdminActual['rol_codigo'] ?? '', $rolesSoloMetricas, true) && !in_array($scriptActual, $paginasReportesMetricas, true)) {
    header('Location: ' . url('admin/dashboard.php?error=solo_metricas'));
    exit;
}
