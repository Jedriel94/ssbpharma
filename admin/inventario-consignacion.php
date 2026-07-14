<?php
require_once '../includes/auth_admin.php';
require_once '../config/database.php';
require_once '../includes/report_scope.php';

$rol_codigo = $_SESSION['admin_rol_codigo'] ?? '';
$rolesPermitidos = ['admin', 'director_general', 'director_unidad', 'gerente', 'viewer'];
if (!in_array($rol_codigo, $rolesPermitidos, true)) {
    header('Location: ' . url('admin/dashboard.php?error=acceso_denegado'));
    exit;
}

$pdo = Database::getInstance()->getConnection();
$representantesVisibles = report_representantes_visibles($pdo, $authAdminActual ?? []);

// ── AJAX: movimientos de un representante (y opcionalmente un producto) ─────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'movimientos') {
    header('Content-Type: application/json');
    $rep_id  = (int)($_GET['rep_id']  ?? 0);
    $prod_id = (int)($_GET['prod_id'] ?? 0);
    if (!$rep_id || !report_rep_permitido($representantesVisibles, $rep_id)) { echo json_encode([]); exit; }

    $sql = "
         SELECT rim.id, rim.tipo, rim.cantidad, rim.cantidad_antes, rim.cantidad_despues,
               rim.notas, rim.created_at,
             rim.producto_id,
               p.producto,
               COALESCE(a2.nombre, '') AS admin_nombre,
               rim.pedido_id
        FROM representante_inventario_movimientos rim
        INNER JOIN productos p ON p.id = rim.producto_id
        LEFT  JOIN administradores a2 ON a2.id = rim.admin_id
        WHERE rim.representante_admin_id = ?
    ";
    $params = [$rep_id];
    if ($prod_id) {
        $sql .= " AND rim.producto_id = ?";
        $params[] = $prod_id;
    }
    $sql .= " ORDER BY rim.created_at DESC, rim.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Obtener todos los representantes con su inventario agregado ──────────────
$sqlRepresentantes = "
    SELECT
        a.id AS rep_id,
        a.nombre AS rep_nombre,
        a.ruta,
        COALESCE(SUM(ri.cantidad_disponible), 0) AS disponible,
        COALESCE(SUM(ri.cantidad_reservada),  0) AS reservada,
        COALESCE(SUM(ri.cantidad_vendida),    0) AS vendida,
        COUNT(ri.producto_id)                    AS productos
    FROM administradores a
    INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
    INNER JOIN representante_inventario ri ON ri.representante_admin_id = a.id
    WHERE a.activo = 1
";
$scopeRepresentantes = report_scope_sql($representantesVisibles, 'a.id');
$sqlRepresentantes .= $scopeRepresentantes['sql'] . "
    GROUP BY a.id, a.nombre, a.ruta
    ORDER BY a.nombre ASC
";
$stmt = $pdo->prepare($sqlRepresentantes);
$stmt->execute($scopeRepresentantes['params']);
$representantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Obtener detalle por producto de un rep (para drill-down inicial al cargar) ─
// Se carga vía AJAX, pero también precargamos la lista de representantes con productos
// para el selector del modal.
?>
<?php include '../includes/header.php'; ?>

<style>
.inv-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    transition: box-shadow .15s, transform .15s;
    cursor: pointer;
}
.inv-card:hover {
    box-shadow: 0 4px 18px rgba(15,23,42,.12);
    transform: translateY(-2px);
}
/* dark mode automático via header.php override de bg-white */
body.theme-dark .inv-card {
    background: var(--bg-card) !important;
    border-color: var(--border-card) !important;
}

