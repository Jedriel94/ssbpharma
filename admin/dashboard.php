<?php
require_once '../includes/auth_admin.php';
require_once '../models/Administrador.php';
require_once '../config/database.php';

$adminModel = new Administrador();
$admin = $adminModel->getById($_SESSION['admin_id']);
$rol_codigo = $admin['rol_codigo'] ?? 'admin';
$rol_nombre = $admin['rol_nombre'] ?? 'Administrador';

// Cargar Gerentes de Distrito subordinados (solo visible para Gerente Nacional = director_unidad)
$gerentes_distrito = [];
if ($rol_codigo === 'director_unidad') {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT a.id, a.nombre
        FROM administradores a
        INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'gerente'
        WHERE a.superior_id = ?
          AND a.activo = 1
        ORDER BY a.nombre ASC
    ");
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $gerentes_distrito = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Representantes para filtro del Reporte de Ventas
$reps_para_filtro_rv = [];
if (!in_array($rol_codigo, ['representante'])) {
    $db_rv = Database::getInstance()->getConnection();
    $repSql = "SELECT a.id, a.nombre
               FROM administradores a
               INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
               WHERE a.activo = 1";
    $repParams = [];
    if ($rol_codigo === 'gerente') {
        $repSql .= " AND a.superior_id = ?";
        $repParams[] = (int)$_SESSION['admin_id'];
    } elseif ($rol_codigo === 'director_unidad') {
        $repSql .= " AND a.superior_id IN (
            SELECT a2.id FROM administradores a2
            INNER JOIN roles r2 ON r2.id = a2.rol_id AND r2.codigo = 'gerente'
            WHERE a2.superior_id = ?
        )";
        $repParams[] = (int)$_SESSION['admin_id'];
    }
    $repSql .= " ORDER BY a.nombre ASC";
    $stmtRv = $db_rv->prepare($repSql);
    $stmtRv->execute($repParams);
    $reps_para_filtro_rv = $stmtRv->fetchAll(PDO::FETCH_ASSOC);
}

// Títulos según rol
$titulo = match($rol_codigo) {
    'admin' => 'Centro de Mando Global',
    'director_general' => 'Dashboard Estratégico',
    'director_unidad' => 'Dashboard de Unidad',
    'gerente' => 'Dashboard de Equipo',
    'representante' => 'Mi Dashboard Personal',
    default => 'Dashboard'
};
?>

<?php include '../includes/header.php'; ?>

