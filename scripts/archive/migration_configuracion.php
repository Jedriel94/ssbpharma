<?php
require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Crear tabla de configuración
    $sql = "
    CREATE TABLE IF NOT EXISTS configuracion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clave VARCHAR(100) NOT NULL UNIQUE,
        valor DECIMAL(10,2) NOT NULL,
        descripcion VARCHAR(255) NOT NULL,
        fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_clave (clave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql);
    echo "✅ Tabla 'configuracion' creada exitosamente\n";
    
    // Insertar valores iniciales
    $insert = "
    INSERT INTO configuracion (clave, valor, descripcion) VALUES
    ('monto_minimo_envio_gratis', 1900.00, 'Monto mínimo para envío gratis'),
    ('costo_envio', 160.00, 'Costo de envío cuando no se alcanza el mínimo')
    ON DUPLICATE KEY UPDATE valor = VALUES(valor);
    ";
    
    $pdo->exec($insert);
    echo "✅ Configuración inicial insertada correctamente\n";
    
    // Mostrar configuración actual
    $stmt = $pdo->query("SELECT * FROM configuracion ORDER BY id");
    $configs = $stmt->fetchAll();
    
    echo "\n📋 Configuración actual:\n";
    foreach ($configs as $config) {
        echo "  • {$config['clave']}: \${$config['valor']} - {$config['descripcion']}\n";
    }
    
    echo "\n✅ Migración completada exitosamente\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
