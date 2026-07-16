<?php
/**
 * Migración de un solo uso: agrega la columna `orden` a la tabla productos.
 * Se conecta a la MISMA base que usa el sitio (config/database.local.php),
 * así que es imposible que le pegue a otra base.
 *
 * USO:  botikit.shop/ssbpharma/migrar-orden.php?token=ssb-migra-2026
 * ⚠️  BORRA ESTE ARCHIVO cuando termines.
 */

$TOKEN = 'ssb-migra-2026';
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Migración orden</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;color:#1e293b}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin:12px 0}
.ok{color:#16a34a;font-weight:700}.err{color:#dc2626;font-weight:700}
.pill{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:999px;font-weight:600}</style>';
echo '<h1>🔧 Migración: columna orden en productos</h1>';

if (($_GET['token'] ?? '') !== $TOKEN) {
    echo '<div class="card err">Falta token. Usa: migrar-orden.php?token=' . htmlspecialchars($TOKEN) . '</div>';
    exit;
}

require_once __DIR__ . '/config/database.php';
$pdo = Database::getInstance()->getConnection();

$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo '<div class="card">Conectado a la base: <span class="pill">' . htmlspecialchars($dbName) . '</span></div>';

try {
    $existe = $pdo->query("SHOW COLUMNS FROM productos LIKE 'orden'")->fetch();
    if ($existe) {
        echo '<div class="card"><span class="ok">✅ La columna <code>orden</code> ya existe.</span> No hay nada que migrar.</div>';
    } else {
        $pdo->exec("ALTER TABLE productos ADD COLUMN orden INT NOT NULL DEFAULT 0 AFTER activo");
        echo '<div class="card"><span class="ok">✅ Columna <code>orden</code> agregada correctamente.</span></div>';
    }
    $n = $pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
    echo '<div class="card">Productos en la tabla: <strong>' . (int)$n . '</strong>. Ya puedes reordenarlos en Admin → Productos (arrastrando).</div>';
} catch (Throwable $e) {
    echo '<div class="card err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '<div class="card" style="background:#fef3c7;border-color:#fde68a">⚠️ Borra este archivo (migrar-orden.php) al terminar.</div>';
