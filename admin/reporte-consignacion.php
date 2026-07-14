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

$repFiltro = (int)($_GET['representante'] ?? 0);
$productoFiltro = (int)($_GET['producto'] ?? 0);
$exportar  = isset($_GET['export']) && $_GET['export'] === 'excel';
$hoy = date('Y-m-d');
$rawFechaCorte = trim($_GET['fecha_corte'] ?? $hoy);
$dtFechaCorte = DateTimeImmutable::createFromFormat('Y-m-d', $rawFechaCorte);
$fechaCorte = ($dtFechaCorte && $dtFechaCorte->format('Y-m-d') === $rawFechaCorte)
    ? $rawFechaCorte
    : $hoy;
$fechaCorteFin = $fechaCorte . ' 23:59:59';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'movimientos') {
    header('Content-Type: application/json; charset=utf-8');

    $repIdAjax = (int)($_GET['rep_id'] ?? 0);
    $prodIdAjax = (int)($_GET['producto_id'] ?? 0);
    if ($repIdAjax <= 0 || !report_rep_permitido($representantesVisibles, $repIdAjax)) {
        echo json_encode([]);
        exit;
    }

    $sqlAjax = "
        SELECT
            rim.id,
            rim.created_at,
            rim.tipo,
            rim.cantidad,
            rim.cantidad_antes,
            rim.cantidad_despues,
            rim.pedido_id,
            rim.solicitud_consignacion_id,
            rim.notas,
            ped.canal,
            ped.entrega_directa,
            ped.num_factura,
            p.producto
        FROM representante_inventario_movimientos rim
        INNER JOIN administradores a ON a.id = rim.representante_admin_id
        INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
        INNER JOIN productos p ON p.id = rim.producto_id
        LEFT JOIN pedidos ped ON ped.id = rim.pedido_id
        WHERE a.activo = 1
          AND rim.representante_admin_id = ?
          AND rim.created_at <= ?
          AND rim.tipo NOT IN ('reserva', 'liberacion_reserva')
          AND (
              rim.tipo <> 'venta'
              OR rim.pedido_id IS NULL
              OR ped.canal = 'representante_directo'
              OR ped.entrega_directa = 1
          )
    ";
    $paramsAjax = [$repIdAjax, $fechaCorteFin];

    if ($prodIdAjax > 0) {
        $sqlAjax .= " AND rim.producto_id = ?";
        $paramsAjax[] = $prodIdAjax;
    }

    $sqlAjax .= " ORDER BY rim.created_at ASC, rim.id ASC";

    $stmtAjax = $pdo->prepare($sqlAjax);
    $stmtAjax->execute($paramsAjax);
    $movimientosAjax = $stmtAjax->fetchAll(PDO::FETCH_ASSOC);
    $saldoCalculado = 0;

    foreach ($movimientosAjax as &$movimientoAjax) {
        $tipo = $movimientoAjax['tipo'];
        $cantidad = (int)$movimientoAjax['cantidad'];
        $saldoAntes = $saldoCalculado;

        if (in_array($tipo, ['entrada_consignacion', 'devolucion', 'cancelacion_venta', 'traspaso_entrada'], true)) {
            $saldoCalculado += $cantidad;
        } elseif (in_array($tipo, ['venta', 'traspaso_salida'], true)) {
            $saldoCalculado -= $cantidad;
        } elseif ($tipo === 'ajuste') {
            $saldoCalculado += ((int)$movimientoAjax['cantidad_despues'] - (int)$movimientoAjax['cantidad_antes']);
        }

        $movimientoAjax['saldo_antes'] = $saldoAntes;
        $movimientoAjax['saldo_despues'] = $saldoCalculado;
    }
    unset($movimientoAjax);

    echo json_encode(array_reverse($movimientosAjax));
    exit;
}