<div class="dashboard-page container mx-auto px-4 py-8">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900"><?= $titulo ?></h1>
            <p class="text-slate-600 mt-2">
                <?= htmlspecialchars($admin['nombre']) ?> • <?= $rol_nombre ?>
            </p>
        </div>

        <div class="flex flex-col gap-2 items-end">
            <?php if ($rol_codigo === 'director_unidad' && !empty($gerentes_distrito)): ?>
            <!-- Filtro por Gerente de Distrito (solo Gerente Nacional) -->
            <div class="flex items-center gap-2">
                <label class="text-xs font-semibold text-slate-500 whitespace-nowrap">Gerente de Distrito:</label>
                <select id="filtroGerente"
                        onchange="onFiltroGerente(this)"
                        class="text-sm border border-slate-300 bg-white text-slate-700 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
                    <option value="">— Todos —</option>
                    <?php foreach ($gerentes_distrito as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Filtros de Fecha -->
            <div class="flex gap-3 flex-wrap">
                <button onclick="setRangoFecha('hoy')" class="rango-btn px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-sm font-semibold transition">
                    Hoy
                </button>
                <button onclick="setRangoFecha('semana')" class="rango-btn px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-sm font-semibold transition">
                    Esta Semana
                </button>
                <button onclick="setRangoFecha('mes')" class="rango-btn px-4 py-2 rounded-xl text-sm font-semibold transition" style="background:var(--accent);color:var(--accent-text,#fff)">
                    Este Mes
                </button>
                <button onclick="setRangoFecha('trimestre')" class="rango-btn px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-sm font-semibold transition">
                    Trimestre
                </button>
                <button onclick="setRangoFecha('personalizado')" id="btn-personalizado" class="rango-btn px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-sm font-semibold transition">
                    Personalizado
                </button>
                <!-- Rango personalizado -->
                <div id="rango-personalizado" class="flex items-center gap-2" style="display:none!important">
                    <input type="date" id="rango-inicio" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 bg-white" style="height:38px">
                    <span class="text-slate-400 text-sm">al</span>
                    <input type="date" id="rango-fin" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 bg-white" style="height:38px">
                    <button onclick="aplicarRangoPersonalizado()" class="px-4 py-2 rounded-xl text-sm font-semibold transition" style="background:var(--accent);color:#fff;height:38px">Aplicar</button>
                </div>
                <!-- Toggle Sin IVA -->
                <label class="flex items-center gap-2 cursor-pointer select-none text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl px-3 py-2 transition" title="Mostrar montos sin IVA">
                    <input type="checkbox" id="toggleSinIva" onchange="onToggleSinIva(this)" class="w-4 h-4 rounded">
                    Sin IVA
                </label>
            </div>
        </div>
    </div>

    <!-- Tarjetas KPI -->
    <div class="dashboard-kpi-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Ventas -->
        <div class="card rounded-2xl p-6 shadow-lg">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-2xl">
                    
                </div>
                <span id="cambioVentas" class="text-sm font-semibold text-slate-500">
                    <span class="loading-spinner"></span>
                </span>
            </div>
            <div class="text-3xl font-bold text-slate-900 mb-1" id="totalVentas">
                $0.00
            </div>
            <div class="text-sm text-slate-600">Ventas Totales</div>
        </div>

        <!-- Piezas -->
        <div class="card rounded-2xl p-6 shadow-lg">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-2xl">
                    
                </div>
                <span id="cambioPiezas" class="text-sm font-semibold text-slate-500">
                    <span class="loading-spinner"></span>
                </span>
            </div>
            <div class="text-3xl font-bold text-slate-900 mb-1" id="totalPiezas">
                0
            </div>
            <div class="text-sm text-slate-600">Piezas Vendidas</div>
        </div>

        <!-- Pedidos -->
        <div class="card rounded-2xl p-6 shadow-lg">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-2xl">
                    
                </div>
                <span id="cambioPedidos" class="text-sm font-semibold text-slate-500">
                    <span class="loading-spinner"></span>
                </span>
            </div>
            <div class="text-3xl font-bold text-slate-900 mb-1" id="totalPedidos">
                0
            </div>
            <div class="text-sm text-slate-600">Pedidos Cerrados</div>
        </div>

        <!-- Ticket Promedio -->
        <div class="card rounded-2xl p-6 shadow-lg">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-2xl">
                    
                </div>
                <span id="cambioTicket" class="text-sm font-semibold text-slate-500">
                    <span class="loading-spinner"></span>
                </span>
            </div>
            <div class="text-3xl font-bold text-slate-900 mb-1" id="ticketPromedio">
                $0.00
            </div>
            <div class="text-sm text-slate-600">Ticket Promedio</div>
        </div>
    </div>

    <!-- Operación de Representantes -->
    <div class="dashboard-card card rounded-2xl p-6 shadow-lg mb-8">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-6">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Operación de Representantes</h2>
                <p class="text-sm text-slate-600">Consignación, efectivo y CFDI</p>
            </div>
            <span class="text-xs font-semibold text-slate-500 uppercase">
                <?= in_array($rol_codigo, ['director_general', 'admin'], true) ? 'Vista global' : 'Vista de equipo' ?>
            </span>
        </div>

        <div class="dashboard-op-grid grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold text-slate-500 uppercase">Venta tienda</p>
                <p id="opVentasTienda" class="text-2xl font-bold text-slate-900 mt-2">0</p>
                <p id="opMontoTienda" class="text-xs text-slate-500 mt-1">$0.00</p>
            </div>
            <div class="rounded-xl border border-orange-200 bg-orange-50 p-4">
                <p class="text-xs font-semibold text-orange-700 uppercase">Pago validar</p>
                <p id="opPagosValidar" class="text-2xl font-bold text-orange-700 mt-2">0</p>
                <p class="text-xs text-orange-600 mt-1">Pendiente/por verificar</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs font-semibold text-amber-700 uppercase">Efectivo</p>
                <p id="opEfectivoPendiente" class="text-2xl font-bold text-amber-700 mt-2">0</p>
                <p id="opMontoEfectivo" class="text-xs text-amber-600 mt-1">$0.00</p>
            </div>
            <div class="rounded-xl border border-purple-200 bg-purple-50 p-4">
                <p class="text-xs font-semibold text-purple-700 uppercase">CFDI pendiente</p>
                <p id="opCfdiPendiente" class="text-2xl font-bold text-purple-700 mt-2">0</p>
                <p class="text-xs text-purple-600 mt-1">PDF/XML faltante</p>
            </div>
        </div>

        <div class="px-4 py-3 border-b border-slate-100">
            <input type="search" id="op-buscar" placeholder="Buscar representante..." oninput="_opRenderTable()"
                class="w-full max-w-xs rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-slate-300" style="height:36px">
        </div>
        <div class="dashboard-table-scroll overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="op-th-sort px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase" data-sort-col="nombre" onclick="opSortBy('nombre')">Representante<span class="op-sort-icon">↕</span></th>
                        <th class="op-th-sort px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase" data-sort-col="ventas_tienda" onclick="opSortBy('ventas_tienda')">Tienda<span class="op-sort-icon">↕</span></th>
                        <th class="op-th-sort px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase" data-sort-col="monto_tienda" onclick="opSortBy('monto_tienda')">Monto tienda<span class="op-sort-icon">↕</span></th>
                        <th class="op-th-sort px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase" data-sort-col="efectivo_pendiente" onclick="opSortBy('efectivo_pendiente')">Efectivo pend.<span class="op-sort-icon">↕</span></th>
                        <th class="op-th-sort px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase" data-sort-col="cfdi_pendientes" onclick="opSortBy('cfdi_pendientes')">CFDI pend.<span class="op-sort-icon">↕</span></th>
                    </tr>
                </thead>
                <tbody id="tablaOperacionRepresentantes">
                    <tr>
                        <td colspan="5" class="text-center py-8 text-slate-500">Cargando operación...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="pag-bar" id="op-pag-bar">
            <div class="pag-left">
                <span class="pag-info" id="op-pag-info"></span>
                <select class="pag-size" id="op-pag-size">
                    <option value="10" selected>10 / pág</option>
                    <option value="25">25 / pág</option>
                    <option value="50">50 / pág</option>
                    <option value="100">100 / pág</option>
                </select>
            </div>
            <div class="pag-controls" id="op-pag-ctrl"></div>
        </div>
    </div>

    <!-- Contenido según Rol -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        
        <!-- Gráfica de Tendencia (full) -->
        <div class="dashboard-card dashboard-chart-card lg:col-span-3 card rounded-2xl p-6 shadow-lg">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-slate-900">Tendencia de Ventas</h2>
                <select id="periodoGrafica" onchange="cargarTendencia()" class="input-field rounded-xl px-4 py-2 text-sm">
                    <option value="dia">Últimos 30 días</option>
                    <option value="semana">Últimas 12 semanas</option>
                    <option value="mes">Últimos 12 meses</option>
                </select>
            </div>
            <div class="dashboard-chart-wrap">
                <canvas id="chartTendencia" height="60"></canvas>
            </div>
        </div>
    </div>

    <!-- Ranking de Desempeño -->
    <div class="dashboard-card card rounded-2xl p-6 shadow-lg">
        <h2 class="text-xl font-bold text-slate-900 mb-6">
            <?php if ($rol_codigo === 'admin' || $rol_codigo === 'director_general'): ?>
                Ranking de Representantes
            <?php elseif ($rol_codigo === 'representante'): ?>
                Mi Posición en el Ranking
            <?php else: ?>
                Desempeño del Equipo
            <?php endif; ?>
        </h2>
        <div class="dashboard-table-scroll overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Posición</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Nombre</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Piezas</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Ventas ($)</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Pedidos</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Última Venta</th>
                    </tr>
                </thead>
                <tbody id="tablaRanking">
                    <tr>
                        <td colspan="6" class="text-center py-8 text-slate-500">
                            <div class="loading-spinner">Cargando datos...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Productos más vendidos -->
    <div class="dashboard-card card rounded-2xl p-6 shadow-lg mt-8" id="seccionTopProductos">
        <!-- Header + filtros -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="text-xl font-bold text-slate-900">Productos más vendidos</h2>
            <div class="flex flex-wrap items-center gap-2">
                <!-- Filtro Ruta -->
                <select id="filProdRuta" onchange="cargarTopProductos()" class="input-field rounded-xl px-3 py-2 text-sm">
                    <option value="">Todas las rutas</option>
                </select>
                <!-- Filtro Representante -->
                <select id="filProdRep" onchange="cargarTopProductos()" class="input-field rounded-xl px-3 py-2 text-sm">
                    <option value="">Todos los representantes</option>
                </select>
                <!-- Límite Top N -->
                <select id="filProdLimit" onchange="cargarTopProductos()" class="input-field rounded-xl px-3 py-2 text-sm">
                    <option value="5">Top 5</option>
                    <option value="10">Top 10</option>
                    <option value="25" selected>Top 25</option>
                    <option value="50">Top 50</option>
                    <option value="0">Todos</option>
                </select>
                <!-- Toggle kits -->
                <label class="flex items-center gap-2 cursor-pointer select-none text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl px-3 py-2 transition">
                    <input type="checkbox" id="filProdKits" onchange="cargarTopProductos()" class="w-4 h-4 rounded accent-primary-500">
                    Mostrar Kits
                </label>
            </div>
        </div>
        <!-- Nota informativa -->
        <p id="notaProductos" class="text-xs text-slate-500 mb-4">
            Las piezas de cada producto incluyen tanto ventas directas como unidades que salieron dentro de kits.
            Activa "Mostrar Kits" para ver el desglose por separado: cada producto mostrará solo sus ventas directas y los kits aparecerán como filas propias.
        </p>
        <!-- Tabla -->
        <div class="dashboard-table-scroll overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase w-10">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Producto / Kit</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Piezas</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Monto ($)</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Pedidos</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">% del total</th>
                    </tr>
                </thead>
                <tbody id="tablaTopProductos">
                    <tr><td colspan="6" class="text-center py-8 text-slate-500"><div class="loading-spinner">Cargando...</div></td></tr>
                </tbody>
                <tfoot id="tablaTopProductosFoot" class="hidden">
                    <tr class="bg-slate-100 border-t-2 border-slate-300 font-bold">
                        <td colspan="2" class="px-4 py-3 text-right text-slate-700 uppercase text-xs tracking-wide">Totales</td>
                        <td id="footTotalPiezas" class="px-4 py-3 text-right text-slate-900">—</td>
                        <td id="footTotalMonto"  class="px-4 py-3 text-right text-green-700">—</td>
                        <td id="footTotalPedidos" class="px-4 py-3 text-right text-slate-900">—</td>
                        <td class="px-4 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ===================== REPORTE DE VENTAS ===================== -->
    <div class="dashboard-card card rounded-2xl shadow-lg mt-8">
        <!-- Header -->
        <div class="px-6 pt-6 pb-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Reporte de Ventas</h2>
                <p class="text-sm text-slate-500 mt-0.5">Desglose del período seleccionado por distintas dimensiones.</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Toggle kits — solo visible en dimensión producto -->
                <label id="rvKitsToggle" class="flex items-center gap-2 cursor-pointer select-none text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl px-3 py-2 transition">
                    <input type="checkbox" id="rvMostrarKits" onchange="cargarReporteVentas()" class="w-4 h-4 rounded accent-primary-500">
                    Mostrar Kits
                </label>
                <?php if (!empty($reps_para_filtro_rv)): ?>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-slate-500 whitespace-nowrap">Representante:</label>
                    <select id="rvRepFiltro" onchange="onRvRepFiltro(this)"
                            class="text-sm border border-slate-300 bg-white text-slate-700 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
                        <option value="">— Todos —</option>
                        <?php foreach ($reps_para_filtro_rv as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <span id="reporteVentasTotal" class="text-sm font-semibold text-slate-500"></span>
            </div>
        </div>

        <!-- Dimensión tabs — horizontalmente scrollable en móvil -->
        <div class="dashboard-tabs-scroll overflow-x-auto border-b border-slate-200">
            <div class="flex min-w-max">
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition-colors" data-dim="producto" onclick="setReporteDim('producto', this)">Por producto</button>
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 border-transparent text-slate-500 hover:text-slate-700 -mb-px transition-colors" data-dim="cliente" onclick="setReporteDim('cliente', this)">Por cliente</button>
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 border-transparent text-slate-500 hover:text-slate-700 -mb-px transition-colors" data-dim="estado" onclick="setReporteDim('estado', this)">Por estado</button>
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 border-transparent text-slate-500 hover:text-slate-700 -mb-px transition-colors" data-dim="localidad" onclick="setReporteDim('localidad', this)">Por localidad</button>
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 border-transparent text-slate-500 hover:text-slate-700 -mb-px transition-colors" data-dim="especialidad" onclick="setReporteDim('especialidad', this)">Por especialidad</button>
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 border-transparent text-slate-500 hover:text-slate-700 -mb-px transition-colors" data-dim="cp" onclick="setReporteDim('cp', this)">Por CP</button>
                <button class="rv-dim-btn px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 border-transparent text-slate-500 hover:text-slate-700 -mb-px transition-colors" data-dim="representante" onclick="setReporteDim('representante', this)">Por representante</button>
            </div>
        </div>

        <!-- Tabla dinámica -->
        <div class="dashboard-table-scroll overflow-x-auto">
            <table class="w-full text-sm">
                <thead id="reporteVentasThead" class="bg-slate-100 text-slate-600">
                    <tr></tr>
                </thead>
                <tbody id="reporteVentasTbody">
                    <tr><td colspan="7" class="text-center py-8 text-slate-500">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($rol_codigo === 'representante'): ?>
    <!-- Últimos Pedidos (solo para representantes) -->
    <div class="dashboard-card card rounded-2xl p-6 shadow-lg mt-6">
        <h2 class="text-xl font-bold text-slate-900 mb-6">Mis Últimos Pedidos</h2>
        <div class="dashboard-table-scroll overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Cliente</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Piezas</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Total</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Estado</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Fecha</th>
                    </tr>
                </thead>
                <tbody id="tablaUltimosPedidos">
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ===================== DRAWER: Detalle Representante ===================== -->
<div id="repDrawerOverlay" class="fixed inset-0 bg-black/40 z-40 hidden" onclick="cerrarDetalleRep()"></div>
<aside id="repDrawer" class="fixed top-0 right-0 h-full w-full max-w-2xl bg-white shadow-2xl z-50 flex flex-col transform translate-x-full transition-transform duration-300">
    <!-- Header drawer -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50 shrink-0">
        <div>
            <h3 id="drawerNombre" class="text-lg font-bold text-slate-900">—</h3>
            <p id="drawerCodigo" class="text-xs text-slate-500 font-mono">—</p>
        </div>
        <button onclick="cerrarDetalleRep()" class="p-2 rounded-xl hover:bg-slate-200 transition text-slate-500 text-xl font-bold">&times;</button>
    </div>

    <!-- KPIs del período -->
    <div id="drawerKpis" class="grid grid-cols-3 gap-3 px-6 py-4 border-b border-slate-100 shrink-0">
        <div class="rounded-xl bg-green-50 border border-green-200 p-3 text-center">
            <p class="text-xs font-semibold text-green-700 uppercase">Ventas entregadas</p>
            <p id="dkVentas" class="text-2xl font-bold text-green-800 mt-1">—</p>
            <p id="dkMonto" class="text-xs text-green-600 mt-0.5">—</p>
        </div>
        <div class="rounded-xl bg-orange-50 border border-orange-200 p-3 text-center">
            <p class="text-xs font-semibold text-orange-700 uppercase">Por validar</p>
            <p id="dkValidar" class="text-2xl font-bold text-orange-800 mt-1">—</p>
            <p class="text-xs text-orange-600 mt-0.5">Pendiente/por verificar</p>
        </div>
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-center">
            <p class="text-xs font-semibold text-amber-700 uppercase">Efectivo pend.</p>
            <p id="dkEfectivo" class="text-2xl font-bold text-amber-800 mt-1">—</p>
            <p id="dkMontoEfectivo" class="text-xs text-amber-600 mt-0.5">—</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-slate-200 shrink-0 overflow-x-auto">
        <button class="drawer-tab active px-5 py-3 text-sm font-semibold border-b-2 border-primary-500 text-primary-600 -mb-px whitespace-nowrap" onclick="drawerTab('pedidos', this)">Pedidos</button>
        <button class="drawer-tab px-5 py-3 text-sm font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 -mb-px whitespace-nowrap" onclick="drawerTab('solicitudes', this)">Solicitudes</button>
        <button class="drawer-tab px-5 py-3 text-sm font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 -mb-px whitespace-nowrap" onclick="drawerTab('inventario', this)">Inventario</button>
        <button class="drawer-tab px-5 py-3 text-sm font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 -mb-px whitespace-nowrap" onclick="drawerTab('ventas', this)">Ventas</button>
    </div>

    <!-- Contenido scrollable -->
    <div class="overflow-y-auto flex-1 px-6 py-4">
        <!-- Tab Pedidos -->
        <div id="drawerTabPedidos">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">#</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Cliente</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Canal</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Estado</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Total</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Fecha</th>
                    </tr>
                </thead>
                <tbody id="drawerPedidosTbody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
        <!-- Tab Solicitudes -->
        <div id="drawerTabSolicitudes" class="hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">#</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Estado</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Paquetería</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Solicitud</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Entrega</th>
                    </tr>
                </thead>
                <tbody id="drawerSolicitudesTbody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
        <!-- Tab Inventario -->
        <div id="drawerTabInventario" class="hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Producto</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Disponible</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Reservado</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Vendido</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Devuelto</th>
                    </tr>
                </thead>
                <tbody id="drawerInventarioTbody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
        <!-- Tab Ventas por producto -->
        <div id="drawerTabVentas" class="hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Producto</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Venta Directa</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Tienda</th>
                        <th class="px-3 py-2 text-right font-semibold text-slate-600">Total</th>
                    </tr>
                </thead>
                <tbody id="drawerVentasTbody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </div>

    <!-- Spinner de carga -->
    <div id="drawerSpinner" class="absolute inset-0 flex items-center justify-center bg-white/80 hidden">
        <div class="w-10 h-10 border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>
</aside>

<!-- ===================== MINI-MODAL: Detalle drill-down ===================== -->
<div id="miniModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="cerrarMiniModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 shrink-0">
            <h4 id="miniModalTitulo" class="font-bold text-slate-900">—</h4>
            <button onclick="cerrarMiniModal()" class="text-slate-400 hover:text-slate-700 text-xl font-bold">&times;</button>
        </div>
        <div id="miniModalBody" class="overflow-y-auto flex-1 px-5 py-4 text-sm">
            <div class="flex justify-center py-8"><div class="w-8 h-8 border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div></div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* Reporte de Ventas — tab activo usa color de acento del tema */
.rv-dim-btn.rv-dim-active {
    border-bottom-color: var(--accent);
    color: var(--accent);
}

@media (max-width: 1023px) {
    .dashboard-page {
        max-width: 100%;
        padding-left: 16px;
        padding-right: 16px;
    }

    .dashboard-page > .flex:first-child {
        align-items: stretch;
    }

    .dashboard-page > .flex:first-child > div:last-child,
    .dashboard-page > .flex:first-child > div:last-child > .flex,
    .dashboard-page > .flex:first-child > div:last-child > div {
        width: 100%;
        align-items: stretch;
    }

    .dashboard-page .rango-btn,
    .dashboard-page #toggleSinIva,
    .dashboard-page #rvMostrarKits,
    .dashboard-page select,
    .dashboard-page label[title="Mostrar montos sin IVA"] {
        min-height: 40px;
    }

    .dashboard-card {
        overflow: hidden;
    }

    .dashboard-op-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .dashboard-table-scroll,
    .dashboard-tabs-scroll,
    #drawerTabPedidos,
    #drawerTabSolicitudes,
    #drawerTabInventario,
    #drawerTabVentas,
    #miniModalBody {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .dashboard-table-scroll table,
    #drawerTabPedidos table,
    #drawerTabSolicitudes table,
    #drawerTabInventario table,
    #drawerTabVentas table,
    #miniModalBody table {
        min-width: 720px;
    }

    #tablaOperacionRepresentantes,
    #tablaRanking,
    #tablaTopProductos,
    #reporteVentasTbody,
    #tablaUltimosPedidos {
        white-space: nowrap;
    }

    .op-th-sort { cursor: pointer; user-select: none; }
    .op-th-sort:hover { background: #e2e8f0; }
    .op-sort-icon { display: inline-block; margin-left: 4px; opacity: 0.35; font-size: 10px; vertical-align: middle; }
    .op-th-sort.sort-active .op-sort-icon { opacity: 1; color: #0f766e; }

    .dashboard-chart-card > .flex {
        align-items: stretch;
        gap: 12px;
    }

    .dashboard-chart-card canvas {
        min-height: 260px;
    }

    .dashboard-chart-wrap {
        position: relative;
        height: 320px;
        width: 100%;
    }

    .dashboard-chart-wrap canvas {
        width: 100% !important;
        height: 100% !important;
    }

    #repDrawer {
        max-width: min(92vw, 42rem);
    }
}

@media (max-width: 767px) {
    .dashboard-page {
        padding-top: 18px;
        padding-left: 12px;
        padding-right: 12px;
    }

    .dashboard-page h1 {
        font-size: 24px;
        line-height: 1.15;
    }

    .dashboard-page h2 {
        font-size: 18px;
    }

    .dashboard-page > .flex:first-child {
        margin-bottom: 20px;
    }

    .dashboard-page > .flex:first-child > div:last-child {
        align-items: stretch;
    }

    .dashboard-page > .flex:first-child > div:last-child > div {
        justify-content: stretch;
    }

    .dashboard-page .rango-btn,
    .dashboard-page label[title="Mostrar montos sin IVA"] {
        flex: 1 1 calc(50% - 6px);
        justify-content: center;
        padding-left: 10px;
        padding-right: 10px;
        text-align: center;
    }

    .dashboard-page #filtroGerente {
        width: 100%;
    }

    .dashboard-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }

    .dashboard-kpi-grid .card,
    .dashboard-card.p-6 {
        border-radius: 14px;
        padding: 16px;
    }

    .dashboard-kpi-grid .card .text-3xl {
        font-size: 22px;
        line-height: 1.15;
        word-break: break-word;
    }

    .dashboard-kpi-grid .card .w-12 {
        width: 40px;
        height: 40px;
        font-size: 20px;
    }

    .dashboard-op-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .dashboard-op-grid > * {
        padding: 12px;
    }

    .dashboard-op-grid .text-2xl {
        font-size: 20px;
    }

    .dashboard-chart-card > .flex,
    #seccionTopProductos > .flex:first-child,
    .dashboard-card > .px-6:first-child {
        flex-direction: column;
        align-items: stretch;
    }

    #seccionTopProductos select,
    #seccionTopProductos label,
    #rvKitsToggle {
        width: 100%;
    }

    .dashboard-chart-wrap {
        height: 300px;
    }

    .rv-dim-btn {
        padding-left: 14px;
        padding-right: 14px;
    }

    .pag-bar {
        align-items: stretch;
        flex-direction: column;
        overflow: visible;
    }

    .pag-left,
    .pag-controls {
        width: 100%;
        justify-content: space-between;
    }

    #repDrawer {
        max-width: 100vw;
    }

    #repDrawer > .flex:first-child,
    #repDrawer .overflow-y-auto {
        padding-left: 16px;
        padding-right: 16px;
    }

    #drawerKpis {
        grid-template-columns: 1fr;
        padding: 12px 16px;
        gap: 10px;
    }

    #repDrawer .drawer-tab {
        flex: 1 1 0;
        padding-left: 8px;
        padding-right: 8px;
        text-align: center;
    }

    #miniModal {
        padding: 10px;
        align-items: flex-end;
    }

    #miniModal > .relative {
        max-height: 88vh;
        border-bottom-left-radius: 0;
        border-bottom-right-radius: 0;
    }

    #miniModalTitulo {
        max-width: calc(100vw - 90px);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
}