.badge-disponible { background:#dcfce7; color:#166534; }
.badge-reservada  { background:#fef9c3; color:#854d0e; }
.badge-vendida    { background:#e0e7ff; color:#3730a3; }

.tipo-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.tipo-reserva            { background:#fef9c3; color:#92400e; }
.tipo-liberacion_reserva { background:#dcfce7; color:#166534; }
.tipo-venta              { background:#e0e7ff; color:#3730a3; }
.tipo-entrada_consignacion{ background:#cffafe; color:#164e63; }
.tipo-devolucion         { background:#fce7f3; color:#9d174d; }
.tipo-ajuste             { background:#f1f5f9; color:#475569; }
.tipo-cancelacion_venta  { background:#fee2e2; color:#991b1b; }
.tipo-traspaso_salida    { background:#fde68a; color:#78350f; }
.tipo-traspaso_entrada   { background:#bbf7d0; color:#14532d; }

#modalMovimientos {
    display: none;
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(15,23,42,.55);
    align-items: flex-start;
    justify-content: center;
    padding: 24px 12px;
    overflow-y: auto;
}
#modalMovimientos.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 860px;
    box-shadow: 0 20px 60px rgba(15,23,42,.25);
    overflow: hidden;
}
body.theme-dark .modal-box { background: var(--bg-card) !important; }

.modal-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
body.theme-dark .modal-header { background: var(--bg-card-hover) !important; border-color: var(--border-card) !important; }

.mov-table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #fff;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    position: sticky;
    top: 0;
    z-index: 1;
}
.mov-table td {
    padding: 8px 12px;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.mov-table tr:last-child td { border-bottom: none; }
</style>

<div class="p-4 md:p-6 max-w-7xl mx-auto">

    <!-- Título -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">📦 Inventario a Consignación</h1>
            <p class="text-sm text-slate-500 mt-0.5">Stock disponible y en reserva por representante</p>
        </div>
        <a href="solicitudes-consignacion.php"
           class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">
            ← Solicitudes de consignación
        </a>
    </div>

    <?php if (empty($representantes)): ?>
    <div class="text-center py-20 text-slate-400">
        <div class="text-5xl mb-3">📭</div>
        <p class="font-semibold">No hay representantes con inventario asignado</p>
    </div>
    <?php else: ?>

    <!-- Resumen global -->
    <?php
        $totDisp = array_sum(array_column($representantes, 'disponible'));
        $totRes  = array_sum(array_column($representantes, 'reservada'));
        $totVen  = array_sum(array_column($representantes, 'vendida'));
    ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-slate-900"><?= count($representantes) ?></div>
            <div class="text-xs text-slate-500 mt-0.5 font-semibold uppercase tracking-wide">Representantes</div>
        </div>
        <div class="bg-emerald-50 rounded-xl border border-emerald-200 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-emerald-700"><?= number_format($totDisp) ?></div>
            <div class="text-xs text-emerald-600 mt-0.5 font-semibold uppercase tracking-wide">Total Disponible</div>
        </div>
        <div class="bg-yellow-50 rounded-xl border border-yellow-200 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-yellow-700"><?= number_format($totRes) ?></div>
            <div class="text-xs text-yellow-600 mt-0.5 font-semibold uppercase tracking-wide">Total en Reserva</div>
        </div>
        <div class="bg-indigo-50 rounded-xl border border-indigo-200 p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-indigo-700"><?= number_format($totVen) ?></div>
            <div class="text-xs text-indigo-600 mt-0.5 font-semibold uppercase tracking-wide">Total Vendido</div>
        </div>
    </div>

    <!-- Buscador -->
    <div class="mb-4">
        <input type="text" id="buscadorRep"
               oninput="filtrarTarjetas(this.value)"
               placeholder="🔍 Buscar representante..."
               style="background:var(--bg-primary);color:var(--text-primary);border-color:var(--border-color)"
               class="w-full sm:w-80 px-4 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
        <span id="contadorTarjetas" class="ml-3 text-sm text-slate-400"></span>
    </div>

    <!-- Tarjetas de representantes -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" id="gridRepresentantes">
        <?php foreach ($representantes as $rep): ?>
        <div class="inv-card p-5"
             data-nombre="<?= strtolower(htmlspecialchars($rep['rep_nombre'])) ?>"
             onclick="verMovimientos(<?= $rep['rep_id'] ?>, <?= htmlspecialchars(json_encode($rep['rep_nombre']), ENT_QUOTES) ?>)">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <p class="font-bold text-slate-900 text-base leading-tight"><?= htmlspecialchars($rep['rep_nombre']) ?></p>
                    <?php if ($rep['ruta']): ?>
                    <p class="text-xs text-slate-400 mt-0.5">Ruta: <?= htmlspecialchars($rep['ruta']) ?></p>
                    <?php endif; ?>
                </div>
                <span class="text-xs bg-slate-100 text-slate-600 rounded-full px-2 py-0.5 font-semibold whitespace-nowrap">
                    <?= $rep['productos'] ?> producto<?= $rep['productos'] != 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="flex gap-2 flex-wrap">
                <span class="badge-disponible text-xs font-bold px-3 py-1 rounded-full">
                    ✓ <?= number_format($rep['disponible']) ?> disponible
                </span>
                <span class="badge-reservada text-xs font-bold px-3 py-1 rounded-full">
                    ⏳ <?= number_format($rep['reservada']) ?> reserva
                </span>
                <?php if ($rep['vendida'] > 0): ?>
                <span class="badge-vendida text-xs font-bold px-3 py-1 rounded-full">
                    💰 <?= number_format($rep['vendida']) ?> vendido
                </span>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-400 mt-3 text-right">Ver movimientos →</p>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<!-- Modal de movimientos -->
<div id="modalMovimientos">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h2 class="font-bold text-slate-900 text-lg" id="modalTitle">Movimientos</h2>
                <p class="text-xs text-slate-500 mt-0.5" id="modalSubtitle"></p>
            </div>
            <div class="flex items-center gap-2">
                <select id="filtroProducto"
                        onchange="filtrarPorProducto()"
                        class="text-sm border border-slate-300 rounded-lg px-3 py-2 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Todos los productos</option>
                </select>
                <button onclick="cerrarModal()"
                        class="text-slate-400 hover:text-slate-700 text-xl leading-none font-bold px-2">✕</button>
            </div>
        </div>

        <!-- Resumen del representante -->
        <div id="resumenRep" class="flex gap-3 flex-wrap px-6 py-4 border-b border-slate-100 bg-slate-50"></div>

        <!-- Tabla de movimientos -->
        <div style="max-height:60vh; overflow-y:auto">
            <table class="mov-table w-full">
                <thead style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
                    <tr>
                        <th class="text-left">Fecha</th>
                        <th class="text-left">Producto</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Cant.</th>
                        <th class="text-right">Antes</th>
                        <th class="text-right">Después</th>
                        <th class="text-left">Pedido</th>
                        <th class="text-left">Notas</th>
                    </tr>
                </thead>
                <tbody id="tablaMovimientos">
                    <tr><td colspan="8" class="text-center py-10 text-slate-400">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let currentRepId   = null;
let todosMovimientos = [];
let movimientosVista = [];
let productosMap   = {};

const tipoLabels = {
    reserva:              'Reserva',
    liberacion_reserva:   'Lib. Reserva',
    venta:                'Venta',
    entrada_consignacion: 'Entrada Consig.',
    devolucion:           'Devolución',
    ajuste:               'Ajuste',
    cancelacion_venta:    'Cancel. Venta',
    traspaso_salida:      'Traspaso Salida',
    traspaso_entrada:     'Traspaso Entrada',
};

function keyReservaVenta(m) {
    if (!m.pedido_id || !m.producto_id) return null;
    return String(m.pedido_id) + '|' + String(m.producto_id);
}

// Construye una vista operativa del historial:
// - Si una reserva fue consumida completamente por una venta del mismo pedido+producto,
//   se oculta para evitar duplicidad visual (Reserva + Venta por la misma salida).
function construirMovimientosVista(movs) {
    const cronologico = [...movs].reverse();
    const reservasPorClave = new Map();
    const consumidoPorReservaId = new Map();

    cronologico.forEach(m => {
        const cantidad = Number(m.cantidad || 0);
        const clave = keyReservaVenta(m);

        if (m.tipo === 'reserva' && clave && cantidad > 0) {
            if (!reservasPorClave.has(clave)) reservasPorClave.set(clave, []);
            reservasPorClave.get(clave).push({ id: m.id, restante: cantidad });
            return;
        }

        if (m.tipo === 'venta' && clave && cantidad > 0) {
            let porConsumir = cantidad;
            const cola = reservasPorClave.get(clave) || [];
            for (let i = 0; i < cola.length && porConsumir > 0; i++) {
                const r = cola[i];
                if (r.restante <= 0) continue;
                const consumo = Math.min(r.restante, porConsumir);
                r.restante -= consumo;
                porConsumir -= consumo;
                consumidoPorReservaId.set(r.id, (consumidoPorReservaId.get(r.id) || 0) + consumo);
            }
        }
    });

    return movs.flatMap(m => {
        if (m.tipo !== 'reserva') return [m];

        const cantidadOriginal = Number(m.cantidad || 0);
        const consumido = Number(consumidoPorReservaId.get(m.id) || 0);

        // Reserva totalmente consumida por venta: no mostrar.
        if (consumido >= cantidadOriginal && cantidadOriginal > 0) return [];

        // Reserva parcialmente consumida: mostrar solo lo pendiente.
        if (consumido > 0 && consumido < cantidadOriginal) {
            return [{ ...m, cantidad: cantidadOriginal - consumido }];
        }

        return [m];
    });
}

function verMovimientos(repId, repNombre) {
    currentRepId = repId;
    document.getElementById('modalTitle').textContent = '📋 ' + repNombre;
    document.getElementById('modalSubtitle').textContent = 'Cargando movimientos...';
    document.getElementById('tablaMovimientos').innerHTML =
        '<tr><td colspan="8" class="text-center py-10 text-slate-400">⏳ Cargando...</td></tr>';
    document.getElementById('resumenRep').innerHTML = '';
    document.getElementById('filtroProducto').innerHTML = '<option value="">Todos los productos</option>';
    document.getElementById('modalMovimientos').classList.add('open');

    fetch('inventario-consignacion.php?ajax=movimientos&rep_id=' + repId)
        .then(r => r.json())
        .then(data => {
            todosMovimientos = data;
            movimientosVista = construirMovimientosVista(data);

            // Construir mapa de productos para el filtro
            productosMap = {};
            movimientosVista.forEach(m => {
                if (!productosMap[m.producto]) productosMap[m.producto] = m.producto;
            });

            const sel = document.getElementById('filtroProducto');
            Object.keys(productosMap).sort().forEach(nombre => {
                const opt = document.createElement('option');
                opt.value = nombre;
                opt.textContent = nombre;
                sel.appendChild(opt);
            });

            renderMovimientos(movimientosVista);
            renderResumen(movimientosVista);
            document.getElementById('modalSubtitle').textContent =
                movimientosVista.length + ' movimiento' + (movimientosVista.length !== 1 ? 's' : '');
        })
        .catch(() => {
            document.getElementById('tablaMovimientos').innerHTML =
                '<tr><td colspan="8" class="text-center py-8 text-red-500">Error al cargar los movimientos</td></tr>';
        });
}

function filtrarPorProducto() {
    const filtro = document.getElementById('filtroProducto').value;
    const lista  = filtro
        ? movimientosVista.filter(m => m.producto === filtro)
        : movimientosVista;
    renderMovimientos(lista);
    renderResumen(lista);
    document.getElementById('modalSubtitle').textContent =
        lista.length + ' movimiento' + (lista.length !== 1 ? 's' : '') +
        (filtro ? ' · ' + filtro : '');
}

function renderResumen(movs) {
    // Stock actual visible en la vista (suma del ultimo cantidad_despues por producto)
    const ultimoPorProducto = new Map();
    movs.forEach(m => {
        if (!ultimoPorProducto.has(m.producto)) {
            ultimoPorProducto.set(m.producto, Number(m.cantidad_despues || 0));
        }
    });
    const stockActual = Array.from(ultimoPorProducto.values()).reduce((a, b) => a + b, 0);

    // Simplificado: mostrar conteos de tipos
    const conteos = {};
    movs.forEach(m => { conteos[m.tipo] = (conteos[m.tipo] || 0) + m.cantidad; });

    const el = document.getElementById('resumenRep');
    const badgesTipos = Object.entries(conteos).map(([tipo, cant]) =>
        `<span class="tipo-pill tipo-${tipo}">${tipoLabels[tipo] || tipo}: ${cant}</span>`
    ).join('');

    el.innerHTML =
        `<span class="tipo-pill" style="background:#dcfce7;color:#166534">Stock: ${stockActual}</span>` +
        badgesTipos;
}

function renderMovimientos(movs) {
    const tbody = document.getElementById('tablaMovimientos');
    if (!movs.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-10 text-slate-400">Sin movimientos</td></tr>';
        return;
    }
    tbody.innerHTML = movs.map(m => {
        const fecha = m.created_at ? m.created_at.substring(0, 16).replace('T', ' ') : '—';
        const signo = ['entrada_consignacion','liberacion_reserva','devolucion','traspaso_entrada'].includes(m.tipo)
            ? '<span class="text-emerald-600 font-bold">+' + m.cantidad + '</span>'
            : '<span class="text-red-500 font-bold">−' + m.cantidad + '</span>';
        const pedido = m.pedido_id
            ? `<a href="pedido-detalle.php?pedido_id=${m.pedido_id}&full=1&return=inventario_consignacion" target="_blank"
                  class="text-teal-600 underline font-mono text-xs">#${m.pedido_id}</a>`
            : '—';
        return `<tr>
            <td class="font-mono text-xs text-slate-500">${fecha}</td>
            <td class="font-semibold text-slate-800">${escHtml(m.producto)}</td>
            <td><span class="tipo-pill tipo-${m.tipo}">${tipoLabels[m.tipo] || m.tipo}</span></td>
            <td class="text-right">${signo}</td>
            <td class="text-right text-slate-500">${m.cantidad_antes}</td>
            <td class="text-right font-semibold">${m.cantidad_despues}</td>
            <td>${pedido}</td>
            <td class="text-slate-500 text-xs max-w-xs truncate">${escHtml(m.notas || '')}</td>
        </tr>`;
    }).join('');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function cerrarModal() {
    document.getElementById('modalMovimientos').classList.remove('open');
    currentRepId = null;
    todosMovimientos = [];
    movimientosVista = [];
}

function filtrarTarjetas(q) {
    const texto = q.toLowerCase().trim();
    const tarjetas = document.querySelectorAll('#gridRepresentantes .inv-card');
    let visibles = 0;
    tarjetas.forEach(card => {
        const nombre = card.dataset.nombre || '';
        const mostrar = !texto || nombre.includes(texto);
        card.style.display = mostrar ? '' : 'none';
        if (mostrar) visibles++;
    });
    const contador = document.getElementById('contadorTarjetas');
    if (contador) contador.textContent = texto ? visibles + ' resultado' + (visibles !== 1 ? 's' : '') : '';
}

// Cerrar al hacer clic fuera del modal
document.getElementById('modalMovimientos').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') cerrarModal();
});
</script>

<?php include '../includes/footer.php'; ?>
