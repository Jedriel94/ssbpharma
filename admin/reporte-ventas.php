<?php
require_once '../includes/auth_admin.php';
require_once '../models/Configuracion.php';
require_once '../config/database.php';
require_once '../includes/report_scope.php';

$rol_codigo = $_SESSION['admin_rol_codigo'] ?? '';
$rolesPermitidos = ['admin', 'director_general', 'director_unidad', 'gerente', 'viewer'];
if (!in_array($rol_codigo, $rolesPermitidos, true)) {
    header('Location: ' . url('admin/dashboard.php?error=acceso_denegado'));
    exit;
}

// ── Umbral de estado (desde configuración del sistema) ─────────────────────
$umbral = Configuracion::get('dashboard_estado_ventas', 'entregado');
$umbralMap = [
    'confirmado' => ['confirmado', 'en_ruta', 'entregado'],
    'en_ruta'    => ['en_ruta', 'entregado'],
    'entregado'  => ['entregado'],
];
$estadosValidos = $umbralMap[$umbral] ?? ['entregado'];

// ── Filtros ────────────────────────────────────────────────────────────────
$hoy       = date('Y-m-d');
$primerDia = date('Y-m-01');
$desde     = $_GET['desde'] ?? $primerDia;
$hasta     = $_GET['hasta'] ?? $hoy;
$repFiltro = $_GET['representante'] ?? '';
$sinIva    = isset($_GET['sin_iva']) && $_GET['sin_iva'] === '1';
$exportar  = isset($_GET['export']) && $_GET['export'] === 'csv';

// Sanitize
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : $primerDia;
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : $hoy;

// ── Construir query ────────────────────────────────────────────────────────
$pdo = Database::getInstance()->getConnection();
$representantesVisibles = report_representantes_visibles($pdo, $authAdminActual ?? []);

// Placeholders para estados
$placeholders = implode(',', array_fill(0, count($estadosValidos), '?'));

$whereExtras  = '';
$params       = [];
// Estados
foreach ($estadosValidos as $e) $params[] = $e;
// Fechas
$params[] = $desde . ' 00:00:00';
$params[] = $hasta . ' 23:59:59';
// Representante opcional
if ($repFiltro !== '') {
    $repFiltroInt = (int)$repFiltro;
    if (report_rep_permitido($representantesVisibles, $repFiltroInt)) {
        $whereExtras .= ' AND p.representante_admin_id = ?';
        $params[] = $repFiltroInt;
    } else {
        $whereExtras .= ' AND 1=0';
    }
}
$scopeReporte = report_scope_sql($representantesVisibles, 'p.representante_admin_id');
$whereExtras .= $scopeReporte['sql'];
foreach ($scopeReporte['params'] as $scopeParam) {
    $params[] = $scopeParam;
}

$sql = "
    SELECT
        p.id                                                    AS pedido_id,
        p.num_factura                                           AS no_nota,
        YEAR(p.created_at)                                      AS anio,
        MONTH(p.created_at)                                     AS mes,
        a.ruta                                                  AS ruta,
        a.nombre                                                AS nombre_rm,
        'Salud y Bienestar'                                     AS unidad_negocio,
        pr.marca                                                AS marca,
        pr.codigo_barras                                        AS ean,
        pr.producto                                             AS producto,
        dp.cantidad                                             AS piezas,
        IF(?, dp.precio_unitario / (1 + dp.impuesto), dp.precio_unitario) AS importe_unitario,
        IF(?, dp.subtotal        / (1 + dp.impuesto), dp.subtotal)        AS importe_total,
        c.id                                                    AS no_cliente,
        c.nombre                                                AS nombre_cliente,
        c.especialidad                                          AS especialidad,
        p.cp_envio                                              AS cp,
        p.ciudad                                                AS municipio,
        p.estado_envio                                          AS estado_cliente,
        CASE
            WHEN (
                SELECT COUNT(*)
                FROM pedidos p2
                WHERE p2.cliente_id = p.cliente_id
                  AND p2.id < p.id
                  AND p2.estado IN ($placeholders)
            ) = 0 THEN 'Nuevo'
            ELSE 'ReCompra'
        END                                                     AS estatus_cliente
    FROM detalle_pedidos dp
    JOIN pedidos   p  ON dp.pedido_id  = p.id
    JOIN clientes  c  ON p.cliente_id  = c.id
    JOIN productos pr ON dp.producto_id = pr.id
    LEFT JOIN administradores a ON p.representante_admin_id = a.id
    WHERE p.estado IN ($placeholders)
      AND p.created_at BETWEEN ? AND ?
      $whereExtras
    ORDER BY p.created_at DESC, p.id ASC, dp.id ASC
";

// Orden: sin_iva x2 (para los IF), estados subquery, estados WHERE, fechas, [rep]
$sinIvaInt      = $sinIva ? 1 : 0;
$paramsCompletos = array_merge([$sinIvaInt, $sinIvaInt], $estadosValidos, $params);