@media (max-width: 420px) {
    .dashboard-kpi-grid,
    .dashboard-op-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-page .rango-btn,
    .dashboard-page label[title="Mostrar montos sin IVA"] {
        flex-basis: 100%;
    }
}
</style>

<script src="<?= asset('js/paginator.js') ?>"></script>
<script>
const BASE_PATH = window.BASE_PATH || '';
let chartTendencia = null;
let fechaInicio = '<?= date('Y-m-01') ?>';
let fechaFin = '<?= date('Y-m-d') ?>';
let sinIva = 0;
let gerenteId = '';  // Filtro por Gerente de Distrito (solo Gerente Nacional)
let rvRepId   = '';  // Filtro por representante en Reporte de Ventas

function onRvRepFiltro(sel) {
    rvRepId = sel.value;
    cargarReporteVentas();
}
const rolCodigo = '<?= $rol_codigo ?>';
const representanteId = <?= $rol_codigo === 'representante' ? (int)$admin['id'] : 'null' ?>;

function onFiltroGerente(sel) {
    gerenteId = sel.value;
    cargarKPIs();
    cargarRanking();
    cargarFiltrosProductos().then(() => cargarTopProductos());
    cargarOperacionRepresentantes();
    cargarReporteVentas();
}

// Cargar datos al iniciar
document.addEventListener('DOMContentLoaded', function() {
    cargarKPIs();
    cargarRanking();
    cargarFiltrosProductos().then(() => cargarTopProductos());
    cargarTendencia();
    cargarOperacionRepresentantes();
    initReporteVentas();
    
    <?php if ($rol_codigo === 'representante'): ?>
    cargarUltimosPedidos();
    <?php endif; ?>

    // ── Polling: solo cuando la pestaña está activa ──────────────────────
    // Sin setInterval — setTimeout chain que se cancela al ocultar la pestaña
    // y dispara inmediatamente al volver. Igual que representante/index.php.
    const POLL_INTERVAL = 120_000; // 2 min — dashboard de gerentes actualiza menos frecuente
    let _pollTimer = null;

    function _stopPoll() {
        clearTimeout(_pollTimer);
        _pollTimer = null;
    }

    async function _tickDashboard() {
        if (document.visibilityState !== 'visible') return;
        await cargarKPIs();
        if (document.visibilityState === 'visible')
            _pollTimer = setTimeout(_tickDashboard, POLL_INTERVAL);
    }

    function _startPoll() {
        _stopPoll();
        _pollTimer = setTimeout(_tickDashboard, POLL_INTERVAL);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            // Pestaña activa: refrescar inmediatamente y reanudar ciclo
            cargarKPIs();
            _startPoll();
        } else {
            // Pestaña oculta: cancelar — sin actividad en segundo plano
            _stopPoll();
        }
    });

    _startPoll();
});

