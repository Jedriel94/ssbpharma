<?php
require_once __DIR__ . '/../includes/auth_representante.php';

$db = Database::getInstance()->getConnection();
// ── AJAX: cancelar pedido ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancelar_pedido') {
    header('Content-Type: application/json');
    try {
        $pedido_id = (int)($_POST['pedido_id'] ?? 0);
        if ($pedido_id <= 0) throw new Exception('Pedido inválido');

        $chk = $db->prepare("SELECT id, estado FROM pedidos WHERE id = ? AND representante_admin_id = ? AND canal = 'representante_directo' LIMIT 1");
        $chk->execute([$pedido_id, $representanteAdminId]);
        $pedido = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) throw new Exception('Pedido no encontrado');

        $estadosPermitidos = ['pendiente', 'por_verificar'];
        if (!in_array($pedido['estado'], $estadosPermitidos, true)) {
            throw new Exception('Este pedido ya no puede cancelarse');
        }

        $upd = $db->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?");
        $upd->execute([$pedido_id]);

        // Liberar la reserva de inventario
        require_once __DIR__ . '/../models/RepresentanteVenta.php';
        (new RepresentanteVenta())->liberarReserva($pedido_id);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// ── AJAX: subir comprobante de pago ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'subir_comprobante') {
    header('Content-Type: application/json');
    try {
        $pedido_id = (int)($_POST['pedido_id'] ?? 0);
        if ($pedido_id <= 0) throw new Exception('Pedido inválido');

                // Permitir comprobante para ventas directas y tienda del representante (efectivo pendiente/por_verificar).
                $chk = $db->prepare("SELECT id, comprobante_pago, canal, metodo_pago, estado
                                                        FROM pedidos
                                                        WHERE id = ?
                                                            AND representante_admin_id = ?
                                                            AND (
                                                                        canal = 'representante_directo'
                                                                        OR (canal = 'cliente_directo' AND metodo_pago = 'efectivo' AND estado IN ('pendiente', 'por_verificar'))
                                                                    )
                                                        LIMIT 1");
        $chk->execute([$pedido_id, $representanteAdminId]);
        $pedido = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) throw new Exception('Pedido no encontrado');

        if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió el archivo');
        }
        $file = $_FILES['comprobante'];
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if ($file['size'] > 5 * 1024 * 1024) throw new Exception('El archivo no debe superar 5 MB');
        if (!in_array($file['type'], $allowedTypes, true)) throw new Exception('Solo se permiten PDF o imágenes (JPG, PNG, WEBP)');

        $uploadDir = uploads_dir('comprobantes') . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'comprobante_' . $pedido_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new Exception('Error al guardar el archivo');
        }

        // Delete old comprobante file if exists
        if (!empty($pedido['comprobante_pago'])) {
            $old = $uploadDir . basename($pedido['comprobante_pago']);
            if (file_exists($old)) @unlink($old);
        }

        $upd = $db->prepare("UPDATE pedidos SET comprobante_pago = ?, estado = IF(estado = 'pendiente', 'por_verificar', estado), fecha_pago = COALESCE(fecha_pago, NOW()) WHERE id = ?");
        $upd->execute([$filename, $pedido_id]);

        echo json_encode(['success' => true, 'filename' => $filename]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$stmt = $db->prepare("
    SELECT p.*, c.nombre as cliente_nombre, c.telefono as cliente_telefono
    FROM pedidos p
    INNER JOIN clientes c ON c.id = p.cliente_id
    WHERE p.representante_admin_id = ?
      AND (
          p.canal = 'representante_directo'
          OR (p.canal = 'cliente_directo')
      )
    ORDER BY p.created_at DESC
    LIMIT 100
");
$stmt->execute([$representanteAdminId]);
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cargar detalles de todos los pedidos en una sola query
$detalles_map = [];
if (!empty($ventas)) {
    $ids = array_column($ventas, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $dst = $db->prepare("
        SELECT dp.pedido_id, dp.cantidad, dp.precio_unitario, dp.subtotal,
               pr.producto, pr.imagen
        FROM detalle_pedidos dp
        JOIN productos pr ON pr.id = dp.producto_id
        WHERE dp.pedido_id IN ($ph)
        ORDER BY dp.id ASC
    ");
    $dst->execute($ids);
    foreach ($dst->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $detalles_map[$row['pedido_id']][] = $row;
    }
}

function money_ventas($value) {
    return '$' . number_format((float)$value, 2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas | Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
</head>
<body class="bg-[#f0f5fa] text-slate-950">
    <main class="max-w-3xl mx-auto px-4 py-5 pb-24">
        <div class="sticky top-0 -mx-4 px-4 py-3 bg-[#f0f5fa]/95 backdrop-blur border-b border-stone-200 mb-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Entrega directa &amp; Tienda</p>
                    <h1 class="text-2xl font-black">Mis ventas</h1>
                </div>
                <a href="<?= url('representante/index.php') ?>" class="min-h-11 px-4 rounded-lg bg-slate-950 text-white font-bold grid place-items-center">Inicio</a>
            </div>
            <!-- Filtros -->
            <div class="mt-3 flex flex-col gap-2">
                <input id="vt-search" type="search" placeholder="Buscar por cliente o producto…"
                       oninput="vtFiltrar()"
                       class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm placeholder-stone-400 focus:outline-none focus:ring-2 focus:ring-slate-400">
                <div class="flex flex-wrap gap-1.5" id="vt-estados">
                    <button data-st="todos"        onclick="vtEst(this)" class="vt-chip active px-3 py-1 rounded-full text-xs font-bold border border-stone-300 bg-slate-950 text-white">Todos</button>
                    <button data-st="pendiente"    onclick="vtEst(this)" class="vt-chip px-3 py-1 rounded-full text-xs font-bold border border-amber-200 bg-amber-50 text-amber-800">Pendiente</button>
                    <button data-st="por_verificar" onclick="vtEst(this)" class="vt-chip px-3 py-1 rounded-full text-xs font-bold border border-blue-200 bg-blue-50 text-blue-800">Por verificar</button>
                    <button data-st="confirmado"   onclick="vtEst(this)" class="vt-chip px-3 py-1 rounded-full text-xs font-bold border border-green-200 bg-green-50 text-green-800">Confirmado</button>
                    <button data-st="en_ruta"      onclick="vtEst(this)" class="vt-chip px-3 py-1 rounded-full text-xs font-bold border border-purple-200 bg-purple-50 text-purple-800">En ruta</button>
                    <button data-st="entregado"    onclick="vtEst(this)" class="vt-chip px-3 py-1 rounded-full text-xs font-bold border border-stone-300 bg-stone-100 text-stone-600">Entregado</button>
                    <button data-st="cancelado"    onclick="vtEst(this)" class="vt-chip px-3 py-1 rounded-full text-xs font-bold border border-red-200 bg-red-50 text-red-700">Cancelado</button>
                </div>
            </div>
        </div>
        <p id="vt-sin-res" class="hidden text-sm text-slate-400 text-center py-6">Sin resultados para este filtro.</p>

        <div class="grid gap-3" id="vt-lista">
            <?php if (empty($ventas)): ?>
                <div class="rounded-lg border border-dashed border-stone-300 bg-white/70 p-5 text-slate-500">
                    Aun no hay ventas directas registradas.
                </div>
            <?php else: ?>
                <?php foreach ($ventas as $venta): ?>
                    <?php
                        $es_tienda = ($venta['canal'] ?? 'representante_directo') !== 'representante_directo';
                        $tieneComprobante = !empty($venta['comprobante_pago']);
                        $esEfectivoPendiente = (($venta['metodo_pago'] ?? '') === 'efectivo') && in_array($venta['estado'], ['pendiente', 'por_verificar'], true);
                        $mostrarComprobante = (
                            (!$es_tienda && $venta['estado'] !== 'entregado' && $venta['estado'] !== 'cancelado')
                            || ($es_tienda && $esEfectivoPendiente)
                        );
                        $estadoLabel = match($venta['estado']) {
                            'pendiente'    => ['Pendiente', 'bg-amber-100 text-amber-800'],
                            'por_verificar'=> ['Por verificar', 'bg-blue-100 text-blue-800'],
                            'confirmado'   => ['Confirmado', 'bg-green-100 text-green-800'],
                            'en_ruta'      => ['En ruta', 'bg-purple-100 text-purple-800'],
                            'entregado'    => ['Entregado', 'bg-slate-100 text-slate-600'],
                            'cancelado'    => ['Cancelado', 'bg-red-100 text-red-800'],
                            default        => [htmlspecialchars($venta['estado']), 'bg-stone-100 text-stone-600'],
                        };
                        $search_text = strtolower(
                            ($venta['cliente_nombre'] ?? '') . ' ' .
                            ($venta['cliente_telefono'] ?? '') . ' ' .
                            implode(' ', array_column($detalles_map[$venta['id']] ?? [], 'producto'))
                        );
                    ?>
                    <article class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm vt-card"
                             id="pedido-<?= $venta['id'] ?>"
                             data-estado="<?= htmlspecialchars($venta['estado']) ?>"
                             data-search="<?= htmlspecialchars($search_text) ?>">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h2 class="font-black">Pedido #<?= str_pad((string)$venta['id'], 4, '0', STR_PAD_LEFT) ?></h2>
                                    <?php if ($es_tienda): ?>
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-sky-100 text-sky-700">Tienda</span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700">Directa</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm text-slate-500 mt-1 truncate"><?= htmlspecialchars($venta['cliente_nombre'] ?: $venta['cliente_telefono']) ?></p>
                                <?php if ($venta['metodo_pago']): ?>
                                <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($venta['metodo_pago']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-black"><?= money_ventas($venta['total']) ?></div>
                                <span class="inline-block mt-1 text-xs font-bold px-2 py-0.5 rounded-full <?= $estadoLabel[1] ?>"><?= $estadoLabel[0] ?></span>
                            </div>
                        </div>

                        <!-- Acción principal: procesar pago si está pendiente -->
                        <?php
                            $puedeOperar = !$es_tienda && in_array($venta['estado'], ['pendiente', 'por_verificar']);
                            $sinMetodo   = empty($venta['metodo_pago']);
                        ?>
                        <?php if ($puedeOperar): ?>
                        <div class="mt-3 pt-3 border-t border-stone-100 flex flex-col gap-2">
                            <?php if ($sinMetodo): ?>
                                <p class="text-xs text-amber-700 font-bold">Pago pendiente — no se ha seleccionado un método de pago</p>
                                <a href="<?= url('procesar-pago.php') ?>?pedido_id=<?= (int)$venta['id'] ?>&telefono=<?= urlencode($venta['cliente_telefono']) ?>&modo=rep"
                                   class="w-full flex items-center justify-center gap-2 rounded-lg bg-slate-950 text-white font-bold py-2.5 px-4 text-sm hover:bg-slate-700 transition">
                                    Cobrar este pedido →
                                </a>
                            <?php endif; ?>
                            <button type="button"
                                    onclick="cancelarPedido(<?= (int)$venta['id'] ?>)"
                                    class="w-full flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-red-50 text-red-700 font-bold py-2 px-4 text-sm hover:bg-red-100 transition">
                                Cancelar pedido
                            </button>
                        </div>
                        <?php endif; ?>

                        <!-- Comprobante -->
                        <?php if ($mostrarComprobante): ?>
                        <div class="mt-3 pt-3 border-t border-stone-100">
                            <?php if ($tieneComprobante): ?>
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-green-600 font-bold">Comprobante subido</span>
                                    <a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$venta['id'] . '&tipo=comprobante') ?>"
                                       target="_blank"
                                       class="text-xs text-blue-600 underline">Ver</a>
                                    <button type="button"
                                            onclick="abrirUpload(<?= $venta['id'] ?>)"
                                            class="text-xs text-stone-400 underline ml-auto">Reemplazar</button>
                                </div>
                            <?php else: ?>
                                <button type="button"
                                        onclick="abrirUpload(<?= $venta['id'] ?>)"
                                        class="w-full flex items-center justify-center gap-2 rounded-lg border border-dashed border-stone-300 bg-stone-50 py-2 px-3 text-sm font-bold text-stone-600 hover:border-stone-400 hover:bg-stone-100 transition">
                                    Subir comprobante de pago
                                </button>
                            <?php endif; ?>

                            <!-- Upload form (hidden until triggered) -->
                            <div id="upload-area-<?= $venta['id'] ?>" class="hidden mt-2">
                                <div class="rounded-lg border border-stone-200 bg-stone-50 p-3">
                                    <p class="text-xs font-bold text-stone-500 mb-2">PDF, JPG, PNG o WEBP · máx 5 MB</p>
                                    <input type="file" id="file-input-<?= $venta['id'] ?>"
                                           accept=".pdf,.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp,application/pdf"
                                           class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-stone-200 file:px-3 file:py-1.5 file:text-xs file:font-bold file:cursor-pointer">
                                    <div id="upload-msg-<?= $venta['id'] ?>" class="text-xs mt-1 hidden"></div>
                                    <div class="flex gap-2 mt-2">
                                        <button type="button"
                                                onclick="subirComprobante(<?= $venta['id'] ?>)"
                                                class="flex-1 rounded-md bg-slate-950 text-white text-sm font-bold py-2 hover:bg-slate-700 transition">
                                            Subir
                                        </button>
                                        <button type="button"
                                                onclick="cerrarUpload(<?= $venta['id'] ?>)"
                                                class="rounded-md bg-stone-200 text-stone-700 text-sm font-bold px-4 py-2 hover:bg-stone-300 transition">
                                            Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; // comprobante ?>

                        <!-- Toggle detalle -->
                        <?php $items_pedido = $detalles_map[$venta['id']] ?? []; ?>
                        <button type="button"
                                onclick="toggleDetalle(<?= $venta['id'] ?>)"
                                class="mt-3 w-full flex items-center justify-between text-xs font-bold text-slate-500 hover:text-slate-800 transition pt-3 border-t border-stone-100">
                            <span id="det-lbl-<?= $venta['id'] ?>">▸ Ver detalle (<?= count($items_pedido) ?> prod<?= count($items_pedido) != 1 ? 's' : '' ?>)</span>
                            <span id="det-chv-<?= $venta['id'] ?>">▾</span>
                        </button>

                        <!-- Panel detalle -->
                        <div id="det-<?= $venta['id'] ?>" class="hidden mt-2 rounded-lg border border-stone-100 bg-stone-50 p-3 text-sm">
                            <?php if (!empty($items_pedido)): ?>
                            <table class="w-full text-xs mb-3">
                                <thead>
                                    <tr class="text-left text-stone-400 border-b border-stone-200">
                                        <th class="pb-1 font-bold">Producto</th>
                                        <th class="pb-1 font-bold text-right">Cant.</th>
                                        <th class="pb-1 font-bold text-right">P.U.</th>
                                        <th class="pb-1 font-bold text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items_pedido as $item): ?>
                                    <tr class="border-b border-stone-100 last:border-0">
                                        <td class="py-1 pr-2 font-medium text-slate-700"><?= htmlspecialchars($item['producto']) ?></td>
                                        <td class="py-1 text-right text-slate-500"><?= $item['cantidad'] ?></td>
                                        <td class="py-1 text-right text-slate-500 font-mono">$<?= number_format($item['precio_unitario'], 2) ?></td>
                                        <td class="py-1 text-right font-mono font-bold text-slate-700">$<?= number_format($item['subtotal'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>

                            <?php
                                $subtotal_p  = array_sum(array_column($items_pedido, 'subtotal'));
                                $descuento   = (float)($venta['cupon_descuento'] ?? 0);
                                $costo_envio = round((float)$venta['total'] - $subtotal_p + $descuento, 2);
                            ?>
                            <div class="border-t border-stone-200 pt-2 space-y-1 text-xs">
                                <div class="flex justify-between text-slate-500">
                                    <span>Subtotal productos</span>
                                    <span class="font-mono">$<?= number_format($subtotal_p, 2) ?></span>
                                </div>
                                <?php if ($descuento > 0): ?>
                                <div class="flex justify-between text-emerald-700">
                                    <span>
                                        Cupón<?php if (!empty($venta['cupon_codigo'])): ?>
                                        <code class="ml-1 bg-emerald-50 px-1 rounded"><?= htmlspecialchars($venta['cupon_codigo']) ?></code>
                                        <?php endif; ?>
                                    </span>
                                    <span class="font-mono">−$<?= number_format($descuento, 2) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($costo_envio > 0): ?>
                                <div class="flex justify-between text-slate-500">
                                    <span>Envío</span>
                                    <span class="font-mono">$<?= number_format($costo_envio, 2) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between font-black text-slate-900 pt-1 border-t border-stone-200">
                                    <span>Total</span>
                                    <span class="font-mono">$<?= number_format($venta['total'], 2) ?></span>
                                </div>
                            </div>

                            <?php if (!empty($venta['comprobante_pago']) || !empty($venta['comprobante_envio']) || !empty($venta['factura_pdf']) || !empty($venta['factura_xml'])): ?>
                            <div class="mt-3 pt-2 border-t border-stone-200 flex flex-wrap gap-3">
                                <?php if (!empty($venta['comprobante_pago'])): ?>
                                <a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$venta['id'] . '&tipo=comprobante') ?>"
                                   target="_blank"
                                   class="flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-800">
                                    Comprobante de pago ↗
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($venta['comprobante_envio'])): ?>
                                <a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$venta['id'] . '&tipo=envio') ?>"
                                   target="_blank"
                                   class="flex items-center gap-1 text-xs font-bold text-emerald-600 hover:text-emerald-800">
                                    Guía de envío ↗
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($venta['factura_pdf'])): ?>
                                <a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$venta['id'] . '&tipo=factura_pdf') ?>"
                                   target="_blank"
                                   class="flex items-center gap-1 text-xs font-bold text-violet-600 hover:text-violet-800">
                                    Factura PDF ↗
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($venta['factura_xml'])): ?>
                                <a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$venta['id'] . '&tipo=factura_xml') ?>"
                                   download
                                   class="flex items-center gap-1 text-xs font-bold text-violet-600 hover:text-violet-800">
                                    Descargar XML
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
    // ── Toast / Confirm ──────────────────────────────────────────────────────
    // showToast y showConfirm centralizados en js/ui-toast.js

        let vtEstActivo = 'todos';

        function vtEst(btn) {
            vtEstActivo = btn.dataset.st;
            document.querySelectorAll('.vt-chip').forEach(b => {
                const on = b.dataset.st === vtEstActivo;
                b.classList.toggle('bg-slate-950', on);
                b.classList.toggle('text-white', on);
                b.classList.toggle('border-slate-950', on);
            });
            vtFiltrar();
        }

        function vtFiltrar() {
            const q     = (document.getElementById('vt-search').value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.vt-card');
            let visible = 0;
            cards.forEach(card => {
                const estOk    = vtEstActivo === 'todos' || card.dataset.estado === vtEstActivo;
                const searchOk = !q || card.dataset.search.includes(q);
                const show     = estOk && searchOk;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('vt-sin-res').classList.toggle('hidden', visible > 0);
        }

        function toggleDetalle(id) {
            const panel = document.getElementById('det-' + id);
            const lbl   = document.getElementById('det-lbl-' + id);
            const chv   = document.getElementById('det-chv-' + id);
            const open  = panel.classList.toggle('hidden');
            chv.textContent = open ? '▾' : '▴';
            lbl.textContent = lbl.textContent.replace(/^[▸▾▴] /, (open ? '▸ ' : '▴ '));
        }

        function abrirUpload(id) {
            document.getElementById('upload-area-' + id).classList.remove('hidden');
        }
        function cerrarUpload(id) {
            document.getElementById('upload-area-' + id).classList.add('hidden');
            const msg = document.getElementById('upload-msg-' + id);
            msg.classList.add('hidden');
            msg.textContent = '';
            document.getElementById('file-input-' + id).value = '';
        }

        async function subirComprobante(id) {
            const fileInput = document.getElementById('file-input-' + id);
            const msgEl    = document.getElementById('upload-msg-' + id);

            if (!fileInput.files.length) {
                mostrarMsg(msgEl, 'Selecciona un archivo primero', 'error');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'subir_comprobante');
            fd.append('pedido_id', id);
            fd.append('comprobante', fileInput.files[0]);

            mostrarMsg(msgEl, 'Subiendo...', 'info');

            try {
                const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    mostrarMsg(msgEl, 'Comprobante guardado', 'ok');
                    setTimeout(() => location.reload(), 900);
                } else {
                    mostrarMsg(msgEl, data.message || 'Error al subir', 'error');
                }
            } catch (e) {
                mostrarMsg(msgEl, 'Error de red', 'error');
            }
        }

        function mostrarMsg(el, texto, tipo) {
            el.classList.remove('hidden');
            el.textContent = texto;
            el.style.color = tipo === 'error' ? '#b42318' : tipo === 'ok' ? '#166534' : '#64748b';
        }

        async function cancelarPedido(id) {
            showConfirm('¿Cancelar el pedido #' + id + '? Esta acción no se puede deshacer.', async () => {
                const fd = new FormData();
                fd.append('action', 'cancelar_pedido');
                fd.append('pedido_id', id);
                try {
                    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        location.reload();
                    } else {
                        showToast(data.message || 'No se pudo cancelar el pedido', 'error');
                    }
                } catch (e) {
                    showToast('Error de red', 'error');
                }
            });
        }
    </script>
    <script>
    // ── Scroll al ancla si se llega desde ventas recientes ───────────────────
    (function () {
        const hash = location.hash;
        if (!hash) return;
        const el = document.querySelector(hash);
        if (!el) return;
        // Esperar el pintado inicial antes de scrollear
        requestAnimationFrame(() => {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Resaltar brevemente la card
            el.style.transition = 'box-shadow .3s, outline .3s';
            el.style.outline = '2px solid #4a70a9';
            el.style.boxShadow = '0 0 0 4px rgba(18,108,106,.15)';
            setTimeout(() => {
                el.style.outline = '';
                el.style.boxShadow = '';
            }, 2200);
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
