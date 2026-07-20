<?php
/**
 * Borrado puntual de pedidos de prueba. USO ÚNICO — borrar este archivo al terminar.
 *
 * Paso 1 (solo mira):  borrar-pedidos.php?token=ssb-borrar-2026
 * Paso 2 (borra):      borrar-pedidos.php?token=ssb-borrar-2026&confirmar=BORRAR
 *
 * Los IDs están fijos en el código: no hay forma de borrar otro pedido por error.
 * Las tablas hijas (detalle, mensajes, kit_ventas, etc.) se van solas por ON DELETE CASCADE.
 */

$TOKEN = 'ssb-borrar-2026';
$IDS   = [2, 4, 5, 6, 7, 8, 9]; // = folios #0002, #0004, #0005, #0006, #0007, #0008, #0009

require __DIR__ . '/config/database.php';
header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><meta charset="utf-8"><title>Borrar pedidos</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#1e293b;background:#f8fafc}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin:12px 0}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #eef2f7}
th{color:#64748b;font-size:12px;text-transform:uppercase}.ok{color:#16a34a;font-weight:700}.err{color:#dc2626;font-weight:700}
.btn{display:inline-block;background:#dc2626;color:#fff;font-weight:700;padding:12px 20px;border-radius:10px;text-decoration:none;margin-top:8px}
code{background:#f1f5f9;padding:2px 6px;border-radius:5px}</style>';
echo '<h1>🗑️ Borrar pedidos de prueba</h1>';

if (($_GET['token'] ?? '') !== $TOKEN) {
    echo '<div class="card err">Falta token.</div>';
    exit;
}

$db = Database::getInstance()->getConnection();
echo '<div class="card">Base de datos: <code>' . htmlspecialchars(DB_NAME) . '</code></div>';

$in = implode(',', array_map('intval', $IDS));

// ── Mostrar SIEMPRE qué pedidos se van (o se fueron) ──
$rows = $db->query("
    SELECT p.id, p.estado, p.total, p.created_at,
           c.nombre AS cliente, c.telefono,
           (SELECT COUNT(*) FROM detalle_pedidos d WHERE d.pedido_id = p.id) AS n_detalle,
           (SELECT COUNT(*) FROM mensajes_pedido m WHERE m.pedido_id = p.id) AS n_msg
    FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id
    WHERE p.id IN ($in) ORDER BY p.id
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo '<div class="card ok">✅ Ya no existe ninguno de esos pedidos. Nada que borrar.<br>Borra este archivo (<code>borrar-pedidos.php</code>).</div>';
    exit;
}

echo '<div class="card"><h2>Se van a borrar estos ' . count($rows) . ' pedidos</h2><table>';
echo '<tr><th>Folio</th><th>Cliente</th><th>Tel</th><th>Estado</th><th>Total</th><th>Fecha</th><th>Hijos</th></tr>';
foreach ($rows as $r) {
    echo '<tr><td>#' . str_pad($r['id'], 4, '0', STR_PAD_LEFT) . '</td>'
        . '<td>' . htmlspecialchars($r['cliente'] ?? '—') . '</td>'
        . '<td>' . htmlspecialchars($r['telefono'] ?? '—') . '</td>'
        . '<td>' . htmlspecialchars($r['estado'] ?? '—') . '</td>'
        . '<td>$' . number_format((float)$r['total'], 2) . '</td>'
        . '<td>' . htmlspecialchars($r['created_at'] ?? '—') . '</td>'
        . '<td>' . (int)$r['n_detalle'] . ' det · ' . (int)$r['n_msg'] . ' msg</td></tr>';
}
echo '</table></div>';

if (($_GET['confirmar'] ?? '') !== 'BORRAR') {
    echo '<div class="card"><strong>Revisa la tabla de arriba.</strong> Si son los correctos, dale al botón. '
       . 'Esto es <span class="err">permanente</span> y no se puede deshacer.<br>'
       . '<a class="btn" href="?token=' . urlencode($TOKEN) . '&confirmar=BORRAR">Sí, borrar estos ' . count($rows) . ' pedidos</a></div>';
    exit;
}

// ── Borrado real, en transacción ──
try {
    $db->beginTransaction();
    $stmt = $db->prepare("DELETE FROM pedidos WHERE id IN ($in)");
    $stmt->execute();
    $n = $stmt->rowCount();
    $db->commit();
    echo '<div class="card ok">✅ Listo: ' . $n . ' pedidos borrados (con todos sus detalles y mensajes).<br><br>'
       . '⚠️ Ahora <strong>borra este archivo</strong> (<code>borrar-pedidos.php</code>) desde el Administrador de archivos.</div>';
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo '<div class="card err">No se borró nada (se revirtió todo): ' . htmlspecialchars($e->getMessage()) . '</div>';
}
