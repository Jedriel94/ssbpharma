<?php
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../models/SolicitudConsignacion.php';
require_once __DIR__ . '/../models/RepresentantePerfil.php';
require_once __DIR__ . '/../utils/Mailer.php';

// ── AJAX: guardar dirección ───────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'guardar_direccion') {
    header('Content-Type: application/json');
    $pM   = new RepresentantePerfil();
    $perf = $pM->getByAdminId($representanteAdminId) ?? [];
    $pM->guardarParaUsuario($representanteAdminId, array_merge($perf, [
        'dir_calle'        => trim($_POST['dir_calle']        ?? ''),
        'dir_numero'       => trim($_POST['dir_numero']       ?? ''),
        'dir_colonia'      => trim($_POST['dir_colonia']      ?? ''),
        'dir_ciudad'       => trim($_POST['dir_ciudad']       ?? ''),
        'dir_estado'       => trim($_POST['dir_estado']       ?? ''),
        'dir_cp'           => trim($_POST['dir_cp']           ?? ''),
        'dir_referencias'  => trim($_POST['dir_referencias']  ?? ''),
        'dir_quien_recibe' => trim($_POST['dir_quien_recibe'] ?? ''),
    ]));
    echo json_encode(['ok' => true]);
    exit;
}

$productoModel = new Producto();
$solicitudModel = new SolicitudConsignacion();
$tagsPermitidos = $usuarioRepresentante['representante_tags_permitidos'] ?? null;
$productos = $productoModel->getAllActivosByTags($tagsPermitidos);
$mensaje = null;
$error = null;

