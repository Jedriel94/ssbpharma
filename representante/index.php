<?php
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/Configuracion.php';
require_once __DIR__ . '/../models/RepresentanteInventario.php';
require_once __DIR__ . '/../models/SolicitudConsignacion.php';
require_once __DIR__ . '/../models/Pedido.php';
require_once __DIR__ . '/../models/Cliente.php';

// ── AJAX: buscar clientes por texto (nombre/tel/ciudad) ────────────────────
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

// ── AJAX: buscar cliente por teléfono (para autofill en modal) ────────────
if (($_GET['action'] ?? '') === 'buscar_cliente_modal' && isset($_GET['tel'])) {
    header('Content-Type: application/json');
    $clienteModel = new Cliente();
    $tel = preg_replace('/\D+/', '', $_GET['tel']);
    $c = $tel ? $clienteModel->getByTelefono($tel) : null;
    if ($c) {
        echo json_encode([
            'found'          => true,
            'nombre'         => $c['nombre'] ?? '',
            'tipo_cliente'   => $c['tipo_cliente'] ?? 'medico',
            'especialidad'   => $c['especialidad'] ?? '',
            'calle'          => $c['calle'] ?? '',
            'numero'         => $c['numero'] ?? '',
            'colonia'        => $c['colonia'] ?? '',
            'cp'             => $c['cp'] ?? '',
            'ciudad'         => $c['ciudad'] ?? '',
            'estado'         => $c['estado'] ?? '',
            'referencias'    => $c['referencias'] ?? '',
            'quien_recibe'   => $c['quien_recibe'] ?? '',
            'nombre_medico'  => $c['nombre_medico'] ?? '',
            'telefono_medico'=> $c['telefono_medico'] ?? '',
            'email_factura'  => $c['email_factura'] ?? '',
            'rfc'            => $c['rfc'] ?? '',
            'razon_social'   => $c['razon_social'] ?? '',
            'codigo_postal'  => $c['codigo_postal'] ?? '',
            'uso_cfdi'       => $c['uso_cfdi'] ?? '',
            'regimen_fiscal' => $c['regimen_fiscal'] ?? '',
        ]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ── AJAX: guardar / actualizar cliente ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_cliente') {
    header('Content-Type: application/json');
    $clienteModel = new Cliente();
    $pdo = Database::getInstance()->getConnection();

    $tel    = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
    $nombre = trim(strip_tags($_POST['nombre'] ?? ''));

    if (strlen($tel) !== 10) {
        echo json_encode(['success' => false, 'message' => 'Telefono invalido: debe tener exactamente 10 digitos']);
        exit;
    }

    // Crear si no existe
    $cliente = $clienteModel->getByTelefono($tel);
    $es_nuevo = false;
    if (!$cliente) {
        $clienteModel->create($tel, $nombre ?: null);
        $cliente = $clienteModel->getByTelefono($tel);
        $es_nuevo = true;
        // Asignar representante
        if ($cliente && empty($cliente['representante_admin_id'])) {
            $pdo->prepare("UPDATE clientes SET representante_admin_id=? WHERE id=?")->execute([$representanteAdminId, $cliente['id']]);
        }
    }

    if (!$cliente) {
        echo json_encode(['success' => false, 'message' => 'No se pudo crear el cliente']);
        exit;
    }

    // Actualizar campos extra (solo los que se enviaron no-vacíos)
    $campos = ['nombre', 'tipo_cliente', 'especialidad', 'calle', 'numero', 'colonia', 'cp', 'ciudad', 'estado', 'referencias', 'quien_recibe', 'nombre_medico', 'telefono_medico', 'email_factura', 'rfc', 'razon_social', 'codigo_postal', 'uso_cfdi', 'regimen_fiscal'];
    $sets = []; $vals = [];
    foreach ($campos as $campo) {
        $val = trim($_POST[$campo] ?? '');
        if ($val !== '') { $sets[] = "`$campo` = ?"; $vals[] = $val; }
    }
    // Manejar constancia fiscal (archivo)
    $constanciaGuardada = '';
    if (!empty($_FILES['cli_constancia']['tmp_name'])) {
        $file = $_FILES['cli_constancia'];
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if (in_array($file['type'], $allowedTypes)) {
            // Misma carpeta y mismo formato de nombre que mis-datos.php, para que
            // descargar-fiscal.php (que valida sesion) pueda servir el archivo.
            $uploadDir = uploads_dir_privado('fiscales') . '/';
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fname = 'constancia_' . $tel . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fname)) {
                $sets[] = '`constancia_fiscal` = ?';
                $vals[] = $fname;
                $constanciaGuardada = $fname;
            }
        }
    }
    if (!empty($sets)) {
        $vals[] = $cliente['id'];
        $pdo->prepare('UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    $nombre_final = trim($_POST['nombre'] ?? '') ?: ($cliente['nombre'] ?? $tel);
    echo json_encode([
        'success'  => true,
        'es_nuevo' => $es_nuevo,
        'nombre'   => $nombre_final,
        'telefono' => $tel,
        'message'  => $es_nuevo ? "Cliente registrado" : "Cliente actualizado",
    ]);
    exit;
}

// ── AJAX: métricas del período ────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'metricas') {
    header('Content-Type: application/json');
    $periodo = $_GET['periodo'] ?? 'mes';
    $desde_c = $_GET['desde'] ?? '';
    $hasta_c = $_GET['hasta'] ?? '';
    $canal_f = $_GET['canal'] ?? 'todos'; // todos | directo | qr
    // Umbral de estados contables — igual que el dashboard de gerentes
    require_once __DIR__ . '/../models/Configuracion.php';
    $estado_conf = Configuracion::get('dashboard_estado_ventas', 'entregado');
    $estadoInMap = [
        'confirmado' => "'confirmado','en_ruta','entregado'",
        'en_ruta'    => "'en_ruta','entregado'",
        'entregado'  => "'entregado'",
    ];
    $estadoIn = $estadoInMap[$estado_conf] ?? "'entregado'";
    $hoy = date('Y-m-d');
    // Condición de canal
    if ($canal_f === 'directo') {
        $canal_cond = "AND p.canal = 'representante_directo'";
    } elseif ($canal_f === 'qr') {
        $canal_cond = "AND p.canal != 'representante_directo'"; // tienda/QR: todo lo que no es captura manual
    } else {
        $canal_cond = ''; // todos: sin filtro de canal, solo por representante_admin_id
    }
    switch ($periodo) {
        case 'hoy':
            $d1 = $h1 = $hoy;
            $d2 = $h2 = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'semana':
            $dn = (int)date('N');
            $d1 = date('Y-m-d', strtotime('-' . ($dn - 1) . ' days'));
            $h1 = $hoy;
            $d2 = date('Y-m-d', strtotime('-' . ($dn + 6) . ' days'));
            $h2 = date('Y-m-d', strtotime('-' . $dn . ' days'));
            break;
        case 'mes_anterior':
            $_lm = date('Y-m', strtotime('-1 month'));
            $_2m = date('Y-m', strtotime('-2 months'));
            $d1  = $_lm . '-01'; $h1 = date('Y-m-t', strtotime($_lm . '-01'));
            $d2  = $_2m . '-01'; $h2 = date('Y-m-t', strtotime($_2m . '-01'));
            break;
        case 'personalizado':
            $d1 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde_c) ? $desde_c : date('Y-m-01');
            $h1 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta_c) ? $hasta_c : $hoy;
            $diff = max(0, (int)round((strtotime($h1) - strtotime($d1)) / 86400));
            $h2 = date('Y-m-d', strtotime($d1) - 86400);
            $d2 = date('Y-m-d', strtotime($h2) - $diff * 86400);
            break;
        default: // mes
            $d1 = date('Y-m-01'); $h1 = $hoy;
            $_pm = date('Y-m', strtotime('-1 month'));
            $d2 = $_pm . '-01'; $h2 = date('Y-m-t', strtotime($_pm . '-01'));
    }
    $sin_iva = isset($_GET['sin_iva']) && $_GET['sin_iva'] === '1';
    $pdo2 = Database::getInstance()->getConnection();
    $rid2 = $representanteAdminId;
    // Sin IVA: usar SUM(subtotal/(1+impuesto)) en lugar de SUM(subtotal)
    $subtotalExpr = $sin_iva
        ? 'SUM(subtotal / (1 + impuesto))'
        : 'SUM(subtotal)';
    $sql_m = "SELECT COUNT(DISTINCT p.id) as ordenes,
        COALESCE(SUM(GREATEST(COALESCE(dd.subtotal_productos,0) - COALESCE(p.cupon_descuento,0), 0)),0) as pesos,
        COUNT(DISTINCT p.cliente_id) as clientes,
        COALESCE(SUM(dd.piezas),0) as piezas,
        COALESCE(SUM(p.requiere_factura=1),0) as con_factura,
        COALESCE(SUM(p.estado='entregado'),0) as entrega_directa
      FROM pedidos p
      LEFT JOIN (
        SELECT pedido_id, SUM(cantidad) as piezas, {$subtotalExpr} as subtotal_productos
        FROM detalle_pedidos
        GROUP BY pedido_id
      ) dd ON dd.pedido_id=p.id
      WHERE p.representante_admin_id=? {$canal_cond}
        AND p.estado IN ({$estadoIn}) AND DATE(p.created_at) BETWEEN ? AND ?";
    $st = $pdo2->prepare($sql_m);
    $st->execute([$rid2, $d1, $h1]); $actual   = $st->fetch(PDO::FETCH_ASSOC);
    $st->execute([$rid2, $d2, $h2]); $anterior = $st->fetch(PDO::FETCH_ASSOC);
    $sql_top = "SELECT pr.producto, pr.imagen, COALESCE(SUM(dd.cantidad),0) as piezas
      FROM pedidos p
      JOIN detalle_pedidos dd ON dd.pedido_id=p.id
      JOIN productos pr ON pr.id=dd.producto_id
      WHERE p.representante_admin_id=? {$canal_cond}
        AND p.estado IN ({$estadoIn}) AND DATE(p.created_at) BETWEEN ? AND ?
      GROUP BY pr.id ORDER BY piezas DESC LIMIT 5";
    $st2 = $pdo2->prepare($sql_top);
    $st2->execute([$rid2, $d1, $h1]);
    $top = array_map(function($r) {
        $r['imagen_url'] = !empty($r['imagen']) ? uploads_url('productos/' . $r['imagen']) : null;
        return $r;
    }, $st2->fetchAll(PDO::FETCH_ASSOC));
    $sql_tipo = "SELECT c.tipo_cliente, COUNT(DISTINCT p.cliente_id) as n
      FROM pedidos p JOIN clientes c ON c.id=p.cliente_id
      WHERE p.representante_admin_id=? {$canal_cond}
        AND p.estado IN ({$estadoIn}) AND DATE(p.created_at) BETWEEN ? AND ?
      GROUP BY c.tipo_cliente";
    $st3 = $pdo2->prepare($sql_tipo);
    $st3->execute([$rid2, $d1, $h1]);
    $tipo = [];
    foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $row) $tipo[$row['tipo_cliente']] = (int)$row['n'];
    $dlt = fn($a, $b) => $b == 0 ? ($a > 0 ? 100.0 : 0.0) : round(($a - $b) / $b * 100, 1);
    echo json_encode([
        'pesos'           => (float)$actual['pesos'],
        'piezas'          => (int)$actual['piezas'],
        'ordenes'         => (int)$actual['ordenes'],
        'clientes'        => (int)$actual['clientes'],
        'entrega_directa' => (int)$actual['entrega_directa'],
        'ticket'          => $actual['ordenes'] > 0 ? round($actual['pesos'] / $actual['ordenes'], 2) : 0,
        'pct_factura'     => $actual['ordenes'] > 0 ? round($actual['con_factura'] / $actual['ordenes'] * 100) : 0,
        'pesos_anterior'  => (float)$anterior['pesos'],
        'delta_pesos'     => $dlt((float)$actual['pesos'],  (float)$anterior['pesos']),
        'delta_piezas'    => $dlt((int)$actual['piezas'],   (int)$anterior['piezas']),
        'delta_ordenes'   => $dlt((int)$actual['ordenes'],  (int)$anterior['ordenes']),
        'top_productos'   => $top,
        'tipo_medico'     => $tipo['medico']   ?? 0,
        'tipo_paciente'   => $tipo['paciente'] ?? 0,
    ]);
    exit;
}

