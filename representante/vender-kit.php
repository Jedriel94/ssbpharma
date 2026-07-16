<?php
require_once __DIR__ . '/../includes/auth_representante.php';

// Módulo de venta de kits deshabilitado para representantes.
header('Location: index.php');
exit;

require_once __DIR__ . '/../models/Configuracion.php';
require_once __DIR__ . '/../models/RepresentanteVenta.php';
require_once __DIR__ . '/../models/Cliente.php';
require_once __DIR__ . '/../config/database.php';

// ── AJAX: buscar cliente por teléfono ─────────────────────────────────────
if (($_GET['action'] ?? '') === 'buscar_cliente' && isset($_GET['telefono'])) {
    header('Content-Type: application/json');
    $clienteModel = new Cliente();
    $tel = preg_replace('/\D+/', '', $_GET['telefono']);
    $c = $tel ? $clienteModel->getByTelefono($tel) : null;
    if ($c) {
        echo json_encode([
            'found'        => true,
            'nombre'       => $c['nombre'] ?? '',
            'calle'        => $c['calle'] ?? '',
            'numero'       => $c['numero'] ?? '',
            'colonia'      => $c['colonia'] ?? '',
            'cp'           => $c['cp'] ?? '',
            'estado'       => $c['estado'] ?? '',
            'ciudad'       => $c['ciudad'] ?? '',
            'referencias'  => $c['referencias'] ?? '',
            'quien_recibe' => $c['quien_recibe'] ?? '',
            'email_factura'=> $c['email_factura'] ?? '',
            'tipo_cliente' => $c['tipo_cliente'] ?? 'medico',
            'especialidad' => $c['especialidad'] ?? '',
            'nombre_medico' => $c['nombre_medico'] ?? '',
            'telefono_medico' => $c['telefono_medico'] ?? '',
        ]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ── AJAX: buscar clientes por texto ───────────────────────────────────────
if (($_GET['action'] ?? '') === 'buscar_clientes_texto') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }
    $pdo  = Database::getInstance()->getConnection();
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $stmt = $pdo->prepare(
        "SELECT telefono, nombre, ciudad, estado
         FROM clientes
         WHERE nombre LIKE ? OR telefono LIKE ? OR ciudad LIKE ?
         ORDER BY nombre LIMIT 25"
    );
    $stmt->execute([$like, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Cargar kits con stock del rep ──────────────────────────────────────────
$pdo  = Database::getInstance()->getConnection();
$stmt = $pdo->prepare(
    "SELECT k.id, k.nombre, k.descripcion, k.imagen, k.precio_kit,
            MIN(CASE WHEN ri.cantidad_disponible IS NULL THEN 0
                     ELSE FLOOR(ri.cantidad_disponible / kp.cantidad)
                END) AS kits_posibles_rep
     FROM kits k
     INNER JOIN kit_productos kp ON k.id = kp.kit_id
     INNER JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
     LEFT JOIN representante_inventario ri
            ON ri.producto_id = kp.producto_id
           AND ri.representante_admin_id = ?
     WHERE k.activo = 1
     GROUP BY k.id, k.nombre, k.descripcion, k.imagen, k.precio_kit
     ORDER BY k.orden, k.nombre"
);
$stmt->execute([$representanteAdminId]);
$kits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cargar especialidades activas para el selector de cliente
$_db_esp = Database::getInstance()->getConnection();
$especialidades_lista = $_db_esp
    ->query("SELECT nombre FROM especialidades WHERE activo=1 ORDER BY orden,nombre")
    ->fetchAll(PDO::FETCH_COLUMN);

// ── POST: crear pedido desde kit ──────────────────────────────────────────
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_cliente_post = trim($_POST['tipo_cliente'] ?? 'medico');
    $especialidad_post = trim($_POST['especialidad'] ?? '');
    if ($tipo_cliente_post === 'medico' && $especialidad_post === '') {
        $error = 'La especialidad es obligatoria para cliente médico antes de Registrar -> Cobrar.';
    }

    if (!$error) {
        $kit_id = (int)($_POST['kit_id'] ?? 0);
        if ($kit_id <= 0) {
            $error = 'Selecciona un kit antes de continuar.';
        } else {
            $ventaModel = new RepresentanteVenta();
            $resultado  = $ventaModel->crearDesdeKitPorAdmin($representanteAdminId, $kit_id, $_POST);
            if ($resultado['success']) {
                $tel_cliente = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
                header(
                    'Location: ' . url('procesar-pago.php')
                    . '?pedido_id=' . (int)$resultado['pedido_id']
                    . '&telefono=' . urlencode($tel_cliente)
                    . '&modo=rep'
                );
                exit;
            } else {
                $error = $resultado['mensaje'];
            }
        }
    }
}

$storeName = Configuracion::get('nombre_tienda', 'Solumedic');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vender Kit | <?= htmlspecialchars($storeName) ?></title>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
    <style>
        body {
            margin:0;
            min-height:100vh;
            background:var(--paper);
            color:var(--ink);
        }
        .shell {
            width:min(100%,860px);
            margin:0 auto;
            padding:16px 14px 130px;
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
        h1 { margin:0; font-size:24px; font-weight:900; }
        .eyebrow { color:var(--muted); font-size:12px; font-weight:900; text-transform:uppercase; }
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
        .panel-title h2 { margin:0; font-size:16px; font-weight:900; }
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
        input:focus, select:focus, textarea:focus {
            border-color:var(--brand);
            box-shadow:0 0 0 3px rgba(18,108,106,.12);
        }
        textarea { min-height:80px; resize:vertical; }
        .grid2 { display:grid; grid-template-columns:1fr; gap:10px; }
        .alert { border-radius:8px; padding:12px 14px; margin-bottom:12px; font-weight:800; }
        .alert.err { background:#fee4e2; color:#b42318; }

        /* ── Kit cards ── */
        .kit-grid { display:grid; grid-template-columns:1fr; gap:10px; }
        .kit-card {
            display:flex;
            align-items:stretch;
            gap:0;
            border:2px solid var(--line);
            border-radius:10px;
            background:white;
            cursor:pointer;
            transition:border-color .15s, box-shadow .15s;
            overflow:hidden;
            position:relative;
        }
        .kit-card.disabled { opacity:.5; cursor:not-allowed; }
        .kit-card:not(.disabled):hover { border-color:var(--brand); box-shadow:0 4px 16px rgba(18,108,106,.12); }
        .kit-card input[type=radio] { position:absolute; opacity:0; width:0; height:0; }
        .kit-card input[type=radio]:checked + .kit-inner { border-left:4px solid var(--brand); }
        .kit-inner {
            display:flex;
            align-items:center;
            gap:12px;
            flex:1;
            padding:12px;
            border-left:4px solid transparent;
            transition:border-color .15s;
        }
        .kit-img {
            width:64px;
            height:64px;
            border-radius:8px;
            object-fit:cover;
            flex-shrink:0;
            background:var(--field);
        }
        .kit-img-placeholder {
            width:64px;
            height:64px;
            border-radius:8px;
            background:var(--field);
            display:grid;
            place-items:center;
            font-size:28px;
            flex-shrink:0;
        }
        .kit-info { flex:1; min-width:0; }
        .kit-name { font-weight:900; font-size:15px; margin-bottom:2px; }
        .kit-desc { font-size:12px; color:var(--muted); line-height:1.4; }
        .kit-right { display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex-shrink:0; }
        .kit-price { font-size:18px; font-weight:900; color:var(--brand); white-space:nowrap; }
        .kit-stock { font-size:11px; font-weight:900; text-transform:uppercase; }
        .kit-stock.ok { color:#166534; }
        .kit-stock.out { color:#b42318; }
        .check-ring {
            width:22px;
            height:22px;
            border-radius:50%;
            border:2px solid #d8d0c3;
            display:grid;
            place-items:center;
            flex-shrink:0;
            transition:all .15s;
            background:white;
        }
        .kit-card input[type=radio]:checked ~ .kit-check .check-ring {
            background:var(--brand);
            border-color:var(--brand);
        }
        .check-ring::after {
            content:'';
            width:8px;
            height:8px;
            border-radius:50%;
            background:white;
            opacity:0;
            transition:opacity .15s;
        }
        .kit-card input[type=radio]:checked ~ .kit-check .check-ring::after { opacity:1; }
        .kit-no-stock {
            font-size:11px;
            background:#fee4e2;
            color:#b42318;
            padding:2px 8px;
            border-radius:4px;
            font-weight:900;
        }

        /* ── Fixed footer ── */
        .summary {
            position:fixed;
            left:0; right:0; bottom:0;
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
        .total-label { color:var(--muted); font-size:12px; font-weight:900; text-transform:uppercase; }
        .total { font-size:26px; line-height:1; font-weight:900; }
        .submit {
            min-height:58px;
            border:0;
            border-radius:8px;
            background:var(--brand);
            color:white;
            font-size:16px;
            font-weight:900;
            cursor:pointer;
            box-shadow:0 14px 30px rgba(18,108,106,.24);
        }
        .submit:disabled { background:#94a3b8; box-shadow:none; cursor:not-allowed; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* ── Client search sheet ── */
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
        }
        .srch-handle { width:40px; height:4px; background:#ddd; border-radius:2px; margin:10px auto 0; flex-shrink:0; }
        .srch-header {
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 16px 10px;
            border-bottom:1px solid var(--line);
            flex-shrink:0;
        }
        .srch-header span { font-weight:900; font-size:15px; }
        .srch-close {
            margin-left:auto;
            min-width:36px; min-height:36px;
            border:0;
            background:var(--field);
            border-radius:8px;
            font-size:18px;
            cursor:pointer;
        }
        .srch-input-wrap { padding:12px 16px; flex-shrink:0; }
        .srch-input-wrap input { min-height:52px; font-size:17px; border:2px solid var(--brand); border-radius:12px; }
        .srch-results { overflow-y:auto; flex:1; padding:0 16px 24px; }
        .srch-empty { padding:24px 0; text-align:center; color:var(--muted); font-size:14px; }
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
        }
        .srch-item:active { border-color:var(--brand); background:rgba(18,108,106,.06); }
        .srch-avatar {
            width:40px; height:40px;
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
        .srch-item-name { font-weight:900; font-size:15px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .srch-item-meta { font-size:12px; color:var(--muted); margin-top:2px; }
        .srch-item-tel { font-size:13px; font-weight:700; color:var(--brand); flex-shrink:0; }
        .btn-buscar {
            min-height:48px; padding:0 14px;
            border:1.5px solid var(--brand);
            border-radius:8px; background:white;
            color:var(--brand); font-weight:900;
            font-size:14px; cursor:pointer;
        }
        .extra-toggle {
            width:100%;
            min-height:44px;
            border:1px dashed #d8d0c3;
            border-radius:8px;
            background:#fff;
            color:var(--ink);
            text-align:left;
            padding:0 12px;
            font-weight:800;
            cursor:pointer;
            margin-top:12px;
        }
        .extra-toggle.open { border-color:var(--brand); color:var(--brand); }
        @media (min-width:720px) {
            .shell { padding-inline:24px; }
            .top { margin-inline:-24px; padding-inline:24px; }
            .grid2 { grid-template-columns:1fr 1fr; }
            .kit-grid { grid-template-columns:1fr 1fr; }
            .srch-overlay { align-items:center; }
            .srch-sheet { width:min(100%,520px); max-height:80dvh; border-radius:16px; }
            .srch-handle { display:none; }
        }
    </style>
</head>
<body>
<main class="shell">
    <header class="top">
        <div class="toprow">
            <div>
                <div class="eyebrow">Venta directa</div>
                <h1>Vender Kit</h1>
            </div>
            <a class="back" href="<?= url('representante/index.php') ?>">Inicio</a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="alert err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($kits)): ?>
        <div class="panel" style="text-align:center;padding:32px;color:var(--muted)">
            <div style="font-size:40px;margin-bottom:12px"></div>
            <div style="font-weight:900;margin-bottom:6px">Sin kits disponibles</div>
            <div style="font-size:14px">No hay kits activos configurados.</div>
        </div>
    <?php else: ?>
    <form method="POST" id="kitForm">
        <!-- ── Selección de kit ──────────────────────────────────────── -->
        <section class="panel">
            <div class="panel-title">
                <h2>Selecciona un kit</h2>
            </div>
            <div class="kit-grid">
            <?php foreach ($kits as $kit):
                $posibles = (int)$kit['kits_posibles_rep'];
                $disabled = $posibles === 0;
                $imgSrc   = !empty($kit['imagen'])
                    ? uploads_url('kits/' . $kit['imagen'])
                    : null;
            ?>
                <label class="kit-card<?= $disabled ? ' disabled' : '' ?>"
                       onclick="<?= $disabled ? 'return false' : 'seleccionarKit(this)' ?>">
                    <input type="radio" name="kit_id" value="<?= $kit['id'] ?>"
                           <?= $disabled ? 'disabled' : '' ?>>
                    <div class="kit-inner">
                        <?php if ($imgSrc): ?>
                            <img class="kit-img" src="<?= htmlspecialchars($imgSrc) ?>"
                                 alt="<?= htmlspecialchars($kit['nombre']) ?>">
                        <?php else: ?>
                            <div class="kit-img-placeholder"></div>
                        <?php endif; ?>
                        <div class="kit-info">
                            <div class="kit-name"><?= htmlspecialchars($kit['nombre']) ?></div>
                            <?php if (!empty($kit['descripcion'])): ?>
                                <div class="kit-desc"><?= htmlspecialchars(mb_strimwidth($kit['descripcion'], 0, 80, '…')) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="kit-right">
                            <div class="kit-price">$<?= number_format((float)$kit['precio_kit'], 2) ?></div>
                            <?php if ($disabled): ?>
                                <div class="kit-no-stock">Sin stock</div>
                            <?php else: ?>
                                <div class="kit-stock ok"><?= $posibles ?> disponible<?= $posibles > 1 ? 's' : '' ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="kit-check" style="display:flex;align-items:center;padding-right:12px">
                        <div class="check-ring"></div>
                    </div>
                </label>
            <?php endforeach; ?>
            </div>
        </section>

        <!-- ── Cantidad de kits ──────────────────────────────────────── -->
        <section class="panel" id="section-cantidad" style="display:none">
            <div class="panel-title">
                <h2>Cantidad</h2>
            </div>
            <div class="qty-row">
                <button type="button" id="qty-minus" class="qty-btn" onclick="stepCantidad(-1)" disabled>−</button>
                <span id="qty-val" class="qty-num">1</span>
                <button type="button" id="qty-plus"  class="qty-btn" onclick="stepCantidad(1)">+</button>
                <span id="qty-max" class="qty-max-label"></span>
            </div>
            <input type="hidden" name="cantidad_kits" id="kit-cantidad-input" value="1">
        </section>

        <!-- ── Datos del cliente ─────────────────────────────────────── -->
        <section class="panel" id="section-cliente" style="display:none">
            <div class="panel-title">
                <h2>Cliente</h2>
            </div>
            <div class="grid2">
                <div>
                    <label for="telefono">Teléfono</label>
                    <div style="display:flex;gap:8px;align-items:stretch">
                        <div style="position:relative;flex:1">
                            <input id="telefono" name="telefono" type="tel" inputmode="numeric"
                                   autocomplete="tel" required placeholder="10 dígitos"
                                   oninput="onTelInput(this)">
                            <span id="tel-badge"
                                  style="display:none;position:absolute;top:50%;right:10px;transform:translateY(-50%);font-size:11px;font-weight:900;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:99px">
                                Existe
                            </span>
                        </div>
                        <button type="button" class="btn-buscar" onclick="abrirBusqueda()" title="Buscar cliente"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></button>
                    </div>
                </div>
                <div>
                    <label for="cliente_nombre">Nombre</label>
                    <input id="cliente_nombre" name="cliente_nombre" type="text"
                           autocomplete="name" placeholder="Opcional">
                </div>
            </div>
            <div style="margin-top:12px">
                <label>Tipo de cliente</label>
                <input type="hidden" name="tipo_cliente" id="tipo-cliente-val" value="medico">
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="chip active" data-value="medico" onclick="setTipo('medico', this)">Médico</button>
                    <button type="button" class="chip" data-value="paciente" onclick="setTipo('paciente', this)">Paciente</button>
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

        <!-- ── Notas ─────────────────────────────────────────────────── -->
        <section class="panel" id="section-notas" style="display:none">
            <div class="panel-title">
                <h2>Notas</h2>
            </div>
            <label for="notas">Observaciones (opcional)</label>
            <textarea id="notas" name="notas" placeholder="Ej. cliente nuevo, indicaciones de entrega…"></textarea>
        </section>

        <!-- ── Fixed footer ─────────────────────────────────────────── -->
        <div class="summary">
            <div class="summary-inner">
                <div>
                    <div class="total-label">Total kit</div>
                    <div class="total" id="footer-precio">—</div>
                </div>
                <button type="submit" class="submit" id="submitBtn" disabled>
                    Registrar → Cobrar
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</main>

<!-- ── Modal buscar cliente ─────────────────────────────────────────────── -->
<div id="srch-overlay" class="srch-overlay" onclick="onOverlayClick(event)">
    <div class="srch-sheet">
        <div class="srch-handle"></div>
        <div class="srch-header">
            <span>Buscar cliente</span>
            <button class="srch-close" onclick="cerrarBusqueda()">&times;</button>
        </div>
        <div class="srch-input-wrap">
            <input type="search" id="srch-input" placeholder="Nombre, teléfono, ciudad…"
                   autocomplete="off" inputmode="search" oninput="onSrchInput(this.value)">
        </div>
        <div class="srch-results" id="srch-results">
            <div class="srch-empty">Escribe al menos 2 caracteres para buscar</div>
        </div>
    </div>
</div>

<!-- Chip style (inline porque no hay Tailwind aquí) -->
<style>
/* Stepper de cantidad */
.qty-row {
    display: flex;
    align-items: center;
    gap: 0;
    justify-content: flex-start;
}
.qty-btn {
    width: 52px;
    height: 52px;
    border: 2px solid var(--brand, #6b4e2a);
    background: white;
    color: var(--brand, #6b4e2a);
    font-size: 26px;
    font-weight: 700;
    border-radius: 12px;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    transition: background .12s, color .12s;
}
.qty-btn:active:not(:disabled) {
    background: var(--brand, #6b4e2a);
    color: white;
}
.qty-btn:disabled {
    opacity: .3;
    cursor: default;
}
.qty-num {
    min-width: 64px;
    text-align: center;
    font-size: 32px;
    font-weight: 800;
    color: var(--ink, #2c1a0e);
    line-height: 1;
}
.qty-max-label {
    margin-left: 12px;
    font-size: 13px;
    color: #888;
    font-weight: 600;
}
</style>
<style>
.chip {
    min-height:44px; padding:0 16px;
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
</style>

<script>
// ── Kit selection ────────────────────────────────────────────────────────
const KIT_PRICES = <?= json_encode(
    array_column($kits, 'precio_kit', 'id')
) ?>;
const KIT_STOCK = <?= json_encode(
    array_column($kits, 'kits_posibles_rep', 'id')
) ?>;

let kitCantidad = 1;

function actualizarTotal() {
    const radio = document.querySelector('input[name=kit_id]:checked');
    if (!radio) return;
    const precio = parseFloat(KIT_PRICES[radio.value] || 0);
    const total = precio * kitCantidad;
    document.getElementById('footer-precio').textContent =
        '$' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('kit-cantidad-input').value = kitCantidad;
}

function stepCantidad(delta) {
    const radio = document.querySelector('input[name=kit_id]:checked');
    if (!radio) return;
    const max = parseInt(KIT_STOCK[radio.value] || 1);
    kitCantidad = Math.min(max, Math.max(1, kitCantidad + delta));
    document.getElementById('qty-val').textContent = kitCantidad;
    document.getElementById('qty-minus').disabled = kitCantidad <= 1;
    document.getElementById('qty-plus').disabled  = kitCantidad >= max;
    actualizarTotal();
}

function seleccionarKit(label) {
    const radio = label.querySelector('input[type=radio]');
    if (!radio || radio.disabled) return;
    radio.checked = true;
    // Highlight
    document.querySelectorAll('.kit-card').forEach(c => c.style.outline = '');
    label.style.outline = '2px solid var(--brand)';
    // Show quantity + client + notes panels
    document.getElementById('section-cantidad').style.display = '';
    document.getElementById('section-cliente').style.display = '';
    document.getElementById('section-notas').style.display = '';
    // Reset stepper
    const max = parseInt(KIT_STOCK[radio.value] || 1);
    kitCantidad = 1;
    document.getElementById('qty-val').textContent = 1;
    document.getElementById('qty-minus').disabled = true;
    document.getElementById('qty-plus').disabled  = max <= 1;
    document.getElementById('qty-max').textContent = 'máx ' + max;
    // Update footer price
    actualizarTotal();
    document.getElementById('submitBtn').disabled = false;
    // Smooth scroll to client section
    setTimeout(() => {
        document.getElementById('section-cliente').scrollIntoView({behavior:'smooth', block:'nearest'});
    }, 80);
}

function setTipo(val, btn) {
    document.getElementById('tipo-cliente-val').value = val;
    document.querySelectorAll('[onclick^="setTipo"]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    actualizarCamposMedico(val);
}

function toggleExtra() {
    const extra = document.getElementById('extra-fields');
    const btn = document.getElementById('btn-extra');
    if (!extra || !btn) return;
    const isOpen = extra.style.display !== 'none';
    extra.style.display = isOpen ? 'none' : '';
    btn.classList.toggle('open', !isOpen);
}

function openExtra() {
    const extra = document.getElementById('extra-fields');
    const btn = document.getElementById('btn-extra');
    if (extra) extra.style.display = '';
    if (btn) btn.classList.add('open');
}

function fill(id, value) {
    const el = document.getElementById(id);
    if (el && value) el.value = value;
}

function fillEspecialidad(value) {
    if (!value) return;
    const sel = document.getElementById('ex-especialidad-sel');
    const hid = document.getElementById('ex-especialidad');
    const otro = document.getElementById('ex-especialidad-otro');
    if (!sel || !hid) return;
    const found = Array.from(sel.options).some(o => o.value === value);
    if (found) {
        sel.value = value;
        hid.value = value;
        if (otro) otro.style.display = 'none';
    } else {
        sel.value = '__otro__';
        hid.value = value;
        if (otro) {
            otro.style.display = '';
            otro.value = value;
        }
    }
}

function onEspChange(sel) {
    const otro = document.getElementById('ex-especialidad-otro');
    const hid  = document.getElementById('ex-especialidad');
    if (!hid) return;
    if (sel.value === '__otro__') {
        if (otro) {
            otro.style.display = '';
            otro.focus();
        }
        hid.value = '';
    } else {
        if (otro) otro.style.display = 'none';
        hid.value = sel.value;
    }
}

function actualizarCamposMedico(tipo) {
    const esMedico = tipo === 'medico';
    const bloqueMedico = document.getElementById('bloque-medico-fields');
    if (bloqueMedico) bloqueMedico.style.display = esMedico ? 'none' : '';

    if (esMedico) {
        const nomMedico = document.getElementById('ex-nom-medico');
        const telMedico = document.getElementById('ex-tel-medico');
        if (nomMedico) nomMedico.value = '';
        if (telMedico) telMedico.value = '';
    }
}

async function buscarCliente(tel) {
    const badge = document.getElementById('tel-badge');
    try {
        const resp = await fetch('?action=buscar_cliente&telefono=' + encodeURIComponent(tel));
        const d = await resp.json();
        if (!d.found) {
            if (badge) badge.style.display = 'none';
            return;
        }

        if (badge) badge.style.display = '';
        fill('cliente_nombre', d.nombre);
        fill('ex-email', d.email_factura);
        fill('ex-calle', d.calle);
        fill('ex-numero', d.numero);
        fill('ex-colonia', d.colonia);
        fill('ex-cp', d.cp);
        fill('ex-referencias', d.referencias);
        fill('ex-quien', d.quien_recibe);

        if (d.estado) {
            initUbicaciones({
                selectEstado: '#ex-estado',
                selectMunicipio: '#ex-ciudad',
                valorEstado: d.estado,
                valorMunicipio: d.ciudad || '',
                basePath: '<?= BASE_PATH ?>',
            });
        }

        const tipo = d.tipo_cliente || 'medico';
        const chip = Array.from(document.querySelectorAll('[onclick^="setTipo"]'))
            .find(c => c.dataset.value === tipo);
        if (chip) setTipo(tipo, chip);

        fillEspecialidad(d.especialidad || '');
        if (tipo === 'paciente') {
            fill('ex-nom-medico', d.nombre_medico);
            fill('ex-tel-medico', d.telefono_medico);
        }
        actualizarCamposMedico(tipo);
    } catch {
        if (badge) badge.style.display = 'none';
    }
}

// ── Client autofill ──────────────────────────────────────────────────────
let telTimer = null;
function onTelInput(input) {
    const raw = input.value.replace(/\D/g, '');
    const badge = document.getElementById('tel-badge');
    badge.style.display = 'none';
    clearTimeout(telTimer);
    if (raw.length >= 10) {
        telTimer = setTimeout(() => {
            buscarCliente(raw);
        }, 400);
    }
}

// ── Client search sheet ──────────────────────────────────────────────────
let srchTimer = null;
function abrirBusqueda() {
    document.getElementById('srch-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('srch-input').focus(), 180);
}

// Delegated tap/click — evita onclick inline con JSON.stringify que rompe en mobile
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('srch-results').addEventListener('click', function (e) {
        const item = e.target.closest('.srch-item');
        if (item) elegirCliente(item.dataset.tel, item.dataset.nombre);
    });
});
function cerrarBusqueda() {
    document.getElementById('srch-overlay').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('srch-input').value = '';
    document.getElementById('srch-results').innerHTML = '<div class="srch-empty">Escribe al menos 2 caracteres para buscar</div>';
}
function onOverlayClick(e) {
    if (e.target === document.getElementById('srch-overlay')) cerrarBusqueda();
}
function onSrchInput(q) {
    clearTimeout(srchTimer);
    if (q.length < 2) {
        document.getElementById('srch-results').innerHTML = '<div class="srch-empty">Escribe al menos 2 caracteres para buscar</div>';
        return;
    }
    srchTimer = setTimeout(() => {
        fetch('?action=buscar_clientes_texto&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(items => {
                const el = document.getElementById('srch-results');
                if (!items.length) {
                    el.innerHTML = '<div class="srch-empty">Sin resultados</div>';
                    return;
                }
                el.innerHTML = items.map(c => `
                    <div class="srch-item" data-tel="${escHtml(c.telefono)}" data-nombre="${escHtml(c.nombre || '')}">
                        <div class="srch-avatar">${c.nombre ? c.nombre.charAt(0).toUpperCase() : '?'}</div>
                        <div class="srch-item-info">
                            <div class="srch-item-name">${escHtml(c.nombre || '—')}</div>
                            <div class="srch-item-meta">${escHtml([c.ciudad, c.estado].filter(Boolean).join(', ') || '')}</div>
                        </div>
                        <div class="srch-item-tel">${escHtml(c.telefono)}</div>
                    </div>`).join('');
            });
    }, 300);
}
function elegirCliente(tel, nombre) {
    document.getElementById('telefono').value = tel;
    if (nombre) document.getElementById('cliente_nombre').value = nombre;
    buscarCliente(tel);
    cerrarBusqueda();
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Prevent double-submit ────────────────────────────────────────────────
document.getElementById('kitForm')?.addEventListener('submit', function(e) {
    const tipo = document.getElementById('tipo-cliente-val')?.value || 'medico';
    const especialidad = (document.getElementById('ex-especialidad')?.value || '').trim();
    if (tipo === 'medico' && !especialidad) {
        e.preventDefault();
        openExtra();
        showToast('Si el tipo de cliente es Médico, la especialidad es obligatoria.', 'warning');
        const espSel = document.getElementById('ex-especialidad-sel');
        if (espSel) espSel.focus();
        return;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px;">'
        + '<svg style="animation:spin .8s linear infinite;width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">'
        + '<circle cx="12" cy="12" r="10" stroke-opacity=".3"/>'
        + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>'
        + '</svg>Procesando…</span>';
    btn.style.opacity = '0.75';
});

document.addEventListener('DOMContentLoaded', () => {
    initUbicaciones({
        selectEstado: '#ex-estado',
        selectMunicipio: '#ex-ciudad',
        basePath: '<?= BASE_PATH ?>',
    });
    actualizarCamposMedico('medico');
});
</script>
<script src="<?= BASE_PATH ?>js/ui-toast.js"></script>
<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
</body>
</html>