$stmt = $pdo->prepare($sql);
$stmt->execute($paramsCompletos);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Exportar CSV ──────────────────────────────────────────────────────────
if ($exportar) {
    $nombreArchivo = 'reporte_ventas_' . $desde . '_' . $hasta . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // BOM para Excel con UTF-8
    fputs($out, "\xEF\xBB\xBF");

    // Cabecera
    fputcsv($out, [
        'No. Nota','Año','Mes','Ruta','Nombre RM','Unidad de Negocio','Marca',
        'EAN','Producto','Piezas','Importe Unitario','Importe Total',
        'No. Cliente','Nombre del cliente','Especialidad','CP','Municipio',
        'Estado','Estatus de cliente'
    ]);

    // Datos
    foreach ($filas as $f) {
        fputcsv($out, [
            $f['no_nota'],
            $f['anio'],
            $f['mes'],
            $f['ruta'],
            $f['nombre_rm'],
            $f['unidad_negocio'],
            $f['marca'],
            $f['ean'],
            $f['producto'],
            $f['piezas'],
            number_format((float)$f['importe_unitario'], 2, '.', ''),
            number_format((float)$f['importe_total'],    2, '.', ''),
            $f['no_cliente'],
            $f['nombre_cliente'],
            $f['especialidad'],
            $f['cp'],
            $f['municipio'],
            $f['estado_cliente'],
            $f['estatus_cliente'],
        ]);
    }

    fclose($out);
    exit;
}

// ── Representantes para filtro ─────────────────────────────────────────────
$sqlRep = "
    SELECT a.id, a.nombre, a.ruta
    FROM administradores a
    INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
    WHERE a.activo = 1
";
$paramsRep = [];
$scopeRep = report_scope_sql($representantesVisibles, 'a.id');
$sqlRep .= $scopeRep['sql'] . " ORDER BY a.nombre ASC";
$paramsRep = $scopeRep['params'];
$stmtRep = $pdo->prepare($sqlRep);
$stmtRep->execute($paramsRep);
$representantes = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

// ── Totales rápidos ────────────────────────────────────────────────────────
$totalFilas    = count($filas);
$totalPiezas   = array_sum(array_column($filas, 'piezas'));
$totalImporte  = array_sum(array_column($filas, 'importe_total'));
?>
<?php include '../includes/header.php'; ?>