// Establecer rango de fechas
// Devuelve YYYY-MM-DD usando la zona horaria LOCAL del navegador
// (evita el bug de toISOString() que retorna fecha UTC y difiere de CST a partir de las 6 PM)
function localDateStr(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function onToggleSinIva(cb) {
    sinIva = cb.checked ? 1 : 0;
    cargarKPIs();
    cargarRanking();
    cargarTopProductos();
    cargarOperacionRepresentantes();
    cargarTendencia();
    cargarReporteVentas();
}

function setRangoFecha(rango) {
    const hoy = new Date();
    
    switch(rango) {
        case 'hoy':
            fechaInicio = fechaFin = localDateStr(hoy);
            break;
        case 'semana':
            const inicioSemana = new Date(hoy);
            inicioSemana.setDate(hoy.getDate() - hoy.getDay());
            fechaInicio = localDateStr(inicioSemana);
            fechaFin = localDateStr(hoy);
            break;
        case 'mes':
            fechaInicio = localDateStr(new Date(hoy.getFullYear(), hoy.getMonth(), 1));
            fechaFin = localDateStr(hoy);
            break;
        case 'trimestre':
            const inicioTrimestre = new Date(hoy.getFullYear(), Math.floor(hoy.getMonth() / 3) * 3, 1);
            fechaInicio = localDateStr(inicioTrimestre);
            fechaFin = localDateStr(hoy);
            break;
        case 'personalizado':
            // Solo muestra/oculta el panel, no recarga datos
            document.getElementById('rango-personalizado').style.display = 'flex';
            document.querySelectorAll('.rango-btn').forEach(btn => {
                btn.style.background = '';
                btn.style.color = '';
                btn.classList.add('bg-slate-100', 'hover:bg-slate-200');
            });
            event.target.style.background = 'var(--accent)';
            event.target.style.color = 'var(--accent-text, #fff)';
            event.target.classList.remove('bg-slate-100', 'hover:bg-slate-200');
            return; // No recargar datos todavía
    }
    
    // Actualizar botones activos
    document.querySelectorAll('.rango-btn').forEach(btn => {
        btn.style.background = '';
        btn.style.color = '';
        btn.classList.add('bg-slate-100', 'hover:bg-slate-200');
    });
    document.getElementById('rango-personalizado').style.display = 'none';
    event.target.style.background = 'var(--accent)';
    event.target.style.color = 'var(--accent-text, #fff)';
    event.target.classList.remove('bg-slate-100', 'hover:bg-slate-200');
    
    // Recargar datos
    cargarKPIs();
    cargarRanking();
    cargarTopProductos();
    cargarOperacionRepresentantes();
    cargarReporteVentas();
}

function aplicarRangoPersonalizado() {
    const inicio = document.getElementById('rango-inicio').value;
    const fin    = document.getElementById('rango-fin').value;
    if (!inicio || !fin) { alert('Selecciona ambas fechas'); return; }
    if (fin < inicio)    { alert('La fecha final debe ser igual o posterior al inicio'); return; }
    fechaInicio = inicio;
    fechaFin    = fin;
    cargarKPIs();
    cargarRanking();
    cargarTopProductos();
    cargarOperacionRepresentantes();
    cargarReporteVentas();
}

// Cargar KPIs
async function cargarKPIs() {
    try {
        const response = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=kpis&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}`);
        const result = await response.json();
        
        if (result.success) {
            const data = result.data;
            document.getElementById('totalVentas').textContent = '$' + parseFloat(data.total_ventas).toLocaleString('es-MX', {minimumFractionDigits: 2});
            document.getElementById('totalPiezas').textContent = parseInt(data.total_piezas).toLocaleString('es-MX');
            document.getElementById('totalPedidos').textContent = parseInt(data.total_pedidos).toLocaleString('es-MX');
            const ticketVal = parseFloat(data.ticket_promedio) || 0;
            document.getElementById('ticketPromedio').textContent = '$' + ticketVal.toLocaleString('es-MX', {minimumFractionDigits: 2});
            
            // Cargar comparativa
            cargarComparativa();
        }
    } catch (error) {
        console.error('Error cargando KPIs:', error);
    }
}

// Cargar comparativa
async function cargarComparativa() {
    try {
        const response = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=comparativa&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}`);
        const result = await response.json();
        
        if (result.success) {
            const cambios = result.data.cambios;
            
            actualizarCambio('cambioVentas', cambios.ventas);
            actualizarCambio('cambioPiezas', cambios.piezas);
            actualizarCambio('cambioPedidos', cambios.pedidos);
            actualizarCambio('cambioTicket', cambios.ticket);
        }
    } catch (error) {
        console.error('Error cargando comparativa:', error);
    }
}

function actualizarCambio(elementId, porcentaje) {
    const elemento = document.getElementById(elementId);
    const icono = porcentaje >= 0 ? '↗' : '↘';
    const color = porcentaje >= 0 ? 'text-green-600' : 'text-red-600';
    elemento.className = `text-sm font-semibold ${color}`;
    elemento.textContent = `${icono} ${Math.abs(porcentaje)}%`;
}

// Cargar ranking
async function cargarRanking() {
    try {
        const response = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=ranking&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}`);
        const result = await response.json();
        
        if (result.success) {
            const tbody = document.getElementById('tablaRanking');
            tbody.innerHTML = '';
            
            result.data.forEach((item, index) => {
                const medallas = ['', '', ''];
                const posicion = index < 3 ? medallas[index] + ' ' + (index + 1) : (index + 1);
                
                // Resaltar si es el representante actual
                const esActual = representanteId && item.id === representanteId;
                const claseResaltado = esActual ? 'bg-yellow-50 border-l-4 border-yellow-400' : '';
                
                const ultimaVenta = item.ultima_venta ? new Date(item.ultima_venta).toLocaleDateString('es-MX') : '—';
                
                tbody.innerHTML += `
                    <tr class="border-t hover:bg-slate-50 transition ${claseResaltado}">
                        <td class="px-4 py-3 font-bold text-slate-900">${posicion}</td>
                        <td class="px-4 py-3 text-slate-900">
                            ${item.nombre}
                            ${esActual ? '<span class="ml-2 text-xs bg-yellow-200 text-yellow-800 px-2 py-1 rounded-full">Tú</span>' : ''}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-700">${parseInt(item.total_piezas).toLocaleString('es-MX')}</td>
                        <td class="px-4 py-3 text-right font-semibold text-green-600">$${parseFloat(item.total_ventas).toLocaleString('es-MX', {minimumFractionDigits: 2})}</td>
                        <td class="px-4 py-3 text-right text-slate-600">${item.total_pedidos}</td>
                        <td class="px-4 py-3 text-sm text-slate-500">${ultimaVenta}</td>
                    </tr>
                `;
            });
        }
    } catch (error) {
        console.error('Error cargando ranking:', error);
    }
}

// Cargar filtros de productos (rutas y representantes) — llamada única al inicio
async function cargarFiltrosProductos() {
    try {
        const res = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=filtros_productos${gerenteId ? '&gerente_id='+gerenteId : ''}`);
        const result = await res.json();
        if (!result.success) return;

        const selRuta = document.getElementById('filProdRuta');
        const selRep  = document.getElementById('filProdRep');

        result.data.rutas.forEach(r => {
            const opt = document.createElement('option');
            opt.value = r.ruta;
            opt.textContent = r.desc_ruta ? `${r.ruta} – ${r.desc_ruta}` : r.ruta;
            selRuta.appendChild(opt);
        });

        result.data.representantes.forEach(r => {
            const opt = document.createElement('option');
            opt.value = r.id;
            opt.textContent = r.nombre + (r.ruta ? ` (${r.ruta})` : '');
            selRep.appendChild(opt);
        });
    } catch(e) { console.error('filtros_productos', e); }
}