// Cargar perfil del representante para la dirección de envío
$perfilModel = new RepresentantePerfil();
$miPerfil    = $perfilModel->getByAdminId($representanteAdminId) ?? [];
// Estados y municipios cargados dinámicamente vía js/ubicaciones.js

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = [];
    foreach ($_POST['productos'] ?? [] as $producto_id => $cantidad) {
        $cantidad = (int)$cantidad;
        if ($cantidad > 0) {
            $items[] = [
                'producto_id' => (int)$producto_id,
                'cantidad_solicitada' => $cantidad
            ];
        }
    }

    $resultado = $solicitudModel->crearPorAdmin(
        $representanteAdminId,
        $items,
        trim($_POST['notas_representante'] ?? '') ?: null,
        $representanteAdminId
    );

    if ($resultado['success']) {
        $solicitudId = str_pad((string)$resultado['solicitud_id'], 4, '0', STR_PAD_LEFT);

        // Enviar correo de recepción al representante
        try {
            // Usar el email de login (administradores.email) como fuente principal
            $email_rep = trim($usuarioRepresentante['email'] ?? '');
            $email_dest = Mailer::resolveRecipient($email_rep);
            if ($email_dest !== null && intval(Configuracion::get('email_solicitudes_activo', '1'))) {
                $sol_data    = $solicitudModel->getById($resultado['solicitud_id']);
                $sol_detalle = $solicitudModel->getDetalle($resultado['solicitud_id']);
                if ($sol_data) {
                    Mailer::sendSolicitudInventario(
                        $email_dest,
                        $representanteNombre,
                        $sol_data,
                        $sol_detalle
                    );
                }
            }
        } catch (Exception $e) {
            error_log('Error enviando correo solicitud inventario: ' . $e->getMessage());
        }

        header('Location: ' . url('representante/index.php') . '?solicitud_ok=' . $solicitudId);
        exit;
    } else {
        $error = $resultado['mensaje'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Solicitar inventario | Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
    <style>
        .qtybox {
            display:grid;
            grid-template-columns:36px 48px 36px;
            align-items:center;
            border:1px solid #d8d0c3;
            border-radius:8px;
            overflow:hidden;
            background:white;
        }
        .qtybox button {
            min-height:44px;
            border:0;
            background:#f2eee7;
            color:#101820;
            font-size:20px;
            font-weight:900;
            cursor:pointer;
            line-height:1;
            -webkit-tap-highlight-color:transparent;
        }
        .qtybox button:active { background:#e6e0d6; }
        .qtybox input {
            border:0;
            min-height:44px;
            text-align:center;
            padding:0;
            font-weight:900;
            font-size:17px;
            -moz-appearance:textfield;
            background:white;
        }
        .qtybox input::-webkit-outer-spin-button,
        .qtybox input::-webkit-inner-spin-button { -webkit-appearance:none; }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
<body class="bg-[#fbfaf7] text-slate-950">
    <main class="max-w-3xl mx-auto px-4 py-5 pb-24">
        <div class="sticky top-0 -mx-4 px-4 py-3 bg-[#fbfaf7]/95 backdrop-blur border-b border-stone-200 mb-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Consignacion</p>
                    <h1 class="text-2xl font-black">Solicitar inventario</h1>
                </div>
                <a href="<?= url('representante/index.php') ?>" class="min-h-11 px-4 rounded-lg bg-slate-950 text-white font-bold grid place-items-center">Inicio</a>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="rounded-lg bg-green-100 text-green-800 px-4 py-3 mb-4 font-bold"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="rounded-lg bg-red-100 text-red-800 px-4 py-3 mb-4 font-bold"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Dirección de envío -->
        <?php
        $dirPartes = array_filter([
            trim(($miPerfil['dir_calle'] ?? '') . ' ' . ($miPerfil['dir_numero'] ?? '')),
            $miPerfil['dir_colonia'] ?? '',
            ($miPerfil['dir_ciudad'] ?? '') . (!empty($miPerfil['dir_cp']) ? ' C.P. ' . $miPerfil['dir_cp'] : ''),
            $miPerfil['dir_estado'] ?? '',
            $miPerfil['dir_referencias'] ?? '',
            !empty($miPerfil['dir_quien_recibe']) ? 'Recibe: ' . $miPerfil['dir_quien_recibe'] : '',
        ], fn($v) => trim($v) !== '');
        $tieneDireccion = !empty($dirPartes);
        ?>
        <?php if (!$tieneDireccion): ?>
        <div id="dir-banner" class="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 mb-4 flex gap-3 items-start">
            <span class="text-2xl mt-0.5">⚠️</span>
            <div class="flex-1 min-w-0">
                <div class="font-black text-amber-900 text-sm">Sin dirección de envío</div>
                <div class="text-xs text-amber-800 mt-0.5">Agrega tu dirección para que podamos enviarte el inventario.</div>
            </div>
            <button type="button" onclick="abrirDirModal()" class="shrink-0 text-xs font-black text-amber-900 underline bg-transparent border-none cursor-pointer">Agregar</button>
        </div>
        <?php else: ?>
        <div class="rounded-xl border border-stone-200 bg-white p-3 mb-4 flex gap-3 items-center shadow-sm">
            <span class="text-xl shrink-0">📦</span>
            <div class="flex-1 min-w-0">
                <div class="text-[10px] font-black uppercase text-slate-500 tracking-wide mb-0.5">Enviar a</div>
                <div id="dir-card" class="text-sm text-slate-900 leading-snug"><?= htmlspecialchars(implode(' · ', $dirPartes)) ?></div>
            </div>
            <button type="button" onclick="abrirDirModal()" class="shrink-0 text-xs font-black text-[#126c6a] bg-transparent border-none cursor-pointer">Editar</button>
        </div>
        <?php endif; ?>

        <?php if (empty($productos)): ?>
            <div class="rounded-lg border border-dashed border-stone-300 bg-white/70 p-5 text-slate-500">
                No hay productos disponibles para solicitar.
            </div>
        <?php else: ?>
            <form method="POST" id="solicitudForm">
                <section class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h2 class="font-black">Productos</h2>
                        <span class="text-xs font-bold uppercase text-slate-500"><?= count($productos) ?> disponibles</span>
                    </div>
                    <div class="grid gap-2">
                        <?php foreach ($productos as $producto): ?>
                            <div class="grid grid-cols-[1fr_120px] gap-3 items-center rounded-lg border border-stone-200 bg-[#fffdf9] p-3">
                                <div class="min-w-0">
                                    <div class="font-black truncate"><?= htmlspecialchars($producto['producto']) ?></div>
                                    <div class="text-xs text-slate-500 mt-1">Stock general: <?= (int)($producto['existencia'] ?? 0) ?></div>
                                </div>
                                <div class="qtybox">
                                    <button type="button" onclick="stepQty(this,-1)" aria-label="Menos">&#8722;</button>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        name="productos[<?= (int)$producto['id'] ?>]"
                                        value="0"
                                        data-cantidad
                                    >
                                    <button type="button" onclick="stepQty(this,1)" aria-label="Más">&#43;</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm mt-3">
                    <label for="notas_representante" class="block text-xs font-bold uppercase text-slate-500 mb-2">Notas</label>
                    <textarea id="notas_representante" name="notas_representante" rows="3" class="w-full rounded-lg border border-stone-300 p-3" placeholder="Observaciones opcionales"></textarea>
                </section>

                <div class="fixed left-0 right-0 bottom-0 z-30 bg-white/95 border-t border-stone-200 p-3">
                    <div class="max-w-3xl mx-auto grid grid-cols-[1fr_1fr] gap-3 items-center">
                        <div>
                            <div class="text-xs font-bold uppercase text-slate-500">Unidades</div>
                            <div id="totalSolicitado" class="text-2xl font-black">0</div>
                        </div>
                        <button type="submit" id="btnEnviar" class="min-h-14 rounded-lg bg-[#126c6a] text-white font-black">Enviar</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <script>
    // ── Toast ──────────────────────────────────────────────────────────────
    // showToast centralizado en js/ui-toast.js

        const totalNode = document.getElementById('totalSolicitado');
        const form = document.getElementById('solicitudForm');

        function stepQty(btn, delta) {
            const input = btn.closest('.qtybox').querySelector('[data-cantidad]');
            const val = Math.max(0, parseInt(input.value || '0', 10) + delta);
            input.value = String(val);
            updateTotal();
        }

        function updateTotal() {
            let total = 0;
            document.querySelectorAll('[data-cantidad]').forEach(input => {
                const value = Math.max(0, parseInt(input.value || '0', 10) || 0);
                input.value = String(value);
                total += value;
            });
            if (totalNode) totalNode.textContent = String(total);
            return total;
        }

        document.querySelectorAll('[data-cantidad]').forEach(input => {
            input.addEventListener('input', updateTotal);
        });

        if (form) {
            form.addEventListener('submit', event => {
                if (updateTotal() <= 0) {
                    event.preventDefault();
                    showToast('Selecciona al menos una unidad.', 'warning');
                    return;
                }
                const btn = document.getElementById('btnEnviar');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px;">'
                        + '<svg style="animation:spin .8s linear infinite;width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">'
                        + '<circle cx="12" cy="12" r="10" stroke-opacity=".3"/>'
                        + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>'
                        + '</svg>Enviando…</span>';
                    btn.style.opacity = '0.75';
                }
            });
        }

        updateTotal();
    </script>

    <!-- ═══ Modal dirección de envío ═══ -->
    <div id="dir-overlay" onclick="cerrarDirModal()" style="display:none;position:fixed;inset:0;background:rgba(16,24,32,.45);z-index:3000;backdrop-filter:blur(2px)"></div>
    <div id="dir-sheet" role="dialog" aria-modal="true" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:3001;background:#fff;border-radius:20px 20px 0 0;padding:20px 16px 32px;max-height:88vh;overflow-y:auto">
        <div style="width:36px;height:4px;background:#e6e0d6;border-radius:2px;margin:0 auto 16px"></div>
        <h2 style="font-size:17px;font-weight:900;margin:0 0 16px;color:#101820">Dirección de envío</h2>
        <form id="dir-form" onsubmit="guardarDir(event)">
            <div style="display:grid;gap:12px">
                <div>
                    <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Calle</label>
                    <input type="text" id="dir-calle" name="dir_calle" autocomplete="street-address"
                        value="<?= htmlspecialchars($miPerfil['dir_calle'] ?? '') ?>"
                        style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Número</label>
                        <input type="text" id="dir-numero" name="dir_numero"
                            value="<?= htmlspecialchars($miPerfil['dir_numero'] ?? '') ?>"
                            style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">C.P.</label>
                        <input type="text" id="dir-cp" name="dir_cp" inputmode="numeric" maxlength="5"
                            value="<?= htmlspecialchars($miPerfil['dir_cp'] ?? '') ?>"
                            style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none">
                    </div>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Colonia</label>
                    <input type="text" id="dir-colonia" name="dir_colonia"
                        value="<?= htmlspecialchars($miPerfil['dir_colonia'] ?? '') ?>"
                        style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Estado</label>
                        <select id="dir-estado" name="dir_estado"
                            style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none;appearance:auto">
                            <option value="">— Seleccionar —</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Municipio / Alcaldía</label>
                        <select id="dir-ciudad" name="dir_ciudad"
                            style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none;appearance:auto">
                            <option value="">— Primero selecciona un estado —</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Referencias del domicilio</label>
                    <textarea id="dir-referencias" name="dir_referencias" rows="2"
                        placeholder="Entre calles, color de fachada…"
                        style="margin-top:4px;width:100%;box-sizing:border-box;min-height:60px;border:1.5px solid #e6e0d6;border-radius:10px;padding:10px 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none;resize:vertical"><?= htmlspecialchars($miPerfil['dir_referencias'] ?? '') ?></textarea>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:900;text-transform:uppercase;color:#65717f;letter-spacing:.05em">Quién recibe</label>
                    <input type="text" id="dir-quien" name="dir_quien_recibe"
                        value="<?= htmlspecialchars($miPerfil['dir_quien_recibe'] ?? '') ?>"
                        placeholder="Nombre de quien recibe"
                        style="margin-top:4px;width:100%;box-sizing:border-box;height:44px;border:1.5px solid #e6e0d6;border-radius:10px;padding:0 12px;font-size:15px;background:#f2eee7;color:#101820;outline:none">
                </div>
            </div>
            <button type="submit" id="dir-submit"
                style="margin-top:20px;width:100%;height:50px;background:#126c6a;color:white;font-size:16px;font-weight:900;border:none;border-radius:12px;cursor:pointer">
                Guardar dirección
            </button>
        </form>
    </div>

    <script>
        function abrirDirModal() {
            document.getElementById('dir-overlay').style.display = '';
            document.getElementById('dir-sheet').style.display   = '';
        }
        function cerrarDirModal() {
            document.getElementById('dir-overlay').style.display = 'none';
            document.getElementById('dir-sheet').style.display   = 'none';
        }
        async function guardarDir(e) {
            e.preventDefault();
            const btn = document.getElementById('dir-submit');
            btn.disabled = true; btn.textContent = 'Guardando…';
            try {
                const fd = new FormData(document.getElementById('dir-form'));
                fd.append('action', 'guardar_direccion');
                const res  = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    const partes = [
                        (document.getElementById('dir-calle').value.trim() + ' ' + document.getElementById('dir-numero').value.trim()).trim(),
                        document.getElementById('dir-colonia').value.trim(),
                        (document.getElementById('dir-ciudad').value.trim() + (document.getElementById('dir-cp').value.trim() ? ' C.P. ' + document.getElementById('dir-cp').value.trim() : '')).trim(),
                        document.getElementById('dir-estado').value.trim(),
                        document.getElementById('dir-referencias').value.trim(),
                        document.getElementById('dir-quien').value.trim() ? 'Recibe: ' + document.getElementById('dir-quien').value.trim() : '',
                    ].filter(Boolean);
                    // Actualizar o crear la tarjeta de dirección
                    let card = document.getElementById('dir-card');
                    const banner = document.getElementById('dir-banner');
                    if (!card) {
                        // Primera vez: reemplazar el banner de aviso con la tarjeta compacta
                        if (banner) {
                            banner.outerHTML = `<div class="rounded-xl border border-stone-200 bg-white p-3 mb-4 flex gap-3 items-center shadow-sm">
                                <span class="text-xl shrink-0">📦</span>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[10px] font-black uppercase text-slate-500 tracking-wide mb-0.5">Enviar a</div>
                                    <div id="dir-card" class="text-sm text-slate-900 leading-snug"></div>
                                </div>
                                <button type="button" onclick="abrirDirModal()" class="shrink-0 text-xs font-black text-[#126c6a] bg-transparent border-none cursor-pointer">Editar</button>
                            </div>`;
                        }
                        card = document.getElementById('dir-card');
                    }
                    if (card) card.textContent = partes.join(' · ');
                    cerrarDirModal();
                } else {
                    showToast('No se pudo guardar', 'error');
                }
            } catch {
                showToast('Error de conexión', 'error');
            } finally {
                btn.disabled = false; btn.textContent = 'Guardar dirección';
            }
        }
    </script>
    <style>
        @keyframes _vt-in { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:none; } }
        #_toast-c { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:9999; display:flex; flex-direction:column; gap:10px; align-items:center; pointer-events:none; }
        #_toast-c > div { pointer-events:auto; cursor:pointer; }
    </style>
    <div id="_toast-c"></div>
<script src="<?= BASE_PATH ?>js/ui-toast.js"></script>
<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initUbicaciones({
        selectEstado:    '#dir-estado',
        selectMunicipio: '#dir-ciudad',
        valorEstado:     <?= json_encode($miPerfil['dir_estado'] ?? '') ?>,
        valorMunicipio:  <?= json_encode($miPerfil['dir_ciudad'] ?? '') ?>,
        basePath:        '<?= BASE_PATH ?>',
    });
});
</script>
</body>
</html>
