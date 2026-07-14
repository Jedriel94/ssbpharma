<?php
require_once '../includes/auth_admin.php';
require_once '../config/database.php';

$rol_codigo = $_SESSION['admin_rol_codigo'] ?? '';
if ($rol_codigo !== 'admin') {
    header('Location: ' . url('admin/dashboard.php?error=acceso_denegado'));
    exit;
}

$pdo = Database::getInstance()->getConnection();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

$q = trim($_GET['q'] ?? '');
$tablaSeleccionada = trim($_GET['tabla'] ?? '');
$qDatos = trim($_GET['q_datos'] ?? '');
$campoDatos = trim($_GET['campo_datos'] ?? '');
$tabsParam = trim($_GET['tabs'] ?? '');
$verMeta = isset($_GET['ver_meta']) && $_GET['ver_meta'] === '1';
$perPage = (int)($_GET['per_page'] ?? 50);
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}

$params = [$dbName];
$sqlTablas = "
    SELECT
        t.TABLE_NAME AS nombre,
        t.ENGINE AS motor,
        COALESCE(t.TABLE_ROWS, 0) AS filas_aprox,
        COALESCE(t.DATA_LENGTH, 0) AS data_len,
        COALESCE(t.INDEX_LENGTH, 0) AS index_len,
        t.TABLE_COLLATION AS collation_name
    FROM information_schema.TABLES t
    WHERE t.TABLE_SCHEMA = ?
";
if ($q !== '') {
    $sqlTablas .= ' AND t.TABLE_NAME LIKE ?';
    $params[] = '%' . $q . '%';
}
$sqlTablas .= ' ORDER BY t.TABLE_NAME ASC';

$stmtTablas = $pdo->prepare($sqlTablas);
$stmtTablas->execute($params);
$tablas = $stmtTablas->fetchAll(PDO::FETCH_ASSOC);
$tablasDisponibles = array_values(array_map(function ($r) {
    return $r['nombre'];
}, $tablas));

$tabs = [];
if ($tabsParam !== '') {
    foreach (explode(',', $tabsParam) as $tb) {
        $tb = trim($tb);
        if ($tb !== '' && in_array($tb, $tablasDisponibles, true) && !in_array($tb, $tabs, true)) {
            $tabs[] = $tb;
        }
    }
}
if ($tablaSeleccionada !== '' && in_array($tablaSeleccionada, $tablasDisponibles, true) && !in_array($tablaSeleccionada, $tabs, true)) {
    $tabs[] = $tablaSeleccionada;
}
if ($tablaSeleccionada === '' && !empty($tabs)) {
    $tablaSeleccionada = $tabs[0];
}

$tablaExiste = ($tablaSeleccionada !== '' && in_array($tablaSeleccionada, $tablasDisponibles, true));
$columnas = [];
$indices = [];
$foraneas = [];
$filasDatos = [];
$haySiguiente = false;

