<?php
/**
 * Migración de un solo uso: saca la carpeta de subidas FUERA de la carpeta que despliega git.
 *
 * Qué hace:
 *   1. Copia todo lo de  <proyecto>/uploads/   ->  <proyecto>/../ssbpharma_uploads/
 *   2. Agrega UPLOADS_DIR y UPLOADS_URL a config/database.local.php (que no se despliega)
 *
 * Resultado: un redespliegue ya NUNCA puede borrar tus imágenes ni los comprobantes/facturas.
 *
 * USO:  botikit.shop/ssbpharma/mover-uploads.php?token=ssb-uploads-2026
 * ⚠️  BORRA ESTE ARCHIVO cuando termines.
 */

$TOKEN = 'ssb-uploads-2026';
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Mover uploads</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;color:#1e293b;background:#f8fafc}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin:12px 0}
.ok{color:#16a34a;font-weight:700}.err{color:#dc2626;font-weight:700}.k{color:#64748b}
code{background:#f1f5f9;padding:2px 6px;border-radius:5px;font-size:13px}
pre{background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;overflow:auto;font-size:12px}</style>';
echo '<h1>📦 Mover archivos subidos fuera del despliegue</h1>';

if (($_GET['token'] ?? '') !== $TOKEN) {
    echo '<div class="card err">Falta token. Usa: mover-uploads.php?token=' . htmlspecialchars($TOKEN) . '</div>';
    exit;
}

$projectRoot = __DIR__;
$src = $projectRoot . '/uploads';
$dst = dirname($projectRoot) . '/ssbpharma_uploads';
$localCfg = $projectRoot . '/config/database.local.php';

echo '<div class="card"><div class="k">Origen:</div> <code>' . htmlspecialchars($src) . '</code>';
echo '<br><div class="k">Destino (fuera del despliegue):</div> <code>' . htmlspecialchars($dst) . '</code></div>';

if (!is_dir($src)) {
    echo '<div class="card err">No existe la carpeta uploads de origen.</div>';
    exit;
}

// ── 1. Copiar recursivamente ──────────────────────────────────────────────
function copiarDir($from, $to, &$n, &$errs) {
    if (!is_dir($to) && !@mkdir($to, 0755, true)) { $errs[] = "No se pudo crear $to"; return; }
    foreach (scandir($from) as $item) {
        if ($item === '.' || $item === '..') continue;
        $f = $from . '/' . $item;
        $t = $to . '/' . $item;
        if (is_dir($f)) {
            copiarDir($f, $t, $n, $errs);
        } else {
            if (file_exists($t) && filesize($t) === filesize($f)) { $n['saltados']++; continue; }
            if (@copy($f, $t)) { $n['copiados']++; } else { $errs[] = "No se pudo copiar $item"; }
        }
    }
}

$n = ['copiados' => 0, 'saltados' => 0];
$errs = [];
copiarDir($src, $dst, $n, $errs);

echo '<div class="card"><h2>1) Copia de archivos</h2>';
echo '<span class="ok">✅ Copiados: ' . $n['copiados'] . '</span> · <span class="k">Ya existían: ' . $n['saltados'] . '</span>';
if ($errs) {
    echo '<br><span class="err">Errores (' . count($errs) . '):</span><pre>' . htmlspecialchars(implode("\n", array_slice($errs, 0, 10))) . '</pre>';
}
echo '</div>';

// ── 2. Configurar database.local.php ──────────────────────────────────────
echo '<div class="card"><h2>2) Configuración</h2>';
if (!is_file($localCfg)) {
    echo '<span class="err">No se encontró config/database.local.php</span>';
} elseif (strpos(file_get_contents($localCfg), 'UPLOADS_DIR') !== false) {
    echo '<span class="ok">✅ Ya estaba configurado</span> (UPLOADS_DIR ya existe en database.local.php)';
} else {
    $bloque = "\n// ── Archivos subidos FUERA de la carpeta que despliega git ──\n"
            . "// Asi un redespliegue nunca puede borrarlos.\n"
            . "define('UPLOADS_DIR', __DIR__ . '/../../ssbpharma_uploads');\n"
            . "define('UPLOADS_URL', '/ssbpharma_uploads/');\n";
    if (@file_put_contents($localCfg, $bloque, FILE_APPEND) !== false) {
        echo '<span class="ok">✅ Configuración agregada a config/database.local.php</span><pre>' . htmlspecialchars(trim($bloque)) . '</pre>';
    } else {
        echo '<span class="err">No se pudo escribir config/database.local.php. Agrega estas 2 lineas a mano:</span><pre>' . htmlspecialchars(trim($bloque)) . '</pre>';
    }
}
echo '</div>';

// ── 3. Verificar ──────────────────────────────────────────────────────────
echo '<div class="card"><h2>3) Verificación</h2>';
$prods = is_dir($dst . '/productos') ? count(array_diff(scandir($dst . '/productos'), ['.', '..'])) : 0;
$comps = is_dir($dst . '/comprobantes') ? count(array_diff(scandir($dst . '/comprobantes'), ['.', '..'])) : 0;
echo 'Archivos en la carpeta nueva → <strong>productos:</strong> ' . $prods . ' · <strong>comprobantes:</strong> ' . $comps;
echo '<br><br><span class="k">Recarga tu tienda: las imágenes deben seguir viéndose, y ya viven fuera del despliegue.</span>';
echo '</div>';

echo '<div class="card" style="background:#fef3c7;border-color:#fde68a">⚠️ <strong>Borra este archivo</strong> (mover-uploads.php) al terminar.<br>'
   . 'La carpeta vieja <code>uploads/</code> se queda como respaldo — puedes borrarla despues de confirmar que todo se ve bien.</div>';
