<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/MensajePedido.php';
require_once __DIR__ . '/../models/Pedido.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$action = $_POST['action'] ?? '';

// Verificar nuevos mensajes
if ($action === 'check_new_messages') {
    $pedidoId = $_POST['pedido_id'] ?? 0;
    $userType = $_POST['user_type'] ?? 'cliente'; // 'cliente' o 'admin'
    $lastCheck = $_POST['last_check'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
    
    $mensajeModel = new MensajePedido($db);
    
    // Determinar qué tipo de mensajes buscar
    if ($userType === 'cliente') {
        // Cliente busca mensajes del admin
        $query = "SELECT COUNT(*) as count 
                  FROM mensajes_pedidos 
                  WHERE pedido_id = :pedido_id 
                  AND es_admin = 1 
                  AND fecha_creacion > :last_check";
    } else {
        // Admin busca mensajes del cliente
        $query = "SELECT COUNT(*) as count 
                  FROM mensajes_pedidos 
                  WHERE pedido_id = :pedido_id 
                  AND es_admin = 0 
                  AND fecha_creacion > :last_check";
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':pedido_id', $pedidoId);
    $stmt->bindParam(':last_check', $lastCheck);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = $result['count'] ?? 0;
    
    echo json_encode([
        'has_new_messages' => $count > 0,
        'count' => $count,
        'last_check' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Verificar cambio de estado (solo para clientes)
if ($action === 'check_status_change') {
    $pedidoId = $_POST['pedido_id'] ?? 0;
    $lastCheck = $_POST['last_check'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
    
    // Verificar si hay cambios de estado registrados
    $query = "SELECT estado, fecha_actualizacion 
              FROM pedidos 
              WHERE id = :pedido_id 
              AND fecha_actualizacion > :last_check";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':pedido_id', $pedidoId);
    $stmt->bindParam(':last_check', $lastCheck);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'status_changed' => true,
            'new_status' => $result['estado'],
            'updated_at' => $result['fecha_actualizacion']
        ]);
    } else {
        echo json_encode([
            'status_changed' => false
        ]);
    }
    exit;
}

// Si no se reconoce la acción
echo json_encode(['error' => 'Acción no válida']);
