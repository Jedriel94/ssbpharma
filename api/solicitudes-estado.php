<?php
/**
 * API: Estado actual de solicitudes del representante autenticado
 * Usado para polling en representante/solicitudes.php y representante/index.php
 */
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/SolicitudConsignacion.php';

header('Content-Type: application/json');

$solicitudModel = new SolicitudConsignacion();
$solicitudes    = $solicitudModel->getByRepresentanteAdmin($representanteAdminId, 100);

$estados = [];
foreach ($solicitudes as $s) {
    $estados[(int)$s['id']] = $s['estado'];
}

echo json_encode(['ok' => true, 'estados' => $estados]);