async function cargarTopProductos() {
    const tbody       = document.getElementById('tablaTopProductos');
    const ruta        = document.getElementById('filProdRuta')?.value  ?? '';
    const repId       = document.getElementById('filProdRep')?.value   ?? '';
    const incluirKits = document.getElementById('filProdKits')?.checked ? '1' : '0';
    const limitVal    = document.getElementById('filProdLimit')?.value  ?? '25';
    const limit       = parseInt(limitVal) > 0 ? parseInt(limitVal) : 9999;

    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-slate-500"><div class="loading-spinner">Cargando...</div></td></tr>';

    // Actualizar nota informativa
    const nota = document.getElementById('notaProductos');
    if (nota) {
        nota.textContent = incluirKits === '1'
            ? 'Modo “Mostrar Kits” activo: cada producto muestra solo sus ventas directas (sin contar unidades que salieron en kits). Los kits aparecen como filas separadas.'
            : 'Las piezas de cada producto incluyen tanto ventas directas como unidades que salieron dentro de kits. Activa “Mostrar Kits” para ver el desglose por separado.';
    }

    try {
        let url = `${BASE_PATH}api/dashboard-stats.php?action=top_productos&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&mostrar_kits=${incluirKits}&limit=${limit}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}`;
        if (ruta)  url += `&ruta=${encodeURIComponent(ruta)}`;
        if (repId) url += `&rep_id=${encodeURIComponent(repId)}`;

        const res    = await fetch(url);
        const result = await res.json();

        if (!result.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-6 text-red-500">${result.message || 'Error'}</td></tr>`;
            return;
        }

        const totalPiezas = result.total_piezas || 0;

        if (!result.data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-slate-500">Sin datos para el período y filtros seleccionados.</td></tr>';
            document.getElementById('tablaTopProductosFoot').classList.add('hidden');
            return;
        }

        tbody.innerHTML = '';
        let sumPiezas = 0, sumMonto = 0, sumPedidos = 0;
        result.data.forEach((p, i) => {
            const pct   = totalPiezas > 0 ? ((p.total_piezas / totalPiezas) * 100).toFixed(1) : '0.0';
            const barW  = totalPiezas > 0 ? Math.round((p.total_piezas / totalPiezas) * 100) : 0;
            const kitBadge = parseInt(p.es_kit) === 1
                ? '<span class="ml-2 text-xs font-semibold bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Kit</span>'
                : '';
            sumPiezas  += parseInt(p.total_piezas)  || 0;
            sumMonto   += parseFloat(p.total_monto)  || 0;
            sumPedidos += parseInt(p.veces_vendido)  || 0;
            tbody.innerHTML += `
                <tr class="border-t hover:bg-slate-50 transition">
                    <td class="px-4 py-3 text-center font-bold text-slate-400">${i + 1}</td>
                    <td class="px-4 py-3 text-slate-900 font-medium">${p.nombre}${kitBadge}</td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-700">${parseInt(p.total_piezas).toLocaleString('es-MX')}</td>
                    <td class="px-4 py-3 text-right font-semibold text-green-600">$${parseFloat(p.total_monto).toLocaleString('es-MX', {minimumFractionDigits:2})}</td>
                    <td class="px-4 py-3 text-right text-slate-600">${parseInt(p.veces_vendido).toLocaleString('es-MX')}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <div class="w-20 h-2 rounded-full bg-slate-200 overflow-hidden">
                                <div class="h-full rounded-full" style="width:${barW}%;background:var(--accent)"></div>
                            </div>
                            <span class="text-xs font-semibold text-slate-600 w-10 text-right">${pct}%</span>
                        </div>
                    </td>
                </tr>
            `;
        });
        // Fila de totales
        const foot = document.getElementById('tablaTopProductosFoot');
        document.getElementById('footTotalPiezas').textContent  = sumPiezas.toLocaleString('es-MX');
        document.getElementById('footTotalMonto').textContent   = '$' + sumMonto.toLocaleString('es-MX', {minimumFractionDigits:2});
        document.getElementById('footTotalPedidos').textContent = sumPedidos.toLocaleString('es-MX');
        foot.classList.remove('hidden');
    } catch(error) {
        console.error('Error top productos:', error);
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-6 text-red-500">Error cargando datos.</td></tr>';
    }
}

let _opRepsData = [];
let _opSortCol = null;
let _opSortDir = -1;

function opSortBy(col) {
    if (_opSortCol === col) {
        _opSortDir *= -1;
    } else {
        _opSortCol = col;
        _opSortDir = col === 'nombre' ? 1 : -1;
    }
    _opRenderTable();
}

function _opRenderTable() {
    const tbody = document.getElementById('tablaOperacionRepresentantes');
    tbody.innerHTML = '';
    document.querySelectorAll('.op-th-sort').forEach(th => {
        th.classList.remove('sort-active');
        th.querySelector('.op-sort-icon').textContent = '↕';
    });
    if (_opSortCol) {
        const activeTh = document.querySelector(`.op-th-sort[data-sort-col="${_opSortCol}"]`);
        if (activeTh) {
            activeTh.classList.add('sort-active');
            activeTh.querySelector('.op-sort-icon').textContent = _opSortDir === 1 ? '↑' : '↓';
        }
    }
    const query = (document.getElementById('op-buscar')?.value || '').toLowerCase().trim();
    const filtered = query
        ? _opRepsData.filter(r =>
            (r.nombre || '').toLowerCase().includes(query) ||
            (r.codigo || '').toLowerCase().includes(query)
          )
        : _opRepsData;
    const sorted = _opSortCol
        ? [...filtered].sort((a, b) => {
            const va = _opSortCol === 'nombre' ? (a.nombre || '').toLowerCase() : parseFloat(a[_opSortCol] || 0);
            const vb = _opSortCol === 'nombre' ? (b.nombre || '').toLowerCase() : parseFloat(b[_opSortCol] || 0);
            return va < vb ? -_opSortDir : va > vb ? _opSortDir : 0;
          })
        : filtered;
    sorted.forEach(rep => {
        const tr = document.createElement('tr');
        tr.className = 'op-rep-row border-t hover:bg-slate-50 transition cursor-pointer';
        tr.onclick = () => verDetalleRep(rep.id, rep.nombre, rep.codigo);
        tr.innerHTML = `
            <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">${rep.nombre}</div>
                <div class="text-xs text-slate-500">${rep.codigo}</div>
            </td>
            <td class="px-4 py-3 text-right font-semibold">${parseInt(rep.ventas_tienda || 0).toLocaleString('es-MX')}</td>
            <td class="px-4 py-3 text-right font-semibold">$${parseFloat(rep.monto_tienda || 0).toLocaleString('es-MX', {minimumFractionDigits: 2})}</td>
            <td class="px-4 py-3 text-right">${parseInt(rep.efectivo_pendiente || 0).toLocaleString('es-MX')}</td>
            <td class="px-4 py-3 text-right">${parseInt(rep.cfdi_pendientes || 0).toLocaleString('es-MX')}</td>
        `;
        tbody.appendChild(tr);
    });
    _opPag.apply(Array.from(tbody.querySelectorAll('tr.op-rep-row')));
}

const _opPag = new Paginator({
    rows:   () => document.querySelectorAll('#tablaOperacionRepresentantes tr.op-rep-row'),
    bar:    '#op-pag-bar',
    info:   '#op-pag-info',
    ctrl:   '#op-pag-ctrl',
    sizeEl: '#op-pag-size',
    unit:   'representante', units: 'representantes',
});

