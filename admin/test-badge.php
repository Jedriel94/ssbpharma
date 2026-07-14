<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/MensajePedido.php';

$mensajeModel = new MensajePedido();

// Probar con el pedido 18
$pedido_id = 18;
$count = $mensajeModel->contarNoLeidosAdmin($pedido_id);

echo "<h1>Test de Mensajes No Leídos</h1>";
echo "<p>Pedido ID: {$pedido_id}</p>";
echo "<p>Mensajes NO leídos del cliente: <strong>{$count}</strong></p>";

// Mostrar todos los mensajes del pedido
$mensajes = $mensajeModel->getByPedido($pedido_id);
echo "<h2>Todos los mensajes:</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Tipo Usuario</th><th>Mensaje</th><th>Leído</th><th>Fecha</th></tr>";
foreach ($mensajes as $msg) {
    $leido_texto = $msg['leido'] == 1 ? 'Sí' : 'No';
    echo "<tr>";
    echo "<td>{$msg['id']}</td>";
    echo "<td>{$msg['usuario_tipo']}</td>";
    echo "<td>{$msg['mensaje']}</td>";
    echo "<td>{$leido_texto}</td>";
    echo "<td>{$msg['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// Probar el endpoint AJAX
echo "<h2>Test AJAX:</h2>";
echo "<button onclick='testAjax()'>Probar Actualización de Badges</button>";
echo "<div id='resultado'></div>";

?>

<script>
async function testAjax() {
    const pedidosActivos = [18]; // Probar con pedido 18
    
    const formData = new FormData();
    formData.append('action', 'obtener_conteo_mensajes');
    formData.append('pedidos_ids', JSON.stringify(pedidosActivos));
    
    const response = await fetch('kanban.php', {
        method: 'POST',
        body: formData
    });
    
    const data = await response.json();
    
    document.getElementById('resultado').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
}
</script>