$where = 'WHERE a.activo = 1';
$params = [];
if ($repFiltro > 0) {
    if (report_rep_permitido($representantesVisibles, $repFiltro)) {
        $where .= ' AND a.id = ?';
        $params[] = $repFiltro;
    } else {
        $where .= ' AND 1=0';
    }
}
if ($productoFiltro > 0) {
    $where .= ' AND p.id = ?';
    $params[] = $productoFiltro;
}
$scopeReporte = report_scope_sql($representantesVisibles, 'a.id');
$where .= $scopeReporte['sql'];
foreach ($scopeReporte['params'] as $scopeParam) {
    $params[] = $scopeParam;
}

$sql = "
    SELECT
        a.id AS representante_id,
        a.nombre AS representante,
        a.ruta,
        p.id AS producto_id,
        p.producto,
        ri.created_at AS inventario_created_at,
        COALESCE(ri.cantidad_disponible, 0) AS cantidad_disponible,
        COALESCE(ri.cantidad_reservada, 0) AS cantidad_reservada,
        COALESCE(ri.cantidad_vendida, 0) AS cantidad_vendida,
        (
            SELECT rp.precio
            FROM rangos_precios rp
            WHERE rp.producto_id = p.id
            ORDER BY rp.cantidad_min ASC
            LIMIT 1
        ) AS precio_base
    FROM representante_inventario ri
    INNER JOIN administradores a ON a.id = ri.representante_admin_id
    INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
    INNER JOIN productos p ON p.id = ri.producto_id
    $where
    ORDER BY a.nombre ASC, p.producto ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rowsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$movimientosPorInventario = [];
if (!empty($rowsRaw)) {
    $sqlMovimientos = "
        SELECT
            rim.representante_admin_id,
            rim.producto_id,
            rim.tipo,
            rim.cantidad,
            rim.cantidad_antes,
            rim.cantidad_despues,
            rim.created_at,
            rim.id
        FROM representante_inventario_movimientos rim
        INNER JOIN representante_inventario ri
            ON ri.representante_admin_id = rim.representante_admin_id
           AND ri.producto_id = rim.producto_id
        INNER JOIN administradores a ON a.id = ri.representante_admin_id
        INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
        INNER JOIN productos p ON p.id = ri.producto_id
        LEFT JOIN pedidos ped ON ped.id = rim.pedido_id
        $where
          AND rim.created_at <= ?
          AND (
              rim.tipo <> 'venta'
              OR rim.pedido_id IS NULL
              OR ped.canal = 'representante_directo'
              OR ped.entrega_directa = 1
          )
        ORDER BY rim.representante_admin_id ASC, rim.producto_id ASC, rim.created_at ASC, rim.id ASC
    ";
    $stmtMovimientos = $pdo->prepare($sqlMovimientos);
    $stmtMovimientos->execute(array_merge($params, [$fechaCorteFin]));
    foreach ($stmtMovimientos->fetchAll(PDO::FETCH_ASSOC) as $movimiento) {
        $key = (int)$movimiento['representante_admin_id'] . ':' . (int)$movimiento['producto_id'];
        $movimientosPorInventario[$key][] = $movimiento;
    }
}