if ($tablaExiste) {
    $stmtCols = $pdo->prepare("SELECT
            c.ORDINAL_POSITION,
            c.COLUMN_NAME,
            c.COLUMN_TYPE,
            c.IS_NULLABLE,
            c.COLUMN_KEY,
            c.COLUMN_DEFAULT,
            c.EXTRA,
            c.COLLATION_NAME
        FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME = ?
        ORDER BY c.ORDINAL_POSITION ASC");
    $stmtCols->execute([$dbName, $tablaSeleccionada]);
    $columnas = $stmtCols->fetchAll(PDO::FETCH_ASSOC);

    $nombresColumnas = array_values(array_map(function ($c) {
        return $c['COLUMN_NAME'];
    }, $columnas));
    if ($campoDatos !== '' && !in_array($campoDatos, $nombresColumnas, true)) {
        $campoDatos = '';
    }

    if ($verMeta) {
        $stmtIdx = $pdo->prepare("SELECT
                s.INDEX_NAME,
                s.NON_UNIQUE,
                s.SEQ_IN_INDEX,
                s.COLUMN_NAME,
                s.INDEX_TYPE
            FROM information_schema.STATISTICS s
            WHERE s.TABLE_SCHEMA = ? AND s.TABLE_NAME = ?
            ORDER BY s.INDEX_NAME ASC, s.SEQ_IN_INDEX ASC");
        $stmtIdx->execute([$dbName, $tablaSeleccionada]);
        $indices = $stmtIdx->fetchAll(PDO::FETCH_ASSOC);

        $stmtFk = $pdo->prepare("SELECT
                k.CONSTRAINT_NAME,
                k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = ?
              AND k.TABLE_NAME = ?
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.CONSTRAINT_NAME ASC, k.ORDINAL_POSITION ASC");
        $stmtFk->execute([$dbName, $tablaSeleccionada]);
        $foraneas = $stmtFk->fetchAll(PDO::FETCH_ASSOC);
    }

    $tablaSafe = str_replace('`', '``', $tablaSeleccionada);

    $whereData = '';
    $paramsData = [];
    if ($qDatos !== '' && !empty($columnas)) {
        $qLike = '%' . $qDatos . '%';
        if ($campoDatos !== '') {
            $colNameSafe = str_replace('`', '``', $campoDatos);
            $whereData = " WHERE CAST(`{$colNameSafe}` AS CHAR) LIKE ?";
            $paramsData[] = $qLike;
        } else {
            $likes = [];
            foreach ($columnas as $c) {
                $colNameSafe = str_replace('`', '``', $c['COLUMN_NAME']);
                $likes[] = "CAST(`{$colNameSafe}` AS CHAR) LIKE ?";
                $paramsData[] = $qLike;
            }
            if (!empty($likes)) {
                $whereData = ' WHERE ' . implode(' OR ', $likes);
            }
        }
    }

    $offset = ($page - 1) * $perPage;
    $limiteLectura = $perPage + 1;

    if ($whereData === '') {
        $stmtData = $pdo->query("SELECT * FROM `{$tablaSafe}` LIMIT {$offset}, {$limiteLectura}");
        $filasCrudas = $stmtData->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtData = $pdo->prepare("SELECT * FROM `{$tablaSafe}`{$whereData} LIMIT {$offset}, {$limiteLectura}");
        $stmtData->execute($paramsData);
        $filasCrudas = $stmtData->fetchAll(PDO::FETCH_ASSOC);
    }

    if (count($filasCrudas) > $perPage) {
        $haySiguiente = true;
        $filasDatos = array_slice($filasCrudas, 0, $perPage);
    } else {
        $filasDatos = $filasCrudas;
    }
}

function fmt_bytes($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = max(0, min($pow, count($units) - 1));
    $val = $bytes / pow(1024, $pow);
    return number_format($val, $pow === 0 ? 0 : 2) . ' ' . $units[$pow];
}

function tabs_to_param($tabs) {
    return implode(',', $tabs);
}

$totalTablas = count($tablas);
$totalBytes = 0.0;
foreach ($tablas as $t) {
    $totalBytes += (float)$t['data_len'] + (float)$t['index_len'];
}

include '../includes/header.php';
?>

<style>
.tb-card  { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 14px; }
.tb-filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.tb-label  { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.tb-input { height: 36px; padding: 0 12px; border-radius: 9px; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text-primary); font-size: 13px; min-width: 280px; }
.tb-btn { height: 36px; padding: 0 16px; border-radius: 9px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; }
.tb-btn-primary { background: var(--accent); color: #fff; }
.tb-btn-outline { background: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-card); }
.tb-table-wrap { overflow-x: auto; }
.tb-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tb-table thead th { background: linear-gradient(to right, var(--tw-neu-800), var(--tw-neu-900)); color: #fff; padding: 9px 12px; text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 2; }
body.theme-dark .tb-table thead th { background: linear-gradient(to right, var(--tw-neu-100), var(--tw-neu-50)); }
.tb-table tbody tr { border-bottom: 1px solid var(--border-card); }
.tb-table tbody td { padding: 8px 12px; color: var(--text-primary); white-space: nowrap; }
.tb-empty { text-align:center; padding: 30px 20px; color: var(--text-muted); font-size: 14px; }
.tb-chip { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: var(--bg-input); border: 1px solid var(--border-card); color: var(--text-secondary); }
.tb-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.tb-tab { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:10px; border:1px solid var(--border-card); background:var(--bg-input); color:var(--text-secondary); font-size:12px; font-weight:700; text-decoration:none; }
.tb-tab.active { border-color:var(--accent); color:var(--accent); }
.tb-tab-close { opacity:.8; font-weight:800; padding:0 2px; }
.tb-layout { display: grid; grid-template-columns: 320px 1fr; gap: 16px; align-items: start; }
.tb-side { position: sticky; top: 88px; }
.tb-side-list { max-height: 72vh; overflow: auto; }
.tb-side-item { display: block; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-card); background: var(--bg-input); color: var(--text-primary); text-decoration: none; font-size: 12px; margin-bottom: 8px; }
.tb-side-item:hover { background: var(--bg-card-hover); }
.tb-side-item.active { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
.tb-side-row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.tb-side-name { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-side-meta { font-size: 11px; color: var(--text-secondary); margin-top: 4px; }
@media (max-width: 1024px) {
    .tb-layout { grid-template-columns: 1fr; }
    .tb-side { position: static; }
    .tb-side-list { max-height: 42vh; }
}
</style>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold" style="color:var(--text-primary)">Administrador de Tablas</h1>
        <p style="color:var(--text-muted);font-size:13px;margin-top:4px">
            Datos primero para revisar inconsistencias. Estructura e indices solo cuando la solicitas.
        </p>
    </div>

    <div class="tb-layout">
        <aside class="tb-side">
            <div class="tb-card" style="padding:14px 14px">
                <form method="GET" style="display:flex;flex-direction:column;gap:8px">
                    <div class="tb-label">Buscar tabla</div>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="tb-input" style="min-width:0;width:100%" placeholder="Ej. pedidos, productos">
                    <input type="hidden" name="tabla" value="<?= htmlspecialchars($tablaSeleccionada) ?>">
                    <input type="hidden" name="q_datos" value="<?= htmlspecialchars($qDatos) ?>">
                    <input type="hidden" name="campo_datos" value="<?= htmlspecialchars($campoDatos) ?>">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                    <input type="hidden" name="ver_meta" value="<?= $verMeta ? '1' : '' ?>">
                    <input type="hidden" name="tabs" value="<?= htmlspecialchars(tabs_to_param($tabs)) ?>">
                    <div style="display:flex;gap:8px">
                        <button type="submit" class="tb-btn tb-btn-primary" style="flex:1">Filtrar</button>
                        <a href="administrador-tablas.php" class="tb-btn tb-btn-outline" style="display:flex;align-items:center;justify-content:center">Limpiar</a>
                    </div>
                </form>
            </div>

            <div class="tb-card mt-3" style="padding:12px 12px">
                <div class="tb-side-meta" style="margin:0 0 10px 0">
                    <?= number_format($totalTablas) ?> tablas · <?= htmlspecialchars(fmt_bytes($totalBytes)) ?>
                </div>
                <div class="tb-side-list">
                    <?php if (empty($tablas)): ?>
                    <div class="tb-empty" style="padding:22px 10px">Sin tablas para mostrar.</div>
                    <?php else: ?>
                    <?php foreach ($tablas as $t): ?>
                    <?php
                        $tabsOpen = $tabs;
                        if (!in_array($t['nombre'], $tabsOpen, true)) {
                            $tabsOpen[] = $t['nombre'];
                        }
                        $tableTotal = (float)$t['data_len'] + (float)$t['index_len'];
                    ?>
                    <a class="tb-side-item <?= $tablaSeleccionada === $t['nombre'] ? 'active' : '' ?>"
                              href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $t['nombre'], 'per_page' => $perPage, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'page' => 1, 'ver_meta' => $verMeta ? '1' : null, 'tabs' => tabs_to_param($tabsOpen)])) ?>">
                        <div class="tb-side-row">
                            <span class="tb-side-name"><?= htmlspecialchars($t['nombre']) ?></span>
                            <span class="tb-chip"><?= number_format((int)$t['filas_aprox']) ?></span>
                        </div>
                        <div class="tb-side-meta"><?= htmlspecialchars($t['motor'] ?: '—') ?> · <?= htmlspecialchars(fmt_bytes($tableTotal)) ?></div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <section>
            <?php if ($tablaExiste): ?>
            <div class="tb-card" style="padding:16px 20px">
                <?php if (!empty($tabs)): ?>
                <div class="tb-tabs">
                    <?php foreach ($tabs as $tab): ?>
                    <?php
                        $tabsSinActual = array_values(array_filter($tabs, function ($x) use ($tab) {
                            return $x !== $tab;
                        }));
                        $nextIfClose = ($tablaSeleccionada === $tab) ? ($tabsSinActual[0] ?? '') : $tablaSeleccionada;
                    ?>
                    <a class="tb-tab <?= $tab === $tablaSeleccionada ? 'active' : '' ?>"
                       href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $tab, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'per_page' => $perPage, 'page' => 1, 'ver_meta' => $verMeta ? '1' : null, 'tabs' => tabs_to_param($tabs)])) ?>">
                        <?= htmlspecialchars($tab) ?>
                        <span onclick="event.preventDefault();event.stopPropagation();location.href='?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $nextIfClose ?: null, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'per_page' => $perPage, 'page' => 1, 'ver_meta' => $verMeta ? '1' : null, 'tabs' => tabs_to_param($tabsSinActual)])) ?>'" class="tb-tab-close" title="Cerrar tab">x</span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                    <h2 class="text-xl font-bold" style="color:var(--text-primary)">Tabla: <?= htmlspecialchars($tablaSeleccionada) ?></h2>
                    <div class="flex items-center gap-2">
                        <span class="tb-chip"><?= number_format(count($columnas)) ?> columnas</span>
                        <?php if ($verMeta): ?>
                        <a class="tb-btn tb-btn-outline" style="height:32px;padding:0 12px;display:inline-flex;align-items:center"
                                    href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $tablaSeleccionada, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'per_page' => $perPage, 'page' => $page, 'tabs' => tabs_to_param($tabs)])) ?>">Ocultar detalles</a>
                        <?php else: ?>
                        <a class="tb-btn tb-btn-outline" style="height:32px;padding:0 12px;display:inline-flex;align-items:center"
                                    href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $tablaSeleccionada, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'per_page' => $perPage, 'page' => $page, 'ver_meta' => '1', 'tabs' => tabs_to_param($tabs)])) ?>">Ver detalles</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tb-card" style="padding:12px 14px">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
                        <h3 class="font-bold" style="color:var(--text-primary)">Datos (solo lectura)</h3>
                        <form method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                            <input type="hidden" name="tabla" value="<?= htmlspecialchars($tablaSeleccionada) ?>">
                            <input type="hidden" name="q_datos" value="<?= htmlspecialchars($qDatos) ?>">
                            <input type="hidden" name="campo_datos" value="<?= htmlspecialchars($campoDatos) ?>">
                            <input type="hidden" name="ver_meta" value="<?= $verMeta ? '1' : '' ?>">
                            <input type="hidden" name="tabs" value="<?= htmlspecialchars(tabs_to_param($tabs)) ?>">
                            <div class="tb-label" style="margin:0">Filas por pagina</div>
                            <select name="per_page" class="tb-input" style="min-width:110px;height:34px;padding-right:28px" onchange="this.form.submit()">
                                <?php foreach ($allowedPerPage as $pp): ?>
                                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <form method="GET" class="tb-filter-bar" style="margin-bottom:10px">
                        <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <input type="hidden" name="tabla" value="<?= htmlspecialchars($tablaSeleccionada) ?>">
                        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                        <input type="hidden" name="page" value="1">
                        <input type="hidden" name="ver_meta" value="<?= $verMeta ? '1' : '' ?>">
                        <input type="hidden" name="tabs" value="<?= htmlspecialchars(tabs_to_param($tabs)) ?>">
                        <div>
                            <div class="tb-label">Buscar en datos de la tabla</div>
                            <input type="text" name="q_datos" value="<?= htmlspecialchars($qDatos) ?>" class="tb-input" placeholder="Buscar texto en cualquier columna">
                        </div>
                        <div>
                            <div class="tb-label">Campo</div>
                            <select name="campo_datos" class="tb-input" style="min-width:220px">
                                <option value="">Todas las columnas</option>
                                <?php foreach ($columnas as $c): ?>
                                <?php $cn = $c['COLUMN_NAME']; ?>
                                <option value="<?= htmlspecialchars($cn) ?>" <?= $campoDatos === $cn ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:flex;gap:8px;align-items:flex-end">
                            <button type="submit" class="tb-btn tb-btn-primary">Buscar</button>
                            <a class="tb-btn tb-btn-outline" style="display:inline-flex;align-items:center;justify-content:center"
                               href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $tablaSeleccionada, 'per_page' => $perPage, 'ver_meta' => $verMeta ? '1' : null, 'tabs' => tabs_to_param($tabs)])) ?>">Limpiar</a>
                        </div>
                    </form>

                    <div style="color:var(--text-secondary);font-size:12px;margin-bottom:8px">
                        Mostrando <?= number_format(count($filasDatos)) ?> registros (conteo total omitido para mayor rendimiento).
                    </div>

                    <div class="tb-table-wrap" style="max-height:420px">
                        <?php if (empty($filasDatos)): ?>
                        <div class="tb-empty" style="padding:24px 12px">Sin datos en esta tabla.</div>
                        <?php else: ?>
                        <table class="tb-table">
                            <thead>
                                <tr>
                                    <?php foreach ($columnas as $c): ?>
                                    <th><?= htmlspecialchars($c['COLUMN_NAME']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filasDatos as $r): ?>
                                <tr>
                                    <?php foreach ($columnas as $c): ?>
                                    <?php $cn = $c['COLUMN_NAME']; $val = $r[$cn] ?? null; ?>
                                    <td>
                                        <?php if ($val === null): ?>
                                            <span class="tb-chip">NULL</span>
                                        <?php else: ?>
                                            <?= htmlspecialchars((string)$val) ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center justify-between mt-3" style="font-size:12px;color:var(--text-secondary)">
                        <div>Pagina <?= number_format($page) ?></div>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                            <a class="tb-btn tb-btn-outline" style="height:30px;padding:0 10px;display:inline-flex;align-items:center"
                                         href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $tablaSeleccionada, 'per_page' => $perPage, 'page' => $page - 1, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'ver_meta' => $verMeta ? '1' : null, 'tabs' => tabs_to_param($tabs)])) ?>">Anterior</a>
                            <?php endif; ?>
                            <?php if ($haySiguiente): ?>
                            <a class="tb-btn tb-btn-outline" style="height:30px;padding:0 10px;display:inline-flex;align-items:center"
                                         href="?<?= http_build_query(array_filter(['q' => $q, 'tabla' => $tablaSeleccionada, 'per_page' => $perPage, 'page' => $page + 1, 'q_datos' => $qDatos, 'campo_datos' => $campoDatos, 'ver_meta' => $verMeta ? '1' : null, 'tabs' => tabs_to_param($tabs)])) ?>">Siguiente</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($verMeta): ?>
            <div class="tb-card mt-4" style="padding:12px 14px">
                <h3 class="font-bold mb-3" style="color:var(--text-primary)">Estructura</h3>
                <div class="tb-table-wrap" style="max-height:42vh">
                    <table class="tb-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Columna</th>
                                <th>Tipo</th>
                                <th>NULL</th>
                                <th>Key</th>
                                <th>Default</th>
                                <th>Extra</th>
                                <th>Collation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($columnas as $c): ?>
                            <tr>
                                <td><?= (int)$c['ORDINAL_POSITION'] ?></td>
                                <td><strong><?= htmlspecialchars($c['COLUMN_NAME']) ?></strong></td>
                                <td><?= htmlspecialchars($c['COLUMN_TYPE']) ?></td>
                                <td><?= htmlspecialchars($c['IS_NULLABLE']) ?></td>
                                <td><?= htmlspecialchars($c['COLUMN_KEY'] ?: '—') ?></td>
                                <td><?= htmlspecialchars((string)$c['COLUMN_DEFAULT']) ?></td>
                                <td><?= htmlspecialchars($c['EXTRA'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($c['COLLATION_NAME'] ?: '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
                    <div class="tb-card" style="padding:12px 14px">
                        <h4 class="font-bold mb-2" style="color:var(--text-primary)">Indices</h4>
                        <div class="tb-table-wrap" style="max-height:260px">
                            <?php if (empty($indices)): ?>
                            <div class="tb-empty" style="padding:24px 12px">Sin indices.</div>
                            <?php else: ?>
                            <table class="tb-table">
                                <thead><tr><th>Indice</th><th>Unico</th><th>Seq</th><th>Columna</th><th>Tipo</th></tr></thead>
                                <tbody>
                                    <?php foreach ($indices as $i): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($i['INDEX_NAME']) ?></td>
                                        <td><?= ((int)$i['NON_UNIQUE'] === 0) ? 'Si' : 'No' ?></td>
                                        <td><?= (int)$i['SEQ_IN_INDEX'] ?></td>
                                        <td><?= htmlspecialchars($i['COLUMN_NAME']) ?></td>
                                        <td><?= htmlspecialchars($i['INDEX_TYPE']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tb-card" style="padding:12px 14px">
                        <h4 class="font-bold mb-2" style="color:var(--text-primary)">Llaves Foraneas</h4>
                        <div class="tb-table-wrap" style="max-height:260px">
                            <?php if (empty($foraneas)): ?>
                            <div class="tb-empty" style="padding:24px 12px">Sin llaves foraneas.</div>
                            <?php else: ?>
                            <table class="tb-table">
                                <thead><tr><th>Constraint</th><th>Columna</th><th>Referencia</th></tr></thead>
                                <tbody>
                                    <?php foreach ($foraneas as $fk): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($fk['CONSTRAINT_NAME']) ?></td>
                                        <td><?= htmlspecialchars($fk['COLUMN_NAME']) ?></td>
                                        <td><?= htmlspecialchars($fk['REFERENCED_TABLE_NAME'] . '.' . $fk['REFERENCED_COLUMN_NAME']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php elseif ($tablaSeleccionada !== ''): ?>
            <div class="tb-card" style="padding:16px 20px">
                <p style="color:var(--text-secondary)">La tabla seleccionada no existe en el filtro actual.</p>
            </div>
            <?php else: ?>
            <div class="tb-card" style="padding:24px 20px">
                <p style="color:var(--text-secondary)">Selecciona una tabla del panel izquierdo para ver sus datos.</p>
            </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php include '../includes/footer.php'; ?>