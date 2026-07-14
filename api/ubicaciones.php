<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

$action = $_GET['action'] ?? '';
$db     = Database::getInstance()->getConnection();

try {
    switch ($action) {
        case 'estados':
            $stmt = $db->query("SELECT id, nombre FROM estados ORDER BY nombre");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'municipios':
            $estado = trim($_GET['estado'] ?? '');
            if (!$estado) {
                echo json_encode(['success' => false, 'message' => 'Falta estado']);
                break;
            }
            $stmt = $db->prepare(
                "SELECT m.nombre FROM municipios m
                 INNER JOIN estados e ON e.id = m.estado_id
                 WHERE e.nombre = ?
                 ORDER BY m.nombre"
            );
            $stmt->execute([$estado]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error interno']);
}