function calcularMetricasFifo(array $movimientos, string $fechaCorte, int $existenciaFallback = 0, ?string $fechaFallback = null): array
{
    $lotes = [];
    $ultimaVenta = null;
    $fechaBase = new DateTimeImmutable($fechaCorte);
    $tiposEntrada = ['entrada_consignacion', 'devolucion', 'cancelacion_venta', 'traspaso_entrada'];
    $tiposSalida = ['venta', 'traspaso_salida'];

    $agregarLote = static function (string $fecha, int $cantidad) use (&$lotes): void {
        if ($cantidad > 0) {
            $lotes[] = ['fecha' => substr($fecha, 0, 10), 'cantidad' => $cantidad];
        }
    };

    $consumirFifo = static function (int $cantidad) use (&$lotes): void {
        for ($i = 0; $i < count($lotes) && $cantidad > 0; $i++) {
            if ($lotes[$i]['cantidad'] <= 0) {
                continue;
            }
            $consumo = min($lotes[$i]['cantidad'], $cantidad);
            $lotes[$i]['cantidad'] -= $consumo;
            $cantidad -= $consumo;
        }
        $lotes = array_values(array_filter($lotes, static fn($lote) => $lote['cantidad'] > 0));
    };

    foreach ($movimientos as $movimiento) {
        $tipo = $movimiento['tipo'];
        $cantidad = max(0, (int)$movimiento['cantidad']);
        $fecha = $movimiento['created_at'] ?: date('Y-m-d');

        if (in_array($tipo, $tiposEntrada, true)) {
            $agregarLote($fecha, $cantidad);
        } elseif (in_array($tipo, $tiposSalida, true)) {
            $consumirFifo($cantidad);
            if ($tipo === 'venta') {
                $ultimaVenta = substr($fecha, 0, 10);
            }
        } elseif ($tipo === 'ajuste') {
            $delta = (int)$movimiento['cantidad_despues'] - (int)$movimiento['cantidad_antes'];
            if ($delta > 0) {
                $agregarLote($fecha, $delta);
            } elseif ($delta < 0) {
                $consumirFifo(abs($delta));
            }
        }
    }

    $totalLotes = array_sum(array_column($lotes, 'cantidad'));
    if ($totalLotes === 0 && $existenciaFallback > 0 && (!$fechaFallback || substr($fechaFallback, 0, 10) <= $fechaCorte)) {
        $agregarLote($fechaFallback ?: $fechaCorte, $existenciaFallback);
        $totalLotes = $existenciaFallback;
    }

    if ($totalLotes <= 0) {
        return ['cantidad_fifo' => 0, 'antiguedad_maxima' => null, 'antiguedad_promedio' => null, 'dias_sin_venta' => null];
    }

    $diasMaximos = 0;
    $diasPonderados = 0;
    foreach ($lotes as $lote) {
        $fechaLote = new DateTimeImmutable($lote['fecha']);
        $dias = (int)$fechaLote->diff($fechaBase)->days;
        $diasMaximos = max($diasMaximos, $dias);
        $diasPonderados += $dias * (int)$lote['cantidad'];
    }

    $diasSinVenta = null;
    if ($ultimaVenta !== null) {
        $diasSinVenta = (int)(new DateTimeImmutable($ultimaVenta))->diff($fechaBase)->days;
    }

    return [
        'cantidad_fifo' => $totalLotes,
        'antiguedad_maxima' => $diasMaximos,
        'antiguedad_promedio' => (int)round($diasPonderados / max(1, $totalLotes)),
        'dias_sin_venta' => $diasSinVenta,
    ];
}

$filas = [];
$totalPiezas = 0;
$totalPesos = 0.0;

foreach ($rowsRaw as $row) {
    $cantidadDisponible = (int)$row['cantidad_disponible'];
    $cantidadReservada  = (int)$row['cantidad_reservada'];
    $cantidadVendida    = (int)$row['cantidad_vendida'];

    $keyInventario = (int)$row['representante_id'] . ':' . (int)$row['producto_id'];
    $metricasFifo = calcularMetricasFifo(
        $movimientosPorInventario[$keyInventario] ?? [],
        $fechaCorte,
        $cantidadDisponible + $cantidadReservada,
        $row['inventario_created_at'] ?? null
    );

    // Piezas vivas al corte: lotes FIFO despues de entradas y salidas hasta la fecha limite.
    $cantidad = (int)$metricasFifo['cantidad_fifo'];
    $precio   = (float)($row['precio_base'] ?? 0);
    $importe  = round($cantidad * $precio, 2);

    if ($cantidad <= 0) {
        continue;
    }

    $filas[] = [
        'representante_id' => (int)$row['representante_id'],
        'producto_id' => (int)$row['producto_id'],
        'representante' => $row['representante'],
        'ruta' => $row['ruta'],
        'producto' => $row['producto'],
        'cantidad' => $cantidad,
        'cantidad_disponible' => $cantidadDisponible,
        'cantidad_reservada' => $cantidadReservada,
        'cantidad_vendida' => $cantidadVendida,
        'precio' => $precio,
        'importe' => $importe,
        'antiguedad_maxima' => $metricasFifo['antiguedad_maxima'],
        'antiguedad_promedio' => $metricasFifo['antiguedad_promedio'],
        'dias_sin_venta' => $metricasFifo['dias_sin_venta'],
    ];

    $totalPiezas += $cantidad;
    $totalPesos += $importe;
}

