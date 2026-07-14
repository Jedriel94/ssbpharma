<?php
// Test sin autenticación para debug
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance()->getConnection();
    
    $query = "SELECT * FROM productos WHERE activo = 1 ORDER BY producto ASC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total' => count($productos),
        'productos' => $productos,
        'campos' => !empty($productos) ? array_keys($productos[0]) : [],
        'buscar_bacfil' => array_filter($productos, function($p) {
            return stripos($p['producto'], 'bacfil') !== false;
        })
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