<style>
.rv-card  { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 14px; }
.rv-stat  { padding: 16px 20px; }
.rv-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px; }
.rv-stat-val   { font-size: 24px; font-weight: 800; color: var(--text-primary); }
.rv-filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.rv-label  { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.rv-input, .rv-select {
    height: 36px; padding: 0 12px;
    border-radius: 9px; border: 1px solid var(--border-input);
    background: var(--bg-input); color: var(--text-primary);
    font-size: 13px;
}
.rv-select { padding-right: 28px; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23aaa' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center;
}
.rv-btn {
    height: 36px; padding: 0 16px; border-radius: 9px; border: none;
    font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity .15s;
}
.rv-btn:hover { opacity: .85; }
.rv-btn-primary { background: var(--accent); color: #fff; }
.rv-btn-outline { background: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-card); }
.rv-btn-green   { background: #16a34a; color: #fff; }

/* Tabla */
.rv-table-wrap  { overflow-x: auto; }
.rv-table       { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.rv-table thead th {
    background: linear-gradient(to right, var(--tw-neu-800), var(--tw-neu-900));
    color: #fff;
    padding: 9px 12px; text-align: left; font-size: 11px; font-weight: 700;
    letter-spacing: .04em; text-transform: uppercase; white-space: nowrap;
    position: sticky; top: 0; z-index: 2;
}
body.theme-dark .rv-table thead th {
    background: linear-gradient(to right, var(--tw-neu-100), var(--tw-neu-50));
}
.rv-table tbody tr { border-bottom: 1px solid var(--border-card); }
.rv-table tbody tr:hover { background: var(--bg-card-hover); }
.rv-table tbody td { padding: 8px 12px; color: var(--text-primary); white-space: nowrap; }
.rv-badge-nuevo    { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; background:#dcfce7; color:#166534; }
.rv-badge-recompra { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; background:#dbeafe; color:#1e40af; }
body.theme-dark .rv-badge-nuevo    { background:#14532d; color:#bbf7d0; }
body.theme-dark .rv-badge-recompra { background:#1e3a5f; color:#bfdbfe; }
.rv-empty { text-align:center; padding: 60px 20px; color: var(--text-muted); font-size: 15px; }
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Encabezado -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--text-primary)">Reporte Semanal de Ventas</h1>
            <p style="color:var(--text-muted);font-size:13px;margin-top:4px">
                Layout Columbia · Estado mínimo: <strong><?= htmlspecialchars($umbral) ?></strong>
            </p>
        </div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
           class="rv-btn rv-btn-green flex items-center gap-2">
            Descargar CSV
        </a>
    </div>

    <!-- Filtros -->
    <div class="rv-card mb-6" style="padding:16px 20px">
        <form method="GET" class="rv-filter-bar">
            <div>
                <div class="rv-label">Desde</div>
                <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="rv-input">
            </div>
            <div>
                <div class="rv-label">Hasta</div>
                <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="rv-input">
            </div>
            <?php if (!empty($representantes)): ?>
            <div>
                <div class="rv-label">Representante</div>
                <select name="representante" class="rv-select" style="min-width:180px">
                    <option value="">Todos</option>
                    <?php foreach ($representantes as $rep): ?>
                    <option value="<?= $rep['id'] ?>" <?= $repFiltro == $rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['ruta'] ? '[' . $rep['ruta'] . '] ' . $rep['nombre'] : $rep['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:8px;align-items:flex-end">
                <label class="flex items-center gap-2 cursor-pointer select-none" style="height:36px;padding:0 14px;border-radius:9px;border:1px solid var(--border-input);background:var(--bg-input);font-size:13px;font-weight:600;color:var(--text-primary)" title="Mostrar importes sin IVA">
                    <input type="checkbox" name="sin_iva" value="1" <?= $sinIva ? 'checked' : '' ?> onchange="this.form.submit()" class="w-4 h-4 rounded">
                    Sin IVA
                </label>
                <button type="submit" class="rv-btn rv-btn-primary">Filtrar</button>
                <a href="reporte-ventas.php" class="rv-btn rv-btn-outline" style="display:flex;align-items:center;justify-content:center">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="rv-card rv-stat">
            <div class="rv-stat-label">Líneas de detalle</div>
            <div class="rv-stat-val"><?= number_format($totalFilas) ?></div>
        </div>
        <div class="rv-card rv-stat">
            <div class="rv-stat-label">Total piezas</div>
            <div class="rv-stat-val"><?= number_format($totalPiezas) ?></div>
        </div>
        <div class="rv-card rv-stat">
            <div class="rv-stat-label">Importe total <?= $sinIva ? '(sin IVA)' : '(con IVA)' ?></div>
            <div class="rv-stat-val">$<?= number_format($totalImporte, 2) ?></div>
        </div>
    </div>

    <!-- Tabla -->
    <div class="rv-card" style="overflow:hidden">
        <div class="rv-table-wrap" style="max-height:65vh">
            <?php if (empty($filas)): ?>
            <div class="rv-empty">Sin registros para el período seleccionado.</div>
            <?php else: ?>
            <table class="rv-table">
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>No. Nota</th>
                        <th>Año</th>
                        <th>Mes</th>
                        <th>Ruta</th>
                        <th>Nombre RM</th>
                        <th>Unidad de Negocio</th>
                        <th>Marca</th>
                        <th>EAN</th>
                        <th>Producto</th>
                        <th>Piezas</th>
                        <th>Importe Unitario</th>
                        <th>Importe Total</th>
                        <th>No. Cliente</th>
                        <th>Nombre del cliente</th>
                        <th>Especialidad</th>
                        <th>CP</th>
                        <th>Municipio</th>
                        <th>Estado</th>
                        <th>Estatus de cliente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $f): ?>
                    <tr>
                        <td style="font-family:monospace">#<?= (int)$f['pedido_id'] ?></td>
                        <td><?= htmlspecialchars($f['no_nota'] ?? '') ?></td>
                        <td><?= $f['anio'] ?></td>
                        <td><?= $f['mes'] ?></td>
                        <td><?= htmlspecialchars($f['ruta'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['nombre_rm'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['unidad_negocio']) ?></td>
                        <td><?= htmlspecialchars($f['marca'] ?? '—') ?></td>
                        <td style="font-family:monospace"><?= htmlspecialchars($f['ean'] ?? '—') ?></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($f['producto']) ?>"><?= htmlspecialchars($f['producto']) ?></td>
                        <td style="text-align:right;font-weight:700"><?= (int)$f['piezas'] ?></td>
                        <td style="text-align:right">$<?= number_format((float)$f['importe_unitario'], 2) ?></td>
                        <td style="text-align:right;font-weight:700">$<?= number_format((float)$f['importe_total'], 2) ?></td>
                        <td style="font-family:monospace"><?= $f['no_cliente'] ?></td>
                        <td><?= htmlspecialchars($f['nombre_cliente'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['especialidad'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['cp'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['municipio'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['estado_cliente'] ?? '—') ?></td>
                        <td>
                            <?php if ($f['estatus_cliente'] === 'Nuevo'): ?>
                                <span class="rv-badge-nuevo">Nuevo</span>
                            <?php else: ?>
                                <span class="rv-badge-recompra">ReCompra</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php if (!empty($filas)): ?>
        <div style="padding:10px 16px;border-top:1px solid var(--border-card);font-size:12px;color:var(--text-muted)">
            <?= number_format($totalFilas) ?> líneas · <?= number_format($totalPiezas) ?> piezas · $<?= number_format($totalImporte,2) ?> importe total (sin IVA)
        </div>
        <?php endif; ?>
    </div>

</div>

<?php include '../includes/footer.php'; ?>