if ($exportar) {
    $nombreArchivo = 'reporte_inventario_consignacion_corte_' . str_replace('-', '', $fechaCorte) . '_' . date('His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Fecha corte', 'Representante', 'Producto', 'Cantidad al corte', 'Precio', 'Importe', 'Antiguedad maxima (dias)', 'Antiguedad promedio (dias)', 'Dias sin venta']);
    foreach ($filas as $f) {
        fputcsv($out, [
            $fechaCorte,
            $f['representante'],
            $f['producto'],
            $f['cantidad'],
            number_format((float)$f['precio'], 2, '.', ''),
            number_format((float)$f['importe'], 2, '.', ''),
            $f['antiguedad_maxima'] ?? '',
            $f['antiguedad_promedio'] ?? '',
            $f['dias_sin_venta'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

$sqlRep = "SELECT a.id, a.nombre, a.ruta
           FROM administradores a
           INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
           WHERE a.activo = 1";
$scopeRep = report_scope_sql($representantesVisibles, 'a.id');
$sqlRep .= $scopeRep['sql'] . " ORDER BY a.nombre ASC";
$stmtRep = $pdo->prepare($sqlRep);
$stmtRep->execute($scopeRep['params']);
$representantes = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

$sqlProd = "SELECT DISTINCT p.id, p.producto
            FROM representante_inventario ri
            INNER JOIN productos p ON p.id = ri.producto_id
            INNER JOIN administradores a ON a.id = ri.representante_admin_id
            WHERE a.activo = 1";
$scopeProd = report_scope_sql($representantesVisibles, 'a.id');
$sqlProd .= $scopeProd['sql'] . " ORDER BY p.producto ASC";
$stmtProd = $pdo->prepare($sqlProd);
$stmtProd->execute($scopeProd['params']);
$productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<style>
.rc-card  { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 14px; }
.rc-stat  { padding: 16px 20px; }
.rc-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px; }
.rc-stat-val   { font-size: 24px; font-weight: 800; color: var(--text-primary); }
.rc-filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.rc-label  { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.rc-input, .rc-select {
    height: 36px; padding: 0 12px;
    border-radius: 9px; border: 1px solid var(--border-input);
    background: var(--bg-input); color: var(--text-primary);
    font-size: 13px;
}
.rc-select {
    padding-right: 28px;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23aaa' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 9px center;
}
.rc-btn {
    height: 36px; padding: 0 16px; border-radius: 9px; border: none;
    font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity .15s;
}
.rc-btn:hover { opacity: .85; }
.rc-btn-primary { background: var(--accent); color: #fff; }
.rc-btn-outline { background: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-card); }
.rc-btn-green   { background: #16a34a; color: #fff; }

.rc-table-wrap  { overflow-x: auto; }
.rc-table       { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.rc-table thead th {
    background: linear-gradient(to right, var(--tw-neu-800), var(--tw-neu-900));
    color: #fff;
    padding: 9px 12px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 2;
}
body.theme-dark .rc-table thead th {
    background: linear-gradient(to right, var(--tw-neu-100), var(--tw-neu-50));
}
.rc-table tbody tr { border-bottom: 1px solid var(--border-card); }
.rc-table tbody tr:hover { background: var(--bg-card-hover); }
.rc-table tbody td { padding: 8px 12px; color: var(--text-primary); white-space: nowrap; }
.rc-empty { text-align:center; padding: 60px 20px; color: var(--text-muted); font-size: 15px; }
.rc-link-btn { background: none; border: 0; padding: 0; color: var(--accent); font-weight: 700; cursor: pointer; text-align: left; }
.rc-link-btn:hover { text-decoration: underline; }
.rc-modal {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(15,23,42,.55); padding: 24px 12px;
    align-items: flex-start; justify-content: center; overflow-y: auto;
}
.rc-modal.open { display: flex; }
.rc-modal-box {
    width: 100%; max-width: 980px; background: var(--bg-card);
    border: 1px solid var(--border-card); border-radius: 14px;
    box-shadow: 0 20px 60px rgba(15,23,42,.25); overflow: hidden;
}
.rc-modal-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
    padding: 16px 20px; border-bottom: 1px solid var(--border-card);
    background: var(--bg-card-hover);
}
.rc-modal-title { font-size: 16px; font-weight: 800; color: var(--text-primary); }
.rc-modal-sub { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.rc-close { border: 0; background: transparent; color: var(--text-muted); font-size: 24px; line-height: 1; cursor: pointer; }
.rc-mov-wrap { max-height: 62vh; overflow: auto; }
.rc-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.tipo-entrada_consignacion, .tipo-traspaso_entrada { background:#dcfce7; color:#166534; }
.tipo-venta, .tipo-traspaso_salida { background:#fee2e2; color:#991b1b; }
.tipo-devolucion, .tipo-cancelacion_venta, .tipo-liberacion_reserva { background:#dbeafe; color:#1e40af; }
.tipo-reserva { background:#fef9c3; color:#854d0e; }
.tipo-ajuste { background:#f1f5f9; color:#475569; }
</style>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--text-primary)">Inventario en Consignacion</h1>
            <p style="color:var(--text-muted);font-size:13px;margin-top:4px">
                Antiguedad FIFO y piezas al corte <?= htmlspecialchars($fechaCorte) ?>.
            </p>
        </div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>"
           class="rc-btn rc-btn-green flex items-center gap-2">
            Descargar Excel
        </a>
    </div>

    <div class="rc-card mb-6" style="padding:16px 20px">
        <form method="GET" class="rc-filter-bar">
            <div>
                <div class="rc-label">Representante</div>
                <select name="representante" class="rc-select" style="min-width:260px">
                    <option value="0">Todos</option>
                    <?php foreach ($representantes as $rep): ?>
                    <option value="<?= (int)$rep['id'] ?>" <?= $repFiltro === (int)$rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['ruta'] ? '[' . $rep['ruta'] . '] ' . $rep['nombre'] : $rep['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="rc-label">Producto</div>
                <select name="producto" class="rc-select" style="min-width:320px">
                    <option value="0">Todos</option>
                    <?php foreach ($productos as $prod): ?>
                    <option value="<?= (int)$prod['id'] ?>" <?= $productoFiltro === (int)$prod['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($prod['producto']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="rc-label">Fecha corte</div>
                <input type="date" name="fecha_corte" class="rc-input"
                       value="<?= htmlspecialchars($fechaCorte) ?>"
                       style="width:150px">
            </div>
            <div style="display:flex;gap:8px;align-items:flex-end">
                <button type="submit" class="rc-btn rc-btn-primary">Filtrar</button>
                <a href="reporte-consignacion.php" class="rc-btn rc-btn-outline" style="display:flex;align-items:center;justify-content:center">Limpiar</a>
            </div>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="rc-card rc-stat">
            <div class="rc-stat-label">Total piezas al corte</div>
            <div class="rc-stat-val"><?= number_format($totalPiezas) ?></div>
        </div>
        <div class="rc-card rc-stat">
            <div class="rc-stat-label">Total pesos</div>
            <div class="rc-stat-val">$<?= number_format($totalPesos, 2) ?></div>
        </div>
    </div>

    <div class="rc-card" style="overflow:hidden">
        <div class="rc-table-wrap" style="max-height:65vh">
            <?php if (empty($filas)): ?>
            <div class="rc-empty">Sin registros para el filtro seleccionado.</div>
            <?php else: ?>
            <table class="rc-table">
                <thead>
                    <tr>
                        <th>Representante</th>
                        <th>Ruta</th>
                        <th>Producto</th>
                        <th>Piezas al Corte</th>
                        <th title="Inventario disponible vigente">Disponible Vig.</th>
                        <th title="Inventario reservado vigente">Reservada Vig.</th>
                        <th title="Inventario vendido acumulado vigente">Vendida Vig.</th>
                        <th>Precio</th>
                        <th>Importe</th>
                        <th title="Antiguedad del lote mas viejo que queda en existencia, usando FIFO">Antig. Máx.</th>
                        <th title="Promedio ponderado por piezas de los lotes vivos, usando FIFO">Antig. Prom.</th>
                        <th title="Dias desde la ultima venta registrada del producto por representante">Días sin Venta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $f): ?>
                    <tr>
                        <td>
                            <button type="button"
                                    class="rc-link-btn"
                                    onclick="verMovimientosRep(<?= (int)$f['representante_id'] ?>, <?= (int)$f['producto_id'] ?>, <?= htmlspecialchars(json_encode($f['representante']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['producto']), ENT_QUOTES) ?>)">
                                <?= htmlspecialchars($f['representante']) ?>
                            </button>
                        </td>
                        <td><?= htmlspecialchars($f['ruta'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($f['producto']) ?></td>
                        <td><?= number_format((int)$f['cantidad']) ?></td>
                        <td><?= number_format((int)$f['cantidad_disponible']) ?></td>
                        <td><?= number_format((int)$f['cantidad_reservada']) ?></td>
                        <td><?= number_format((int)$f['cantidad_vendida']) ?></td>
                        <td>$<?= number_format((float)$f['precio'], 2) ?></td>
                        <td>$<?= number_format((float)$f['importe'], 2) ?></td>
                        <td><?php
                            $d = $f['antiguedad_maxima'];
                            echo $d === null ? '<span style="color:#94a3b8">—</span>' : '<strong>' . (int)$d . 'd</strong>';
                        ?></td>
                        <td><?php
                            $d = $f['antiguedad_promedio'];
                            echo $d === null ? '<span style="color:#94a3b8">—</span>' : '<strong>' . (int)$d . 'd</strong>';
                        ?></td>
                        <td><?php
                            $d = $f['dias_sin_venta'];
                            echo $d === null ? '<span style="color:#94a3b8">Sin ventas</span>' : '<strong>' . (int)$d . 'd</strong>';
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="modalMovimientosConsignacion" class="rc-modal">
    <div class="rc-modal-box">
        <div class="rc-modal-head">
            <div>
                <div class="rc-modal-title" id="movModalTitle">Movimientos</div>
                <div class="rc-modal-sub" id="movModalSub">Fecha corte <?= htmlspecialchars($fechaCorte) ?></div>
            </div>
            <button type="button" class="rc-close" onclick="cerrarMovimientosRep()" aria-label="Cerrar">&times;</button>
        </div>
        <div class="rc-mov-wrap">
            <table class="rc-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Producto</th>
                        <th>Tipo</th>
                        <th>Cant.</th>
                        <th>Saldo Antes</th>
                        <th>Saldo Despues</th>
                        <th>Solicitud</th>
                        <th>Pedido</th>
                        <th>Docto</th>
                        <th>Notas</th>
                    </tr>
                </thead>
                <tbody id="movModalBody">
                    <tr><td colspan="10" class="rc-empty">Selecciona un representante.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const rcFechaCorte = <?= json_encode($fechaCorte) ?>;
let rcProductoDetalle = '';
const rcTipoLabels = {
    entrada_consignacion: 'Entrada',
    venta: 'Venta',
    reserva: 'Reserva',
    liberacion_reserva: 'Lib. reserva',
    devolucion: 'Devolucion',
    ajuste: 'Ajuste',
    cancelacion_venta: 'Cancelacion',
    traspaso_salida: 'Traspaso salida',
    traspaso_entrada: 'Traspaso entrada'
};

function escHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function signoMovimiento(tipo) {
    return ['entrada_consignacion', 'devolucion', 'cancelacion_venta', 'traspaso_entrada', 'liberacion_reserva'].includes(tipo) ? '+' : '-';
}

function verMovimientosRep(repId, productoId, repNombre, productoNombre) {
    const modal = document.getElementById('modalMovimientosConsignacion');
    const body = document.getElementById('movModalBody');
    rcProductoDetalle = productoNombre;
    document.getElementById('movModalTitle').textContent = repNombre;
    document.getElementById('movModalSub').textContent = productoNombre + ' · movimientos hasta ' + rcFechaCorte;
    body.innerHTML = '<tr><td colspan="10" class="rc-empty">Cargando movimientos...</td></tr>';
    modal.classList.add('open');

    const params = new URLSearchParams({
        ajax: 'movimientos',
        rep_id: String(repId),
        producto_id: String(productoId),
        fecha_corte: rcFechaCorte
    });

    fetch('reporte-consignacion.php?' + params.toString())
        .then(response => response.json())
        .then(movs => renderMovimientosRep(movs))
        .catch(() => {
            body.innerHTML = '<tr><td colspan="10" class="rc-empty" style="color:#dc2626">No se pudieron cargar los movimientos.</td></tr>';
        });
}

function renderMovimientosRep(movs) {
    const body = document.getElementById('movModalBody');
    document.getElementById('movModalSub').textContent =
        rcProductoDetalle + ' · ' + movs.length + ' movimiento' + (movs.length === 1 ? '' : 's') + ' hasta ' + rcFechaCorte;

    if (!movs.length) {
        body.innerHTML = '<tr><td colspan="10" class="rc-empty">Sin movimientos para el corte seleccionado.</td></tr>';
        return;
    }

    body.innerHTML = movs.map(m => {
        const tipo = String(m.tipo || '');
        const signo = signoMovimiento(tipo);
        const cantidad = Number(m.cantidad || 0);
        const pedido = m.pedido_id
            ? '<a class="rc-link-btn" href="pedido-detalle.php?pedido_id=' + encodeURIComponent(m.pedido_id) + '&full=1" target="_blank">#' + escHtml(m.pedido_id) + '</a>'
            : '<span style="color:#94a3b8">-</span>';
        const solicitud = m.solicitud_consignacion_id
            ? '<span style="font-family:monospace">#' + escHtml(m.solicitud_consignacion_id) + '</span>'
            : '<span style="color:#94a3b8">-</span>';
        const nota = m.num_factura
            ? '<span style="font-family:monospace">' + escHtml(m.num_factura) + '</span>'
            : '<span style="color:#94a3b8">-</span>';

        return '<tr>' +
            '<td style="font-family:monospace">' + escHtml(String(m.created_at || '').substring(0, 16)) + '</td>' +
            '<td>' + escHtml(m.producto) + '</td>' +
            '<td><span class="rc-pill tipo-' + escHtml(tipo) + '">' + escHtml(rcTipoLabels[tipo] || tipo) + '</span></td>' +
            '<td style="text-align:right;font-weight:700">' + signo + numberFormat(cantidad) + '</td>' +
            '<td style="text-align:right">' + numberFormat(Number(m.saldo_antes || 0)) + '</td>' +
            '<td style="text-align:right;font-weight:700">' + numberFormat(Number(m.saldo_despues || 0)) + '</td>' +
            '<td>' + solicitud + '</td>' +
            '<td>' + pedido + '</td>' +
            '<td>' + nota + '</td>' +
            '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis" title="' + escHtml(m.notas) + '">' + escHtml(m.notas || '') + '</td>' +
        '</tr>';
    }).join('');
}

function numberFormat(value) {
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(value);
}

function cerrarMovimientosRep() {
    document.getElementById('modalMovimientosConsignacion').classList.remove('open');
}

document.getElementById('modalMovimientosConsignacion').addEventListener('click', function(event) {
    if (event.target === this) {
        cerrarMovimientosRep();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        cerrarMovimientosRep();
    }
});
</script>

<?php include '../includes/footer.php'; ?>
