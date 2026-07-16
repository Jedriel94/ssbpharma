<?php
require_once '../includes/auth_admin.php';
require_once '../models/Configuracion.php';
require_once '../models/SolicitudConsignacion.php';

$solicitudModel = new SolicitudConsignacion();
$mensaje = null;
$tipoMensaje = 'success';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function estadoLabel($estado) {
    $labels = [
        'solicitada' => 'Solicitada',
        'aprobada' => 'Aprobada',
        'preparando' => 'Preparando',
        'en_transito' => 'En transito',
        'entregada' => 'Entregada (7d)',
        'rechazada' => 'Rechazada (7d)',
        'cancelada' => 'Cancelada (7d)',
    ];
    return $labels[$estado] ?? ucfirst((string)$estado);
}

function estadoPill($estado) {
    $map = [
        'solicitada' => 'border-blue-200 bg-blue-50 text-blue-700',
        'aprobada' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'preparando' => 'border-amber-200 bg-amber-50 text-amber-700',
        'en_transito' => 'border-indigo-200 bg-indigo-50 text-indigo-700',
        'entregada' => 'border-slate-200 bg-slate-100 text-slate-700',
        'rechazada' => 'border-red-200 bg-red-50 text-red-700',
        'cancelada' => 'border-slate-200 bg-slate-50 text-slate-500',
    ];
    return $map[$estado] ?? $map['solicitada'];
}

function guardarArchivoGuia($solicitudId) {
    if (empty($_FILES['guia_archivo']) || $_FILES['guia_archivo']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES['guia_archivo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se pudo subir el archivo de guia');
    }

    $maxSize = 8 * 1024 * 1024;
    if ((int)$file['size'] > $maxSize) {
        throw new Exception('El archivo de guia no debe exceder 8 MB');
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $permitidas, true)) {
        throw new Exception('La guia debe ser PDF o imagen JPG, PNG o WEBP');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $mimesPermitidos = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $mimesPermitidos, true)) {
        throw new Exception('El tipo de archivo de guia no es valido');
    }

    $uploadDir = uploads_dir('guias_consignacion') . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'guia_consignacion_' . (int)$solicitudId . '_' . time() . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        throw new Exception('No se pudo guardar el archivo de guia');
    }

    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $solicitudId = (int)($_POST['solicitud_id'] ?? 0);
    $notas = trim($_POST['notas_admin'] ?? '');
    $notas = $notas === '' ? null : $notas;

    switch ($accion) {
        case 'aprobar':
            $resultado = $solicitudModel->aprobar($solicitudId, $_POST['cantidades'] ?? [], $_SESSION['admin_id'], $notas);
            break;
        case 'rechazar':
            $resultado = $solicitudModel->rechazar($solicitudId, $_SESSION['admin_id'], $notas);
            break;
        case 'preparar':
            $resultado = $solicitudModel->marcarPreparando($solicitudId, $_SESSION['admin_id'], $notas);
            break;
        case 'enviar':
            try {
                $guiaArchivo = guardarArchivoGuia($solicitudId);
            } catch (Exception $e) {
                $resultado = ['success' => false, 'mensaje' => $e->getMessage()];
                break;
            }
            $resultado = $solicitudModel->enviar($solicitudId, $_SESSION['admin_id'], [
                'paqueteria' => $_POST['paqueteria'] ?? '',
                'numero_guia' => $_POST['numero_guia'] ?? '',
                'url_rastreo' => $_POST['url_rastreo'] ?? '',
                'guia_archivo' => $guiaArchivo ?? '',
            ], $notas);
            break;
        case 'update_guia':
            try {
                $guiaArchivo = guardarArchivoGuia($solicitudId);
            } catch (Exception $e) {
                $resultado = ['success' => false, 'mensaje' => $e->getMessage()];
                break;
            }
            $resultado = $solicitudModel->actualizarGuia($solicitudId, $_SESSION['admin_id'], [
                'paqueteria'  => $_POST['paqueteria']  ?? '',
                'numero_guia' => $_POST['numero_guia'] ?? '',
                'url_rastreo' => $_POST['url_rastreo'] ?? '',
                'guia_archivo' => $guiaArchivo ?? '',
            ]);
            break;
        case 'entregar':
            $resultado = $solicitudModel->entregar($solicitudId, $_SESSION['admin_id'], $_POST['cantidades'] ?? null, $notas);
            break;
        case 'cancelar':
            $resultado = $solicitudModel->cancelar($solicitudId, $_SESSION['admin_id'], $notas);
            break;
        default:
            $resultado = ['success' => false, 'mensaje' => 'Accion no reconocida'];
            break;
    }

    $mensaje = $resultado['mensaje'] ?? 'Solicitud actualizada';
    $tipoMensaje = !empty($resultado['success']) ? 'success' : 'error';
}

$vista = in_array($_GET['vista'] ?? '', ['tablero', 'lista']) ? $_GET['vista'] : 'tablero';

// Tablero: limitar estados finales a 7 días. Lista: sin restricción
$filtrosTablero = [
    'limit'                => 500,
    'fecha_limite_finales' => date('Y-m-d', strtotime('-7 days')),
];
$solicitudes = $solicitudModel->getAll($vista === 'tablero' ? $filtrosTablero : ['limit' => 500]);