// ── AJAX: lista de pedidos del período (para modal del card) ──────────────
if (($_GET['action'] ?? '') === 'pedidos_entrega') {
    header('Content-Type: application/json');
    $periodo = $_GET['periodo'] ?? 'mes';
    $desde_c = $_GET['desde'] ?? '';
    $hasta_c = $_GET['hasta'] ?? '';
    $canal_f = $_GET['canal'] ?? 'todos';
    $hoy = date('Y-m-d');
    if ($canal_f === 'directo') {
        $canal_cond = "AND p.canal = 'representante_directo'";
    } elseif ($canal_f === 'qr') {
        $canal_cond = "AND p.canal != 'representante_directo'";
    } else {
        $canal_cond = '';
    }
    switch ($periodo) {
        case 'hoy':    $d1 = $h1 = $hoy; break;
        case 'semana': $d1 = date('Y-m-d', strtotime('monday this week')); $h1 = $hoy; break;
        case 'mes':    $d1 = date('Y-m-01'); $h1 = $hoy; break;
        case 'personalizado':
            $d1 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde_c) ? $desde_c : date('Y-m-01');
            $h1 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta_c) ? $hasta_c : $hoy;
            break;
        default: $d1 = date('Y-m-01'); $h1 = $hoy;
    }
    $pdo2 = Database::getInstance()->getConnection();
    $sql = "SELECT p.id,
                   GREATEST(COALESCE(dd.subtotal_productos,0) - COALESCE(p.cupon_descuento,0), 0) as total,
                   p.estado, p.canal, p.metodo_pago, DATE(p.created_at) as fecha,
                   c.nombre as cliente_nombre, c.telefono as cliente_telefono
            FROM pedidos p
            INNER JOIN clientes c ON c.id = p.cliente_id
            LEFT JOIN (
                SELECT pedido_id, SUM(subtotal) as subtotal_productos
                FROM detalle_pedidos
                GROUP BY pedido_id
            ) dd ON dd.pedido_id = p.id
            WHERE p.representante_admin_id = ? {$canal_cond}
              AND p.estado IN ({$estadoIn})
              AND DATE(p.created_at) BETWEEN ? AND ?
            ORDER BY p.created_at DESC";
    $st = $pdo2->prepare($sql);
    $st->execute([$representanteAdminId, $d1, $h1]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Cargar especialidades para el modal ───────────────────────────────────
$_esp = Database::getInstance()->getConnection()->query("SELECT nombre FROM especialidades WHERE activo=1 ORDER BY orden,nombre");
$especialidades_idx = $_esp ? $_esp->fetchAll(PDO::FETCH_COLUMN) : [];

$inventarioModel = new RepresentanteInventario();
$solicitudModel = new SolicitudConsignacion();
$pedidoModel = new Pedido();
$db = Database::getInstance()->getConnection();

// Solo necesitamos saber si tiene dirección (para aviso onboarding)
require_once __DIR__ . '/../models/RepresentantePerfil.php';
$_pf = (new RepresentantePerfil())->getByAdminId($representanteAdminId);
$_sinDireccion = empty(trim($_pf['dir_calle'] ?? ''));
unset($_pf);

$resumenInventario = $inventarioModel->getResumenPorAdmin($representanteAdminId);
$inventario = $inventarioModel->getInventarioPorAdmin($representanteAdminId, true);
$solicitudes = $solicitudModel->getByRepresentanteAdmin($representanteAdminId, 5);

$stmt = $db->prepare("
    SELECT
        COUNT(*) as ventas_hoy,
        COALESCE(SUM(GREATEST(COALESCE(dd.subtotal_productos,0) - COALESCE(p.cupon_descuento,0), 0)), 0) as total_hoy
    FROM pedidos p
    LEFT JOIN (
        SELECT pedido_id, SUM(subtotal) as subtotal_productos
        FROM detalle_pedidos
        GROUP BY pedido_id
    ) dd ON dd.pedido_id = p.id
    WHERE p.representante_admin_id = ?
      AND DATE(p.created_at) = CURDATE()
");
$stmt->execute([$representanteAdminId]);
$ventasHoy = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT
        COUNT(*) as pagos_pendientes,
        COALESCE(SUM(GREATEST(COALESCE(dd.subtotal_productos,0) - COALESCE(p.cupon_descuento,0), 0)), 0) as monto_pendiente
    FROM pedidos p
    LEFT JOIN (
        SELECT pedido_id, SUM(subtotal) as subtotal_productos
        FROM detalle_pedidos
        GROUP BY pedido_id
    ) dd ON dd.pedido_id = p.id
    WHERE p.representante_admin_id = ?
      AND (
          p.estado IN ('pendiente', 'por_verificar')
          OR p.estado_liquidacion = 'pendiente'
      )
");
$stmt->execute([$representanteAdminId]);
$pagosPendientes = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT
        p.id,
        GREATEST(COALESCE(dd.subtotal_productos,0) - COALESCE(p.cupon_descuento,0), 0) as total,
        p.estado,
        p.canal,
        p.metodo_pago,
        p.estado_liquidacion,
        p.requiere_factura,
        p.created_at,
        c.nombre as cliente_nombre,
        c.telefono as cliente_telefono
    FROM pedidos p
    INNER JOIN clientes c ON c.id = p.cliente_id
    LEFT JOIN (
        SELECT pedido_id, SUM(subtotal) as subtotal_productos
        FROM detalle_pedidos
        GROUP BY pedido_id
    ) dd ON dd.pedido_id = p.id
    WHERE p.representante_admin_id = ?
    ORDER BY p.created_at DESC
    LIMIT 6
");
$stmt->execute([$representanteAdminId]);
$ventasRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function money_rep($value) {
    return '$' . number_format((float)$value, 2);
}

function estado_label_rep($estado) {
    $labels = [
        'pendiente' => 'Pendiente',
        'por_verificar' => 'Por verificar',
        'confirmado' => 'Confirmado',
        'en_ruta' => 'En ruta',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
        'solicitada' => 'Solicitada',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
        'preparando' => 'Preparando',
        'en_transito' => 'En transito',
        'entregada' => 'Entregada',
    ];
    return $labels[$estado] ?? ucfirst((string)$estado);
}

$_rep_protocolo   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_rep_host        = $_SERVER['HTTP_HOST'];
$_rep_base        = rtrim(str_replace('/representante', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
$_rep_enlace      = $_rep_protocolo . '://' . $_rep_host . $_rep_base . '/r/' . $representanteCodigo;
$_rep_qr_url      = 'https://api.qrserver.com/v1/create-qr-code/?size=350x350&data=' . urlencode($_rep_enlace);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Representante | Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
    <style>
        :root {
            --ink: #102040;
            --muted: #6a90b8;
            --paper: #f0f5fa;
            --panel: #ffffff;
            --line: #bfcfe8;
            --field: #eef4fa;
            --brand: #4a70a9;
            --brand-dark: #3a5a90;
            --accent: #8fabd4;
            --warn: #9a5b13;
            --danger: #b42318;
        }

        * { box-sizing: border-box; }

        html { overflow-x: hidden; }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background:
                linear-gradient(135deg, rgba(18,108,106,.08), transparent 34%),
                linear-gradient(315deg, rgba(216,111,77,.10), transparent 28%),
                var(--paper);
            color: var(--ink);
        }

        .shell {
            width: min(100%, 960px);
            margin: 0 auto;
            padding: 18px 14px 96px;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            margin: -18px -14px 14px;
            padding: calc(14px + env(safe-area-inset-top)) 14px 12px;
            background: rgba(251,250,247,.92);
            border-bottom: 1px solid rgba(230,224,214,.85);
            backdrop-filter: blur(16px);
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .brand-lockup {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 10px;
        }

        .mark {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: var(--brand);
            color: white;
            font-weight: 900;
            letter-spacing: .5px;
            box-shadow: 0 10px 28px rgba(18,108,106,.25);
            flex: 0 0 auto;
        }

        .eyebrow {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: 21px;
            line-height: 1.05;
            letter-spacing: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .logout {
            min-width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--line);
            display: grid;
            place-items: center;
            color: var(--ink);
            text-decoration: none;
            background: white;
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 14px 0;
        }

        .metric {
            min-height: 104px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
            background: rgba(255,255,255,.88);
            box-shadow: 0 12px 30px rgba(16,24,32,.06);
        }

        .metric strong {
            display: block;
            margin-top: 8px;
            font-size: 25px;
            line-height: 1;
            letter-spacing: 0;
        }

        .metric span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 14px 0 18px;
        }

        .action {
            min-height: 72px;
            border-radius: 8px;
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 0;
            text-decoration: none;
            color: white;
            background: var(--brand);
            box-shadow: 0 14px 30px rgba(18,108,106,.25);
        }

        .action.secondary {
            color: var(--ink);
            background: white;
            border: 1px solid var(--line);
            box-shadow: 0 10px 22px rgba(16,24,32,.06);
        }

        .action-icon {
            width: 38px;
            height: 38px;
            flex: 0 0 auto;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,.16);
        }

        .secondary .action-icon { background: var(--field); }

        #accionesRapidas > * { min-height: 72px; }
        .action b {
            display: block;
            font-size: 15px;
            line-height: 1.15;
        }

        .action small {
            display: block;
            margin-top: 3px;
            color: inherit;
            opacity: .72;
            font-size: 12px;
        }

        .section {
            margin-top: 18px;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 9px;
        }

        .section h2 {
            margin: 0;
            font-size: 16px;
            letter-spacing: 0;
        }

        .section-link {
            color: var(--brand-dark);
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
        }

        .list {
            display: grid;
            gap: 8px;
        }

        .row {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            background: rgba(255,255,255,.9);
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 66px;
        }

        .thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: var(--field);
            overflow: hidden;
            flex: 0 0 auto;
            display: grid;
            place-items: center;
            color: var(--muted);
            font-weight: 900;
        }

        .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .row-main {
            min-width: 0;
            flex: 1;
            overflow: hidden;
        }

        .row-title {
            display: block;
            font-size: 14px;
            font-weight: 900;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .row-sub {
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
        }

        .qty {
            min-width: 54px;
            min-height: 42px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: var(--field);
            color: var(--brand-dark);
            font-size: 18px;
            font-weight: 900;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 26px;
            padding: 0 9px;
            border-radius: 999px;
            background: var(--field);
            color: var(--ink);
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .badge.warn {
            background: #fff3d6;
            color: var(--warn);
        }

        .badge.danger {
            background: #fee4e2;
            color: var(--danger);
        }

        .empty {
            border: 1px dashed #d4cbbb;
            border-radius: 8px;
            padding: 18px;
            background: rgba(255,255,255,.52);
            color: var(--muted);
            font-size: 14px;
        }

        /* bottom-nav → representante.css */

        .nav-item svg,
        .action-icon svg,
        .logout svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        @media (min-width: 700px) {
            .shell {
                padding-inline: 24px;
            }

            .topbar {
                margin-inline: -24px;
                padding-inline: 24px;
            }

            .metric-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .actions {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        /* ─── Dashboard de métricas ─── */
        .dash { margin: 12px 0 0; }
        .dash-periods-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            margin: 0 -14px;
            padding: 0 14px 1px;
        }
        .dash-periods-wrap::-webkit-scrollbar { display: none; }
        .dash-periods { display: flex; gap: 6px; width: max-content; }
        .period-chip {
            height: 34px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1.5px solid var(--line);
            background: white;
            color: var(--muted);
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            white-space: nowrap;
            transition: all .15s;
            font-family: inherit;
        }
        .period-chip.active {
            background: var(--brand);
            border-color: var(--brand);
            color: white;
            box-shadow: 0 4px 14px rgba(18,108,106,.28);
        }
        .canal-chips { display: flex; gap: 6px; padding: 4px 0 8px; flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; }
        .canal-chip {
            height: 28px; padding: 0 12px; border-radius: 999px;
            border: 1.5px solid var(--line); background: white;
            color: var(--muted); font-size: 12px; font-weight: 900;
            cursor: pointer; white-space: nowrap; font-family: inherit;
        }
        .canal-chip.active { background: var(--ink); border-color: var(--ink); color: white; }
        .dash-custom {
            display: none;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .dash-custom.open { display: flex; }
        .dash-date-input {
            flex: 1;
            min-width: 130px;
            height: 40px;
            border: 1.5px solid var(--line);
            border-radius: 8px;
            padding: 0 10px;
            font-size: 14px;
            color: var(--ink);
            background: white;
            outline: none;
            font-family: inherit;
        }
        .dash-date-input:focus { border-color: var(--brand); }
        .dash-arrow { color: var(--muted); font-size: 14px; }
        .dash-date-apply {
            height: 40px;
            padding: 0 18px;
            background: var(--brand);
            color: white;
            border: 0;
            border-radius: 8px;
            font-weight: 900;
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
        }
        /* Hero card */
        .hero-card {
            margin-top: 10px;
            background: white;
            border-radius: 16px;
            padding: 20px 20px 16px 24px;
            border: 1px solid var(--line);
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(16,24,32,.08);
            transition: opacity .2s;
        }
        .hero-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 0% 110%, rgba(18,108,106,.12) 0%, transparent 55%),
                radial-gradient(ellipse at 100% -10%, rgba(216,111,77,.08) 0%, transparent 45%);
            pointer-events: none;
        }
        .hero-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 5px; height: 100%;
            background: linear-gradient(to bottom, var(--brand) 40%, var(--accent));
            border-radius: 16px 0 0 16px;
        }
        .hero-label {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .07em;
        }
        .hero-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
            flex-wrap: wrap;
        }
        .hero-num {
            font-size: 40px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -.025em;
            color: var(--ink);
        }
        .hero-delta {
            height: 26px;
            padding: 0 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            font-size: 12px;
            font-weight: 900;
        }
        .hero-delta.up   { background: #d1fae5; color: #065f46; }
        .hero-delta.down { background: #fee4e2; color: var(--danger); }
        .hero-delta.flat { background: var(--field); color: var(--muted); }
        .hero-sub {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--muted);
        }
        .hero-dot { opacity: .4; }
        .hero-sub strong { color: var(--ink); font-weight: 900; }
        /* KPI grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 8px;
        }
        .kpi-card {
            background: white;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 14px 12px;
            box-shadow: 0 4px 16px rgba(16,24,32,.05);
            animation: kpiFadeUp .4s ease both;
            animation-delay: var(--kd, 0s);
        }
        @keyframes kpiFadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .kpi-icon { font-size: 18px; line-height: 1; margin-bottom: 6px; }
        .kpi-num {
            font-size: 22px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -.01em;
            color: var(--ink);
        }
        .kpi-label {
            margin-top: 4px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .05em;
        }
        .dash-section-head {
            margin-top: 16px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .07em;
            margin-bottom: 8px;
        }
        /* Top productos */
        .top-strip {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            margin: 0 -14px;
            padding: 0 14px 4px;
        }
        .top-strip::-webkit-scrollbar { display: none; }
        .top-card {
            flex: 0 0 112px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 12px 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            box-shadow: 0 3px 12px rgba(16,24,32,.05);
        }
        .top-card-thumb {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--field);
            display: grid;
            place-items: center;
            font-weight: 900;
            font-size: 15px;
            color: var(--brand-dark);
            overflow: hidden;
            flex-shrink: 0;
        }
        .top-card-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .top-card-name {
            font-size: 11px;
            font-weight: 900;
            line-height: 1.35;
            color: var(--ink);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 28px;
        }
        .top-card-piezas { font-size: 22px; font-weight: 900; color: var(--brand); line-height: 1; }
        .top-card-sub { font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: .04em; }
        .top-bar-bg { height: 4px; background: var(--field); border-radius: 2px; overflow: hidden; }
        .top-bar-fill { height: 100%; background: var(--accent); border-radius: 2px; transition: width .6s cubic-bezier(.4,0,.2,1); width: 0; }
        .dash-top-empty { padding: 16px 0; color: var(--muted); font-size: 13px; }
        /* Tipo split */
        .tipo-wrap {
            background: white;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 4px 16px rgba(16,24,32,.05);
            margin-bottom: 6px;
        }
        .tipo-wrap .dash-section-head { margin-top: 0; margin-bottom: 10px; }
        .tipo-row { display: flex; align-items: center; gap: 8px; }
        .tipo-bar {
            flex: 1;
            height: 10px;
            border-radius: 5px;
            background: var(--field);
            overflow: hidden;
            display: flex;
        }
        .tipo-fill { height: 100%; transition: flex .6s cubic-bezier(.4,0,.2,1); }
        .tipo-fill-med { background: var(--brand); }
        .tipo-fill-pac { background: var(--accent); }
        .tipo-lbl { font-size: 11px; font-weight: 700; color: var(--muted); white-space: nowrap; }
        .tipo-lbl-med strong { color: var(--brand); }
        .tipo-lbl-pac strong { color: var(--accent); }
        @media (min-width: 600px) {
            .kpi-grid { grid-template-columns: repeat(4, 1fr); }
            .hero-num { font-size: 46px; }
        }
        /* ── Modal alta de cliente ── */
        .cli-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(16,24,32,.48);
            backdrop-filter: blur(4px);
            align-items: flex-end;
            justify-content: center;
        }
        .cli-overlay.open { display: flex; }
        .cli-sheet {
            width: 100%;
            max-height: 92dvh;
            background: var(--panel);
            border-radius: 20px 20px 0 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 -8px 40px rgba(16,24,32,.2);
            animation: cliUp .22s cubic-bezier(.33,1,.68,1) forwards;
        }
        @keyframes cliUp {
            from { transform: translateY(100%); }
            to   { transform: translateY(0); }
        }
        .cli-handle {
            width: 40px; height: 4px;
            background: #ddd; border-radius: 2px;
            margin: 10px auto 0; flex-shrink: 0;
        }
        .cli-header {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px 10px;
            border-bottom: 1px solid var(--line);
            flex-shrink: 0;
        }
        .cli-title { font-weight: 900; font-size: 16px; flex: 1; }
        .cli-close {
            min-width: 36px; min-height: 36px;
            border: 0; background: var(--field);
            border-radius: 8px; font-size: 18px;
            cursor: pointer; display: grid; place-items: center;
        }
        .cli-body {
            overflow-y: auto; flex: 1;
            padding: 16px 16px 32px;
            -webkit-overflow-scrolling: touch;
        }
        .cli-success {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 40px 24px;
            text-align: center;
        }
        .cli-success-icon {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: #dcfce7;
            display: grid; place-items: center;
            font-size: 32px;
        }
        .cli-grid { display: grid; gap: 12px; }
        .cli-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .cli-label {
            display: block; font-size: 11px; font-weight: 900;
            text-transform: uppercase; color: var(--muted); margin-bottom: 5px;
        }
        .cli-input {
            width: 100%; min-height: 48px;
            border: 1.5px solid #d8d0c3; border-radius: 10px;
            background: #fff; color: var(--ink);
            padding: 10px 12px; font-size: 16px; outline: none;
            box-sizing: border-box;
        }
        .cli-input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(18,108,106,.12); }
        .cli-badge-ok {
            display: inline-block;
            font-size: 11px; font-weight: 900;
            background: #d1fae5; color: #065f46;
            padding: 2px 8px; border-radius: 99px; margin-left: 6px;
        }
        .cli-chips { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
        .cli-chip {
            min-height: 44px; padding: 0 16px;
            border: 1.5px solid #d8d0c3; border-radius: 8px;
            background: white; color: var(--ink);
            font-size: 14px; font-weight: 700; cursor: pointer;
            transition: all .15s;
        }
        .cli-chip.active { border-color: var(--brand); background: rgba(18,108,106,.1); color: var(--brand); }
        .cli-section-toggle {
            display: block; width: 100%; min-height: 42px;
            margin-top: 4px; padding: 0 14px;
            border: 1px dashed #d4cbbb; border-radius: 8px;
            background: transparent; color: var(--muted);
            font-size: 13px; font-weight: 900; cursor: pointer;
            text-align: left; transition: border-color .15s, color .15s;
        }
        .cli-section-toggle.open { border-color: var(--brand); color: var(--brand); }
        .cli-collapsible { display: none; margin-top: 10px; }
        .cli-collapsible.open { display: grid; gap: 10px; }
        .cli-submit {
            display: block; width: 100%; min-height: 54px;
            margin-top: 16px; border: 0; border-radius: 10px;
            background: var(--brand); color: white;
            font-size: 16px; font-weight: 900; cursor: pointer;
            box-shadow: 0 8px 24px rgba(18,108,106,.22);
            transition: background .15s;
        }
        .cli-submit {
            display: block; width: 100%; min-height: 54px;
            margin-top: 16px; border: 0; border-radius: 10px;
            background: var(--brand); color: white;
            font-size: 16px; font-weight: 900; cursor: pointer;
            box-shadow: 0 8px 24px rgba(18,108,106,.22);
            transition: background .15s;
        }
        .cli-submit:disabled { opacity: .6; cursor: default; }
        @keyframes spin { to { transform:rotate(360deg); } }
        /* Botón buscar */
        .cli-btn-buscar {
            min-height: 48px;
            padding: 0 14px;
            border: 1.5px solid var(--brand);
            border-radius: 8px;
            background: white;
            color: var(--brand);
            font-weight: 900;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            transition: background .12s, color .12s;
        }
        .cli-btn-buscar:active { background: var(--brand); color: white; }
        /* Overlay búsqueda de clientes */
        .cli-srch-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 310;
            background: rgba(16,24,32,.45);
            backdrop-filter: blur(3px);
            align-items: flex-end;
            justify-content: center;
        }
        .cli-srch-overlay.open { display: flex; }
        .cli-srch-sheet {
            width: 100%;
            max-height: 88dvh;
            background: white;
            border-radius: 20px 20px 0 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 -8px 40px rgba(16,24,32,.18);
            animation: sheetUp .22s cubic-bezier(.33,1,.68,1) forwards;
        }
        .cli-srch-handle {
            width: 40px;
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            margin: 10px auto 0;
            flex-shrink: 0;
        }
        .cli-srch-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px 10px;
            border-bottom: 1px solid #e8e0d5;
            flex-shrink: 0;
        }
        .cli-srch-header span { font-weight: 900; font-size: 15px; }
        .cli-srch-close {
            margin-left: auto;
            min-width: 36px;
            min-height: 36px;
            border: 0;
            background: #f3ede5;
            border-radius: 8px;
            font-size: 18px;
            cursor: pointer;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .cli-srch-input-wrap {
            padding: 12px 16px;
            flex-shrink: 0;
        }
        .cli-srch-input-wrap input {
            width: 100%;
            box-sizing: border-box;
            min-height: 52px;
            font-size: 17px;
            border: 2px solid var(--brand);
            box-shadow: 0 0 0 4px rgba(18,108,106,.1);
            border-radius: 12px;
            padding: 10px 14px;
            outline: none;
        }
        .cli-srch-results {
            overflow-y: auto;
            flex: 1;
            padding: 0 16px 24px;
            -webkit-overflow-scrolling: touch;
        }
        .cli-srch-empty {
            padding: 24px 0;
            text-align: center;
            color: var(--muted);
            font-size: 14px;
        }
        .cli-srch-item {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 64px;
            padding: 12px 14px;
            margin-bottom: 6px;
            border: 1.5px solid #e8e0d5;
            border-radius: 12px;
            background: #fffdf9;
            cursor: pointer;
            transition: border-color .12s, background .12s;
            -webkit-tap-highlight-color: transparent;
        }
        .cli-srch-item:active, .cli-srch-item:hover {
            border-color: var(--brand);
            background: rgba(18,108,106,.06);
        }
        .cli-srch-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--brand);
            color: white;
            font-weight: 900;
            font-size: 16px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .cli-srch-item-info { flex: 1; min-width: 0; }
        .cli-srch-item-name {
            font-weight: 900;
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cli-srch-item-meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }
        .cli-srch-item-tel {
            font-size: 13px;
            font-weight: 700;
            color: var(--brand);
            flex-shrink: 0;
        }
        @media (min-width: 720px) {
            .cli-overlay { align-items: center; }
            .cli-sheet { width: min(100%, 540px); max-height: 85dvh; border-radius: 16px; animation: none; }
            .cli-handle { display: none; }
            .cli-srch-overlay { align-items: center; }
            .cli-srch-sheet { width: min(100%, 520px); max-height: 80dvh; border-radius: 16px; animation: none; }
        }
        /* KPI card clickable */
        .kpi-card--click { cursor: pointer; transition: box-shadow .18s, transform .18s; }
        .kpi-card--click:hover { box-shadow: 0 8px 24px rgba(16,24,32,.11); transform: translateY(-2px); }
        .kpi-card--click:active { transform: translateY(0); box-shadow: none; }
        /* Modal pedidos entrega */
        .ped-overlay {
            position: fixed; inset: 0; background: rgba(16,24,32,.45);
            display: flex; align-items: flex-end; justify-content: center;
            z-index: 1200; opacity: 0; pointer-events: none;
            transition: opacity .22s;
        }
        .ped-overlay.open { opacity: 1; pointer-events: all; }
        .ped-sheet {
            background: var(--paper); border-radius: 20px 20px 0 0;
            width: 100%; max-width: 640px; max-height: 80dvh;
            display: flex; flex-direction: column;
            transform: translateY(60px); transition: transform .26s cubic-bezier(.32,1,.46,1);
        }
        .ped-overlay.open .ped-sheet { transform: translateY(0); }
        .ped-handle { width: 36px; height: 4px; background: var(--line); border-radius: 2px; margin: 10px auto 0; flex-shrink: 0; }
        .ped-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px 10px; border-bottom: 1px solid var(--line); flex-shrink: 0;
        }
        .ped-head h3 { font-size: 16px; font-weight: 900; }
        .ped-close { background: var(--field); border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; font-size: 16px; display: grid; place-items: center; }
        .ped-body { overflow-y: auto; padding: 12px 16px 24px; flex: 1; }
        .ped-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid var(--line); gap: 10px;
        }
        .ped-row:last-child { border-bottom: none; }
        .ped-id { font-size: 13px; font-weight: 900; color: var(--ink); }
        .ped-cli { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .ped-date { font-size: 11px; color: var(--muted); }
        .ped-right { text-align: right; flex-shrink: 0; }
        .ped-total { font-size: 14px; font-weight: 900; }
        .ped-badge {
            display: inline-block; font-size: 10px; font-weight: 700; border-radius: 99px;
            padding: 2px 7px; margin-top: 3px;
        }
        .ped-empty { padding: 32px 0; text-align: center; color: var(--muted); font-size: 14px; }
        .ped-loading { padding: 32px 0; text-align: center; color: var(--muted); font-size: 13px; }
        @media (min-width: 640px) {
            .ped-sheet { border-radius: 16px; max-height: 75dvh; margin-bottom: 24px; }
            .ped-handle { display: none; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div class="brand-row">
                <div class="brand-lockup">
                    <div class="mark">S</div>
                    <div>
                        <div class="eyebrow"><?= htmlspecialchars(Configuracion::get('nombre_tienda', 'Solumedic')) ?></div>
                        <a href="<?= url('representante/perfil.php') ?>" style="text-decoration:none;color:inherit"><h1><?= htmlspecialchars($representanteNombre) ?></h1></a>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <a class="logout" href="<?= url('logout-admin.php') ?>" aria-label="Cerrar sesion">
                        <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 3v18"/></svg>
                    </a>
                </div>
            </div>
        </header>

        <!-- ═══ DASHBOARD DE MÉTRICAS ═══ -->
        <section class="dash" aria-label="Métricas del período">

            <!-- Chips de período -->
            <div class="dash-periods-wrap">
                <div class="dash-periods" role="group">
                    <button class="period-chip" data-p="hoy"           onclick="selPeriodo(this)">Hoy</button>
                    <button class="period-chip" data-p="semana"        onclick="selPeriodo(this)">Semana</button>
                    <button class="period-chip active" data-p="mes"    onclick="selPeriodo(this)">Este mes</button>
                    <button class="period-chip" data-p="mes_anterior"  onclick="selPeriodo(this)">Mes ant.</button>
                    <button class="period-chip" data-p="personalizado" onclick="selPeriodo(this)">Fechas</button>
                </div>
            </div>
            <!-- Chips de canal -->
            <div class="canal-chips" role="group">
                <button class="canal-chip active" data-canal="todos"   onclick="selCanal(this)">Todos los canales</button>
                <button class="canal-chip" data-canal="directo" onclick="selCanal(this)">Directas</button>
                <button class="canal-chip" data-canal="qr"      onclick="selCanal(this)">Tienda / QR</button>
                <label style="display:flex;align-items:center;gap:6px;margin-left:8px;cursor:pointer;height:28px;padding:0 12px;border-radius:999px;border:1.5px solid var(--line);background:white;font-size:12px;font-weight:900;color:var(--muted);white-space:nowrap;font-family:inherit;" id="sinIvaLabel" title="Mostrar montos sin IVA">
                    <input type="checkbox" id="toggleSinIva" onchange="onToggleSinIva(this)" style="width:14px;height:14px;cursor:pointer;accent-color:var(--brand)">
                    Sin IVA
                </label>
            </div>
            <div class="dash-custom" id="dash-custom">
                <input type="date" id="dash-desde" class="dash-date-input">
                <span class="dash-arrow">→</span>
                <input type="date" id="dash-hasta" class="dash-date-input">
                <button type="button" class="dash-date-apply" onclick="aplicarFechas()">Ver</button>
            </div>

            <!-- Hero: ventas del período -->
            <div class="hero-card" id="dash-hero">
                <div class="hero-label">Ventas del período</div>
                <div class="hero-row">
                    <div class="hero-num" id="dash-pesos">—</div>
                    <div class="hero-delta" id="dash-delta" style="display:none"></div>
                </div>
                <div class="hero-sub">
                    <span><strong id="dash-piezas">—</strong> piezas</span>
                    <span class="hero-dot">·</span>
                    <span><strong id="dash-ordenes">—</strong> órdenes</span>
                </div>
                <div class="hero-sub" style="margin-top:4px;opacity:.65">
                    <span>Período anterior: <strong id="dash-pesos-ant">—</strong></span>
                </div>
            </div>

            <!-- KPI 2×2 -->
            <div class="kpi-grid">
                <div class="kpi-card" style="--kd:.06s">
                    <div class="kpi-icon"></div>
                    <div class="kpi-num" id="dash-clientes">—</div>
                    <div class="kpi-label">Clientes únicos</div>
                </div>
                <div class="kpi-card" style="--kd:.12s">
                    <div class="kpi-icon"></div>
                    <div class="kpi-num" id="dash-ticket">—</div>
                    <div class="kpi-label">Ticket promedio</div>
                </div>
                <div class="kpi-card" style="--kd:.18s">
                    <div class="kpi-icon"></div>
                    <div class="kpi-num" id="dash-pct-cfdi">—</div>
                    <div class="kpi-label">Con factura CFDI</div>
                </div>
                <div class="kpi-card kpi-card--click" style="--kd:.24s" onclick="abrirPedidosEntrega()" title="Ver pedidos">
                    <div class="kpi-icon"></div>
                    <div class="kpi-num" id="dash-entrega">—</div>
                    <div class="kpi-label">Entregas <span style="font-size:9px;opacity:.55">▸ ver</span></div>
                </div>
            </div>

            <!-- Top productos -->
            <div class="dash-section-head">Top productos</div>
            <div class="top-strip" id="dash-top">
                <div class="dash-top-empty">Cargando…</div>
            </div>

            <!-- Perfil de clientes -->
            <div class="tipo-wrap" style="margin-top:10px">
                <div class="dash-section-head">Perfil de clientes</div>
                <div class="tipo-row">
                    <span class="tipo-lbl tipo-lbl-med">Médicos&nbsp;<strong id="dash-n-med">0</strong></span>
                    <div class="tipo-bar">
                        <div class="tipo-fill tipo-fill-med" id="dash-fill-med" style="flex:1"></div>
                        <div class="tipo-fill tipo-fill-pac" id="dash-fill-pac" style="flex:0"></div>
                    </div>
                    <span class="tipo-lbl tipo-lbl-pac"><strong id="dash-n-pac">0</strong>&nbsp;Pacientes</span>
                </div>
            </div>

        </section>

        <section class="actions" id="accionesRapidas" aria-label="Acciones rapidas">
            <button type="button" class="action" onclick="abrirModalCliente()" style="width:100%;border:1px solid var(--line);text-align:left;cursor:pointer;font-family:inherit;">
                <span class="action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/><path d="M19 8h-2m-1-1v2"/></svg></span>
                <span><b>Clientes</b><small>Alta/Modif.</small></span>
            </button>
            <a class="action secondary" href="<?= url('representante/ventas.php') ?>">
                <span class="action-icon"><svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M13 16V8"/><path d="M18 16v-3"/></svg></span>
                <span><b>Mis ventas</b><small>Seguimiento</small></span>
            </a>
            <a class="action secondary" href="<?= url('representante/entrar-tienda.php') ?>" target="_blank">
                <span class="action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                <span><b>Ir a tienda</b><small>Cobrar por cliente</small></span>
            </a>
            <button class="action secondary" onclick="document.getElementById('modalQR').classList.add('open')" style="width:100%;border:1px solid var(--line);cursor:pointer;text-align:left;font-family:inherit;">
                <span class="action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></span>
                <span><b>Compartir tienda</b><small>QR y enlace</small></span>
            </button>
        </section>



        <section class="section">
            <div class="section-head">
                <h2>Solicitudes recientes</h2>
                <a class="section-link" href="<?= url('representante/solicitudes.php') ?>">Ver todas</a>
            </div>
            <div class="list">
                <?php if (empty($solicitudes)): ?>
                    <div class="empty">Aun no hay solicitudes de consignacion.</div>
                <?php else: ?>
                    <?php foreach ($solicitudes as $solicitud): ?>
                        <a class="row" data-sol-id="<?= (int)$solicitud['id'] ?>" href="<?= url('representante/solicitudes.php') ?>#solicitud-<?= (int)$solicitud['id'] ?>" style="text-decoration:none;color:inherit;">
                            <div class="row-main">
                                <span class="row-title">Solicitud #<?= str_pad((string)$solicitud['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                <div class="row-sub">
                                    <?= (int)$solicitud['total_productos'] ?> productos · <?= (int)$solicitud['total_solicitado'] ?> unidades
                                </div>
                            </div>
                            <span class="badge <?= $solicitud['estado'] === 'rechazada' ? 'danger' : ($solicitud['estado'] === 'solicitada' ? 'warn' : '') ?>">
                                <?= estado_label_rep($solicitud['estado']) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="section">
            <div class="section-head">
                <h2>Ventas recientes</h2>
                <a class="section-link" href="<?= url('representante/ventas.php') ?>">Ver todas</a>
            </div>
            <div class="list">
                <?php if (empty($ventasRecientes)): ?>
                    <div class="empty">Aun no hay ventas registradas.</div>
                <?php else: ?>
                    <?php foreach ($ventasRecientes as $venta): ?>
                        <a class="row" href="<?= url('representante/ventas.php') ?>#pedido-<?= (int)$venta['id'] ?>" style="text-decoration:none;color:inherit;">
                            <div class="row-main">
                                <span class="row-title">Pedido #<?= str_pad((string)$venta['id'], 4, '0', STR_PAD_LEFT) ?> · <?= money_rep($venta['total']) ?></span>
                                <div class="row-sub">
                                    <?= htmlspecialchars($venta['cliente_nombre'] ?: $venta['cliente_telefono']) ?>
                                    <?php if (($venta['canal'] ?? '') === 'representante_qr'): ?>
                                    <span style="margin-left:4px;font-size:10px;background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:99px;font-weight:900">QR</span>
                                    <?php endif; ?>
                                    · <?= htmlspecialchars($venta['metodo_pago'] ?: 'sin pago') ?>
                                </div>
                            </div>
                            <span class="badge <?= $venta['estado'] === 'por_verificar' ? 'warn' : ($venta['estado'] === 'cancelado' ? 'danger' : '') ?>">
                                <?= estado_label_rep($venta['estado']) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <!-- ── Modal: pedidos entrega directa ──────────────────────────────── -->
    <div id="ped-overlay" class="ped-overlay" onclick="if(event.target===this)cerrarPedidosEntrega()" role="dialog" aria-modal="true" aria-label="Pedidos">
        <div class="ped-sheet">
            <div class="ped-handle"></div>
            <div class="ped-head">
                <h3>Pedidos del período</h3>
                <button class="ped-close" onclick="cerrarPedidosEntrega()" aria-label="Cerrar">&times;</button>
            </div>
            <div class="ped-body" id="ped-body">
                <div class="ped-loading">Cargando…</div>
            </div>
        </div>
    </div>

    <!-- ── Modal: alta rápida de cliente ───────────────────────────────── -->
    <div id="cli-overlay" class="cli-overlay" onclick="onCliOverlayClick(event)" role="dialog" aria-modal="true" aria-label="Nuevo cliente">
        <div class="cli-sheet">
            <div class="cli-handle"></div>
            <div class="cli-header">
                <span class="cli-title" id="cli-modal-title">Nuevo cliente</span>
                <button class="cli-close" onclick="cerrarModalCliente()" aria-label="Cerrar">&times;</button>
            </div>

            <!-- Formulario -->
            <div class="cli-body" id="cli-form-body">

                <!-- Teléfono -->
                <div style="margin-bottom:12px">
                    <label class="cli-label" for="cli-tel">
                        Teléfono <span id="cli-tel-badge" class="cli-badge-ok" style="display:none">Cliente existente</span>
                    </label>
                    <div style="display:flex;gap:8px;align-items:flex-start">
                        <input id="cli-tel" type="tel" inputmode="numeric" maxlength="10" pattern="[0-9]{10}"
                               class="cli-input" placeholder="10 dígitos"
                               oninput="onCliTelInput(this.value)" style="flex:1">
                        <button type="button" class="cli-btn-buscar" onclick="abrirCliSearch()" title="Buscar cliente por nombre o teléfono"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></button>
                    </div>
                    <div id="cli-tel-error" style="display:none;margin-top:6px;font-size:12px;font-weight:800;color:#b91c1c">
                        El telefono debe tener exactamente 10 digitos.
                    </div>
                </div>

                <!-- Nombre -->
                <div style="margin-bottom:12px">
                    <label class="cli-label" for="cli-nombre">Nombre</label>
                    <input id="cli-nombre" type="text" class="cli-input" placeholder="Nombre completo" autocomplete="name">
                </div>

                <!-- Tipo de cliente -->
                <div style="margin-bottom:12px">
                    <label class="cli-label">Tipo de cliente</label>
                    <input type="hidden" id="cli-tipo" value="medico">
                    <div class="cli-chips">
                        <button type="button" class="cli-chip active" data-cli-tipo="medico" onclick="cliSetTipo('medico')">Médico</button>
                        <button type="button" class="cli-chip" data-cli-tipo="paciente" onclick="cliSetTipo('paciente')">Paciente</button>
                    </div>
                </div>

                <!-- Especialidad -->
                <div style="margin-bottom:12px" id="cli-bloque-especialidad">
                    <label class="cli-label" for="cli-esp-sel">Especialidad</label>
                    <select id="cli-esp-sel" class="cli-input" style="padding-top:0;padding-bottom:0" onchange="cliOnEsp(this)">
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($especialidades_idx as $_e): ?>
                            <option value="<?= htmlspecialchars($_e) ?>"><?= htmlspecialchars($_e) ?></option>
                        <?php endforeach; ?>
                        <option value="__otro__">Otro…</option>
                    </select>
                    <input type="hidden" id="cli-especialidad">
                    <input id="cli-esp-otro" type="text" class="cli-input" style="margin-top:6px;display:none"
                           placeholder="Escribe la especialidad…"
                           oninput="document.getElementById('cli-especialidad').value=this.value">
                </div>

                <!-- Médico del paciente (solo visible si es Paciente) -->
                <div id="cli-bloque-medico" style="display:none;margin-bottom:12px">
                    <div class="cli-grid2">
                        <div>
                            <label class="cli-label" for="cli-nom-medico">Médico del Paciente</label>
                            <input id="cli-nom-medico" type="text" class="cli-input" placeholder="Dr. Juan Pérez">
                        </div>
                        <div>
                            <label class="cli-label" for="cli-tel-medico">Teléfono del médico</label>
                            <input id="cli-tel-medico" type="tel" inputmode="numeric" maxlength="10" class="cli-input" placeholder="10 dígitos"
                                   oninput="this.value=this.value.replace(/\D/g,'')">
                        </div>
                    </div>
                </div>

                <!-- Colapsable: datos completos (igual que venta.php) -->
                <button type="button" id="cli-tog-dir" class="cli-section-toggle"
                        onclick="cliToggle('cli-dir-fields','cli-tog-dir')">&#9656; Completar datos del cliente (opcional)</button>
                <div id="cli-dir-fields" class="cli-collapsible">
                    <div class="cli-grid2">
                        <div>
                            <label class="cli-label" for="cli-calle">Calle</label>
                            <input id="cli-calle" type="text" class="cli-input" autocomplete="street-address" placeholder="Nombre de la calle">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                            <div>
                                <label class="cli-label" for="cli-numero">Número</label>
                                <input id="cli-numero" type="text" class="cli-input" placeholder="123">
                            </div>
                            <div>
                                <label class="cli-label" for="cli-cp">CP</label>
                                <input id="cli-cp" type="text" inputmode="numeric" maxlength="5" class="cli-input" placeholder="00000">
                            </div>
                        </div>
                        <div>
                            <label class="cli-label" for="cli-colonia">Colonia</label>
                            <input id="cli-colonia" type="text" class="cli-input" placeholder="Colonia">
                        </div>
                        <div>
                            <label class="cli-label" for="cli-estado">Estado</label>
                            <select id="cli-estado" class="cli-input" style="padding-top:0;padding-bottom:0">
                                <option value="">— Seleccionar —</option>
                            </select>
                        </div>
                        <div>
                            <label class="cli-label" for="cli-ciudad">Municipio / Alcaldía</label>
                            <select id="cli-ciudad" class="cli-input" style="padding-top:0;padding-bottom:0" disabled>
                                <option value="">— Primero selecciona un estado —</option>
                            </select>
                        </div>
                        <div>
                            <label class="cli-label" for="cli-email">Correo electrónico</label>
                            <input id="cli-email" type="email" inputmode="email" class="cli-input" placeholder="correo@ejemplo.com" autocomplete="email">
                        </div>
                    </div>
                    <div style="margin-top:10px">
                        <label class="cli-label" for="cli-referencias">Referencias del domicilio</label>
                        <textarea id="cli-referencias" class="cli-input" rows="2" style="min-height:60px;resize:vertical" placeholder="Entre calles, color de fachada…"></textarea>
                    </div>
                    <div style="margin-top:10px">
                        <label class="cli-label" for="cli-quien">Quién recibe</label>
                        <input id="cli-quien" type="text" class="cli-input" placeholder="Nombre de quien recibe">
                    </div>
                </div>

                <!-- Colapsable: Datos de Facturación CFDI -->
                <button type="button" id="cli-tog-cfdi" class="cli-section-toggle" style="margin-top:8px"
                        onclick="cliToggle('cli-cfdi-fields','cli-tog-cfdi')">&#9656; Datos de Facturación CFDI (opcional)</button>
                <div id="cli-cfdi-fields" class="cli-collapsible">
                    <div class="cli-grid2">
                        <div>
                            <label class="cli-label" for="cli-rfc">RFC</label>
                            <input id="cli-rfc" type="text" class="cli-input" maxlength="13" placeholder="XAXX010101000" style="text-transform:uppercase">
                        </div>
                        <div>
                            <label class="cli-label" for="cli-cp-fiscal">CP Fiscal</label>
                            <input id="cli-cp-fiscal" type="text" inputmode="numeric" maxlength="5" class="cli-input" placeholder="00000">
                        </div>
                    </div>
                    <div>
                        <label class="cli-label" for="cli-razon">Razón Social</label>
                        <input id="cli-razon" type="text" class="cli-input" placeholder="Nombre o empresa como aparece en el SAT">
                    </div>
                    <div>
                        <label class="cli-label" for="cli-regimen">Régimen Fiscal</label>
                        <select id="cli-regimen" class="cli-input" style="padding-top:0;padding-bottom:0">
                            <option value="">— Seleccionar —</option>
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
                            <option value="616">616 - Sin obligaciones fiscales</option>
                            <option value="621">621 - Incorporación Fiscal</option>
                            <option value="626">626 - Régimen Simplificado de Confianza (RESICO)</option>
                        </select>
                    </div>
                    <div>
                        <label class="cli-label" for="cli-uso-cfdi">Uso CFDI</label>
                        <select id="cli-uso-cfdi" class="cli-input" style="padding-top:0;padding-bottom:0">
                            <option value="">— Seleccionar —</option>
                            <option value="G03">G03 - Gastos en general</option>
                            <option value="G01">G01 - Adquisición de mercancías</option>
                            <option value="G02">G02 - Devoluciones, descuentos o bonificaciones</option>
                            <option value="D01">D01 - Honorarios médicos y gastos hospitalarios</option>
                            <option value="D02">D02 - Gastos médicos por incapacidad</option>
                            <option value="D07">D07 - Primas por seguros de gastos médicos</option>
                            <option value="S01">S01 - Sin efectos fiscales</option>
                            <option value="CP01">CP01 - Pagos</option>
                            <option value="P01">P01 - Por definir</option>
                        </select>
                    </div>
                    <div>
                        <label class="cli-label">Constancia Fiscal <span style="font-weight:400;font-size:11px;color:#999">(PDF o imagen)</span></label>
                        <div id="cli-constancia-drop"
                             onclick="document.getElementById('cli-constancia-input').click()"
                             style="border:2px dashed #ccc;border-radius:8px;padding:14px;text-align:center;cursor:pointer;background:#fafafa;transition:border-color .15s">
                            <div id="cli-constancia-placeholder" style="color:#999;font-size:13px">Toca para seleccionar archivo</div>
                            <div id="cli-constancia-filename" style="display:none;font-size:13px;font-weight:700;color:var(--brand)"></div>
                        </div>
                        <input id="cli-constancia-input" type="file" accept=".pdf,image/*" style="display:none" onchange="onCliConstanciaChange(this)">
                    </div>
                </div>

                <button type="button" id="cli-submit" class="cli-submit" onclick="cliGuardar()">
                    Registrar cliente
                </button>
            </div>

            <!-- Overlay: buscar cliente -->
            <div id="cli-srch-overlay" class="cli-srch-overlay" onclick="onCliSrchOverlayClick(event)">
                <div class="cli-srch-sheet">
                    <div class="cli-srch-handle"></div>
                    <div class="cli-srch-header">
                        <span>Buscar cliente</span>
                        <button class="cli-srch-close" onclick="cerrarCliSearch()" aria-label="Cerrar">&times;</button>
                    </div>
                    <div class="cli-srch-input-wrap">
                        <input type="search" id="cli-srch-input" placeholder="Nombre, teléfono, ciudad…"
                               autocomplete="off" inputmode="search"
                               oninput="onCliSrchInput(this.value)">
                    </div>
                    <div class="cli-srch-results" id="cli-srch-results">
                        <div class="cli-srch-empty">Escribe al menos 2 caracteres para buscar</div>
                    </div>
                </div>
            </div>

            <!-- Pantalla de éxito -->
            <div class="cli-success" id="cli-success-body">
                <div class="cli-success-icon"></div>
                <div style="font-size:20px;font-weight:900" id="cli-success-nombre"></div>
                <div style="color:var(--muted);font-size:14px" id="cli-success-msg"></div>
                <div style="display:flex;gap:10px;margin-top:8px;width:100%">
                    <button type="button" onclick="cliReset()" style="flex:1;min-height:48px;border:1.5px solid var(--brand);border-radius:10px;background:white;color:var(--brand);font-weight:900;font-size:15px;cursor:pointer">
                        &#43; Otro cliente
                    </button>
                    <button type="button" onclick="cerrarModalCliente()" style="flex:1;min-height:48px;border:0;border-radius:10px;background:var(--brand);color:white;font-weight:900;font-size:15px;cursor:pointer">
                        Listo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php $navActive = 'inicio'; require __DIR__ . '/_nav.php'; ?>

    <script>
    // ── Modal alta de cliente ─────────────────────────────────────────────
    let cliTelTimer = null;

    function abrirModalCliente() {
        cliReset();
        document.getElementById('cli-overlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => document.getElementById('cli-tel').focus(), 180);
    }

    function cerrarModalCliente() {
        document.getElementById('cli-overlay').classList.remove('open');
        document.body.style.overflow = '';
    }

    function onCliOverlayClick(e) {
        if (e.target === document.getElementById('cli-overlay')) cerrarModalCliente();
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') cerrarModalCliente();
    });

    function cliReset() {
        // Volver al form limpio para siguiente cliente
        document.getElementById('cli-success-body').style.display = 'none';
        document.getElementById('cli-form-body').style.display = '';
        document.getElementById('cli-modal-title').textContent = 'Nuevo cliente';
        _cliClienteCargado = false;
        ['cli-tel','cli-nombre','cli-nom-medico','cli-tel-medico',
         'cli-calle','cli-numero','cli-cp','cli-colonia','cli-ciudad',
         'cli-referencias','cli-quien','cli-email'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        // Reset selects
        document.getElementById('cli-estado').value = '';
        const cliCiudadSel = document.getElementById('cli-ciudad');
        cliCiudadSel.innerHTML = '<option value="">— Primero selecciona un estado —</option>';
        cliCiudadSel.disabled = true;
        document.getElementById('cli-especialidad').value = '';
        document.getElementById('cli-esp-sel').value = '';
        document.getElementById('cli-esp-otro').style.display = 'none';
        document.getElementById('cli-tel-badge').style.display = 'none';
        cliMostrarTelError(false);
        // Reset CFDI
        ['cli-rfc','cli-razon','cli-cp-fiscal'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
        document.getElementById('cli-regimen').value = '';
        document.getElementById('cli-uso-cfdi').value = '';
        document.getElementById('cli-constancia-input').value = '';
        document.getElementById('cli-constancia-placeholder').style.display = '';
        document.getElementById('cli-constancia-filename').style.display = 'none';
        const submitBtn = document.getElementById('cli-submit');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Registrar cliente';
        }
        cliSetTipo('medico');
        // Cerrar colapsables
        ['cli-dir-fields','cli-cfdi-fields'].forEach(id => {
            document.getElementById(id).classList.remove('open');
        });
        document.getElementById('cli-tog-dir').classList.remove('open');
        document.getElementById('cli-tog-cfdi').classList.remove('open');
        setTimeout(() => document.getElementById('cli-tel').focus(), 100);
    }

    // Autofill al escribir teléfono
    let _cliClienteCargado = false;

    function cliMostrarTelError(mostrar) {
        const err = document.getElementById('cli-tel-error');
        const tel = document.getElementById('cli-tel');
        if (err) err.style.display = mostrar ? '' : 'none';
        if (tel) tel.style.borderColor = mostrar ? '#ef4444' : '';
    }

    function cliSetSubmitText() {
        const btn = document.getElementById('cli-submit');
        if (btn && !btn.disabled) btn.textContent = _cliClienteCargado ? 'Actualizar cliente' : 'Registrar cliente';
    }

    function onCliTelInput(val) {
        const digits = val.replace(/\D/g, '').slice(0, 10);
        document.getElementById('cli-tel').value = digits;
        cliMostrarTelError(digits.length > 0 && digits.length !== 10);
        clearTimeout(cliTelTimer);

        // Si había un cliente cargado y el usuario cambió el número → limpiar
        if (_cliClienteCargado) {
            _cliClienteCargado = false;
            const tel = document.getElementById('cli-tel').value;
            cliReset();
            // Restaurar el teléfono que está escribiendo
            document.getElementById('cli-tel').value = tel;
            document.getElementById('cli-modal-title').textContent = 'Nuevo cliente';
            cliSetSubmitText();
        }

        document.getElementById('cli-tel-badge').style.display = 'none';
        if (digits.length === 10) {
            cliTelTimer = setTimeout(() => cliBuscarTel(digits), 400);
        }
    }

    async function cliBuscarTel(tel) {
        try {
            const res  = await fetch('?action=buscar_cliente_modal&tel=' + encodeURIComponent(tel));
            const data = await res.json();
            if (!data.found) return;
            // Autocompletar
            const set = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
            set('cli-nombre', data.nombre);
            set('cli-nom-medico', data.nombre_medico);
            set('cli-tel-medico', data.telefono_medico);
            set('cli-calle', data.calle);
            set('cli-numero', data.numero);
            set('cli-colonia', data.colonia);
            set('cli-cp', data.cp);
            // Estado y municipio con selector en cascada
            if (data.estado || data.ciudad) {
                initUbicaciones({
                    selectEstado:    '#cli-estado',
                    selectMunicipio: '#cli-ciudad',
                    valorEstado:     data.estado  || '',
                    valorMunicipio:  data.ciudad  || '',
                    basePath:        '<?= BASE_PATH ?>',
                });
            }
            set('cli-referencias', data.referencias);
            set('cli-quien', data.quien_recibe);
            set('cli-email', data.email_factura);
            // Autofill CFDI
            set('cli-rfc', data.rfc);
            set('cli-razon', data.razon_social);
            set('cli-cp-fiscal', data.codigo_postal);
            if (data.regimen_fiscal) document.getElementById('cli-regimen').value = data.regimen_fiscal;
            if (data.uso_cfdi) document.getElementById('cli-uso-cfdi').value = data.uso_cfdi;
            if (data.tipo_cliente) cliSetTipo(data.tipo_cliente);
            cliFillEsp(data.especialidad);
            document.getElementById('cli-tel-badge').style.display = 'inline-block';
            document.getElementById('cli-modal-title').textContent = 'Editar cliente';
            _cliClienteCargado = true;
            cliSetSubmitText();
        } catch {}
    }

    function cliSetTipo(tipo) {
        document.getElementById('cli-tipo').value = tipo;
        document.querySelectorAll('[data-cli-tipo]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.cliTipo === tipo);
        });
        // Bloque médico: visible solo si es Paciente
        document.getElementById('cli-bloque-medico').style.display = tipo === 'paciente' ? '' : 'none';
    }

    function cliOnEsp(sel) {
        const otro = document.getElementById('cli-esp-otro');
        const hid  = document.getElementById('cli-especialidad');
        if (sel.value === '__otro__') {
            otro.style.display = ''; otro.focus(); hid.value = '';
        } else {
            otro.style.display = 'none'; hid.value = sel.value;
        }
    }

    function cliFillEsp(value) {
        if (!value) return;
        const sel = document.getElementById('cli-esp-sel');
        const opt = Array.from(sel.options).find(o => o.value === value);
        if (opt) {
            sel.value = value;
            document.getElementById('cli-especialidad').value = value;
        } else {
            sel.value = '__otro__';
            document.getElementById('cli-esp-otro').style.display = '';
            document.getElementById('cli-esp-otro').value = value;
            document.getElementById('cli-especialidad').value = value;
        }
    }

    // ── Búsqueda de clientes en modal ─────────────────────────────────────
    let cliSrchTimer;
    function abrirCliSearch() {
        const ov = document.getElementById('cli-srch-overlay');
        ov.classList.add('open');
        document.getElementById('cli-srch-results').innerHTML =
            '<div class="cli-srch-empty">Escribe al menos 2 caracteres para buscar</div>';
        const inp = document.getElementById('cli-srch-input');
        inp.value = '';
        setTimeout(() => inp.focus(), 150);
    }
    function cerrarCliSearch() {
        document.getElementById('cli-srch-overlay').classList.remove('open');
    }
    function onCliSrchOverlayClick(e) {
        if (e.target === document.getElementById('cli-srch-overlay')) cerrarCliSearch();
    }
    function onCliSrchInput(q) {
        clearTimeout(cliSrchTimer);
        if (q.trim().length < 2) {
            document.getElementById('cli-srch-results').innerHTML =
                '<div class="cli-srch-empty">Escribe al menos 2 caracteres para buscar</div>';
            return;
        }
        document.getElementById('cli-srch-results').innerHTML = '<div class="cli-srch-empty">Buscando…</div>';
        cliSrchTimer = setTimeout(() => ejecutarCliSearch(q.trim()), 300);
    }
    async function ejecutarCliSearch(q) {
        try {
            const res  = await fetch('?action=buscar_clientes_texto&q=' + encodeURIComponent(q));
            const list = await res.json();
            renderCliSrchResults(list, q);
        } catch {
            document.getElementById('cli-srch-results').innerHTML =
                '<div class="cli-srch-empty">Error al buscar. Intenta de nuevo.</div>';
        }
    }
    function renderCliSrchResults(list, q) {
        const box = document.getElementById('cli-srch-results');
        if (!list.length) {
            box.innerHTML = '<div class="cli-srch-empty">Sin resultados para <strong>' +
                q.replace(/</g,'&lt;') + '</strong></div>';
            return;
        }
        const esc = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        box.innerHTML = list.map(c => {
            const inicial = (c.nombre||c.telefono||'?').trim().charAt(0).toUpperCase();
            const nombre  = c.nombre ? esc(c.nombre) : '<em style="color:#888">Sin nombre</em>';
            const lugar   = [c.ciudad,c.estado].filter(Boolean).map(esc).join(', ');
            return `<div class="cli-srch-item" onclick="seleccionarCliCliente('${esc(c.telefono)}')">
                <div class="cli-srch-avatar">${inicial}</div>
                <div class="cli-srch-item-info">
                    <div class="cli-srch-item-name">${nombre}</div>
                    ${lugar ? `<div class="cli-srch-item-meta">${lugar}</div>` : ''}
                </div>
                <div class="cli-srch-item-tel">${esc(c.telefono)}</div>
            </div>`;
        }).join('');
    }
    function seleccionarCliCliente(tel) {
        cerrarCliSearch();
        const input = document.getElementById('cli-tel');
        input.value = tel;
        onCliTelInput(tel);
    }

    function onCliConstanciaChange(input) {
        const ph = document.getElementById('cli-constancia-placeholder');
        const fn = document.getElementById('cli-constancia-filename');
        if (input.files && input.files[0]) {
            ph.style.display = 'none';
            fn.style.display = '';
            fn.textContent = '' + input.files[0].name;
        } else {
            ph.style.display = '';
            fn.style.display = 'none';
        }
    }

    function cliToggle(contentId, btnId) {
        const el  = document.getElementById(contentId);
        const btn = document.getElementById(btnId);
        el.classList.toggle('open');
        btn.classList.toggle('open');
        const isOpen = el.classList.contains('open');
        btn.innerHTML = btn.innerHTML.replace(isOpen ? '&#9656;' : '&#9662;', isOpen ? '&#9662;' : '&#9656;');
    }

    async function cliGuardar() {
        const telInput = document.getElementById('cli-tel');
        const tel    = telInput.value.replace(/\D/g,'').slice(0, 10);
        telInput.value = tel;
        const nombre = document.getElementById('cli-nombre').value.trim();
        if (tel.length !== 10) { cliMostrarTelError(true); showToast('Ingresa un teléfono de 10 dígitos', 'warning'); telInput.focus(); return; }

        const btn = document.getElementById('cli-submit');
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-flex;align-items:center;justify-content:center;gap:8px;">'
            + '<svg style="animation:spin .8s linear infinite;width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">'
            + '<circle cx="12" cy="12" r="10" stroke-opacity=".3"/>'
            + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>'
            + '</svg>Guardando…</span>';

        const fd = new FormData();
        fd.append('action',          'guardar_cliente');
        fd.append('telefono',        tel);
        fd.append('nombre',          nombre);
        fd.append('tipo_cliente',    document.getElementById('cli-tipo').value);
        fd.append('especialidad',    document.getElementById('cli-especialidad').value);
        fd.append('nombre_medico',   document.getElementById('cli-nom-medico').value.trim());
        fd.append('telefono_medico', document.getElementById('cli-tel-medico').value.replace(/\D/g,''));
        fd.append('calle',           document.getElementById('cli-calle').value.trim());
        fd.append('numero',          document.getElementById('cli-numero').value.trim());
        fd.append('colonia',         document.getElementById('cli-colonia').value.trim());
        fd.append('cp',              document.getElementById('cli-cp').value.trim());
        fd.append('ciudad',          document.getElementById('cli-ciudad').value.trim());
        fd.append('estado',          document.getElementById('cli-estado').value);
        fd.append('referencias',     document.getElementById('cli-referencias').value.trim());
        fd.append('quien_recibe',    document.getElementById('cli-quien').value.trim());
        fd.append('email_factura',   document.getElementById('cli-email').value.trim());
        fd.append('rfc',             document.getElementById('cli-rfc').value.trim().toUpperCase());
        fd.append('razon_social',    document.getElementById('cli-razon').value.trim());
        fd.append('codigo_postal',   document.getElementById('cli-cp-fiscal').value.trim());
        fd.append('regimen_fiscal',  document.getElementById('cli-regimen').value);
        fd.append('uso_cfdi',        document.getElementById('cli-uso-cfdi').value);
        const constanciaFile = document.getElementById('cli-constancia-input').files[0];
        if (constanciaFile) fd.append('cli_constancia', constanciaFile);

        try {
            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) { showToast(data.message, 'error'); btn.disabled = false; cliSetSubmitText(); return; }

            // Mostrar pantalla de éxito
            document.getElementById('cli-form-body').style.display = 'none';
            const ok = document.getElementById('cli-success-body');
            ok.style.display = 'flex';
            document.getElementById('cli-success-nombre').textContent = data.nombre || data.telefono;
            document.getElementById('cli-success-msg').textContent = data.message;
        } catch {
            showToast('Error de conexión. Intenta de nuevo.', 'error');
            btn.disabled = false; cliSetSubmitText();
        }
    }

    // ─── Dashboard de métricas ────────────────────────────────────────────
    let _canalActivo = 'todos';
    let _sinIva = 0;
    let _pedPeriodo = 'mes', _pedDesde = '', _pedHasta = '';

    function onToggleSinIva(cb) {
        _sinIva = cb.checked ? 1 : 0;
        const lbl = document.getElementById('sinIvaLabel');
        if (lbl) {
            lbl.style.background    = cb.checked ? 'var(--brand)' : 'white';
            lbl.style.borderColor   = cb.checked ? 'var(--brand)' : 'var(--line)';
            lbl.style.color         = cb.checked ? 'white'        : 'var(--muted)';
        }
        const periodoBtn = document.querySelector('.period-chip.active');
        const p = periodoBtn ? periodoBtn.dataset.p : 'mes';
        if (p === 'personalizado') {
            aplicarFechas();
        } else {
            fetchMetricas(p);
        }
    }

    function selCanal(btn) {
        document.querySelectorAll('.canal-chip').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        _canalActivo = btn.dataset.canal;
        const periodoBtn = document.querySelector('.period-chip.active');
        const p = periodoBtn ? periodoBtn.dataset.p : 'mes';
        if (p === 'personalizado') {
            aplicarFechas();
        } else {
            fetchMetricas(p);
        }
    }

    function selPeriodo(btn) {
        document.querySelectorAll('.period-chip').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        const p = btn.dataset.p;
        const custom = document.getElementById('dash-custom');
        if (p === 'personalizado') {
            custom.classList.add('open');
        } else {
            custom.classList.remove('open');
            fetchMetricas(p);
        }
    }

    function aplicarFechas() {
        const d = document.getElementById('dash-desde').value;
        const h = document.getElementById('dash-hasta').value;
        if (!d || !h) { showToast('Selecciona ambas fechas', 'warning'); return; }
        fetchMetricas('personalizado', d, h);
    }

    async function fetchMetricas(periodo, desde, hasta) {
        _pedPeriodo = periodo; _pedDesde = desde || ''; _pedHasta = hasta || '';
        const hero = document.getElementById('dash-hero');
        if (hero) hero.style.opacity = '.5';
        try {
            let url = '?action=metricas&periodo=' + encodeURIComponent(periodo) + '&canal=' + encodeURIComponent(_canalActivo) + '&sin_iva=' + _sinIva;
            if (desde) url += '&desde=' + encodeURIComponent(desde) + '&hasta=' + encodeURIComponent(hasta);
            const res  = await fetch(url);
            const data = await res.json();
            renderMetricas(data);
        } catch(e) {
            console.error('Error métricas', e);
        } finally {
            if (hero) hero.style.opacity = '1';
        }
    }

    function _cu(el, to, fmt, ms) {
        if (!el) return;
        const t0 = performance.now();
        const tick = t => {
            const p = Math.min((t - t0) / ms, 1);
            const e = p < .5 ? 2*p*p : 1 - Math.pow(-2*p+2,2)/2;
            el.textContent = fmt(Math.round(to * e));
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    }

    function renderMetricas(d) {
        // Hero
        _cu(document.getElementById('dash-pesos'), Math.round(d.pesos),
            n => '$' + n.toLocaleString('es-MX'), 750);

        const antEl = document.getElementById('dash-pesos-ant');
        if (antEl) {
            antEl.textContent = '$' + Math.round(d.pesos_anterior || 0).toLocaleString('es-MX');
        }

        const dEl = document.getElementById('dash-delta');
        if (dEl) {
            const abs = Math.abs(d.delta_pesos);
            dEl.style.display = '';
            if (abs < 0.5) {
                dEl.className = 'hero-delta flat'; dEl.textContent = '— Sin cambio';
            } else if (d.delta_pesos > 0) {
                dEl.className = 'hero-delta up'; dEl.textContent = '↑ ' + abs + '%';
            } else {
                dEl.className = 'hero-delta down'; dEl.textContent = '↓ ' + abs + '%';
            }
        }
        _cu(document.getElementById('dash-piezas'), d.piezas, n => n.toLocaleString('es-MX'), 600);
        _cu(document.getElementById('dash-ordenes'), d.ordenes, n => n.toLocaleString('es-MX'), 600);

        // KPIs
        _cu(document.getElementById('dash-clientes'), d.clientes, n => n.toLocaleString('es-MX'), 500);
        _cu(document.getElementById('dash-ticket'), Math.round(d.ticket),
            n => '$' + n.toLocaleString('es-MX'), 500);
        const cfdiEl = document.getElementById('dash-pct-cfdi');
        if (cfdiEl) cfdiEl.textContent = d.pct_factura + '%';
        const entEl = document.getElementById('dash-entrega');
        if (entEl) entEl.textContent = d.entrega_directa;

        // Top productos
        const topEl = document.getElementById('dash-top');
        if (topEl) {
            if (!d.top_productos || !d.top_productos.length) {
                topEl.innerHTML = '<div class="dash-top-empty">Sin ventas en el período</div>';
            } else {
                const maxP = Math.max(...d.top_productos.map(p => +p.piezas));
                const _e = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                topEl.innerHTML = d.top_productos.map((p, i) => {
                    const thumb = p.imagen_url
                        ? `<img src="${_e(p.imagen_url)}" alt="">`
                        : `<span>${(_e(p.producto)||'?').charAt(0).toUpperCase()}</span>`;
                    return `<div class="top-card">
                        <div class="top-card-thumb">${thumb}</div>
                        <div class="top-card-name">${_e(p.producto)}</div>
                        <div class="top-card-piezas" id="dash-tp-${i}">0</div>
                        <div class="top-card-sub">piezas</div>
                        <div class="top-bar-bg"><div class="top-bar-fill" id="dash-tpb-${i}"></div></div>
                    </div>`;
                }).join('');
                // Animar barras y contadores tras render
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    d.top_productos.forEach((p, i) => {
                        const pct = maxP > 0 ? Math.round(+p.piezas / maxP * 100) : 0;
                        const bar = document.getElementById('dash-tpb-' + i);
                        if (bar) bar.style.width = pct + '%';
                        _cu(document.getElementById('dash-tp-' + i), +p.piezas, n => n.toString(), 500);
                    });
                }));
            }
        }

        // Perfil de clientes
        const med = d.tipo_medico, pac = d.tipo_paciente;
        const tot = med + pac;
        const nMed = document.getElementById('dash-n-med');
        const nPac = document.getElementById('dash-n-pac');
        if (nMed) nMed.textContent = med;
        if (nPac) nPac.textContent = pac;
        const fMed = document.getElementById('dash-fill-med');
        const fPac = document.getElementById('dash-fill-pac');
        if (fMed && fPac) {
            fMed.style.flex = tot > 0 ? med : 1;
            fPac.style.flex = tot > 0 ? pac : 0;
        }
    }

    // Carga inicial al abrir la página
    fetchMetricas('mes');

    // Re-cargar métricas al volver con el botón Atrás (bfcache)
    window.addEventListener('pageshow', e => {
        if (e.persisted) fetchMetricas(_pedPeriodo, _pedDesde || undefined, _pedHasta || undefined);
    });

    // ── Polling: solo cuando la pestaña está activa ───────────────────────
    // Sin setInterval — usa setTimeout chain que se cancela cuando la pestaña se oculta
    // y dispara inmediatamente al volver.
    const POLL_METRICAS  = 90_000; // 90 s
    const POLL_SOLICITUD = 30_000; // 30 s
    let _tMetricas  = null;
    let _tSolicitud = null;

    function _stopPolling() {
        clearTimeout(_tMetricas);
        clearTimeout(_tSolicitud);
        _tMetricas = _tSolicitud = null;
    }

    async function _tickMetricas() {
        if (document.visibilityState !== 'visible') return;
        await fetchMetricas(_pedPeriodo, _pedDesde || undefined, _pedHasta || undefined);
        if (document.visibilityState === 'visible')
            _tMetricas = setTimeout(_tickMetricas, POLL_METRICAS);
    }

    async function _tickSolicitud() {
        if (document.visibilityState !== 'visible') return;
        await _pollSolicitudes();
        if (document.visibilityState === 'visible')
            _tSolicitud = setTimeout(_tickSolicitud, POLL_SOLICITUD);
    }

    function _startPolling() {
        _stopPolling();
        _tMetricas  = setTimeout(_tickMetricas,  POLL_METRICAS);
        _tSolicitud = setTimeout(_tickSolicitud, POLL_SOLICITUD);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            // Pestaña activa: disparar ambos inmediatamente y reanudar ciclo
            _tickMetricas();
            _pollSolicitudes();
            _startPolling();
        } else {
            // Pestaña oculta: cancelar todo — sin actividad en segundo plano
            _stopPolling();
        }
    });

    // Arrancar ciclo tras carga inicial
    _startPolling();

    // ── Estados de solicitudes ─────────────────────────────────────────────
    const _SOL_LABELS = {
        solicitada:'Solicitada', aprobada:'Aprobada', rechazada:'Rechazada',
        preparando:'Preparando', en_transito:'En tránsito', entregada:'Entregada', cancelada:'Cancelada'
    };
    async function _pollSolicitudes() {
        if (document.visibilityState !== 'visible') return;
        try {
            const res  = await fetch('<?= url('api/solicitudes-estado.php') ?>');
            const data = await res.json();
            if (!data.ok) return;
            let changed = false;
            document.querySelectorAll('[data-sol-id]').forEach(el => {
                const id     = el.dataset.solId;
                const estado = data.estados[id];
                if (!estado) return;
                const badge = el.querySelector('.badge');
                if (!badge) return;
                const nuevoLabel = _SOL_LABELS[estado] ?? estado;
                if (badge.textContent.trim() === nuevoLabel) return;
                badge.textContent = nuevoLabel;
                badge.className = 'badge' +
                    (estado === 'rechazada' || estado === 'cancelada' ? ' danger' :
                     estado === 'solicitada' ? ' warn' : '');
                changed = true;
            });
            // Si cambió algún estado, recargar la sección (página) para reflejar clickabilidad
            if (changed) location.reload();
        } catch { /* silencioso */ }
    }

    // ── Modal: pedidos entrega directa
    async function abrirPedidosEntrega() {
        const overlay = document.getElementById('ped-overlay');
        const body    = document.getElementById('ped-body');
        overlay.classList.add('open');
        body.innerHTML = '<div class="ped-loading">Cargando…</div>';
        try {
            let url = '?action=pedidos_entrega&periodo=' + encodeURIComponent(_pedPeriodo) + '&canal=' + encodeURIComponent(_canalActivo);
            if (_pedDesde) url += '&desde=' + encodeURIComponent(_pedDesde) + '&hasta=' + encodeURIComponent(_pedHasta);
            const res  = await fetch(url);
            const rows = await res.json();
            if (!rows.length) {
                body.innerHTML = '<div class="ped-empty">Sin pedidos en este período</div>';
                return;
            }
            const _e = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const estadoStyle = {
                'pendiente':    'background:#fef3c7;color:#92400e',
                'por_verificar':'background:#dbeafe;color:#1e40af',
                'confirmado':   'background:#d1fae5;color:#065f46',
                'en_ruta':      'background:#ede9fe;color:#5b21b6',
                'entregado':    'background:#f1f5f9;color:#475569',
                'cancelado':    'background:#fee2e2;color:#991b1b',
            };
            const estadoLabel = {
                'pendiente':'Pendiente','por_verificar':'Por verificar',
                'confirmado':'Confirmado','en_ruta':'En ruta',
                'entregado':'Entregado','cancelado':'Cancelado',
            };
            const canalLabel = e => e === 'representante_directo' ? 'Directa' : 'Tienda';
            body.innerHTML = rows.map(r => {
                const st = estadoStyle[r.estado] || 'background:#f1f5f9;color:#475569';
                const sl = estadoLabel[r.estado] || r.estado;
                return `<div class="ped-row">
                    <div>
                        <div class="ped-id">#${String(r.id).padStart(4,'0')} <span style="font-weight:500;font-size:11px;color:var(--muted)">${canalLabel(r.canal)}</span></div>
                        <div class="ped-cli">${_e(r.cliente_nombre || r.cliente_telefono)}</div>
                        <div class="ped-date">${_e(r.fecha)}</div>
                    </div>
                    <div class="ped-right">
                        <div class="ped-total">$${parseFloat(r.total).toLocaleString('es-MX',{minimumFractionDigits:2})}</div>
                        <span class="ped-badge" style="${st}">${sl}</span>
                    </div>
                </div>`;
            }).join('');
        } catch(e) {
            body.innerHTML = '<div class="ped-empty">Error al cargar</div>';
        }
    }

    function cerrarPedidosEntrega() {
        document.getElementById('ped-overlay').classList.remove('open');
    }

    // ── Toast de confirmación de solicitud ─────────────────────────────────
    (function () {
        const p = new URLSearchParams(location.search);
        const sol = p.get('solicitud_ok');
        if (!sol) return;

        // Limpiar la URL sin recargar
        const url = new URL(location.href);
        url.searchParams.delete('solicitud_ok');
        history.replaceState(null, '', url.toString());

        // Usar showToast global si está disponible, si no fallback inline
        const msg = 'Solicitud #' + sol + ' enviada correctamente';
        if (typeof showToast === 'function') {
            showToast(msg, 'success');
        } else {
            const t = document.createElement('div');
            t.setAttribute('role', 'status');
            t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#4a70a9;color:#fff;font-weight:700;font-size:14px;padding:12px 22px;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.22);z-index:9999;white-space:nowrap';
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 4000);
        }
    })();

    </script>
    <style>
        @keyframes _vt-in { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:none; } }
        #_toast-c { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:9999; display:flex; flex-direction:column; gap:10px; align-items:center; pointer-events:none; }
        #_toast-c > div { pointer-events:auto; cursor:pointer; }
    </style>
    <div id="_toast-c"></div>