async function cargarOperacionRepresentantes() {
    try {
        const response = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=operaciones&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}`);
        const result = await response.json();

        if (!result.success) return;

        const kpis = result.data.kpis || {};
        document.getElementById('opVentasTienda').textContent = parseInt(kpis.ventas_tienda || 0).toLocaleString('es-MX');
        document.getElementById('opMontoTienda').textContent = '$' + parseFloat(kpis.monto_tienda || 0).toLocaleString('es-MX', {minimumFractionDigits: 2});
        document.getElementById('opPagosValidar').textContent = parseInt(kpis.pagos_por_validar || 0).toLocaleString('es-MX');
        document.getElementById('opEfectivoPendiente').textContent = parseInt(kpis.efectivo_pendiente || 0).toLocaleString('es-MX');
        document.getElementById('opMontoEfectivo').textContent = '$' + parseFloat(kpis.monto_efectivo_pendiente || 0).toLocaleString('es-MX', {minimumFractionDigits: 2});
        document.getElementById('opCfdiPendiente').textContent = parseInt(kpis.cfdi_pendientes || 0).toLocaleString('es-MX');

        const tbody = document.getElementById('tablaOperacionRepresentantes');
        tbody.innerHTML = '';

        if (!result.data.representantes || result.data.representantes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-slate-500">Sin representantes en este alcance.</td></tr>';
            _opPag.apply([]);
            return;
        }

        _opRepsData = result.data.representantes;
        _opRenderTable();
    } catch (error) {
        console.error('Error cargando operación de representantes:', error);
    }
}

// Cargar tendencia
async function cargarTendencia() {
    const periodo = document.getElementById('periodoGrafica').value;
    const dias = periodo === 'dia' ? 30 : (periodo === 'semana' ? 84 : 365);
    
    try {
        const response = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=tendencia&periodo=${periodo}&dias=${dias}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}`);
        const result = await response.json();
        
        if (result.success) {
            const labels = result.data.map(item => item.periodo);
            const ventas = result.data.map(item => parseFloat(item.total_ventas));
            const piezas = result.data.map(item => parseInt(item.total_piezas));
            const isMobileChart = window.matchMedia('(max-width: 767px)').matches;
            const isSinglePoint = labels.length <= 1;
            
            if (chartTendencia) {
                chartTendencia.destroy();
            }
            
            const ctx = document.getElementById('chartTendencia').getContext('2d');
            chartTendencia = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Ventas ($)',
                        data: ventas,
                        borderColor: 'rgb(34, 197, 94)',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        yAxisID: 'y',
                        tension: 0.4,
                        pointRadius: isSinglePoint ? 5 : 3,
                        pointHoverRadius: isSinglePoint ? 7 : 5,
                        hitRadius: 12
                    }, {
                        label: 'Piezas',
                        data: piezas,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        yAxisID: 'y1',
                        tension: 0.4,
                        pointRadius: isSinglePoint ? 5 : 3,
                        pointHoverRadius: isSinglePoint ? 7 : 5,
                        hitRadius: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: !isMobileChart,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                boxWidth: isMobileChart ? 10 : 40,
                                usePointStyle: isMobileChart
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                autoSkip: true,
                                maxTicksLimit: isMobileChart ? 5 : 12,
                                maxRotation: 0
                            }
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            ticks: {
                                maxTicksLimit: isMobileChart ? 5 : 8,
                                callback: function(value) {
                                    return '$' + value.toLocaleString('es-MX');
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: !isMobileChart,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false,
                            },
                        },
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error cargando tendencia:', error);
    }
}

