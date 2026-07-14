<?php
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/Configuracion.php';
require_once __DIR__ . '/../models/RepresentanteInventario.php';

$inventarioModel = new RepresentanteInventario();
$inventario   = $inventarioModel->getInventarioPorAdmin($representanteAdminId, false);
$otrosReps    = $inventarioModel->getOtrosRepresentantes($representanteAdminId);
$pendRecibidos = $inventarioModel->getTraspasosPendientesRecibidos($representanteAdminId);
$pendEnviados  = $inventarioModel->getTraspasosPendientesEnviados($representanteAdminId);

function money_inv($value) {
    return '$' . number_format((float)$value, 2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario | Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
    <style>
        .drawer-backdrop { background: rgba(0,0,0,.45); }
        .drawer { transform: translateY(100%); transition: transform .28s cubic-bezier(.4,0,.2,1); }
        .drawer.open { transform: translateY(0); }
        .badge-pulse { animation: pulse 2s cubic-bezier(.4,0,.6,1) infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
    </style>
</head>
<body class="bg-[#f0f5fa] text-slate-950">
    <main class="max-w-3xl mx-auto px-4 py-5 pb-32">

        <!-- HEADER -->
        <div class="sticky top-0 -mx-4 px-4 py-3 bg-[#f0f5fa]/95 backdrop-blur border-b border-stone-200 mb-4 z-20">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500"><?= htmlspecialchars(Configuracion::get('nombre_tienda', 'Solumedic')) ?></p>
                    <h1 class="text-2xl font-black flex items-center gap-2">
                        Inventario
                        <?php if (count($pendRecibidos) > 0): ?>
                            <span id="badge-total" class="badge-pulse inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-500 text-white text-xs font-black"><?= count($pendRecibidos) ?></span>
                        <?php endif; ?>
                    </h1>
                </div>
                <a href="<?= url('representante/index.php') ?>" class="min-h-11 px-4 rounded-lg bg-slate-950 text-white font-bold grid place-items-center">Inicio</a>
            </div>
        </div>

        <!-- TRASPASOS RECIBIDOS PENDIENTES -->
        <?php if (!empty($pendRecibidos)): ?>
        <section id="sec-recibidos" class="mb-5">
            <h2 class="text-sm font-black uppercase text-amber-700 mb-2 flex items-center gap-2">
                <span></span> Traspasos por confirmar (<?= count($pendRecibidos) ?>)
            </h2>
            <div class="grid gap-3">
                <?php foreach ($pendRecibidos as $tr): ?>
                <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-4">
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div>
                            <p class="font-black text-slate-900"><?= htmlspecialchars($tr['producto']) ?></p>
                            <p class="text-sm text-slate-600">De: <span class="font-bold"><?= htmlspecialchars($tr['origen_nombre']) ?></span></p>
                            <?php if ($tr['notas']): ?>
                                <p class="text-xs text-slate-500 mt-1">"<?= htmlspecialchars($tr['notas']) ?>"</p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-black text-amber-700"><?= (int)$tr['cantidad'] ?></div>
                            <div class="text-xs font-bold uppercase text-slate-500">Pzas</div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="responderTraspaso(<?= $tr['id'] ?>, 'confirmar')"
                                class="flex-1 min-h-11 rounded-lg bg-emerald-600 text-white font-bold text-sm active:scale-95 transition">
                            Aceptar
                        </button>
                        <button onclick="responderTraspaso(<?= $tr['id'] ?>, 'rechazar')"
                                class="flex-1 min-h-11 rounded-lg bg-red-100 text-red-700 font-bold text-sm active:scale-95 transition border border-red-200">
                            Rechazar
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- TRASPASOS ENVIADOS PENDIENTES -->
        <?php if (!empty($pendEnviados)): ?>
        <section id="sec-enviados" class="mb-5">
            <h2 class="text-sm font-black uppercase text-slate-500 mb-2">Enviados pendientes (<?= count($pendEnviados) ?>)</h2>
            <div class="grid gap-2">
                <?php foreach ($pendEnviados as $tr): ?>
                <div class="rounded-xl border border-stone-200 bg-white p-3 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold text-sm truncate"><?= htmlspecialchars($tr['producto']) ?></p>
                        <p class="text-xs text-slate-500">Para: <?= htmlspecialchars($tr['destino_nombre']) ?> · <span class="font-bold"><?= (int)$tr['cantidad'] ?> pzas</span></p>
                    </div>
                    <button onclick="cancelarTraspaso(<?= $tr['id'] ?>)"
                            class="shrink-0 min-h-10 px-3 rounded-lg bg-slate-100 text-slate-600 font-bold text-xs active:scale-95 transition">
                        Cancelar
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- INVENTARIO -->
        <div class="grid gap-3">
            <?php if (empty($inventario)): ?>
                <div class="rounded-lg border border-dashed border-stone-300 bg-white/70 p-5 text-slate-500">
                    Sin inventario asignado.
                </div>
            <?php else: ?>
                <?php foreach ($inventario as $item): ?>
                    <article class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-black truncate"><?= htmlspecialchars($item['producto']) ?></h2>
                                <p class="text-sm text-slate-500 mt-1">
                                    <?= $item['precio_base'] !== null ? money_inv($item['precio_base']) : 'Sin precio base' ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-3xl font-black text-[#4a70a9]"><?= (int)$item['cantidad_disponible'] ?></div>
                                <div class="text-xs font-bold uppercase text-slate-500">Disp.</div>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 mt-4 text-center">
                            <div class="rounded-lg bg-stone-100 p-2">
                                <div class="font-black"><?= (int)$item['cantidad_reservada'] ?></div>
                                <div class="text-[11px] font-bold text-slate-500 uppercase">Reserv.</div>
                            </div>
                            <div class="rounded-lg bg-stone-100 p-2">
                                <div class="font-black"><?= (int)$item['cantidad_vendida'] ?></div>
                                <div class="text-[11px] font-bold text-slate-500 uppercase">Vend.</div>
                            </div>
                            <div class="rounded-lg bg-stone-100 p-2">
                                <div class="font-black"><?= (int)$item['cantidad_devuelta'] ?></div>
                                <div class="text-[11px] font-bold text-slate-500 uppercase">Dev.</div>
                            </div>
                        </div>
                        <?php if ((int)$item['cantidad_disponible'] > 0 && !empty($otrosReps)): ?>
                        <button onclick="abrirDrawerTraspaso(<?= (int)$item['producto_id'] ?>, <?= htmlspecialchars(json_encode($item['producto']), ENT_QUOTES) ?>, <?= (int)$item['cantidad_disponible'] ?>)"
                                class="mt-3 w-full min-h-10 rounded-lg bg-slate-100 text-slate-700 font-bold text-sm active:scale-95 transition">
                            Traspasar →
                        </button>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- DRAWER CREAR TRASPASO -->
    <div id="drawerBackdrop" class="drawer-backdrop fixed inset-0 z-40 hidden" onclick="cerrarDrawer()"></div>
    <div id="drawer" class="drawer fixed bottom-0 left-0 right-0 z-50 bg-white rounded-t-2xl shadow-2xl max-w-3xl mx-auto px-5 pt-5 pb-10">
        <div class="w-10 h-1 bg-stone-300 rounded-full mx-auto mb-5"></div>
        <h3 class="text-lg font-black mb-4">Traspasar inventario</h3>
        <p class="text-sm text-slate-500 mb-1">Producto</p>
        <p id="drawer-producto-nombre" class="font-bold text-slate-900 mb-4"></p>

        <div class="mb-4">
            <label class="block text-sm font-bold text-slate-700 mb-1">Destino</label>
            <select id="drawer-destino" class="w-full min-h-11 rounded-xl border border-stone-300 bg-stone-50 px-3 font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400">
                <option value="">Seleccionar representante…</option>
                <?php foreach ($otrosReps as $rep): ?>
                    <option value="<?= (int)$rep['id'] ?>"><?= htmlspecialchars($rep['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-bold text-slate-700 mb-1">Cantidad <span id="drawer-max-label" class="text-slate-400 font-normal"></span></label>
            <input type="number" id="drawer-cantidad" min="1" class="w-full min-h-11 rounded-xl border border-stone-300 bg-stone-50 px-3 font-black text-slate-900 text-lg focus:outline-none focus:ring-2 focus:ring-slate-400" placeholder="0">
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold text-slate-700 mb-1">Notas (opcional)</label>
            <input type="text" id="drawer-notas" maxlength="200" class="w-full min-h-11 rounded-xl border border-stone-300 bg-stone-50 px-3 text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400" placeholder="Motivo del traspaso…">
        </div>

        <button onclick="enviarTraspaso()"
                class="w-full min-h-12 rounded-xl bg-slate-950 text-white font-black text-base active:scale-95 transition">
            Enviar traspaso
        </button>
    </div>

    <!-- TOAST -->
    <div id="toast" class="fixed bottom-24 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl text-white font-bold text-sm shadow-lg opacity-0 transition-opacity pointer-events-none max-w-xs text-center"></div>

    <script>
    const API = '<?= url('api/traspasos.php') ?>';
    let _productoId = null, _maxQty = 0;

    function abrirDrawerTraspaso(productoId, nombre, maxQty) {
        _productoId = productoId;
        _maxQty = maxQty;
        document.getElementById('drawer-producto-nombre').textContent = nombre;
        document.getElementById('drawer-max-label').textContent = '(máx ' + maxQty + ')';
        document.getElementById('drawer-cantidad').value = '';
        document.getElementById('drawer-cantidad').max = maxQty;
        document.getElementById('drawer-destino').value = '';
        document.getElementById('drawer-notas').value = '';
        document.getElementById('drawerBackdrop').classList.remove('hidden');
        requestAnimationFrame(() => document.getElementById('drawer').classList.add('open'));
    }

    function cerrarDrawer() {
        document.getElementById('drawer').classList.remove('open');
        document.getElementById('drawerBackdrop').classList.add('hidden');
    }

    async function enviarTraspaso() {
        const destino  = document.getElementById('drawer-destino').value;
        const cantidad = parseInt(document.getElementById('drawer-cantidad').value);
        const notas    = document.getElementById('drawer-notas').value.trim();

        if (!destino)       return showToast('Selecciona un destinatario', 'error');
        if (!cantidad || cantidad < 1) return showToast('Indica una cantidad válida', 'error');
        if (cantidad > _maxQty) return showToast('Cantidad mayor al disponible (' + _maxQty + ')', 'error');

        const fd = new FormData();
        fd.append('action', 'crear');
        fd.append('destino_admin_id', destino);
        fd.append('producto_id', _productoId);
        fd.append('cantidad', cantidad);
        fd.append('notas', notas);

        const res = await post(fd);
        if (res.success) {
            cerrarDrawer();
            showToast('Traspaso enviado · pendiente de confirmación ', 'ok');
            setTimeout(() => location.reload(), 1400);
        } else {
            showToast(res.mensaje || 'Error al crear traspaso', 'error');
        }
    }

    async function responderTraspaso(id, accion) {
        const label = accion === 'confirmar' ? 'Aceptar' : 'Rechazar';
        if (!confirm(label + ' este traspaso?')) return;

        const fd = new FormData();
        fd.append('action', accion);
        fd.append('traspaso_id', id);

        const res = await post(fd);
        if (res.success) {
            showToast(accion === 'confirmar' ? 'Traspaso aceptado ' : 'Traspaso rechazado', 'ok');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(res.mensaje || 'Error', 'error');
        }
    }

    async function cancelarTraspaso(id) {
        if (!confirm('¿Cancelar este traspaso?')) return;

        const fd = new FormData();
        fd.append('action', 'cancelar');
        fd.append('traspaso_id', id);

        const res = await post(fd);
        if (res.success) {
            showToast('Traspaso cancelado', 'ok');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(res.mensaje || 'Error', 'error');
        }
    }

    async function post(fd) {
        try {
            const r = await fetch(API, { method: 'POST', body: fd });
            return await r.json();
        } catch(e) {
            return { success: false, mensaje: 'Error de conexión' };
        }
    }

    let _toastTimer;
    function showToast(msg, tipo) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.style.background = tipo === 'error' ? '#dc2626' : '#1e293b';
        t.style.opacity = '1';
        clearTimeout(_toastTimer);
        _toastTimer = setTimeout(() => t.style.opacity = '0', 3000);
    }
    </script>
</body>
</html>
