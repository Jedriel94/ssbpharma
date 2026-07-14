<?php
/**
 * API: Estado actual de TODAS las solicitudes (vista administrador)
 * Usado para polling en admin/solicitudes-consignacion.php
 */
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../models/SolicitudConsignacion.php';

header('Content-Type: application/json');

$solicitudModel = new SolicitudConsignacion();
$solicitudes    = $solicitudModel->getAll([]);

$estados = [];
foreach ($solicitudes as $s) {
    $estados[(int)$s['id']] = $s['estado'];
}

echo json_encode(['ok' => true, 'estados' => $estados]);