<?php if ($rol_codigo === 'representante'): ?>
// Cargar últimos pedidos (solo representantes)
async function cargarUltimosPedidos() {
    try {
        const response = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=ultimos_pedidos&limit=10`);
        const result = await response.json();
        
        if (result.success) {
            const tbody = document.getElementById('tablaUltimosPedidos');
            tbody.innerHTML = '';
            
            result.data.forEach(pedido => {
                const estadoClasses = {
                    'pendiente': 'bg-yellow-100 text-yellow-800',
                    'confirmado': 'bg-blue-100 text-blue-800',
                    'en_ruta': 'bg-purple-100 text-purple-800',
                    'entregado': 'bg-green-100 text-green-800',
                    'cancelado': 'bg-red-100 text-red-800'
                };
                
                tbody.innerHTML += `
                    <tr class="border-t hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-900">#${pedido.id}</td>
                        <td class="px-4 py-3 text-slate-700">${pedido.cliente_nombre}</td>
                        <td class="px-4 py-3 text-right font-semibold">${parseInt(pedido.total_piezas).toLocaleString('es-MX')}</td>
                        <td class="px-4 py-3 text-right font-semibold text-green-600">$${parseFloat(pedido.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-semibold ${estadoClasses[pedido.estado] || 'bg-slate-100 text-slate-800'}">
                                ${pedido.estado}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-500">${new Date(pedido.created_at).toLocaleDateString('es-MX')}</td>
                    </tr>
                `;
            });
        }
    } catch (error) {
        console.error('Error cargando últimos pedidos:', error);
    }
}
<?php endif; ?>

// ===================== DRAWER: Detalle Representante =====================
const ESTADO_PEDIDO_CLASSES = {
    entregado:     'bg-green-100 text-green-800',
    en_ruta:       'bg-blue-100 text-blue-800',
    confirmado:    'bg-indigo-100 text-indigo-800',
    por_verificar: 'bg-yellow-100 text-yellow-800',
    pendiente:     'bg-orange-100 text-orange-800',
    cancelado:     'bg-red-100 text-red-800',
};
const ESTADO_SOL_CLASSES = {
    entregada:    'bg-green-100 text-green-800',
    en_transito:  'bg-blue-100 text-blue-800',
    preparando:   'bg-indigo-100 text-indigo-800',
    aprobada:     'bg-teal-100 text-teal-800',
    solicitada:   'bg-yellow-100 text-yellow-800',
    rechazada:    'bg-red-100 text-red-800',
    cancelada:    'bg-slate-100 text-slate-600',
};

function estadoPill(estado, map) {
    const cls = map[estado] || 'bg-slate-100 text-slate-600';
    return `<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ${cls}">${estado}</span>`;
}

function fmtFecha(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString('es-MX', {day:'2-digit', month:'short', year:'numeric'});
}

function drawerTab(tab, btn) {
    ['pedidos','solicitudes','inventario','ventas'].forEach(t => {
        document.getElementById('drawerTab' + t.charAt(0).toUpperCase() + t.slice(1)).classList.toggle('hidden', t !== tab);
    });
    document.querySelectorAll('.drawer-tab').forEach(b => {
        b.classList.toggle('border-primary-500', b === btn);
        b.classList.toggle('text-primary-600', b === btn);
        b.classList.toggle('border-transparent', b !== btn);
        b.classList.toggle('text-slate-500', b !== btn);
    });
    if (tab === 'ventas') {
        const repId = document.getElementById('repDrawer').dataset.repId;
        if (repId) cargarDrawerVentasProducto(repId);
    }
}

async function cargarDrawerVentasProducto(repId) {
    const tbody = document.getElementById('drawerVentasTbody');
    tbody.innerHTML = '<tr><td colspan="4" class="py-8 text-center"><div class="inline-block w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div></td></tr>';
    try {
        const res = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=rep_ventas_producto&rep_id=${repId}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&sin_iva=${sinIva}`);
        const result = await res.json();
        if (!result.success || !result.data.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="py-6 text-center text-slate-400">Sin ventas en el período</td></tr>';
            return;
        }
        tbody.innerHTML = result.data.map(row => {
            const dPiezas  = parseInt(row.directa_piezas || 0);
            const tPiezas  = parseInt(row.tienda_piezas  || 0);
            const totPiezas = parseInt(row.total_piezas  || 0);
            return `<tr class="hover:bg-primary-50 transition">
                <td class="px-3 py-2.5 text-slate-800 font-medium">${esc(row.producto)}</td>
                <td class="px-3 py-2.5 text-right">
                    <span class="font-semibold text-indigo-700">${dPiezas.toLocaleString('es-MX')} pzs</span>
                    <span class="text-xs text-slate-400 block">$${fmtMXN(row.directa_monto)}</span>
                </td>
                <td class="px-3 py-2.5 text-right">
                    <span class="font-semibold text-sky-700">${tPiezas.toLocaleString('es-MX')} pzs</span>
                    <span class="text-xs text-slate-400 block">$${fmtMXN(row.tienda_monto)}</span>
                </td>
                <td class="px-3 py-2.5 text-right">
                    <span class="font-semibold text-green-700">${totPiezas.toLocaleString('es-MX')} pzs</span>
                    <span class="text-xs text-slate-400 block">$${fmtMXN(row.total_monto)}</span>
                </td>
            </tr>`;
        }).join('');
    } catch(e) {
        console.error('rep_ventas_producto', e);
        tbody.innerHTML = '<tr><td colspan="4" class="py-6 text-center text-red-400">Error cargando datos</td></tr>';
    }
}

async function verDetalleRep(repId, nombre, codigo) {
    // Abrir drawer
    document.getElementById('repDrawerOverlay').classList.remove('hidden');
    const drawer = document.getElementById('repDrawer');
    drawer.classList.remove('translate-x-full');
    document.getElementById('drawerNombre').textContent = nombre;
    document.getElementById('drawerCodigo').textContent = codigo;
    document.getElementById('drawerSpinner').classList.remove('hidden');

    // Reset tabs al primero
    drawerTab('pedidos', document.querySelector('.drawer-tab'));

    try {
        const res = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=rep_detalle&rep_id=${repId}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}`);
        const result = await res.json();
        if (!result.success) { showToast(result.message || 'Error cargando detalle', 'error'); return; }

        const { kpis, pedidos, solicitudes, inventario } = result.data;

        // KPIs
        document.getElementById('dkVentas').textContent      = parseInt(kpis.ventas_entregadas || 0).toLocaleString('es-MX');
        document.getElementById('dkMonto').textContent        = '$' + parseFloat(kpis.monto_entregado || 0).toLocaleString('es-MX', {minimumFractionDigits:2});
        document.getElementById('dkValidar').textContent      = parseInt(kpis.por_validar || 0).toLocaleString('es-MX');
        document.getElementById('dkEfectivo').textContent     = parseInt(kpis.efectivo_pendiente || 0).toLocaleString('es-MX');
        document.getElementById('dkMontoEfectivo').textContent= '$' + parseFloat(kpis.monto_efectivo || 0).toLocaleString('es-MX', {minimumFractionDigits:2});

        // Tabla Pedidos
        const CANAL_LABELS = {
            representante_directo: ['Directa',  'bg-indigo-100 text-indigo-700'],
            cliente_directo:       ['Tienda',   'bg-slate-100 text-slate-600'],
        };
        function canalPill(canal) {
            const [label, cls] = CANAL_LABELS[canal] || [canal || '—', 'bg-slate-100 text-slate-500'];
            return `<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ${cls}">${label}</span>`;
        }
        const tbPed = document.getElementById('drawerPedidosTbody');
        tbPed.innerHTML = pedidos.length === 0
            ? '<tr><td colspan="6" class="py-6 text-center text-slate-400">Sin pedidos en el período</td></tr>'
            : pedidos.map(p => `
                <tr class="hover:bg-primary-50 cursor-pointer transition" onclick="verDetallePedido(${p.id})">
                    <td class="px-3 py-2 font-mono text-slate-700">#${p.id}</td>
                    <td class="px-3 py-2 text-slate-700 max-w-[140px] truncate">${p.cliente_nombre || '—'}</td>
                    <td class="px-3 py-2">${canalPill(p.canal)}</td>
                    <td class="px-3 py-2">${estadoPill(p.estado, ESTADO_PEDIDO_CLASSES)}</td>
                    <td class="px-3 py-2 text-right font-semibold text-green-700">$${parseFloat(p.total).toLocaleString('es-MX',{minimumFractionDigits:2})}</td>
                    <td class="px-3 py-2 text-slate-500 whitespace-nowrap">${fmtFecha(p.created_at)}</td>
                </tr>`).join('');

        // Tabla Solicitudes
        const tbSol = document.getElementById('drawerSolicitudesTbody');
        tbSol.innerHTML = solicitudes.length === 0
            ? '<tr><td colspan="5" class="py-6 text-center text-slate-400">Sin solicitudes de consignación</td></tr>'
            : solicitudes.map(s => `
                <tr class="hover:bg-primary-50 cursor-pointer transition" onclick="verDetalleSolicitud(${s.id})">
                    <td class="px-3 py-2 font-mono text-slate-700">#${s.id}</td>
                    <td class="px-3 py-2">${estadoPill(s.estado, ESTADO_SOL_CLASSES)}</td>
                    <td class="px-3 py-2 text-slate-600">${s.paqueteria || '—'}${s.numero_guia ? ' <span class="text-xs text-slate-400">'+s.numero_guia+'</span>' : ''}</td>
                    <td class="px-3 py-2 text-slate-500 whitespace-nowrap">${fmtFecha(s.fecha_solicitud)}</td>
                    <td class="px-3 py-2 text-slate-500 whitespace-nowrap">${fmtFecha(s.fecha_entrega)}</td>
                </tr>`).join('');

        // Tabla Inventario
        const tbInv = document.getElementById('drawerInventarioTbody');
        // Guardar repId en el drawer para drill-down de movimientos
        document.getElementById('repDrawer').dataset.repId = repId;
        tbInv.innerHTML = inventario.length === 0
            ? '<tr><td colspan="5" class="py-6 text-center text-slate-400">Sin inventario asignado</td></tr>'
            : inventario.map(i => `
                <tr class="hover:bg-primary-50 cursor-pointer transition" onclick="verMovimientosInventario(${repId}, ${i.producto_id}, '${i.producto_nombre.replace(/'/g,"\\'")}')">
                    <td class="px-3 py-2 text-slate-800 font-medium">${i.producto_nombre}</td>
                    <td class="px-3 py-2 text-right font-semibold text-emerald-700">${parseInt(i.cantidad_disponible)}</td>
                    <td class="px-3 py-2 text-right text-indigo-600">${parseInt(i.cantidad_reservada)}</td>
                    <td class="px-3 py-2 text-right text-green-700">${parseInt(i.cantidad_vendida)}</td>
                    <td class="px-3 py-2 text-right text-slate-500">${parseInt(i.cantidad_devuelta)}</td>
                </tr>`).join('');

    } catch (e) {
        console.error('Error en detalle representante:', e);
        showToast('Error cargando detalle', 'error');
    } finally {
        document.getElementById('drawerSpinner').classList.add('hidden');
    }
}

function cerrarDetalleRep() {
    document.getElementById('repDrawer').classList.add('translate-x-full');
    document.getElementById('repDrawerOverlay').classList.add('hidden');
}

// ===================== MINI-MODAL funciones =====================
function abrirMiniModal(titulo) {
    document.getElementById('miniModalTitulo').textContent = titulo;
    document.getElementById('miniModalBody').innerHTML =
        '<div class="flex justify-center py-8"><div class="w-8 h-8 border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div></div>';
    document.getElementById('miniModal').classList.remove('hidden');
}

function cerrarMiniModal() {
    document.getElementById('miniModal').classList.add('hidden');
}

async function verDetallePedido(pedidoId) {
    abrirMiniModal(`Pedido #${pedidoId} — Productos`);
    try {
        const res = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=pedido_detalle&pedido_id=${pedidoId}`);
        const result = await res.json();
        if (!result.success || !result.data.length) {
            document.getElementById('miniModalBody').innerHTML = '<p class="text-center text-slate-400 py-6">Sin productos registrados</p>';
            return;
        }
        const total = result.data.reduce((s, i) => s + parseFloat(i.subtotal), 0);
        document.getElementById('miniModalBody').innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-slate-100"><tr>
                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Producto</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Cant.</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">P.U.</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Subtotal</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    ${result.data.map(i => `<tr class="hover:bg-slate-50">
                        <td class="px-3 py-2 text-slate-800">${i.nombre}</td>
                        <td class="px-3 py-2 text-right">${parseInt(i.cantidad)}</td>
                        <td class="px-3 py-2 text-right text-slate-500">$${parseFloat(i.precio_unitario).toLocaleString('es-MX',{minimumFractionDigits:2})}</td>
                        <td class="px-3 py-2 text-right font-semibold text-green-700">$${parseFloat(i.subtotal).toLocaleString('es-MX',{minimumFractionDigits:2})}</td>
                    </tr>`).join('')}
                </tbody>
                <tfoot class="bg-slate-50 border-t-2 border-slate-300">
                    <tr><td colspan="3" class="px-3 py-2 font-bold text-right text-slate-700">Total</td>
                    <td class="px-3 py-2 text-right font-bold text-green-700">$${total.toLocaleString('es-MX',{minimumFractionDigits:2})}</td></tr>
                </tfoot>
            </table>`;
    } catch(e) { document.getElementById('miniModalBody').innerHTML = '<p class="text-center text-red-500 py-6">Error al cargar</p>'; }
}

async function verDetalleSolicitud(solicitudId) {
    abrirMiniModal(`Solicitud #${solicitudId} — Productos`);
    try {
        const res = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=solicitud_detalle&solicitud_id=${solicitudId}`);
        const result = await res.json();
        if (!result.success || !result.data.length) {
            document.getElementById('miniModalBody').innerHTML = '<p class="text-center text-slate-400 py-6">Sin productos registrados</p>';
            return;
        }
        document.getElementById('miniModalBody').innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-slate-100"><tr>
                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Producto</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Solicitado</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Aprobado</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Entregado</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    ${result.data.map(i => `<tr class="hover:bg-slate-50">
                        <td class="px-3 py-2 text-slate-800">${i.nombre}</td>
                        <td class="px-3 py-2 text-right">${parseInt(i.cantidad_solicitada)}</td>
                        <td class="px-3 py-2 text-right text-teal-700">${parseInt(i.cantidad_aprobada)}</td>
                        <td class="px-3 py-2 text-right font-semibold text-green-700">${parseInt(i.cantidad_entregada)}</td>
                    </tr>`).join('')}
                </tbody>
            </table>`;
    } catch(e) { document.getElementById('miniModalBody').innerHTML = '<p class="text-center text-red-500 py-6">Error al cargar</p>'; }
}

const TIPO_MOV_LABELS = {
    entrada_consignacion: {label:'Entrada consignación', cls:'bg-blue-100 text-blue-800'},
    venta:               {label:'Venta',                 cls:'bg-green-100 text-green-800'},
    reserva:             {label:'Reserva',               cls:'bg-indigo-100 text-indigo-800'},
    liberacion_reserva:  {label:'Liberación reserva',    cls:'bg-slate-100 text-slate-700'},
    devolucion:          {label:'Devolución',            cls:'bg-orange-100 text-orange-800'},
    ajuste:              {label:'Ajuste',                cls:'bg-yellow-100 text-yellow-800'},
    cancelacion_venta:   {label:'Cancelación venta',     cls:'bg-red-100 text-red-800'},
};

async function verMovimientosInventario(repId, productoId, productoNombre) {
    abrirMiniModal(`Movimientos — ${productoNombre}`);
    try {
        const res = await fetch(`${BASE_PATH}api/dashboard-stats.php?action=inventario_movimientos&rep_id=${repId}&producto_id=${productoId}`);
        const result = await res.json();
        if (!result.success || !result.data.length) {
            document.getElementById('miniModalBody').innerHTML = '<p class="text-center text-slate-400 py-6">Sin movimientos registrados</p>';
            return;
        }
        document.getElementById('miniModalBody').innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-slate-100 sticky top-0"><tr>
                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Tipo</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Cant.</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Antes</th>
                    <th class="px-3 py-2 text-right font-semibold text-slate-600">Después</th>
                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Ref.</th>
                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Fecha</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    ${result.data.map(m => {
                        const t = TIPO_MOV_LABELS[m.tipo] || {label:m.tipo, cls:'bg-slate-100 text-slate-600'};
                        const ref = m.pedido_id ? `Pedido #${m.pedido_id}` : (m.solicitud_consignacion_id ? `Sol. #${m.solicitud_consignacion_id}` : (m.notas || '—'));
                        const signo = m.cantidad > 0 ? '+' : '';
                        return `<tr class="hover:bg-slate-50">
                            <td class="px-3 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ${t.cls}">${t.label}</span></td>
                            <td class="px-3 py-2 text-right font-mono font-semibold ${m.cantidad < 0 ? 'text-red-600':'text-green-700'}">${signo}${m.cantidad}</td>
                            <td class="px-3 py-2 text-right font-mono text-slate-500">${m.cantidad_antes}</td>
                            <td class="px-3 py-2 text-right font-mono text-slate-800">${m.cantidad_despues}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">${ref}</td>
                            <td class="px-3 py-2 text-xs text-slate-400 whitespace-nowrap">${fmtFecha(m.created_at)}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>`;
    } catch(e) { document.getElementById('miniModalBody').innerHTML = '<p class="text-center text-red-500 py-6">Error al cargar</p>'; }
}

// Cerrar con Escape: primero mini-modal, luego drawer
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (!document.getElementById('miniModal').classList.contains('hidden')) {
        cerrarMiniModal();
    } else {
        cerrarDetalleRep();
    }
});

// ===================== REPORTE DE VENTAS =====================
let reporteDimActiva = 'producto';

const REPORTE_CONFIG = {
    producto: {
        cols: [
            {label:'#', align:'text-center w-10'},
            {label:'Producto', align:'text-left'},
            {label:'Marca', align:'text-left'},
            {label:'Piezas', align:'text-right'},
            {label:'Monto', align:'text-right'},
            {label:'Pedidos', align:'text-right'},
            {label:'% monto', align:'text-right'},
        ],
        render(row, i, totalMonto) {
            const pct  = totalMonto > 0 ? ((row.monto / totalMonto) * 100).toFixed(1) : '0.0';
            const barW = totalMonto > 0 ? Math.round((row.monto / totalMonto) * 100) : 0;
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">${esc(row.label)}</td>
                <td class="px-4 py-3 text-slate-500 text-xs">${esc(row.extra) || '—'}</td>
                <td class="px-4 py-3 text-right text-slate-700">${parseInt(row.piezas||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-16 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                            <div class="h-full rounded-full" style="width:${barW}%;background:var(--accent)"></div>
                        </div>
                        <span class="text-xs text-slate-500 w-9 text-right">${pct}%</span>
                    </div>
                </td>
            </tr>`;
        }
    },
    cliente: {
        cols: [
            {label:'#', align:'text-center w-10'},
            {label:'Cliente', align:'text-left'},
            {label:'Tipo', align:'text-left'},
            {label:'Pedidos', align:'text-right'},
            {label:'Monto', align:'text-right'},
            {label:'Último pedido', align:'text-left'},
        ],
        render(row, i) {
            const badge = row.extra === 'medico'
                ? '<span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Médico</span>'
                : row.extra === 'paciente'
                ? '<span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">Paciente</span>'
                : '<span class="text-xs text-slate-400">—</span>';
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">${esc(row.label)}</td>
                <td class="px-4 py-3">${badge}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
                <td class="px-4 py-3 text-sm text-slate-500">${row.ultimo_pedido || '—'}</td>
            </tr>`;
        }
    },
    estado: {
        cols: [
            {label:'#', align:'text-center w-10'},
            {label:'Estado', align:'text-left'},
            {label:'Pedidos', align:'text-right'},
            {label:'Clientes únicos', align:'text-right'},
            {label:'Monto', align:'text-right'},
        ],
        render(row, i) {
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">${esc(row.label)}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.clientes||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
            </tr>`;
        }
    },
    localidad: {
        cols: [
            {label:'#', align:'text-center w-10'},
            {label:'Ciudad', align:'text-left'},
            {label:'Estado', align:'text-left'},
            {label:'Pedidos', align:'text-right'},
            {label:'Clientes únicos', align:'text-right'},
            {label:'Monto', align:'text-right'},
        ],
        render(row, i) {
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">${esc(row.label)}</td>
                <td class="px-4 py-3 text-sm text-slate-500">${esc(row.extra) || '—'}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.clientes||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
            </tr>`;
        }
    },
    especialidad: {
        cols: [
            {label:'#', align:'text-center w-10'},
            {label:'Especialidad', align:'text-left'},
            {label:'Pedidos', align:'text-right'},
            {label:'Clientes', align:'text-right'},
            {label:'Monto', align:'text-right'},
        ],
        render(row, i) {
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">${esc(row.label)}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.clientes||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
            </tr>`;
        }
    },
    cp: {
        cols: [
            {label:'#', align:'text-center w-10'},
            {label:'CP', align:'text-left'},
            {label:'Ciudad', align:'text-left'},
            {label:'Pedidos', align:'text-right'},
            {label:'Clientes', align:'text-right'},
            {label:'Monto', align:'text-right'},
        ],
        render(row, i) {
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-mono font-semibold text-slate-900 tracking-widest">${esc(row.label)}</td>
                <td class="px-4 py-3 text-sm text-slate-500">${esc(row.extra) || '—'}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.clientes||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
            </tr>`;
        }
    },
    representante: {
        cols: [
            {label:'#',            align:'text-center w-10'},
            {label:'Representante', align:'text-left'},
            {label:'Código',        align:'text-left'},
            {label:'Pedidos',       align:'text-right'},
            {label:'Clientes',      align:'text-right'},
            {label:'Monto',         align:'text-right'},
            {label:'% monto',       align:'text-right'},
        ],
        render(row, i, totalMonto) {
            const pct  = totalMonto > 0 ? ((row.monto / totalMonto) * 100).toFixed(1) : '0.0';
            const barW = totalMonto > 0 ? Math.round((row.monto / totalMonto) * 100) : 0;
            return `<tr class="border-t hover:bg-slate-50 transition">
                <td class="px-4 py-3 text-center font-bold text-slate-400 text-xs">${i+1}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">${esc(row.label)}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500">${esc(row.extra) || '—'}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.pedidos||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right text-slate-600">${parseInt(row.clientes||0).toLocaleString('es-MX')}</td>
                <td class="px-4 py-3 text-right font-semibold text-green-700">$${fmtMXN(row.monto)}</td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-16 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                            <div class="h-full rounded-full" style="width:${barW}%;background:var(--accent)"></div>
                        </div>
                        <span class="text-xs text-slate-500 w-9 text-right">${pct}%</span>
                    </div>
                </td>
            </tr>`;
        }
    },
};

// Helpers
function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtMXN(v) {
    return parseFloat(v||0).toLocaleString('es-MX', {minimumFractionDigits:2});
}

function initReporteVentas() {
    // Marcar el primer tab como activo
    const first = document.querySelector('.rv-dim-btn[data-dim="producto"]');
    if (first) first.classList.add('rv-dim-active');
    // El toggle de kits solo aplica a la dimensión producto (activa por defecto)
    _rvSyncKitsToggle('producto');
    cargarReporteVentas();
}

function setReporteDim(dim, btn) {
    reporteDimActiva = dim;
    document.querySelectorAll('.rv-dim-btn').forEach(b => {
        b.classList.remove('rv-dim-active');
        b.classList.add('border-transparent', 'text-slate-500');
    });
    btn.classList.add('rv-dim-active');
    btn.classList.remove('border-transparent', 'text-slate-500');
    _rvSyncKitsToggle(dim);
    cargarReporteVentas();
}

function _rvSyncKitsToggle(dim) {
    const toggle = document.getElementById('rvKitsToggle');
    if (!toggle) return;
    toggle.style.display = dim === 'producto' ? '' : 'none';
}

async function cargarReporteVentas() {
    const tbody = document.getElementById('reporteVentasTbody');
    const thead = document.getElementById('reporteVentasThead');
    const cfg   = REPORTE_CONFIG[reporteDimActiva];
    const ncols = cfg.cols.length;

    // Render headers
    thead.innerHTML = `<tr>${cfg.cols.map(c =>
        `<th class="px-4 py-3 ${c.align} text-xs font-semibold uppercase tracking-wide">${esc(c.label)}</th>`
    ).join('')}</tr>`;

    tbody.innerHTML = `<tr><td colspan="${ncols}" class="text-center py-8 text-slate-500">Cargando…</td></tr>`;
    document.getElementById('reporteVentasTotal').textContent = '';

    try {
        const mostrarKitsRV = reporteDimActiva === 'producto' && document.getElementById('rvMostrarKits')?.checked ? 1 : 0;
        const url = `${BASE_PATH}api/dashboard-stats.php?action=reporte_ventas&dimension=${reporteDimActiva}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&mostrar_kits=${mostrarKitsRV}&sin_iva=${sinIva}${gerenteId ? '&gerente_id='+gerenteId : ''}${rvRepId ? '&rv_rep_id='+rvRepId : ''}`;
        const res    = await fetch(url);
        const result = await res.json();

        if (!result.success) {
            tbody.innerHTML = `<tr><td colspan="${ncols}" class="text-center py-6 text-red-500">${esc(result.message)}</td></tr>`;
            return;
        }
        if (!result.data.length) {
            tbody.innerHTML = `<tr><td colspan="${ncols}" class="text-center py-8 text-slate-500">Sin datos para el período seleccionado.</td></tr>`;
            return;
        }

        document.getElementById('reporteVentasTotal').textContent =
            `${result.data.length.toLocaleString('es-MX')} registros · $${fmtMXN(result.total_monto)}`;

        tbody.innerHTML = result.data.map((row, i) => cfg.render(row, i, result.total_monto)).join('');
    } catch (e) {
        console.error('reporte_ventas', e);
        tbody.innerHTML = `<tr><td colspan="${ncols}" class="text-center py-6 text-red-500">Error cargando datos.</td></tr>`;
    }
}
</script>

<?php include '../includes/footer.php'; ?>
