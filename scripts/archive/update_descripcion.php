<?php
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$pdo->exec("UPDATE configuracion SET descripcion = 'Costo de envío' WHERE clave = 'costo_envio'");

echo "✅ Descripción actualizada correctamente\n";
