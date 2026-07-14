<?php
/**
 * Página de instalación de un solo uso para ssbpharma.
 *
 * Se conecta a la MISMA base que usa el sitio (config/database.local.php),
 * así que es IMPOSIBLE que le pegue a otra base. Siembra: roles, catálogos
 * (estados, municipios, especialidades), configuración (casillas vacías) y
 * métodos de pago (sin credenciales). Asegura que exista un usuario admin.
 *
 * USO:  botikit.shop/ssbpharma/instalar.php?token=ssb-setup-2026
 * ⚠️  BORRA ESTE ARCHIVO cuando termines.
 */

$TOKEN = 'ssb-setup-2026';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Instalación ssbpharma</title><style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#1e293b;background:#f8fafc}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.25rem 1.5rem;margin:1rem 0;box-shadow:0 1px 3px rgba(0,0,0,.05)}
  h1{font-size:1.5rem} .ok{color:#16a34a} .err{color:#dc2626} .muted{color:#64748b}
  .row{padding:.35rem 0;border-bottom:1px solid #f1f5f9} .row:last-child{border:0}
  .pill{display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.8rem;font-weight:600}
  .green{background:#dcfce7;color:#166534} .blue{background:#dbeafe;color:#1e40af}
  code{background:#f1f5f9;padding:.1rem .35rem;border-radius:6px}
  .warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:1rem;border-radius:10px}
</style></head><body>';
echo '<h1>🛠️ Instalación de datos — ssbpharma</h1>';

// --- Guard por token ---
if (($_GET['token'] ?? '') !== $TOKEN) {
    echo '<div class="card warn">🔒 Falta el token de seguridad.<br>Entra con: <code>instalar.php?token=' . htmlspecialchars($TOKEN) . '</code></div></body></html>';
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo '<div class="card err">No se pudo conectar a la base de datos: ' . htmlspecialchars($e->getMessage()) . '</div></body></html>';
    exit;
}

// --- Mostrar a qué base nos conectamos (para confirmar visualmente) ---
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo '<div class="card"><strong>Conectado a la base:</strong> <span class="pill blue">' . htmlspecialchars($dbName) . '</span>';
if (stripos($dbName, 'ssbpharma') === false) {
    echo '<br><span class="err">⚠️ Esta NO parece ser ssbpharma. Revisa config/database.local.php antes de continuar.</span>';
}
echo '</div>';

$resultados = [];
function paso(&$r, $nombre, $ok, $detalle = '') { $r[] = ['n' => $nombre, 'ok' => $ok, 'd' => $detalle]; }

// --- Ejecutar setup_datos.sql ---
$sqlFile = __DIR__ . '/database/setup_datos.sql';
if (!is_file($sqlFile)) {
    echo '<div class="card err">No se encontró database/setup_datos.sql. ¿Se desplegó bien el repo?</div></body></html>';
    exit;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

$sql = file_get_contents($sqlFile);
// Quitar comentarios de línea y partir por ';'
$sql = preg_replace('/^--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$okCount = 0; $errCount = 0;
foreach ($statements as $st) {
    if ($st === '') continue;
    try {
        $pdo->exec($st);
        $okCount++;
    } catch (Throwable $e) {
        $errCount++;
        $tabla = '';
        if (preg_match('/(?:INTO|FROM)\s+`?([a-z_]+)`?/i', $st, $m)) $tabla = $m[1];
        paso($resultados, 'Error en ' . ($tabla ?: 'sentencia'), false, $e->getMessage());
    }
}
paso($resultados, 'Sentencias de datos ejecutadas', $errCount === 0, "$okCount correctas, $errCount con error");

// --- Conteos de verificación ---
foreach (['roles', 'estados', 'municipios', 'especialidades', 'configuracion', 'metodos_pago'] as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        paso($resultados, "Tabla $t", $c > 0, "$c filas");
    } catch (Throwable $e) {
        paso($resultados, "Tabla $t", false, $e->getMessage());
    }
}

// --- Asegurar usuario admin (sin clobberar el existente) ---
$adminMsg = '';
try {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM administradores")->fetchColumn();
    if ($n === 0) {
        $pass = 'SsbPharma' . random_int(1000, 9999) . '!';
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO administradores (usuario, nombre, email, rol_id, password, activo) VALUES ('admin','Administrador','xavier.xjpm94@gmail.com',1,?,1)");
        $ins->execute([$hash]);
        $adminMsg = 'Se creó el admin. Correo: <code>xavier.xjpm94@gmail.com</code> · Contraseña: <code>' . htmlspecialchars($pass) . '</code> (cámbiala al entrar)';
        paso($resultados, 'Usuario admin', true, 'creado');
    } else {
        $adminMsg = "Ya existe un usuario admin ($n). Tu login actual sigue funcionando.";
        paso($resultados, 'Usuario admin', true, "ya existía ($n)");
    }
} catch (Throwable $e) {
    paso($resultados, 'Usuario admin', false, $e->getMessage());
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// --- Reporte ---
echo '<div class="card"><h2>Resultado</h2>';
foreach ($resultados as $r) {
    $icon = $r['ok'] ? '<span class="ok">✅</span>' : '<span class="err">❌</span>';
    echo '<div class="row">' . $icon . ' <strong>' . htmlspecialchars($r['n']) . '</strong> <span class="muted">' . htmlspecialchars($r['d']) . '</span></div>';
}
echo '</div>';

echo '<div class="card"><h2>👤 Acceso admin</h2><p>' . $adminMsg . '</p>';
echo '<p>Entra en: <a href="login-admin.php">login-admin.php</a></p></div>';

echo '<div class="card warn">⚠️ <strong>Importante:</strong> por seguridad, <strong>borra este archivo <code>instalar.php</code></strong> ahora que ya corrió (desde el Administrador de archivos de Hostinger, o dime y lo quito del repo).</div>';

echo '</body></html>';
