<?php
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/RepresentanteInventario.php';

header('Content-Type: application/json');

$inventarioModel = new RepresentanteInventario();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'crear':
            $destino_id = (int)($_POST['destino_admin_id'] ?? 0);
            $producto_id = (int)($_POST['producto_id'] ?? 0);
            $cantidad    = (int)($_POST['cantidad'] ?? 0);
            $notas       = trim(strip_tags($_POST['notas'] ?? ''));

            if (!$destino_id || !$producto_id || $cantidad < 1) {
                throw new Exception('Datos incompletos');
            }

            $resultado = $inventarioModel->crearTraspaso(
                $representanteAdminId,
                $destino_id,
                $producto_id,
                $cantidad,
                $notas
            );
            echo json_encode($resultado);
            break;

        case 'confirmar':
            $traspaso_id = (int)($_POST['traspaso_id'] ?? 0);
            if (!$traspaso_id) throw new Exception('ID de traspaso inválido');

            $resultado = $inventarioModel->confirmarTraspaso($traspaso_id, $representanteAdminId);
            echo json_encode($resultado);
            break;

        case 'rechazar':
            $traspaso_id = (int)($_POST['traspaso_id'] ?? 0);
            if (!$traspaso_id) throw new Exception('ID de traspaso inválido');

            $resultado = $inventarioModel->rechazarTraspaso($traspaso_id, $representanteAdminId);
            echo json_encode($resultado);
            break;

        case 'cancelar':
            $traspaso_id = (int)($_POST['traspaso_id'] ?? 0);
            if (!$traspaso_id) throw new Exception('ID de traspaso inválido');

            $resultado = $inventarioModel->cancelarTraspaso($traspaso_id, $representanteAdminId);
            echo json_encode($resultado);
            break;

        case 'pendientes':
            echo json_encode([
                'success'   => true,
                'recibidos' => $inventarioModel->getTraspasosPendientesRecibidos($representanteAdminId),
                'enviados'  => $inventarioModel->getTraspasosPendientesEnviados($representanteAdminId),
            ]);
            break;

        default:
            throw new Exception('Acción no válida');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'mensaje' => $e->getMessage()]);
}
