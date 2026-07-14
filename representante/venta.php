<?php
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/RepresentanteInventario.php';
require_once __DIR__ . '/../models/RepresentanteVenta.php';
require_once __DIR__ . '/../models/Cliente.php';

// ── AJAX: buscar cliente por teléfono ──────────────────────────────────────
if (($_GET['action'] ?? '') === 'buscar_cliente' && isset($_GET['telefono'])) {
    header('Content-Type: application/json');
    $clienteModel = new Cliente();
    $tel = preg_replace('/\D+/', '', $_GET['telefono']);
    $cliente = $tel ? $clienteModel->getByTelefono($tel) : null;
    if ($cliente) {
        echo json_encode([
            'found'          => true,
            'nombre'         => $cliente['nombre'] ?? '',
            'calle'          => $cliente['calle'] ?? '',
            'numero'         => $cliente['numero'] ?? '',
            'colonia'        => $cliente['colonia'] ?? '',
            'cp'             => $cliente['cp'] ?? '',
            'estado'         => $cliente['estado'] ?? '',
            'ciudad'         => $cliente['ciudad'] ?? '',
            'referencias'    => $cliente['referencias'] ?? '',
            'quien_recibe'   => $cliente['quien_recibe'] ?? '',
            'email_factura'  => $cliente['email_factura'] ?? '',
            'tipo_cliente'   => $cliente['tipo_cliente'] ?? 'medico',
            'especialidad'   => $cliente['especialidad'] ?? '',
            'nombre_medico'  => $cliente['nombre_medico'] ?? '',
            'telefono_medico'=> $cliente['telefono_medico'] ?? '',
            'rfc'            => $cliente['rfc'] ?? '',
            'razon_social'   => $cliente['razon_social'] ?? '',
            'codigo_postal'  => $cliente['codigo_postal'] ?? '',
            'uso_cfdi'       => $cliente['uso_cfdi'] ?? '',
            'regimen_fiscal' => $cliente['regimen_fiscal'] ?? '',
            'notif_factura'  => (int)($cliente['notif_factura'] ?? 1),
        ]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ── AJAX: buscar cliente por texto libre (nombre / teléfono / ciudad) ─────
if (($_GET['action'] ?? '') === 'buscar_clientes_texto') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }
    $pdo  = Database::getInstance()->getConnection();
    $like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
    $stmt = $pdo->prepare(
        "SELECT telefono, nombre, ciudad, estado
         FROM clientes
         WHERE nombre LIKE ? OR telefono LIKE ? OR ciudad LIKE ?
         ORDER BY nombre
         LIMIT 25"
    );
    $stmt->execute([$like, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: precio por cantidad (rangos de precio) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_precio') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../models/Producto.php';
    $pModel = new Producto();
    $precio = $pModel->getPrecioByQuantity(
        (int)($_POST['producto_id'] ?? 0),
        max(1, (int)($_POST['cantidad'] ?? 1))
    );
    echo json_encode(['success' => true, 'precio' => $precio ? (float)$precio : 0]);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Configuracion.php';
$inventarioModel = new RepresentanteInventario();
$ventaModel = new RepresentanteVenta();
$inventario = $inventarioModel->getInventarioPorAdmin($representanteAdminId, true);
$MONTO_MINIMO_ENVIO_GRATIS = Configuracion::get('monto_minimo_envio_gratis', 1900.00);
$COSTO_ENVIO = Configuracion::get('costo_envio', 160.00);

// Cargar especialidades activas
$_db_esp = Database::getInstance()->getConnection();
$especialidades_lista = $_db_esp->query("SELECT nombre FROM especialidades WHERE activo=1 ORDER BY orden,nombre")->fetchAll(PDO::FETCH_COLUMN);

$mensaje = null;
$error = null;
$pedidoCreado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $telefono_post = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
    $_POST['telefono'] = $telefono_post;
    $tipo_cliente_post = trim($_POST['tipo_cliente'] ?? 'medico');
    $especialidad_post = trim($_POST['especialidad'] ?? '');

    if (strlen($telefono_post) !== 10) {
        $error = 'El telefono debe tener exactamente 10 digitos antes de Registrar -> Cobrar.';
    }

    if (!$error && $tipo_cliente_post === 'medico' && $especialidad_post === '') {
        $error = 'La especialidad es obligatoria para cliente médico antes de Registrar -> Cobrar.';
    }

    $items = [];
    foreach ($_POST['productos'] ?? [] as $producto_id => $cantidad) {
        $cantidad = (int)$cantidad;
        if ($cantidad > 0) {
            $items[] = [
                'producto_id' => (int)$producto_id,
                'cantidad' => $cantidad
            ];
        }
    }

    // Leave metodo_pago empty; procesar-pago.php (modo=rep) handles payment collection
    $_POST['metodo_pago'] = '';

    if (!$error) {
        $resultado = $ventaModel->crearPorAdmin(
            $representanteAdminId,
            $_POST,
            $items,
            null
        );

        if ($resultado['success']) {
            $telefono_cliente = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
            $redirect_url = url('procesar-pago.php')
                . '?pedido_id=' . (int)$resultado['pedido_id']
                . '&telefono=' . urlencode($telefono_cliente)
                . '&modo=rep';
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $error = $resultado['mensaje'];
        }
    }
}

function money_rep_venta($value) {
    return '$' . number_format((float)$value, 2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Nueva venta | Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
    <style>
        :root {
            --ink:#102040;
            --muted:#6a90b8;
            --paper:#f0f5fa;
            --line:#bfcfe8;
            --field:#eef4fa;
            --brand:#4a70a9;
            --accent:#8fabd4;
        }
        body {
            margin:0;
            min-height:100vh;
            background:var(--paper);
            color:var(--ink);
        }
        .shell {
            width:min(100%,860px);
            margin:0 auto;
            padding:16px 14px 124px;
        }
        .top {
            position:sticky;
            top:0;
            z-index:20;
            margin:-16px -14px 14px;
            padding:calc(14px + env(safe-area-inset-top)) 14px 12px;
            background:rgba(251,250,247,.94);
            border-bottom:1px solid var(--line);
            backdrop-filter:blur(16px);
        }
        .toprow {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }
        h1 {
            margin:0;
            font-size:24px;
            font-weight:900;
            letter-spacing:0;
        }
        .eyebrow {
            color:var(--muted);
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
        }
        .back {
            min-height:44px;
            padding:0 14px;
            border-radius:8px;
            display:grid;
            place-items:center;
            background:var(--ink);
            color:white;
            text-decoration:none;
            font-weight:900;
        }
        .panel {
            border:1px solid var(--line);
            border-radius:8px;
            background:white;
            padding:14px;
            margin-bottom:12px;
            box-shadow:0 12px 28px rgba(16,24,32,.06);
        }
        .panel-title {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-bottom:12px;
        }
        .panel-title h2 {
            margin:0;
            font-size:16px;
            font-weight:900;
        }
        label {
            display:block;
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
            color:var(--muted);
            margin-bottom:6px;
        }
        input, select, textarea {
            width:100%;
            min-height:48px;
            border:1px solid #d8d0c3;
            border-radius:8px;
            background:#fff;
            color:var(--ink);
            padding:10px 12px;
            font-size:16px;
            outline:none;
        }
        textarea { min-height:82px; resize:vertical; }
        input:focus, select:focus, textarea:focus {
            border-color:var(--brand);
            box-shadow:0 0 0 3px rgba(18,108,106,.12);
        }
        .grid2 {
            display:grid;
            grid-template-columns:1fr;
            gap:10px;
        }
        .product {
            display:grid;
            grid-template-columns:1fr 116px;
            gap:10px;
            align-items:center;
            padding:12px;
            border:1px solid var(--line);
            border-radius:8px;
            background:#fffdf9;
        }
        .product-name {
            font-weight:900;
            font-size:15px;
            line-height:1.15;
        }
        .product-meta {
            color:var(--muted);
            font-size:12px;
            margin-top:4px;
        }
        .qtybox {
            display:grid;
            grid-template-columns:36px 44px 36px;
            gap:0;
            align-items:center;
            justify-content:end;
            border:1px solid #d8d0c3;
            border-radius:8px;
            overflow:hidden;
            background:white;
        }
        .qtybox button {
            min-height:44px;
            border:0;
            background:var(--field);
            color:var(--ink);
            font-size:20px;
            font-weight:900;
        }
        .qtybox input {
            border:0;
            min-height:44px;
            border-radius:0;
            text-align:center;
            padding:0;
            font-weight:900;
            appearance:textfield;
        }
        .alert {
            border-radius:8px;
            padding:12px 14px;
            margin-bottom:12px;
            font-weight:800;
        }
        .alert.ok { background:#dcfce7; color:#166534; }
        .alert.err { background:#fee4e2; color:#b42318; }
        .fiscal {
            display:none;
        }
        .fiscal.open {
            display:block;
        }
        .summary {
            position:fixed;
            left:0;
            right:0;
            bottom:0;
            z-index:30;
            padding:10px 12px calc(10px + env(safe-area-inset-bottom));
            background:rgba(255,255,255,.96);
            border-top:1px solid var(--line);
            backdrop-filter:blur(16px);
        }
        .summary-inner {
            width:min(100%,860px);
            margin:0 auto;
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
            align-items:center;
        }
        .total-label {
            color:var(--muted);
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
        }
        .total {
            font-size:26px;
            line-height:1;
            font-weight:900;
        }
        .submit {
            min-height:58px;
            border:0;
            border-radius:8px;
            background:var(--brand);
            color:white;
            font-size:16px;
            font-weight:900;
            box-shadow:0 14px 30px rgba(18,108,106,.24);
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        .empty {
            border:1px dashed #d4cbbb;
            border-radius:8px;
            padding:18px;
            color:var(--muted);
            background:rgba(255,255,255,.6);
        }
        .extra-toggle {
            display:block;
            width:100%;
            min-height:40px;
            margin-top:10px;
            padding:0 14px;
            border:1px dashed #d4cbbb;
            border-radius:8px;
            background:transparent;
            color:var(--muted);
            font-size:13px;
            font-weight:900;
            cursor:pointer;
            transition:border-color .15s,color .15s;
            text-align:left;
        }
        .extra-toggle.open { border-color:var(--brand); color:var(--brand); }
        .chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
        .chip {
            min-height:44px;
            padding:0 16px;
            border:1.5px solid #d8d0c3;
            border-radius:8px;
            background:white;
            color:var(--ink);
            font-size:14px;
            font-weight:700;
            cursor:pointer;
            transition:all .15s;
        }
        .chip.active {
            border-color:var(--brand);
            background:rgba(18,108,106,.1);
            color:var(--brand);
        }

        /* ── Modal buscar cliente ── */
        .srch-overlay {
            display:none;
            position:fixed;
            inset:0;
            z-index:100;
            background:rgba(16,24,32,.45);
            backdrop-filter:blur(3px);
            align-items:flex-end;
            justify-content:center;
        }
        .srch-overlay.open { display:flex; }
        .srch-sheet {
            width:100%;
            max-height:88dvh;
            background:white;
            border-radius:20px 20px 0 0;
            display:flex;
            flex-direction:column;
            overflow:hidden;
            box-shadow:0 -8px 40px rgba(16,24,32,.18);
            animation:sheetUp .22s cubic-bezier(.32,0,.67,0) reverse,
                       sheetUp .22s cubic-bezier(.33,1,.68,1) forwards;
        }
        @keyframes sheetUp {
            from { transform:translateY(100%); }
            to   { transform:translateY(0); }
        }
        .srch-handle {
            width:40px;
            height:4px;
            background:#ddd;
            border-radius:2px;
            margin:10px auto 0;
            flex-shrink:0;
        }
        .srch-header {
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 16px 10px;
            border-bottom:1px solid var(--line);
            flex-shrink:0;
        }
        .srch-header span {
            font-weight:900;
            font-size:15px;
        }
        .srch-close {
            margin-left:auto;
            min-width:36px;
            min-height:36px;
            border:0;
            background:var(--field);
            border-radius:8px;
            font-size:18px;
            cursor:pointer;
            display:grid;
            place-items:center;
            flex-shrink:0;
        }
        .srch-input-wrap {
            padding:12px 16px;
            flex-shrink:0;
        }
        .srch-input-wrap input {
            min-height:52px;
            font-size:17px;
            border:2px solid var(--brand);
            box-shadow:0 0 0 4px rgba(18,108,106,.1);
            border-radius:12px;
        }
        .srch-results {
            overflow-y:auto;
            flex:1;
            padding:0 16px 24px;
            -webkit-overflow-scrolling:touch;
        }
        .srch-empty {
            padding:24px 0;
            text-align:center;
            color:var(--muted);
            font-size:14px;
        }
        .srch-item {
            display:flex;
            align-items:center;
            gap:12px;
            min-height:64px;
            padding:12px 14px;
            margin-bottom:6px;
            border:1.5px solid var(--line);
            border-radius:12px;
            background:#fffdf9;
            cursor:pointer;
            transition:border-color .12s, background .12s;
            -webkit-tap-highlight-color:transparent;
        }
        .srch-item:active, .srch-item:hover {
            border-color:var(--brand);
            background:rgba(18,108,106,.06);
        }
        .srch-avatar {
            width:40px;
            height:40px;
            border-radius:50%;
            background:var(--brand);
            color:white;
            font-weight:900;
            font-size:16px;
            display:grid;
            place-items:center;
            flex-shrink:0;
        }
        .srch-item-info { flex:1; min-width:0; }
        .srch-item-name {
            font-weight:900;
            font-size:15px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .srch-item-meta {
            font-size:12px;
            color:var(--muted);
            margin-top:2px;
        }
        .srch-item-tel {
            font-size:13px;
            font-weight:700;
            color:var(--brand);
            flex-shrink:0;
        }
        .btn-buscar {
            min-height:48px;
            padding:0 14px;
            border:1.5px solid var(--brand);
            border-radius:8px;
            background:white;
            color:var(--brand);
            font-weight:900;
            font-size:14px;
            cursor:pointer;
            white-space:nowrap;
            transition:background .12s, color .12s;
        }
        .btn-buscar:active { background:var(--brand); color:white; }
        @media (min-width:720px) {
            .srch-overlay { align-items:center; }
            .srch-sheet {
                width:min(100%,520px);
                max-height:80dvh;
                border-radius:16px;
                animation:none;
            }
            .srch-handle { display:none; }
        }

        @media (min-width:720px) {
            .shell { padding-inline:24px; }
            .top { margin-inline:-24px; padding-inline:24px; }
            .grid2 { grid-template-columns:1fr 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="top">
            <div class="toprow">
                <div>
                    <div class="eyebrow">Entrega directa</div>
                    <h1>Nueva venta</h1>
                </div>
                <a class="back" href="<?= url('representante/index.php') ?>">Inicio</a>
            </div>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert ok">
                <?= htmlspecialchars($mensaje) ?> · Total <?= money_rep_venta($pedidoCreado['total'] ?? 0) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($inventario)): ?>
            <div class="empty">No tienes inventario disponible para crear ventas.</div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="ventaForm">
                <section class="panel">
                    <div class="panel-title">
                        <h2>Cliente</h2>
                    </div>
                    <div class="grid2">
                        <div>
                            <label for="telefono">Telefono</label>
                            <div style="display:flex;gap:8px;align-items:stretch">
                                <div style="position:relative;flex:1">
                                    <input id="telefono" name="telefono" type="tel" inputmode="numeric" autocomplete="tel" required maxlength="10" pattern="[0-9]{10}" placeholder="10 digitos" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)">
                                    <span id="tel-badge" style="display:none;position:absolute;top:50%;right:10px;transform:translateY(-50%);font-size:11px;font-weight:900;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:99px">Cliente existente</span>
                                </div>
                                <button type="button" class="btn-buscar" onclick="abrirBusqueda()" title="Buscar cliente por nombre o teléfono"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></button>
                            </div>
                        </div>
                        <div>
                            <label for="cliente_nombre">Nombre</label>
                            <input id="cliente_nombre" name="cliente_nombre" type="text" autocomplete="name" placeholder="Opcional">
                        </div>
                    </div>

                    <!-- Tipo de cliente: siempre visible -->
                    <div style="margin-top:12px">
                        <label>Tipo de cliente</label>
                        <input type="hidden" name="tipo_cliente" id="tipo-cliente-val" value="medico">
                        <div class="chips">
                            <button type="button" class="chip active" data-chip-group="tipo_cliente" data-value="medico" onclick="chipSelect(this,'tipo-cliente-val'); actualizarCamposMedico('medico')">Médico</button>
                            <button type="button" class="chip" data-chip-group="tipo_cliente" data-value="paciente" onclick="chipSelect(this,'tipo-cliente-val'); actualizarCamposMedico('paciente')">Paciente</button>
                        </div>
                    </div>

                    <button type="button" id="btn-extra" class="extra-toggle" onclick="toggleExtra()">
                        ▸ Completar datos del cliente (opcional)
                    </button>
                    <div id="extra-fields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">

                        <div class="grid2" style="margin-bottom:10px">
                            <div>
                                <label for="ex-calle">Calle</label>
                                <input id="ex-calle" name="calle" type="text" autocomplete="off" placeholder="Nombre de la calle">
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                <div>
                                    <label for="ex-numero">Número</label>
                                    <input id="ex-numero" name="numero" type="text" placeholder="123">
                                </div>
                                <div>
                                    <label for="ex-cp">CP</label>
                                    <input id="ex-cp" name="cp" type="text" inputmode="numeric" maxlength="5" placeholder="00000">
                                </div>
                            </div>
                            <div>
                                <label for="ex-colonia">Colonia</label>
                                <input id="ex-colonia" name="colonia" type="text" placeholder="Colonia">
                            </div>
                            <div>
                                <label for="ex-estado">Estado</label>
                                <select id="ex-estado" name="estado">
                                    <option value="">— Seleccionar —</option>
                                </select>
                            </div>
                            <div>
                                <label for="ex-ciudad">Municipio / Alcaldía</label>
                                <select id="ex-ciudad" name="ciudad">
                                    <option value="">— Primero selecciona un estado —</option>
                                </select>
                            </div>
                            <div>
                                <label for="ex-email">Correo electrónico</label>
                                <input id="ex-email" name="email_factura" type="email" inputmode="email" autocomplete="email" placeholder="correo@ejemplo.com">
                            </div>
                        </div>
                        <div>
                            <label for="ex-referencias">Referencias del domicilio</label>
                            <textarea id="ex-referencias" name="referencias" style="min-height:60px" placeholder="Referencias para encontrar el domicilio..."></textarea>
                        </div>
                        <div style="margin-top:10px">
                            <label for="ex-quien">Quién recibe</label>
                            <input id="ex-quien" name="quien_recibe" type="text" placeholder="Nombre de quien recibe">
                        </div>

                        <!-- Médico del paciente (solo visible cuando es Paciente) -->
                        <div id="bloque-medico-fields" style="margin-top:10px;display:none">
                            <div class="grid2">
                                <div>
                                    <label for="ex-nom-medico">Médico del Paciente</label>
                                    <input id="ex-nom-medico" name="nombre_medico" type="text" placeholder="Dr. Juan Pérez">
                                </div>
                                <div>
                                    <label for="ex-tel-medico">Teléfono del médico</label>
                                    <input id="ex-tel-medico" name="telefono_medico" type="tel" inputmode="numeric" maxlength="10" placeholder="10 dígitos" oninput="this.value=this.value.replace(/\D/g,'')">
                                </div>
                            </div>
                        </div>

                        <!-- Especialidad (médico y paciente) -->
                        <div id="bloque-especialidad" style="margin-top:10px">
                            <label for="ex-especialidad">Especialidad</label>
                            <select id="ex-especialidad-sel" onchange="onEspChange(this)" style="margin-bottom:0">
                                <option value="">— Seleccionar —</option>
                                <?php foreach ($especialidades_lista as $_esp): ?>
                                    <option value="<?= htmlspecialchars($_esp) ?>"><?= htmlspecialchars($_esp) ?></option>
                                <?php endforeach; ?>
                                <option value="__otro__">Otro...</option>
                            </select>
                            <input type="hidden" name="especialidad" id="ex-especialidad">
                            <input id="ex-especialidad-otro" name="especialidad_otro" type="text"
                                   placeholder="Escribe la especialidad..."
                                   style="display:none;margin-top:6px"
                                   oninput="document.getElementById('ex-especialidad').value=this.value">
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-title">
                        <h2>Productos</h2>
                        <span class="eyebrow" id="prod-count"><?= count($inventario) ?> disponibles</span>
                    </div>
                    <div style="position:relative;margin-bottom:10px">
                        <input type="search" id="prod-filter"
                               placeholder="Filtrar productos…"
                               autocomplete="off"
                               oninput="filtrarProductos(this.value)"
                               style="padding-left:38px;min-height:46px;font-size:15px">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:16px;pointer-events:none"></span>
                    </div>
                    <div id="prod-lista" class="grid gap-2">
                        <?php foreach ($inventario as $item): ?>
                            <?php
                                $precio = $item['precio_base'] !== null ? (float)$item['precio_base'] : 0;
                                $max = (int)$item['cantidad_disponible'];
                            ?>
                            <div class="product" data-product-row data-price="<?= htmlspecialchars((string)$precio) ?>">
                                <div>
                                    <div class="product-name"><?= htmlspecialchars($item['producto']) ?></div>
                                    <div class="product-meta">
                                Disp. <?= $max ?> · <span class="precio-unitario"><?= $precio > 0 ? money_rep_venta($precio) : 'Sin precio' ?></span>
                            </div>
                            </div>
                                <div class="qtybox">
                                    <button type="button" data-step="-1" aria-label="Restar">-</button>
                                    <input
                                        type="number"
                                        name="productos[<?= (int)$item['producto_id'] ?>]"
                                        value="0"
                                        min="0"
                                        max="<?= $max ?>"
                                        data-qty
                                        data-price="<?= htmlspecialchars((string)$precio) ?>"
                                        data-product-id="<?= (int)$item['producto_id'] ?>"
                                        inputmode="numeric"
                                    >
                                    <button type="button" data-step="1" aria-label="Sumar">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="prod-empty" style="display:none;padding:16px 0;text-align:center;color:var(--muted);font-size:14px">Sin resultados</div>
                </section>

                <section class="panel">
                    <div class="panel-title">
                        <h2>Notas</h2>
                    </div>
                    <div>
                        <label for="notas">Observaciones</label>
                        <textarea id="notas" name="notas" placeholder="Observaciones opcionales"></textarea>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-title">
                        <h2>Cupón de descuento</h2>
                    </div>
                    <div style="display:flex;gap:8px">
                        <input id="cupon-input" type="text" placeholder="Código del cupón" autocomplete="off"
                               style="text-transform:uppercase;flex:1">
                        <button type="button" id="btn-aplicar-cupon" onclick="aplicarCupon()"
                                style="min-height:48px;padding:0 16px;background:var(--brand);color:white;border:0;border-radius:8px;font-weight:900;font-size:14px;white-space:nowrap;cursor:pointer">
                            Aplicar
                        </button>
                    </div>
                    <div id="cupon-msg" style="margin-top:6px;font-size:13px;display:none"></div>
                    <div id="cupon-aplicado" style="display:none;margin-top:8px;background:#d1fae5;color:#065f46;padding:8px 12px;border-radius:6px;font-size:13px;font-weight:900;align-items:center;justify-content:space-between;gap:8px">
                        <span><span id="cupon-codigo-text"></span> — <span id="cupon-desc-text" style="font-weight:600"></span></span>
                        <button type="button" onclick="removerCupon()" style="border:0;background:none;color:#b91c1c;cursor:pointer;font-size:16px;line-height:1">&times;</button>
                    </div>
                    <input type="hidden" name="cupon_id" id="hidden_cupon_id" value="">
                    <input type="hidden" name="cupon_codigo" id="hidden_cupon_codigo" value="">
                    <input type="hidden" name="cupon_descuento" id="hidden_cupon_descuento" value="0">
                </section>

                <section class="panel">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black m-0">Factura CFDI</h2>
                            <p class="text-sm text-slate-500 mt-1">Activalo solo si el cliente la solicita.</p>
                        </div>
                        <input id="requiere_factura" name="requiere_factura" value="1" type="checkbox" class="w-6 h-6 min-h-0">
                    </div>
                    <div class="fiscal mt-4" id="fiscalFields">
                        <div class="grid2">
                            <div>
                                <label for="rfc">RFC</label>
                                <input id="rfc" name="rfc" type="text" maxlength="13">
                            </div>
                            <div>
                                <label for="razon_social">Razon social</label>
                                <input id="razon_social" name="razon_social" type="text">
                            </div>
                            <div>
                                <label for="codigo_postal">CP fiscal</label>
                                <input id="codigo_postal" name="codigo_postal" type="text" maxlength="5" inputmode="numeric">
                            </div>
                            <div>
                                <label for="uso_cfdi">Uso CFDI</label>
                                <select id="uso_cfdi" name="uso_cfdi">
                                    <option value="G03">G03 - Gastos en general</option>
                                    <option value="G01">G01 - Adquisición de mercancías</option>
                                    <option value="G02">G02 - Devoluciones, descuentos o bonificaciones</option>
                                    <option value="I01">I01 - Construcciones</option>
                                    <option value="I02">I02 - Mobiliario y equipo de oficina</option>
                                    <option value="I03">I03 - Equipo de transporte</option>
                                    <option value="I04">I04 - Equipo de cómputo y accesorios</option>
                                    <option value="I05">I05 - Dados, troqueles, moldes y herramental</option>
                                    <option value="I06">I06 - Comunicaciones telefónicas</option>
                                    <option value="I07">I07 - Comunicaciones satelitales</option>
                                    <option value="I08">I08 - Otra maquinaria y equipo</option>
                                    <option value="D01">D01 - Honorarios médicos y gastos hospitalarios</option>
                                    <option value="D02">D02 - Gastos médicos por incapacidad</option>
                                    <option value="D03">D03 - Gastos funerales</option>
                                    <option value="D04">D04 - Donativos</option>
                                    <option value="D05">D05 - Intereses reales por créditos hipotecarios</option>
                                    <option value="D06">D06 - Aportaciones voluntarias al SAR</option>
                                    <option value="D07">D07 - Primas por seguros de gastos médicos</option>
                                    <option value="D08">D08 - Gastos de transportación escolar</option>
                                    <option value="D09">D09 - Depósitos en cuentas para el ahorro</option>
                                    <option value="D10">D10 - Pagos por servicios educativos</option>
                                    <option value="S01">S01 - Sin efectos fiscales</option>
                                    <option value="CP01">CP01 - Pagos</option>
                                    <option value="CN01">CN01 - Nómina</option>
                                    <option value="P01">P01 - Por definir</option>
                                </select>
                            </div>
                            <div>
                                <label for="regimen_fiscal">Régimen fiscal</label>
                                <select id="regimen_fiscal" name="regimen_fiscal">
                                    <option value="">Seleccionar</option>
                                    <option value="601">601 - General de Ley Personas Morales</option>
                                    <option value="603">603 - Personas Morales sin Fines Lucrativos</option>
                                    <option value="605">605 - Sueldos y Salarios</option>
                                    <option value="606">606 - Arrendamiento</option>
                                    <option value="607">607 - Enajenación o Adquisición de Bienes</option>
                                    <option value="608">608 - Demás ingresos</option>
                                    <option value="610">610 - Residentes en el Extranjero</option>
                                    <option value="611">611 - Ingresos por Dividendos</option>
                                    <option value="612">612 - Personas Físicas con Act. Empresariales</option>
                                    <option value="614">614 - Ingresos por intereses</option>
                                    <option value="615">615 - Ingresos por premios</option>
                                    <option value="616">616 - Sin obligaciones fiscales</option>
                                    <option value="620">620 - Sociedades Cooperativas de Producción</option>
                                    <option value="621">621 - Incorporación Fiscal</option>
                                    <option value="622">622 - Act. Agrícolas, Ganaderas, Silvícolas</option>
                                    <option value="623">623 - Opcional para Grupos de Sociedades</option>
                                    <option value="624">624 - Coordinados</option>
                                    <option value="625">625 - Plataformas Tecnológicas</option>
                                    <option value="626">626 - Régimen Simplificado de Confianza (RESICO)</option>
                                </select>
                            </div>
                        </div>
                        <!-- Notificación de factura -->
                        <label style="display:flex;align-items:center;gap:10px;margin-top:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:400;text-transform:none;font-size:13px;color:var(--ink)">
                            <input type="checkbox" id="notif_factura" name="notif_factura" value="1"
                                   class="w-5 h-5 min-h-0" style="flex-shrink:0" checked>
                            Recibir factura electrónica por correo
                        </label>
                        <div style="margin-top:10px">
                            <label for="constancia_fiscal">Constancia fiscal <span style="font-weight:400;text-transform:none;font-size:11px;color:var(--muted)">(opcional · PDF o imagen)</span></label>
                            <div id="constancia-drop" onclick="document.getElementById('constancia_fiscal').click()" style="border:2px dashed #d4cbbb;border-radius:8px;padding:16px 14px;text-align:center;cursor:pointer;transition:border-color .15s,background .15s;background:#fffdf9">
                                <div id="constancia-placeholder" style="color:var(--muted);font-size:13px">Toca para seleccionar archivo</div>
                                <div id="constancia-filename" style="display:none;font-size:13px;font-weight:700;color:var(--brand)"></div>
                            </div>
                            <input id="constancia_fiscal" name="constancia_fiscal" type="file" accept=".pdf,image/*" style="display:none"
                                   onchange="onConstanciaChange(this)">
                        </div>
                    </div>
                </section>

                <div class="summary">
                    <div class="summary-inner">
                        <div>
                            <div class="total-label">Total</div>
                            <div id="descuento-line" style="display:none;font-size:11px;color:#166534;font-weight:900">Desc. −<span id="totalDescuento"></span></div>
                            <div style="font-size:11px;color:#166534;font-weight:900">Sin cargo de envío</div>
                            <div class="total" id="totalVenta">$0.00</div>
                        </div>
                        <button type="submit" class="submit" id="submitBtn">Registrar → Cobrar</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <!-- ── Modal: buscar cliente ─────────────────────────────────────────── -->
    <div id="srch-overlay" class="srch-overlay" role="dialog" aria-modal="true" aria-label="Buscar cliente" onclick="onOverlayClick(event)">
        <div class="srch-sheet">
            <div class="srch-handle"></div>
            <div class="srch-header">
                <span>Buscar cliente</span>
                <button class="srch-close" onclick="cerrarBusqueda()" aria-label="Cerrar">&times;</button>
            </div>
            <div class="srch-input-wrap">
                <input type="search" id="srch-input" placeholder="Nombre, teléfono, ciudad…"
                       autocomplete="off" inputmode="search"
                       oninput="onSrchInput(this.value)">
            </div>
            <div class="srch-results" id="srch-results">
                <div class="srch-empty">Escribe al menos 2 caracteres para buscar</div>
            </div>
        </div>
    </div>

    <script>
        const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
        const totalNode = document.getElementById('totalVenta');
        const form = document.getElementById('ventaForm');
        const facturaToggle = document.getElementById('requiere_factura');
        const fiscalFields = document.getElementById('fiscalFields');

        // Shipping thresholds from server config
        const MONTO_MINIMO_ENVIO_GRATIS = <?= (float)$MONTO_MINIMO_ENVIO_GRATIS ?>;
        const COSTO_ENVIO = <?= (float)$COSTO_ENVIO ?>;

        // Dynamic prices keyed by product_id (updated via range-pricing AJAX)
        const preciosActuales = {};
        document.querySelectorAll('[data-qty]').forEach(input => {
            const pid = input.dataset.productId;
            if (pid) preciosActuales[pid] = parseFloat(input.dataset.price || '0');
        });

        // Coupon state
        let cuponActual = null;
        let descuentoCupon = 0;

        function calcularSubtotal() {
            let subtotal = 0;
            document.querySelectorAll('[data-qty]').forEach(input => {
                const pid = input.dataset.productId;
                const qty = Math.max(0, parseInt(input.value || '0', 10));
                const price = (pid && preciosActuales[pid] != null) ? preciosActuales[pid] : parseFloat(input.dataset.price || '0');
                subtotal += qty * price;
            });
            return subtotal;
        }

        function updateTotal() {
            const subtotal = calcularSubtotal();
            const descuento = Math.min(descuentoCupon, subtotal);
            // Entrega directa: sin cargo de envío
            const total = subtotal - descuento;

            if (totalNode) totalNode.textContent = money.format(total);

            const descuentoLine = document.getElementById('descuento-line');
            const totalDescuentoEl = document.getElementById('totalDescuento');
            if (descuentoLine && totalDescuentoEl) {
                if (descuento > 0) {
                    totalDescuentoEl.textContent = money.format(descuento);
                    descuentoLine.style.display = '';
                } else {
                    descuentoLine.style.display = 'none';
                }
            }

            return total;
        }

        async function fetchPrecio(producto_id, cantidad) {
            try {
                const res = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_precio&producto_id=${encodeURIComponent(producto_id)}&cantidad=${encodeURIComponent(cantidad)}`
                });
                const data = await res.json();
                return (data.success && data.precio > 0) ? parseFloat(data.precio) : null;
            } catch { return null; }
        }

        async function onQtyChangeAsync(input) {
            const pid = input.dataset.productId;
            if (!pid) { updateTotal(); return; }
            const qty = parseInt(input.value || '0', 10);
            if (qty > 0) {
                const precio = await fetchPrecio(pid, qty);
                if (precio !== null) {
                    preciosActuales[pid] = precio;
                    const row = input.closest('[data-product-row]');
                    if (row) {
                        const span = row.querySelector('.precio-unitario');
                        if (span) span.textContent = money.format(precio);
                    }
                }
            }
            updateTotal();
            if (cuponActual) revalidarCupon();
        }

        document.querySelectorAll('[data-step]').forEach(button => {
            button.addEventListener('click', () => {
                const box = button.closest('.qtybox');
                const input = box.querySelector('[data-qty]');
                const step = parseInt(button.dataset.step, 10);
                const max = parseInt(input.max || '0', 10);
                const next = Math.min(max, Math.max(0, parseInt(input.value || '0', 10) + step));
                input.value = String(next);
                onQtyChangeAsync(input);
            });
        });

        document.querySelectorAll('[data-qty]').forEach(input => {
            input.addEventListener('input', () => {
                const max = parseInt(input.max || '0', 10);
                const value = Math.min(max, Math.max(0, parseInt(input.value || '0', 10) || 0));
                input.value = String(value);
                onQtyChangeAsync(input);
            });
        });

        // ── Cupones ────────────────────────────────────────────────────────────
        async function aplicarCupon() {
            const inputEl = document.getElementById('cupon-input');
            const codigo = (inputEl?.value || '').trim().toUpperCase();
            if (!codigo) { mostrarMsgCupon('Ingresa un código de cupón', 'error'); return; }

            const carritoArr = [];
            document.querySelectorAll('[data-qty]').forEach(input => {
                const pid = input.dataset.productId;
                const qty = parseInt(input.value || '0', 10);
                if (pid && qty > 0) {
                    carritoArr.push({ id: parseInt(pid), cantidad: qty, precio: preciosActuales[pid] || parseFloat(input.dataset.price || '0'), es_kit: false });
                }
            });
            if (carritoArr.length === 0) { mostrarMsgCupon('Agrega productos antes de aplicar un cupón', 'error'); return; }

            const fd = new FormData();
            fd.append('action', 'validar');
            fd.append('codigo', codigo);
            fd.append('carrito', JSON.stringify(carritoArr));
            fd.append('representante_admin_id', '<?= (int)$representanteAdminId ?>');

            try {
                const res = await fetch('<?= url('api/cupones.php') ?>', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success && data.valido) {
                    cuponActual = data.cupon;
                    descuentoCupon = parseFloat(data.descuento);
                    document.getElementById('hidden_cupon_id').value = data.cupon.id;
                    document.getElementById('hidden_cupon_codigo').value = data.cupon.codigo;
                    document.getElementById('hidden_cupon_descuento').value = descuentoCupon;
                    mostrarCuponAplicado(data.cupon.codigo, data.cupon.descripcion || data.descuento_texto || '');
                    mostrarMsgCupon(data.message, 'success');
                } else {
                    mostrarMsgCupon(data.message || 'Cupón no válido', 'error');
                }
            } catch (e) {
                mostrarMsgCupon('Error al validar cupón', 'error');
            }
            updateTotal();
        }

        async function revalidarCupon() {
            if (!cuponActual) return;
            const carritoArr = [];
            document.querySelectorAll('[data-qty]').forEach(input => {
                const pid = input.dataset.productId;
                const qty = parseInt(input.value || '0', 10);
                if (pid && qty > 0) {
                    carritoArr.push({ id: parseInt(pid), cantidad: qty, precio: preciosActuales[pid] || parseFloat(input.dataset.price || '0'), es_kit: false });
                }
            });
            if (carritoArr.length === 0) { removerCupon(); return; }

            const fd = new FormData();
            fd.append('action', 'validar');
            fd.append('codigo', cuponActual.codigo);
            fd.append('carrito', JSON.stringify(carritoArr));
            fd.append('representante_admin_id', '<?= (int)$representanteAdminId ?>');

            try {
                const res = await fetch('<?= url('api/cupones.php') ?>', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success && data.valido) {
                    descuentoCupon = parseFloat(data.descuento);
                    document.getElementById('hidden_cupon_descuento').value = descuentoCupon;
                } else {
                    removerCupon();
                }
            } catch {}
            updateTotal();
        }

        function removerCupon() {
            cuponActual = null;
            descuentoCupon = 0;
            document.getElementById('hidden_cupon_id').value = '';
            document.getElementById('hidden_cupon_codigo').value = '';
            document.getElementById('hidden_cupon_descuento').value = '0';
            const inputEl = document.getElementById('cupon-input');
            if (inputEl) { inputEl.value = ''; inputEl.disabled = false; }
            const btnEl = document.getElementById('btn-aplicar-cupon');
            if (btnEl) btnEl.disabled = false;
            ocultarCuponAplicado();
            const msgEl = document.getElementById('cupon-msg');
            if (msgEl) msgEl.style.display = 'none';
            updateTotal();
        }

        function mostrarCuponAplicado(codigo, desc) {
            const el = document.getElementById('cupon-aplicado');
            if (el) el.style.display = 'flex';
            const codigoEl = document.getElementById('cupon-codigo-text');
            if (codigoEl) codigoEl.textContent = codigo;
            const descEl = document.getElementById('cupon-desc-text');
            if (descEl) descEl.textContent = desc;
            const inputEl = document.getElementById('cupon-input');
            if (inputEl) { inputEl.value = ''; inputEl.disabled = true; }
            const btnEl = document.getElementById('btn-aplicar-cupon');
            if (btnEl) btnEl.disabled = true;
        }

        function ocultarCuponAplicado() {
            const el = document.getElementById('cupon-aplicado');
            if (el) el.style.display = 'none';
        }

        function mostrarMsgCupon(msg, tipo) {
            const el = document.getElementById('cupon-msg');
            if (!el) return;
            el.style.display = '';
            el.style.color = tipo === 'error' ? '#b42318' : '#166534';
            el.textContent = msg;
            if (tipo !== 'success') setTimeout(() => { el.style.display = 'none'; }, 5000);
        }

        if (facturaToggle) {
            facturaToggle.addEventListener('change', () => {
                fiscalFields.classList.toggle('open', facturaToggle.checked);
                fiscalFields.querySelectorAll('input').forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'file' || input.name === 'email_factura') return;
                    input.required = facturaToggle.checked;
                });
            });
        }

        if (form) {
            form.addEventListener('submit', event => {
                if (calcularSubtotal() <= 0) {
                    event.preventDefault();
                    showToast('Selecciona al menos un producto.', 'warning');
                    return;
                }

                const telDigits = telInput ? telInput.value.replace(/\D/g, '').slice(0, 10) : '';
                if (telInput) telInput.value = telDigits;
                if (telDigits.length !== 10) {
                    event.preventDefault();
                    showToast('El telefono debe tener exactamente 10 digitos.', 'warning');
                    if (telInput) telInput.focus();
                    return;
                }

                const tipoVal = document.getElementById('tipo-cliente-val');
                const especialidadVal = (especialidadInput?.value || '').trim();
                if (tipoVal && tipoVal.value === 'medico' && !especialidadVal) {
                    event.preventDefault();
                    openExtra();
                    showToast('Si el tipo de cliente es Médico, la especialidad es obligatoria.', 'warning');
                    const espSel = document.getElementById('ex-especialidad-sel');
                    if (espSel) espSel.focus();
                    return;
                }

                // Para modo Médico: copiar nombre+tel del cliente a los campos nombre_medico/telefono_medico
                if (tipoVal && tipoVal.value === 'medico') {
                    if (nomMedicoInput) nomMedicoInput.value = nombreInput ? nombreInput.value : '';
                    if (telMedicoInput) telMedicoInput.value = telInput ? telInput.value.replace(/\D/g,'') : '';
                }
                const btn = document.getElementById('submitBtn');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px;">'
                        + '<svg style="animation:spin .8s linear infinite;width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">'
                        + '<circle cx="12" cy="12" r="10" stroke-opacity=".3"/>'
                        + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>'
                        + '</svg>Procesando…</span>';
                    btn.style.opacity = '0.75';
                }
            });
        }

        updateTotal();

        // ── Autocompletar datos del cliente al ingresar teléfono ──────────────
        const telInput     = document.getElementById('telefono');
        const telBadge     = document.getElementById('tel-badge');
        const nombreInput  = document.getElementById('cliente_nombre');
        const rfcInput         = document.getElementById('rfc');
        const razonInput       = document.getElementById('razon_social');
        const emailClienteInput= document.getElementById('ex-email');
        const calleInput       = document.getElementById('ex-calle');
        const numeroInput      = document.getElementById('ex-numero');
        const coloniaInput     = document.getElementById('ex-colonia');
        const cpInput2         = document.getElementById('ex-cp');
        const ciudadInput      = document.getElementById('ex-ciudad');
        const estadoInput      = document.getElementById('ex-estado');
        const referenciasInput = document.getElementById('ex-referencias');
        const quienInput       = document.getElementById('ex-quien');
        const cpInput          = document.getElementById('codigo_postal');
        const cfdiInput        = document.getElementById('uso_cfdi');
        const regimenInput     = document.getElementById('regimen_fiscal');
        const especialidadInput= document.getElementById('ex-especialidad');
        const nomMedicoInput   = document.getElementById('ex-nom-medico');
        const telMedicoInput   = document.getElementById('ex-tel-medico');

        let buscarTimer = null;

        function fill(el, value) {
            if (el && value) el.value = value;
        }

        function fillEspecialidad(value) {
            if (!value) return;
            const sel = document.getElementById('ex-especialidad-sel');
            const hid = document.getElementById('ex-especialidad');
            const otro = document.getElementById('ex-especialidad-otro');
            if (!sel) return;
            // Check if value exists in options
            const found = Array.from(sel.options).some(o => o.value === value);
            if (found) {
                sel.value = value;
                hid.value = value;
                if (otro) otro.style.display = 'none';
            } else {
                sel.value = '__otro__';
                hid.value = value;
                if (otro) { otro.style.display = ''; otro.value = value; }
            }
        }

        function onEspChange(sel) {
            const otro = document.getElementById('ex-especialidad-otro');
            const hid  = document.getElementById('ex-especialidad');
            if (sel.value === '__otro__') {
                if (otro) { otro.style.display = ''; otro.focus(); }
                if (hid) hid.value = '';
            } else {
                if (otro) otro.style.display = 'none';
                if (hid) hid.value = sel.value;
            }
        }

        function clearClienteBadge() {
            if (telBadge) telBadge.style.display = 'none';
        }

        function openExtra() {
            const extra = document.getElementById('extra-fields');
            const btn   = document.getElementById('btn-extra');
            if (extra) extra.style.display = '';
            if (btn)   btn.classList.add('open');
        }
        function toggleExtra() {
            const extra = document.getElementById('extra-fields');
            const btn   = document.getElementById('btn-extra');
            if (!extra) return;
            const isOpen = extra.style.display !== 'none';
            extra.style.display = isOpen ? 'none' : '';
            btn.classList.toggle('open', !isOpen);
        }
        function chipSelect(el, hiddenId) {
            const group = el.dataset.chipGroup;
            if (el.classList.contains('active')) {
                el.classList.remove('active');
                const h = document.getElementById(hiddenId);
                if (h) h.value = '';
                return;
            }
            document.querySelectorAll(`[data-chip-group="${group}"]`).forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            const h = document.getElementById(hiddenId);
            if (h) h.value = el.dataset.value;
        }
        function setChip(group, value, hiddenId) {
            document.querySelectorAll(`[data-chip-group="${group}"]`).forEach(c => {
                c.classList.toggle('active', c.dataset.value === value);
            });
            if (hiddenId) { const h = document.getElementById(hiddenId); if (h) h.value = value; }
        }

        function actualizarCamposMedico(tipo) {
            const esMedico = tipo === 'medico';
            const bloqueMedico = document.getElementById('bloque-medico-fields');
            if (bloqueMedico) bloqueMedico.style.display = esMedico ? 'none' : '';
            if (esMedico) {
                // Limpiar campos del médico del paciente
                if (nomMedicoInput) nomMedicoInput.value = '';
                if (telMedicoInput) telMedicoInput.value = '';
            }
            // Si cambia a paciente, abrir el panel
            // no auto-abrir colapsable
        }

        async function buscarCliente(tel) {
            if (tel.length < 10) { clearClienteBadge(); return; }
            try {
                const url = '?action=buscar_cliente&telefono=' + encodeURIComponent(tel);
                const res = await fetch(url);
                const data = await res.json();
                if (data.found) {
                    fill(nombreInput,       data.nombre);
                    fill(emailClienteInput, data.email_factura);
                    fill(calleInput,        data.calle);
                    fill(numeroInput,       data.numero);
                    fill(coloniaInput,      data.colonia);
                    fill(cpInput2,          data.cp);
                    fill(ciudadInput,       data.ciudad);
                    // Al cargar cliente existente, re-inicializar selector de municipios
                    if (data.estado) {
                        initUbicaciones({
                            selectEstado:    '#ex-estado',
                            selectMunicipio: '#ex-ciudad',
                            valorEstado:     data.estado,
                            valorMunicipio:  data.ciudad || '',
                            basePath:        '<?= BASE_PATH ?>',
                        });
                    } else {
                        fill(estadoInput, data.estado);
                    }
                    fill(referenciasInput,  data.referencias);
                    fill(quienInput,        data.quien_recibe);
                    fill(rfcInput,          data.rfc);
                    fill(razonInput,        data.razon_social);
                    fill(cpInput,           data.codigo_postal);
                    fill(cfdiInput,         data.uso_cfdi);
                    fill(regimenInput,      data.regimen_fiscal);
                    // notif_factura
                    const notifFactEl = document.getElementById('notif_factura');
                    if (notifFactEl) notifFactEl.checked = (data.notif_factura ?? 1) == 1;
                    // tipo_cliente
                    if (data.tipo_cliente) setChip('tipo_cliente', data.tipo_cliente, 'tipo-cliente-val');
                    fillEspecialidad(data.especialidad);

                    if ((data.tipo_cliente || 'medico') === 'paciente') {
                        fill(nomMedicoInput, data.nombre_medico);
                        fill(telMedicoInput, data.telefono_medico);
                    }
                    actualizarCamposMedico(data.tipo_cliente || 'medico');
                    if (data.rfc && facturaToggle && !facturaToggle.checked) {
                        facturaToggle.checked = true;
                        fiscalFields.classList.add('open');
                    }
                    if (telBadge) telBadge.style.display = 'inline-block';
                } else {
                    clearClienteBadge();
                }
            } catch (e) { clearClienteBadge(); }
        }

        if (telInput) {
            telInput.addEventListener('input', () => {
                clearClienteBadge();
                const digits = telInput.value.replace(/\D/g, '').slice(0, 10);
                telInput.value = digits;
                clearTimeout(buscarTimer);
                if (digits.length === 10) {
                    buscarTimer = setTimeout(() => buscarCliente(digits), 400);
                }
            });
            telInput.addEventListener('blur', () => {
                const digits = telInput.value.replace(/\D/g, '');
                if (digits.length === 10) buscarCliente(digits);
            });
        }
        // Inicializar estado de campos según tipo por defecto (médico)
        actualizarCamposMedico('medico');

        // ── Modal: buscar cliente ─────────────────────────────────────────
        let srchTimer = null;

        function abrirBusqueda() {
            const overlay = document.getElementById('srch-overlay');
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
            setTimeout(() => {
                const inp = document.getElementById('srch-input');
                if (inp) inp.focus();
            }, 150);
        }

        function cerrarBusqueda() {
            document.getElementById('srch-overlay').classList.remove('open');
            document.body.style.overflow = '';
        }

        function onOverlayClick(e) {
            if (e.target === document.getElementById('srch-overlay')) cerrarBusqueda();
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') cerrarBusqueda();
        });

        function onSrchInput(q) {
            clearTimeout(srchTimer);
            if (q.trim().length < 2) {
                document.getElementById('srch-results').innerHTML =
                    '<div class="srch-empty">Escribe al menos 2 caracteres para buscar</div>';
                return;
            }
            document.getElementById('srch-results').innerHTML =
                '<div class="srch-empty">Buscando…</div>';
            srchTimer = setTimeout(() => ejecutarBusqueda(q.trim()), 300);
        }

        async function ejecutarBusqueda(q) {
            try {
                const res  = await fetch('?action=buscar_clientes_texto&q=' + encodeURIComponent(q));
                const list = await res.json();
                renderSrchResults(list, q);
            } catch {
                document.getElementById('srch-results').innerHTML =
                    '<div class="srch-empty">Error al buscar. Intenta de nuevo.</div>';
            }
        }

        function renderSrchResults(list, q) {
            const box = document.getElementById('srch-results');
            if (!list.length) {
                box.innerHTML = '<div class="srch-empty">Sin resultados para <strong>' +
                    q.replace(/</g,'&lt;') + '</strong></div>';
                return;
            }
            box.innerHTML = list.map(c => {
                const inicial = (c.nombre || c.telefono || '?').trim().charAt(0).toUpperCase();
                const nombre  = c.nombre  ? escHtml(c.nombre)  : '<em style="color:var(--muted)">Sin nombre</em>';
                const lugar   = [c.ciudad, c.estado].filter(Boolean).map(escHtml).join(', ');
                return `<div class="srch-item" onclick="seleccionarCliente('${escHtml(c.telefono)}')">
                    <div class="srch-avatar">${inicial}</div>
                    <div class="srch-item-info">
                        <div class="srch-item-name">${nombre}</div>
                        ${lugar ? `<div class="srch-item-meta">${lugar}</div>` : ''}
                    </div>
                    <div class="srch-item-tel">${escHtml(c.telefono)}</div>
                </div>`;
            }).join('');
        }

        function seleccionarCliente(tel) {
            cerrarBusqueda();
            const telInput = document.getElementById('telefono');
            if (telInput) {
                telInput.value = tel;
                telInput.dispatchEvent(new Event('input'));
                buscarCliente(tel);
            }
        }

        // ── Constancia fiscal ─────────────────────────────────────────────
        function onConstanciaChange(input) {
            const ph  = document.getElementById('constancia-placeholder');
            const fn  = document.getElementById('constancia-filename');
            const drp = document.getElementById('constancia-drop');
            if (input.files && input.files[0]) {
                const f = input.files[0];
                const size = f.size > 1048576 ? (f.size/1048576).toFixed(1)+' MB' : Math.round(f.size/1024)+' KB';
                fn.textContent = '\u2713 ' + f.name + ' (' + size + ')';
                fn.style.display = '';
                ph.style.display = 'none';
                drp.style.borderColor = 'var(--brand)';
                drp.style.background  = 'rgba(18,108,106,.04)';
            } else {
                fn.style.display = 'none';
                ph.style.display = '';
                drp.style.borderColor = '';
                drp.style.background  = '';
            }
        }

        // ── Filtro de productos ───────────────────────────────────────────
        function filtrarProductos(q) {
            const term  = q.trim().toLowerCase();
            const rows  = document.querySelectorAll('#prod-lista [data-product-row]');
            const empty = document.getElementById('prod-empty');
            const count = document.getElementById('prod-count');
            let visible = 0;
            rows.forEach(row => {
                const name = row.querySelector('.product-name')?.textContent.toLowerCase() ?? '';
                const show = !term || name.includes(term);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (empty) empty.style.display = (visible === 0) ? '' : 'none';
            if (count) count.textContent = term
                ? `${visible} de <?= count($inventario) ?>`
                : '<?= count($inventario) ?> disponibles';
        }

        function escHtml(s) {
            return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Toast local (página sin footer global) ──────────────────────────
        // showToast centralizado en js/ui-toast.js
    </script>

<script src="<?= BASE_PATH ?>js/ui-toast.js"></script>
<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initUbicaciones({
        selectEstado:    '#ex-estado',
        selectMunicipio: '#ex-ciudad',
        basePath:        '<?= BASE_PATH ?>',
    });
});
</script>
</body>
</html>
