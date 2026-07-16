<?php
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/SolicitudConsignacion.php';

$solicitudModel = new SolicitudConsignacion();

// AJAX: confirmar recepción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmar_entrega') {
    header('Content-Type: application/json');
    $sid = (int)($_POST['solicitud_id'] ?? 0);
    // Verificar que la solicitud pertenece a este representante
    $sol = $solicitudModel->getById($sid);
    if (!$sol || (int)$sol['representante_admin_id'] !== (int)$representanteAdminId) {
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
    if ($sol['estado'] !== 'en_transito') {
        echo json_encode(['ok' => false, 'msg' => 'La solicitud no está en tránsito']);
        exit;
    }
    $resultado = $solicitudModel->entregar($sid, $representanteAdminId);
    echo json_encode(['ok' => $resultado['success'], 'msg' => $resultado['mensaje'] ?? '']);
    exit;
}
$solicitudes = $solicitudModel->getByRepresentanteAdmin($representanteAdminId, 100);

// Cargar detalle de productos para cada solicitud
$detalles = [];
foreach ($solicitudes as $s) {
    $detalles[(int)$s['id']] = $solicitudModel->getDetalle($s['id']);
}

function estado_sol($estado) {
    $labels = [
        'solicitada' => 'Solicitada',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
        'preparando' => 'Preparando',
        'en_transito' => 'En transito',
        'entregada' => 'Entregada',
        'cancelada' => 'Cancelada',
    ];
    return $labels[$estado] ?? ucfirst((string)$estado);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes | Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
</head>
<body class="bg-[#f0f5fa] text-slate-950">
    <main class="max-w-3xl mx-auto px-4 py-5 pb-24">
        <div class="sticky top-0 -mx-4 px-4 py-3 bg-[#f0f5fa]/95 backdrop-blur border-b border-stone-200 mb-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Consignacion</p>
                    <h1 class="text-2xl font-black">Solicitudes</h1>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?= url('representante/index.php') ?>" class="min-h-11 px-4 rounded-lg bg-slate-950 text-white font-bold grid place-items-center">Inicio</a>
                </div>
            </div>
        </div>

        <div class="grid gap-3">
            <?php if (empty($solicitudes)): ?>
                <div class="rounded-lg border border-dashed border-stone-300 bg-white/70 p-5 text-slate-500">
                    Aun no hay solicitudes.
                </div>
            <?php else: ?>
                <?php foreach ($solicitudes as $solicitud): ?>
                    <article id="solicitud-<?= (int)$solicitud['id'] ?>" class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-black">Solicitud #<?= str_pad((string)$solicitud['id'], 4, '0', STR_PAD_LEFT) ?></h2>
                                <p class="text-sm text-slate-500 mt-1"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($solicitud['fecha_solicitud']))) ?></p>
                            </div>
                            <span data-badge class="rounded-full px-3 py-1 text-xs font-black bg-stone-100"><?= estado_sol($solicitud['estado']) ?></span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 mt-4 text-center">
                            <div class="rounded-lg bg-stone-100 p-2"><b><?= (int)$solicitud['total_productos'] ?></b><div class="text-[11px] uppercase font-bold text-slate-500">Prod.</div></div>
                            <div class="rounded-lg bg-stone-100 p-2"><b><?= (int)$solicitud['total_solicitado'] ?></b><div class="text-[11px] uppercase font-bold text-slate-500">Solic.</div></div>
                            <div class="rounded-lg bg-stone-100 p-2"><b><?= (int)$solicitud['total_entregado'] ?></b><div class="text-[11px] uppercase font-bold text-slate-500">Entreg.</div></div>
                        </div>
                        <?php if (!empty($solicitud['numero_guia'])): ?>
                            <div class="mt-4 rounded-lg border border-stone-200 bg-stone-50 p-3 text-sm">
                                <div class="text-[11px] uppercase font-black text-slate-500">Guia de envio</div>
                                <div class="font-black text-slate-900"><?= htmlspecialchars($solicitud['paqueteria'] ?? '') ?> &middot; <?= htmlspecialchars($solicitud['numero_guia']) ?></div>
                                <?php if (!empty($solicitud['url_rastreo'])): ?>
                                    <a href="<?= htmlspecialchars($solicitud['url_rastreo']) ?>" target="_blank" class="mt-1 inline-block text-xs font-bold text-[#4a70a9] underline">Ver rastreo</a>
                                <?php endif; ?>
                                <?php if (!empty($solicitud['guia_archivo'])): ?>
                                    <a href="<?= uploads_url('guias_consignacion/' . htmlspecialchars($solicitud['guia_archivo'])) ?>" target="_blank" class="mt-1 ml-3 inline-block text-xs font-bold text-[#4a70a9] underline">Ver archivo</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Confirmar recepción -->
                        <?php if ($solicitud['estado'] === 'en_transito'): ?>
                        <button
                            type="button"
                            data-btn-confirmar
                            onclick="confirmarEntrega(this, <?= (int)$solicitud['id'] ?>)"
                            class="mt-3 w-full min-h-11 rounded-xl bg-[#4a70a9] text-white text-sm font-black">
                            Confirmar que recibí el pedido
                        </button>
                        <?php endif; ?>

                        <!-- Notas -->
                        <?php if (!empty($solicitud['notas_representante']) || !empty($solicitud['notas_admin'])): ?>
                        <div class="mt-3 grid gap-2">
                            <?php if (!empty($solicitud['notas_representante'])): ?>
                            <div class="rounded-lg bg-stone-50 border border-stone-200 px-3 py-2">
                                <div class="text-[10px] font-black uppercase text-slate-400 mb-1">Mis notas</div>
                                <p class="text-sm text-slate-700 whitespace-pre-line"><?= htmlspecialchars($solicitud['notas_representante']) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($solicitud['notas_admin'])): ?>
                            <div class="rounded-lg bg-blue-50 border border-blue-200 px-3 py-2">
                                <div class="text-[10px] font-black uppercase text-blue-400 mb-1">Notas del administrador</div>
                                <p class="text-sm text-blue-900 whitespace-pre-line"><?= htmlspecialchars($solicitud['notas_admin']) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Productos solicitados -->
                        <?php $det = $detalles[(int)$solicitud['id']] ?? []; ?>
                        <?php if (!empty($det)): ?>
                        <details class="mt-3 group">
                            <summary class="flex items-center justify-between cursor-pointer list-none select-none rounded-lg bg-stone-100 px-3 py-2">
                                <span class="text-xs font-black uppercase text-slate-600">Productos (<?= count($det) ?>)</span>
                                <svg class="w-4 h-4 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                            </summary>
                            <div class="mt-2 grid gap-1.5">
                                <?php foreach ($det as $item): ?>
                                <div class="flex items-center gap-3 rounded-lg border border-stone-100 bg-white px-3 py-2">
                                    <?php if (!empty($item['imagen'])): ?>
                                        <img src="<?= uploads_url('productos/' . $item['imagen']) ?>" alt="" class="w-9 h-9 rounded object-cover shrink-0">
                                    <?php else: ?>
                                        <div class="w-9 h-9 rounded bg-stone-200 grid place-items-center text-sm font-black text-slate-500 shrink-0"><?= strtoupper(substr($item['producto'], 0, 1)) ?></div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($item['producto']) ?></div>
                                        <div class="text-xs text-slate-500">
                                            Solic.: <b><?= (int)$item['cantidad_solicitada'] ?></b>
                                            <?php if ((int)$item['cantidad_aprobada'] > 0): ?> &middot; Aprobado: <b class="text-[#4a70a9]"><?= (int)$item['cantidad_aprobada'] ?></b><?php endif; ?>
                                            <?php if ((int)$item['cantidad_entregada'] > 0): ?> &middot; Entregado: <b class="text-emerald-700"><?= (int)$item['cantidad_entregada'] ?></b><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    <script>
    // ── Toast / Confirm ──────────────────────────────────────────────────────
    // showToast y showConfirm centralizados en js/ui-toast.js

        async function confirmarEntrega(btn, solicitudId) {
            showConfirm('¿Confirmas que recibiste este pedido?', async () => {
            btn.disabled = true;
            btn.textContent = 'Confirmando…';
            try {
                const fd = new FormData();
                fd.append('action', 'confirmar_entrega');
                fd.append('solicitud_id', solicitudId);
                const res  = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    // Actualizar badge de estado y ocultar el botón
                    const art = btn.closest('article');
                    const badge = art.querySelector('[data-badge]');
                    if (badge) { badge.textContent = 'Entregada'; badge.className = 'rounded-full px-3 py-1 text-xs font-black bg-emerald-100 text-emerald-800'; }
                    btn.remove();
                } else {
                    showToast(data.msg || 'No se pudo confirmar', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Confirmar que recibí el pedido';
                }
            } catch {
                showToast('Error de conexión', 'error');
                btn.disabled = false;
                btn.textContent = 'Confirmar que recibí el pedido';
            }
            });
        }

        // ── Polling de estados (cada 30s, pausado si la pestaña está oculta) ─
        const ESTADO_LABELS = {
            solicitada: 'Solicitada', aprobada: 'Aprobada', rechazada: 'Rechazada',
            preparando: 'Preparando', en_transito: 'En tránsito', entregada: 'Entregada', cancelada: 'Cancelada'
        };
        const ESTADO_CLASS = {
            entregada: 'bg-emerald-100 text-emerald-800',
            rechazada: 'bg-red-100 text-red-700',
            cancelada: 'bg-red-100 text-red-700',
            en_transito: 'bg-blue-100 text-blue-800',
            aprobada: 'bg-teal-100 text-teal-800',
            preparando: 'bg-indigo-100 text-indigo-800',
        };

        async function pollEstados() {
            if (document.visibilityState !== 'visible') return;
            try {
                const res  = await fetch('<?= url('api/solicitudes-estado.php') ?>');
                const data = await res.json();
                if (!data.ok) return;
                Object.entries(data.estados).forEach(([id, estado]) => {
                    const art = document.getElementById('solicitud-' + id);
                    if (!art) return;
                    const badge = art.querySelector('[data-badge]');
                    if (badge && badge.textContent.trim() !== (ESTADO_LABELS[estado] ?? estado)) {
                        badge.textContent = ESTADO_LABELS[estado] ?? estado;
                        badge.className = 'rounded-full px-3 py-1 text-xs font-black ' + (ESTADO_CLASS[estado] ?? 'bg-stone-100 text-slate-700');
                        // Mostrar/ocultar botón confirmar
                        const btnConfirmar = art.querySelector('[data-btn-confirmar]');
                        if (estado === 'en_transito' && !btnConfirmar) {
                            // Recargar para mostrar el botón con datos frescos
                            location.reload();
                        } else if (estado !== 'en_transito' && btnConfirmar) {
                            btnConfirmar.remove();
                        }
                    }
                });
            } catch { /* silencioso */ }
        }

        setInterval(pollEstados, 30000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') pollEstados();
        });

        // ── Scroll al ancla si se llega desde solicitudes recientes ──────────
        (function () {
            const hash = location.hash;
            if (!hash) return;
            const el = document.querySelector(hash);
            if (!el) return;
            requestAnimationFrame(() => {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.style.transition = 'box-shadow .3s, outline .3s';
                el.style.outline = '2px solid #4a70a9';
                el.style.boxShadow = '0 0 0 4px rgba(18,108,106,.15)';
                setTimeout(() => { el.style.outline = ''; el.style.boxShadow = ''; }, 2200);
            });
        })();
    </script>
    <style>
        @keyframes _vt-in { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:none; } }
        #_toast-c { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:9999; display:flex; flex-direction:column; gap:10px; align-items:center; pointer-events:none; }
        #_toast-c > div { pointer-events:auto; cursor:pointer; }
    </style>
    <div id="_toast-c"></div>
<script src="<?= BASE_PATH ?>js/ui-toast.js"></script>
</body>
</html>
