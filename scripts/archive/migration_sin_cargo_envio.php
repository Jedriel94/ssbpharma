<?php
require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Agregar columna sin_cargo_envio a la tabla productos
    $sql = "ALTER TABLE productos ADD COLUMN sin_cargo_envio TINYINT(1) DEFAULT 0 AFTER activo";
    
    $pdo->exec($sql);
    echo "✅ Columna 'sin_cargo_envio' agregada a la tabla productos\n";
    
    // Mostrar productos actuales
    $stmt = $pdo->query("SELECT id, nombre, sin_cargo_envio FROM productos LIMIT 5");
    $productos = $stmt->fetchAll();
    
    echo "\n📋 Primeros productos (sin_cargo_envio por defecto = 0):\n";
    foreach ($productos as $producto) {
        echo "  • {$producto['nombre']}: sin_cargo_envio = {$producto['sin_cargo_envio']}\n";
    }
    
    echo "\n✅ Migración completada exitosamente\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "ℹ️  La columna 'sin_cargo_envio' ya existe\n";
    } else {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