<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initUbicaciones({
        selectEstado:    '#cli-estado',
        selectMunicipio: '#cli-ciudad',
        basePath:        '<?= BASE_PATH ?>',
    });
});
</script>

<!-- ── Modal Compartir QR ──────────────────────────────────────────────── -->
<style>#modalQR{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:16px}#modalQR.open{display:flex}</style>
<div id="modalQR" onclick="if(event.target===this)this.classList.remove('open')">
    <div style="background:#fff;border-radius:20px;padding:28px 24px;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center" onclick="event.stopPropagation()">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
            <h2 style="font-size:17px;font-weight:800;color:#102040">Tu enlace de tienda</h2>
            <button onclick="document.getElementById('modalQR').classList.remove('open')"
                    style="background:#f1f5f9;border:none;border-radius:8px;padding:6px 10px;cursor:pointer;font-size:16px;color:#64748b"></button>
        </div>

        <!-- QR -->
        <img src="<?= $_rep_qr_url ?>" alt="QR" style="width:200px;height:200px;border-radius:12px;border:3px solid #e2e8f0;margin:0 auto 16px">

        <!-- Enlace copiable -->
        <div style="display:flex;gap:8px;margin-bottom:16px">
            <input id="repEnlaceInput" type="text" readonly value="<?= htmlspecialchars($_rep_enlace) ?>"
                   style="flex:1;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:10px;font-size:12px;color:#374151;background:#f8fafc;outline:none">
            <button onclick="repCopiarEnlace()"
                    style="padding:10px 14px;background:#15803d;border:none;border-radius:10px;color:#fff;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap"
                    id="repBtnCopiar">Copiar</button>
        </div>

        <!-- Botones de acción -->
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button onclick="repCompartir()" id="repBtnCompartir"
                    style="flex:1;padding:12px;background:#0f172a;border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;cursor:pointer">
                Compartir
            </button>
            <a href="<?= htmlspecialchars($_rep_enlace) ?>" target="_blank"
               style="flex:1;padding:12px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;color:#15803d;font-size:14px;font-weight:700;text-decoration:none;display:block">
                Abrir tienda
            </a>
        </div>
        <p style="font-size:11px;color:#94a3b8;margin-top:14px">Los clientes que entren por este enlace quedarán vinculados a ti automáticamente.</p>
    </div>
</div>

<script>
function repCopiarEnlace() {
    const input = document.getElementById('repEnlaceInput');
    const btn   = document.getElementById('repBtnCopiar');
    navigator.clipboard.writeText(input.value).then(() => {
        btn.textContent = 'Copiado';
        setTimeout(() => btn.textContent = 'Copiar', 2000);
    }).catch(() => {
        input.select();
        document.execCommand('copy');
        btn.textContent = 'Copiado';
        setTimeout(() => btn.textContent = 'Copiar', 2000);
    });
}
function repCompartir() {
    const url  = document.getElementById('repEnlaceInput').value;
    const btn  = document.getElementById('repBtnCompartir');
    if (navigator.share) {
        navigator.share({
            title: '<?= htmlspecialchars(addslashes(Configuracion::get('nombre_tienda','Solumedic'))) ?>',
            text:  'Entra a nuestra tienda y encuentra los mejores productos:',
            url:   url,
        }).catch(() => {});
    } else {
        // Fallback: abrir WhatsApp web
        window.open('https://wa.me/?text=' + encodeURIComponent(url), '_blank');
    }
}
</script>
</body>
</html>
