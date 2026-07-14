<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../models/Administrador.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . url('login-admin.php?error=acceso_denegado'));
    exit;
}

$authRepresentanteModel = new Administrador();
$usuarioRepresentante = $authRepresentanteModel->getById($_SESSION['admin_id']);

if (!$usuarioRepresentante || ($usuarioRepresentante['rol_codigo'] ?? '') !== 'representante' || empty($usuarioRepresentante['representante_codigo'])) {
    header('Location: ' . url('admin/dashboard.php?error=sin_permiso_representante'));
    exit;
}

$representanteAdminId = (int)$usuarioRepresentante['id'];
$representanteCodigo = $usuarioRepresentante['representante_codigo'];
$representanteNombre = $usuarioRepresentante['nombre'] ?? $usuarioRepresentante['representante_nombre'] ?? 'Representante';