$detalles = [];
$columnas = [
    'solicitada' => [],
    'aprobada' => [],
    'preparando' => [],
    'en_transito' => [],
    'entregada' => [],
    'rechazada' => [],
    'cancelada' => [],
];

foreach ($solicitudes as $solicitud) {
    $estado = $solicitud['estado'];
    if (!isset($columnas[$estado])) {
        $columnas[$estado] = [];
    }
    $columnas[$estado][] = $solicitud;
    $detalles[(int)$solicitud['id']] = $solicitudModel->getDetalle($solicitud['id']);
}

// Representantes únicos para el filtro de la vista lista
$repsUnicos = [];
foreach ($solicitudes as $s) {
    $rid = $s['representante_admin_id'] ?? '';
    if ($rid && !isset($repsUnicos[$rid])) {
        $repsUnicos[$rid] = $s['representante_nombre'] ?? $rid;
    }
}
asort($repsUnicos);

$pageTitle = ($vista === 'lista' ? 'Lista de Consignaciones' : 'Tablero de Consignaciones') . ' - ' . Configuracion::get('nombre_tienda', 'Solumedic');
?>

<?php include '../includes/header.php'; ?>

<style>
    .consignacion-shell {
        width: 100%;
    }

    .consignacion-board {
        display: grid;
        grid-template-columns: repeat(7, minmax(290px, 1fr));
        gap: 14px;
        overflow-x: auto;
        padding-bottom: 12px;
    }

    .consignacion-column {
        --lane-accent: var(--accent);
        min-height: 68vh;
        background:
            linear-gradient(
                180deg,
                color-mix(in srgb, var(--bg-secondary) 72%, var(--text-primary) 28%) 0%,
                color-mix(in srgb, var(--bg-secondary) 80%, var(--text-primary) 20%) 100%
            );
        border: 1px solid color-mix(in srgb, var(--border-color) 72%, var(--text-primary) 28%);
        border-top: 3px solid var(--lane-accent);
        border-radius: 12px;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.18),
            inset 0 12px 24px rgba(15, 23, 42, 0.04),
            var(--shadow-sm, 0 1px 3px rgba(15, 23, 42, 0.08));
    }

    .consignacion-column[data-estado="solicitada"] { --lane-accent: #2563eb; }
    .consignacion-column[data-estado="aprobada"] { --lane-accent: #059669; }
    .consignacion-column[data-estado="preparando"] { --lane-accent: #d97706; }
    .consignacion-column[data-estado="en_transito"] { --lane-accent: #7c3aed; }
    .consignacion-column[data-estado="entregada"] { --lane-accent: #475569; }
    .consignacion-column[data-estado="rechazada"] { --lane-accent: #dc2626; }
    .consignacion-column[data-estado="cancelada"] { --lane-accent: #64748b; }

    .consignacion-status-dot {
        width: 0.55rem;
        height: 0.55rem;
        border-radius: 999px;
        background: var(--lane-accent);
        flex: 0 0 auto;
    }

    .consignacion-count {
        background: var(--bg-secondary);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
    }

    .consignacion-column-header {
        background: color-mix(in srgb, var(--bg-card) 92%, transparent);
        border-bottom: 1px solid var(--border-color);
        backdrop-filter: blur(10px);
    }

    .consignacion-stat {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(15, 23, 42, 0.08));
    }

    .consignacion-card {
        background: var(--bg-card);
        border: 1px solid color-mix(in srgb, var(--border-color) 78%, var(--text-muted));
        border-left: 3px solid color-mix(in srgb, var(--accent) 58%, var(--border-color));
        border-radius: 12px;
        box-shadow: 0 3px 10px rgba(15, 23, 42, 0.10);
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }
    .consignacion-card:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--accent) 42%, var(--border-color));
        border-left-color: var(--accent);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
    }
    .consignacion-input {
        width: 100%;
        min-height: 38px;
        border: 1px solid var(--border-color);
        background: var(--bg-card);
        color: var(--text-primary);
        border-radius: 10px;
        padding: 8px 10px;
        font-size: 13px;
    }
    .consignacion-empty {
        border: 1px dashed var(--border-color);
        background: var(--bg-secondary);
        color: var(--text-muted);
    }
    .consignacion-panel {
        background: color-mix(in srgb, var(--bg-secondary) 78%, var(--bg-card));
        border: 1px solid var(--border-color);
    }
    .consignacion-board::-webkit-scrollbar {
        height: 8px;
    }
    .consignacion-board::-webkit-scrollbar-track {
        background: var(--bg-secondary);
        border-radius: 10px;
    }
    .consignacion-board::-webkit-scrollbar-thumb {
        background: color-mix(in srgb, var(--accent) 45%, var(--border-color));
        border-radius: 10px;
    }
    .consignacion-board::-webkit-scrollbar-thumb:hover {
        background: var(--accent);
    }
    @media (max-width: 900px) {
        .consignacion-board { grid-template-columns: repeat(7, 86vw); }
    }

    /* ── Vista Lista ─────────────────────────────────────────────── */
    .lista-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .lista-table thead tr th {
        background: linear-gradient(to right, var(--tw-neu-800, #1e293b), var(--tw-neu-900, #0f172a));
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 10px 14px;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 2;
    }
    body.theme-dark .lista-table thead tr th {
        background: linear-gradient(to right, var(--tw-neu-100, #21262D), var(--tw-neu-50, #1C2330));
        color: var(--text-primary);
    }
    .lista-table thead tr th:first-child { border-radius: 10px 0 0 0; }
    .lista-table thead tr th:last-child  { border-radius: 0 10px 0 0; }
    .lista-table tbody tr {
        border-bottom: 1px solid var(--border-card, #e2e8f0);
        transition: background .12s;
    }
    .lista-table tbody tr:hover { background: color-mix(in srgb, var(--accent, #126c6a) 5%, transparent); }
    .lista-table tbody td {
        padding: 10px 14px;
        font-size: 13px;
        color: var(--text-primary, #0f172a);
        vertical-align: middle;
    }
    .lista-table tbody tr:last-child td:first-child { border-radius: 0 0 0 10px; }
    .lista-table tbody tr:last-child td:last-child  { border-radius: 0 0 10px 0; }
    .lista-filter-bar {
        background: var(--bg-card, #fff);
        border: 1px solid var(--border-card, #e2e8f0);
        border-radius: 14px;
        padding: 14px 18px;
        margin-bottom: 16px;
    }
    .lista-filter-input {
        height: 38px;
        border: 1px solid var(--border-input, #cbd5e1);
        background: var(--bg-input, #f8fafc);
        color: var(--text-primary, #0f172a);
        border-radius: 9px;
        padding: 0 10px;
        font-size: 13px;
        min-width: 0;
    }
    .lista-filter-input:focus { outline: 2px solid var(--accent, #126c6a); outline-offset: 1px; }
    .lista-wrap {
        background: var(--bg-card, #fff);
        border: 1px solid var(--border-card, #e2e8f0);
        border-radius: 14px;
        overflow: hidden;
    }
    .lista-wrap .lista-scroll { overflow-x: auto; }
    .lista-detail-panel {
        background: var(--bg-input, #f8fafc);
        border: 1px solid var(--border-card, #e2e8f0);
        border-radius: 10px;
        padding: 12px 14px;
        margin-top: 4px;
    }
    .vista-tab {
        display: inline-block;
        padding: 7px 16px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 700;
        border: 1px solid var(--border-card, #e2e8f0);
        cursor: pointer;
        text-decoration: none;
        transition: background .15s, color .15s;
    }
    .vista-tab.active {
        background: linear-gradient(to right, var(--tw-neu-800, #1e293b), var(--tw-neu-900, #0f172a));
        color: #fff;
        border-color: transparent;
    }
    body.theme-dark .vista-tab.active {
        background: linear-gradient(to right, var(--tw-neu-100, #21262D), var(--tw-neu-50, #1C2330));
        color: var(--text-primary);
    }
    .vista-tab:not(.active) {
        background: var(--bg-card, #fff);
        color: var(--text-primary, #0f172a);
    }
</style>

<main class="consignacion-shell px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4 mb-6" style="padding-right:3.5rem">
        <div class="flex-1 min-w-0">
            <h1 class="text-3xl font-bold" style="color:var(--text-primary)">Consignaciones</h1>
            <p class="text-sm mt-1" style="color:var(--text-muted)"><?= $vista === 'lista' ? 'Historial completo · sin límite de fechas' : 'Vista Kanban · estados finales últimos 7 días' ?></p>
        </div>
        <!-- Toggle vistas -->
        <div class="flex gap-1 p-1 rounded-xl shrink-0" style="background:var(--bg-input);border:1px solid var(--border-card)">
            <a href="?vista=tablero" class="vista-tab <?= $vista === 'tablero' ? 'active' : '' ?>">⊞ Tablero</a>
            <a href="?vista=lista"   class="vista-tab <?= $vista === 'lista'   ? 'active' : '' ?>">Lista</a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm font-semibold <?= $tipoMensaje === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700' ?>">
            <?= h($mensaje) ?>
        </div>
    <?php endif; ?>

    <section class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">
        <?php foreach ($columnas as $estado => $items): ?>
            <div class="consignacion-stat rounded-xl p-3">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-600"><?= h(estadoLabel($estado)) ?></div>
                <div class="text-2xl font-bold mt-1 text-slate-800"><?= count($items) ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($vista === 'tablero'): ?>
    <section class="consignacion-board">
        <?php foreach ($columnas as $estadoColumna => $items): ?>
            <div class="consignacion-column" data-estado="<?= h($estadoColumna) ?>">
                <div class="consignacion-column-header sticky top-0 z-10 rounded-t-xl px-4 py-3">
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-slate-900 flex items-center gap-2">
                            <span class="consignacion-status-dot"></span>
                            <?= h(estadoLabel($estadoColumna)) ?>
                        </h2>
                        <span class="consignacion-count rounded-full px-2 py-0.5 text-xs font-semibold"><?= count($items) ?></span>
                    </div>
                </div>
                <div class="p-3 space-y-3">
                    <?php if (empty($items)): ?>
                        <div class="consignacion-empty rounded-xl p-4 text-sm">Sin solicitudes.</div>
                    <?php endif; ?>

                    <?php foreach ($items as $solicitud): ?>
                        <?php
                        $id = (int)$solicitud['id'];
                        $detalle = $detalles[$id] ?? [];
                        ?>
                        <article class="consignacion-card p-4" data-sol-id="<?= $id ?>" data-sol-estado="<?= h($solicitud['estado']) ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase text-slate-500">Solicitud</div>
                                    <h3 class="text-lg font-bold text-slate-900">#<?= str_pad((string)$id, 4, '0', STR_PAD_LEFT) ?></h3>
                                </div>
                                <span class="rounded-full border px-2 py-1 text-[11px] font-semibold <?= estadoPill($solicitud['estado']) ?>"><?= h(estadoLabel($solicitud['estado'])) ?></span>
                            </div>

                            <div class="mt-3">
                                <div class="font-bold text-slate-900"><?= h($solicitud['representante_nombre'] ?? 'Representante') ?></div>
                                <div class="text-xs text-slate-500"><?= h($solicitud['representante_codigo'] ?? 'SIN-CODIGO') ?> · <?= h(date('d/m/Y H:i', strtotime($solicitud['fecha_solicitud']))) ?></div>
                                <?php
                                $dirParts = array_filter([
                                    trim($solicitud['rep_dir_calle'] ?? '') . ' ' . trim($solicitud['rep_dir_numero'] ?? ''),
                                    $solicitud['rep_dir_colonia'] ?? '',
                                    $solicitud['rep_dir_ciudad']  ?? '',
                                    $solicitud['rep_dir_estado']  ?? '',
                                    $solicitud['rep_dir_cp']      ?? '',
                                    $solicitud['rep_dir_referencias']  ?? '',
                                    !empty($solicitud['rep_dir_quien_recibe']) ? 'Recibe: ' . $solicitud['rep_dir_quien_recibe'] : '',
                                ], fn($v) => trim($v) !== '');
                                ?>
                                <?php if ($dirParts): ?>
                                <div class="consignacion-panel mt-1 rounded-lg px-3 py-2">
                                    <div class="text-[10px] font-bold uppercase text-slate-700 mb-0.5">Dirección de envío</div>
                                    <div class="text-xs text-slate-700 leading-relaxed"><?= h(implode(', ', $dirParts)) ?></div>
                                </div>
                                <?php else: ?>
                                <div class="mt-1 text-xs text-slate-400 italic">Sin dirección de envío registrada</div>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-3 gap-2 my-4 text-center">
                                <div class="consignacion-panel rounded-lg p-2"><b><?= (int)$solicitud['total_solicitado'] ?></b><div class="text-[10px] font-bold uppercase text-slate-500">Solic.</div></div>
                                <div class="consignacion-panel rounded-lg p-2"><b><?= (int)$solicitud['total_aprobado'] ?></b><div class="text-[10px] font-bold uppercase text-slate-500">Aprob.</div></div>
                                <div class="consignacion-panel rounded-lg p-2"><b><?= (int)$solicitud['total_entregado'] ?></b><div class="text-[10px] font-bold uppercase text-slate-500">Entreg.</div></div>
                            </div>

                            <?php if (!empty($solicitud['notas_representante']) || !empty($solicitud['notas_admin'])): ?>
                            <div class="grid gap-2 mb-4">
                                <?php if (!empty($solicitud['notas_representante'])): ?>
                                <div class="consignacion-panel rounded-lg px-3 py-2">
                                    <div class="text-[10px] font-bold uppercase text-slate-500 mb-0.5">Notas del representante</div>
                                    <p class="text-xs text-slate-700 whitespace-pre-line"><?= h($solicitud['notas_representante']) ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($solicitud['notas_admin'])): ?>
                                <div class="rounded-lg bg-blue-50 border border-blue-200 px-3 py-2">
                                    <div class="text-[10px] font-bold uppercase text-blue-400 mb-0.5">Notas del admin</div>
                                    <p class="text-xs text-blue-900 whitespace-pre-line"><?= h($solicitud['notas_admin']) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($solicitud['numero_guia'])): ?>
                                <div class="consignacion-panel mb-4 rounded-xl p-3 text-sm">
                                    <div class="text-[11px] font-bold uppercase text-slate-700">Guia</div>
                                    <div class="font-bold text-slate-900"><?= h($solicitud['paqueteria']) ?> · <?= h($solicitud['numero_guia']) ?></div>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        <?php if (!empty($solicitud['url_rastreo'])): ?>
                                            <a href="<?= h($solicitud['url_rastreo']) ?>" target="_blank" class="text-xs font-bold text-slate-700 underline">Abrir rastreo</a>
                                        <?php endif; ?>
                                        <?php if (!empty($solicitud['guia_archivo'])): ?>
                                            <a href="<?= uploads_url('guias_consignacion') ?>/<?= h($solicitud['guia_archivo']) ?>" target="_blank" class="text-xs font-bold text-slate-700 underline">Ver archivo</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <details class="rounded-xl border border-slate-200">
                                <summary class="cursor-pointer list-none px-3 py-2 text-sm font-bold text-slate-700">Detalle y acciones</summary>
                                <div class="border-t border-slate-200 p-3">
                                    <div class="space-y-2 mb-4">
                                        <?php foreach ($detalle as $item): ?>
                                            <div class="grid grid-cols-[1fr_auto] gap-3 text-sm">
                                                <div class="min-w-0">
                                                    <div class="truncate font-semibold text-slate-800"><?= h($item['producto']) ?></div>
                                                    <div class="text-[11px] text-slate-500">Existencia general: <?= (int)$item['existencia_general'] ?></div>
                                                </div>
                                                <div class="text-right text-xs text-slate-600">
                                                    <?= (int)$item['cantidad_solicitada'] ?> / <?= (int)$item['cantidad_aprobada'] ?> / <?= (int)$item['cantidad_entregada'] ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($solicitud['estado'] === 'solicitada'): ?>
                                        <form method="POST" class="space-y-2">
                                            <input type="hidden" name="accion" value="aprobar">
                                            <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                            <?php foreach ($detalle as $item): ?>
                                                <label class="grid grid-cols-[1fr_76px] gap-2 items-center text-xs">
                                                    <span class="truncate"><?= h($item['producto']) ?></span>
                                                    <input class="consignacion-input text-right" type="number" min="0" max="<?= (int)$item['cantidad_solicitada'] ?>" name="cantidades[<?= (int)$item['producto_id'] ?>]" value="<?= (int)$item['cantidad_solicitada'] ?>">
                                                </label>
                                            <?php endforeach; ?>
                                            <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de aprobacion"></textarea>
                                            <button class="btn-primary w-full min-h-10 rounded-xl text-sm font-bold">Aprobar</button>
                                        </form>
                                        <form method="POST" class="mt-2" onsubmit="return confirm('¿Rechazar esta solicitud?')">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                            <button class="w-full min-h-10 rounded-xl border border-red-200 bg-red-50 text-sm font-bold text-red-700">Rechazar</button>
                                        </form>
                                    <?php elseif ($solicitud['estado'] === 'aprobada'): ?>
                                        <form method="POST" class="space-y-2">
                                            <input type="hidden" name="accion" value="preparar">
                                            <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                            <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de preparacion"></textarea>
                                            <button class="w-full min-h-10 rounded-xl border border-amber-200 bg-amber-50 text-sm font-bold text-amber-700">Marcar preparando</button>
                                        </form>
                                    <?php elseif ($solicitud['estado'] === 'preparando'): ?>
                                        <form method="POST" enctype="multipart/form-data" class="space-y-2">
                                            <input type="hidden" name="accion" value="enviar">
                                            <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                            <input class="consignacion-input" name="paqueteria" required placeholder="Paqueteria">
                                            <input class="consignacion-input" name="numero_guia" required placeholder="Numero de guia">
                                            <input class="consignacion-input" name="url_rastreo" placeholder="URL de rastreo">
                                            <label class="consignacion-panel block rounded-xl border-dashed p-3 text-xs font-bold text-slate-600">
                                                Archivo de guia PDF/imagen
                                                <input type="file" name="guia_archivo" accept="application/pdf,image/jpeg,image/png,image/webp" class="mt-2 block w-full text-xs">
                                            </label>
                                            <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de envio"></textarea>
                                            <button class="btn-primary w-full min-h-10 rounded-xl text-sm font-bold">Enviar con guia</button>
                                        </form>
                                    <?php elseif ($solicitud['estado'] === 'en_transito'): ?>
                                        <form method="POST" class="space-y-2">
                                            <input type="hidden" name="accion" value="entregar">
                                            <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                            <?php foreach ($detalle as $item): ?>
                                                <label class="grid grid-cols-[1fr_76px] gap-2 items-center text-xs">
                                                    <span class="truncate"><?= h($item['producto']) ?></span>
                                                    <input class="consignacion-input text-right" type="number" min="0" max="<?= (int)$item['cantidad_aprobada'] ?>" name="cantidades[<?= (int)$item['producto_id'] ?>]" value="<?= (int)$item['cantidad_aprobada'] ?>">
                                                </label>
                                            <?php endforeach; ?>
                                            <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de recepcion"></textarea>
                                            <button class="btn-primary w-full min-h-10 rounded-xl text-sm font-bold">Confirmar entrega</button>
                                        </form>
                                        <details class="mt-3 rounded-xl border border-indigo-200">
                                            <summary class="cursor-pointer list-none px-3 py-2 text-xs font-bold text-indigo-700">Corregir guía</summary>
                                            <form method="POST" enctype="multipart/form-data" class="space-y-2 border-t border-indigo-100 p-3">
                                                <input type="hidden" name="accion" value="update_guia">
                                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                                <input class="consignacion-input" name="paqueteria" required placeholder="Paquetería" value="<?= h($solicitud['paqueteria'] ?? '') ?>">
                                                <input class="consignacion-input" name="numero_guia" required placeholder="Número de guía" value="<?= h($solicitud['numero_guia'] ?? '') ?>">
                                                <input class="consignacion-input" name="url_rastreo" placeholder="URL de rastreo" value="<?= h($solicitud['url_rastreo'] ?? '') ?>">
                                                <label class="consignacion-panel block rounded-xl border-dashed p-3 text-xs font-bold text-slate-600">
                                                    Reemplazar archivo de guía (opcional)
                                                    <input type="file" name="guia_archivo" accept="application/pdf,image/jpeg,image/png,image/webp" class="mt-2 block w-full text-xs">
                                                </label>
                                                <button class="w-full min-h-9 rounded-xl border border-indigo-300 bg-indigo-50 text-xs font-bold text-indigo-700">Guardar guía corregida</button>
                                            </form>
                                        </details>
                                    <?php else: ?>
                                        <div class="text-sm text-slate-500">Sin acciones pendientes.</div>
                                    <?php endif; ?>

                                    <?php if (in_array($solicitud['estado'], ['solicitada', 'aprobada', 'preparando', 'en_transito'], true)): ?>
                                        <form method="POST" class="mt-2" onsubmit="return confirm('¿Cancelar esta solicitud?')">
                                            <input type="hidden" name="accion" value="cancelar">
                                            <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                            <button class="w-full min-h-9 rounded-xl border border-slate-300 text-xs font-bold text-slate-600">Cancelar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; /* tablero */ ?>

    <?php if ($vista === 'lista'): ?>
    <!-- ══ VISTA LISTA ══ -->
    <div class="lista-filter-bar">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex flex-col gap-1">
                <label class="text-[11px] font-bold uppercase" style="color:var(--text-muted)">Estado</label>
                <select id="fil-estado" class="lista-filter-input" style="width:160px">
                    <option value="">Todos</option>
                    <?php foreach (array_keys($columnas) as $e): ?>
                        <option value="<?= h($e) ?>"><?= h(estadoLabel($e)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[11px] font-bold uppercase" style="color:var(--text-muted)">Representante</label>
                <select id="fil-rep" class="lista-filter-input" style="width:190px">
                    <option value="">Todos</option>
                    <?php foreach ($repsUnicos as $rid => $rnombre): ?>
                        <option value="<?= h($rid) ?>"><?= h($rnombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[11px] font-bold uppercase" style="color:var(--text-muted)">Desde</label>
                <input type="date" id="fil-desde" class="lista-filter-input" style="width:150px">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[11px] font-bold uppercase" style="color:var(--text-muted)">Hasta</label>
                <input type="date" id="fil-hasta" class="lista-filter-input" style="width:150px">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[11px] font-bold uppercase" style="color:var(--text-muted)">Buscar</label>
                <input type="search" id="fil-texto" class="lista-filter-input" placeholder="#ID, nombre…" style="width:170px">
            </div>
            <button onclick="limpiarFiltros()" class="lista-filter-input px-4 font-bold" style="color:var(--text-secondary);cursor:pointer">Limpiar</button>
            <span id="fil-count" class="text-xs font-bold ml-auto" style="color:var(--text-muted)"></span>
        </div>
    </div>

    <div class="lista-wrap">
        <div class="lista-scroll">
        <table class="lista-table" id="lista-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Representante</th>
                    <th>Fecha solicitud</th>
                    <th>Estado</th>
                    <th style="text-align:center">Solic.</th>
                    <th style="text-align:center">Aprob.</th>
                    <th style="text-align:center">Entreg.</th>
                    <th>Guía</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="lista-tbody">
            <?php foreach ($solicitudes as $solicitud):
                $id = (int)$solicitud['id'];
                $detalle = $detalles[$id] ?? [];
                $fechaIso = substr($solicitud['fecha_solicitud'], 0, 10);
            ?>
            <tr class="lista-row"
                data-estado="<?= h($solicitud['estado']) ?>"
                data-rep="<?= h($solicitud['representante_admin_id'] ?? '') ?>"
                data-fecha="<?= h($fechaIso) ?>"
                data-texto="<?= h(strtolower('#' . str_pad($id, 4, '0', STR_PAD_LEFT) . ' ' . ($solicitud['representante_nombre'] ?? '') . ' ' . ($solicitud['representante_codigo'] ?? ''))) ?>">
                <td class="font-bold" style="color:var(--text-secondary)">#<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></td>
                <td>
                    <div class="font-bold" style="color:var(--text-primary)"><?= h($solicitud['representante_nombre'] ?? '—') ?></div>
                    <div class="text-[11px]" style="color:var(--text-muted)"><?= h($solicitud['representante_codigo'] ?? '') ?></div>
                </td>
                <td style="white-space:nowrap;color:var(--text-secondary)"><?= h(date('d/m/Y H:i', strtotime($solicitud['fecha_solicitud']))) ?></td>
                <td>
                    <span class="rounded-full border px-2 py-1 text-[11px] font-semibold <?= estadoPill($solicitud['estado']) ?>">
                        <?= h(estadoLabel($solicitud['estado'])) ?>
                    </span>
                </td>
                <td class="text-center font-bold"><?= (int)$solicitud['total_solicitado'] ?></td>
                <td class="text-center font-bold"><?= (int)$solicitud['total_aprobado'] ?></td>
                <td class="text-center font-bold"><?= (int)$solicitud['total_entregado'] ?></td>
                <td style="white-space:nowrap;font-size:12px;color:var(--text-secondary)">
                    <?php if (!empty($solicitud['numero_guia'])): ?>
                        <div class="font-bold"><?= h($solicitud['paqueteria']) ?></div>
                        <div><?= h($solicitud['numero_guia']) ?></div>
                        <?php if (!empty($solicitud['url_rastreo'])): ?>
                            <a href="<?= h($solicitud['url_rastreo']) ?>" target="_blank" class="underline" style="color:var(--accent)">Rastrear</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">Sin guía</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button onclick="toggleDetalleLista(this)" class="lista-filter-input px-3 font-bold text-xs" style="cursor:pointer;height:32px">
                        Ver detalle
                    </button>
                    <div class="lista-row-detalle" style="display:none">
                        <div class="lista-detail-panel">
                            <!-- Productos -->
                            <?php if (!empty($detalle)): ?>
                            <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:10px">
                                <thead><tr style="color:var(--text-muted);font-weight:700;font-size:10px;text-transform:uppercase">
                                    <th style="text-align:left;padding:3px 6px">Producto</th>
                                    <th style="text-align:center;padding:3px 6px">Solic.</th>
                                    <th style="text-align:center;padding:3px 6px">Aprob.</th>
                                    <th style="text-align:center;padding:3px 6px">Entreg.</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($detalle as $item): ?>
                                <tr style="border-top:1px solid var(--border-card)">
                                    <td style="padding:4px 6px"><?= h($item['producto']) ?></td>
                                    <td style="text-align:center;padding:4px 6px"><?= (int)$item['cantidad_solicitada'] ?></td>
                                    <td style="text-align:center;padding:4px 6px"><?= (int)$item['cantidad_aprobada'] ?></td>
                                    <td style="text-align:center;padding:4px 6px"><?= (int)$item['cantidad_entregada'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>

                            <!-- Notas -->
                            <?php if (!empty($solicitud['notas_representante']) || !empty($solicitud['notas_admin'])): ?>
                            <div style="display:grid;gap:8px;margin-bottom:10px">
                                <?php if (!empty($solicitud['notas_representante'])): ?>
                                <div style="background:var(--bg-secondary);border:1px solid var(--border-card);border-radius:8px;padding:8px 10px;font-size:12px">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px">Rep.</div>
                                    <?= h($solicitud['notas_representante']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($solicitud['notas_admin'])): ?>
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 10px;font-size:12px;color:#1e3a8a">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#93c5fd;margin-bottom:3px">Admin</div>
                                    <?= h($solicitud['notas_admin']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Acciones -->
                            <?php if ($solicitud['estado'] === 'solicitada'): ?>
                            <form method="POST" style="display:grid;gap:6px">
                                <input type="hidden" name="accion" value="aprobar">
                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                <?php foreach ($detalle as $item): ?>
                                    <label style="display:grid;grid-template-columns:1fr 76px;gap:8px;align-items:center;font-size:12px">
                                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($item['producto']) ?></span>
                                        <input class="consignacion-input text-right" type="number" min="0" max="<?= (int)$item['cantidad_solicitada'] ?>" name="cantidades[<?= (int)$item['producto_id'] ?>]" value="<?= (int)$item['cantidad_solicitada'] ?>">
                                    </label>
                                <?php endforeach; ?>
                                <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de aprobación"></textarea>
                                <button class="btn-primary w-full min-h-9 rounded-xl text-sm font-bold">Aprobar</button>
                            </form>
                            <form method="POST" style="margin-top:6px" onsubmit="return confirm('\u00bfRechazar esta solicitud?')">
                                <input type="hidden" name="accion" value="rechazar">
                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                <button class="w-full min-h-9 rounded-xl border border-red-200 bg-red-50 text-sm font-bold text-red-700">Rechazar</button>
                            </form>
                            <?php elseif ($solicitud['estado'] === 'aprobada'): ?>
                            <form method="POST" style="display:grid;gap:6px">
                                <input type="hidden" name="accion" value="preparar">
                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de preparación"></textarea>
                                <button class="w-full min-h-9 rounded-xl border border-amber-200 bg-amber-50 text-sm font-bold text-amber-700">Marcar preparando</button>
                            </form>
                            <?php elseif ($solicitud['estado'] === 'preparando'): ?>
                            <form method="POST" enctype="multipart/form-data" style="display:grid;gap:6px">
                                <input type="hidden" name="accion" value="enviar">
                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                <input class="consignacion-input" name="paqueteria" required placeholder="Paquetería">
                                <input class="consignacion-input" name="numero_guia" required placeholder="Número de guía">
                                <input class="consignacion-input" name="url_rastreo" placeholder="URL de rastreo">
                                <label class="consignacion-panel block rounded-xl border-dashed p-3 text-xs font-bold" style="color:var(--text-secondary)">
                                    Archivo de guía PDF/imagen
                                    <input type="file" name="guia_archivo" accept="application/pdf,image/jpeg,image/png,image/webp" class="mt-2 block w-full text-xs">
                                </label>
                                <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de envío"></textarea>
                                <button class="btn-primary w-full min-h-9 rounded-xl text-sm font-bold">Enviar con guía</button>
                            </form>
                            <?php elseif ($solicitud['estado'] === 'en_transito'): ?>
                            <form method="POST" style="display:grid;gap:6px">
                                <input type="hidden" name="accion" value="entregar">
                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                <?php foreach ($detalle as $item): ?>
                                    <label style="display:grid;grid-template-columns:1fr 76px;gap:8px;align-items:center;font-size:12px">
                                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($item['producto']) ?></span>
                                        <input class="consignacion-input text-right" type="number" min="0" max="<?= (int)$item['cantidad_aprobada'] ?>" name="cantidades[<?= (int)$item['producto_id'] ?>]" value="<?= (int)$item['cantidad_aprobada'] ?>">
                                    </label>
                                <?php endforeach; ?>
                                <textarea class="consignacion-input" name="notas_admin" rows="2" placeholder="Notas de recepción"></textarea>
                                <button class="btn-primary w-full min-h-9 rounded-xl text-sm font-bold">Confirmar entrega</button>
                            </form>
                            <?php else: ?>
                            <div style="font-size:12px;color:var(--text-muted)">Sin acciones pendientes.</div>
                            <?php endif; ?>

                            <?php if (in_array($solicitud['estado'], ['solicitada','aprobada','preparando','en_transito'], true)): ?>
                            <form method="POST" style="margin-top:6px" onsubmit="return confirm('\u00bfCancelar esta solicitud?')">
                                <input type="hidden" name="accion" value="cancelar">
                                <input type="hidden" name="solicitud_id" value="<?= $id ?>">
                                <button class="w-full min-h-9 rounded-xl border text-xs font-bold" style="border-color:var(--border-card);color:var(--text-secondary)">Cancelar</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div id="lista-empty" style="display:none;padding:32px;text-align:center;color:var(--text-muted);font-size:14px">
            Sin solicitudes que coincidan con los filtros.
        </div>
        <div class="pag-bar" id="pag-bar-sc">
            <div class="pag-left">
                <span class="pag-info" id="pag-info-sc"></span>
                <select class="pag-size" id="pag-size-sc">
                    <option value="10" selected>10 / pág</option>
                    <option value="25">25 / pág</option>
                    <option value="50">50 / pág</option>
                    <option value="100">100 / pág</option>
                </select>
            </div>
            <div class="pag-controls" id="pag-ctrl-sc"></div>
        </div>
    </div>
    <?php endif; /* lista */ ?>
</main>

<?php include '../includes/footer.php'; ?>

<script src="<?= asset('js/paginator.js') ?>"></script>
<script>
// ── Vista Lista: toggle detalle ───────────────────────────────────────────
function toggleDetalleLista(btn) {
    const panel = btn.nextElementSibling;
    const open  = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    btn.textContent = open ? 'Ver detalle' : 'Ocultar';
}

// ── Vista Lista: filtros + paginación client-side ────────────────────────
(function () {
    const pag = new Paginator({
        rows:   () => document.querySelectorAll('#lista-tbody .lista-row'),
        bar:    '#pag-bar-sc',
        info:   '#pag-info-sc',
        ctrl:   '#pag-ctrl-sc',
        sizeEl: '#pag-size-sc',
        unit:   'solicitud', units: 'solicitudes',
    });

    function aplicar() {
        const estado = document.getElementById('fil-estado')?.value  ?? '';
        const rep    = document.getElementById('fil-rep')?.value     ?? '';
        const desde  = document.getElementById('fil-desde')?.value   ?? '';
        const hasta  = document.getElementById('fil-hasta')?.value   ?? '';
        const texto  = (document.getElementById('fil-texto')?.value  ?? '').toLowerCase();

        const empty = document.getElementById('lista-empty');
        const tbl   = document.getElementById('lista-table');

        pag.filter(tr =>
            (!estado || tr.dataset.estado === estado) &&
            (!rep    || tr.dataset.rep    === rep)    &&
            (!desde  || tr.dataset.fecha  >= desde)  &&
            (!hasta  || tr.dataset.fecha  <= hasta)  &&
            (!texto  || tr.dataset.texto.includes(texto))
        );

        const total = pag.total;
        const cnt = document.getElementById('fil-count');
        if (cnt)   cnt.textContent     = total + ' solicitud' + (total !== 1 ? 'es' : '');
        if (empty) empty.style.display = total === 0 ? 'block' : 'none';
        if (tbl)   tbl.style.display   = total === 0 ? 'none'  : '';
    }

    window.scPaginar = (page) => pag.paginate(page);

    ['fil-estado','fil-rep','fil-desde','fil-hasta','fil-texto'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', aplicar);
    });

    aplicar();
})();

function limpiarFiltros() {
    ['fil-estado','fil-rep','fil-desde','fil-hasta','fil-texto'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    document.getElementById('fil-estado')?.dispatchEvent(new Event('input'));
}

// ── Polling de estados de solicitudes (solo pestaña activa) ───────────────
(function () {
    const POLL_INTERVAL = 30_000;
    let _timer = null;

    async function _poll() {
        try {
            const res  = await fetch('<?= url('api/solicitudes-estado-admin.php') ?>');
            const data = await res.json();
            if (!data.ok) return;
            let changed = false;
            document.querySelectorAll('[data-sol-id]').forEach(el => {
                const nuevoEstado = data.estados[el.dataset.solId];
                if (nuevoEstado && el.dataset.solEstado !== nuevoEstado) changed = true;
            });
            if (changed) location.reload();
        } catch { /* silencioso */ }
    }

    async function _tick() {
        if (document.visibilityState !== 'visible') return;
        await _poll();
        if (document.visibilityState === 'visible')
            _timer = setTimeout(_tick, POLL_INTERVAL);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            _poll();
            _timer = setTimeout(_tick, POLL_INTERVAL);
        } else {
            clearTimeout(_timer);
            _timer = null;
        }
    });

    _timer = setTimeout(_tick, POLL_INTERVAL);
})();
</script>
