<?php
require_once __DIR__ . '/../includes/auth_admin.php'; // Proteger página
require_once __DIR__ . '/../models/Pedido.php';
require_once __DIR__ . '/../models/Cliente.php';
require_once __DIR__ . '/../models/MensajePedido.php';

$pedidoModel = new Pedido();
$clienteModel = new Cliente();
$mensajeModel = new MensajePedido();

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'obtener_conteo_mensajes':
            $pedidos_ids = json_decode($_POST['pedidos_ids'] ?? '[]', true);
            
            if (empty($pedidos_ids) || !is_array($pedidos_ids)) {
                echo json_encode(['success' => false, 'conteos' => []]);
                exit;
            }
            
            $conteos = [];
            foreach ($pedidos_ids as $pedido_id) {
                // Contar solo mensajes NO LEÍDOS del cliente
                $conteos[$pedido_id] = $mensajeModel->contarNoLeidosAdmin($pedido_id);
            }
            
            echo json_encode(['success' => true, 'conteos' => $conteos]);
            exit;
            
        case 'cambiar_estado':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $nuevo_estado = $_POST['estado'] ?? '';
            
            $estados_validos = ['pendiente', 'por_verificar', 'confirmado', 'en_ruta', 'entregado', 'cancelado'];
            
            if (!in_array($nuevo_estado, $estados_validos)) {
                echo json_encode(['success' => false, 'message' => 'Estado no válido']);
                exit;
            }
            
            if ($pedidoModel->updateEstado($pedido_id, $nuevo_estado)) {
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar']);
            }
            exit;

        case 'confirmar_pago':
            $pedido_id = intval($_POST['pedido_id'] ?? 0);
            if (!$pedido_id) {
                echo json_encode(['success' => false, 'message' => 'Pedido inválido']);
                exit;
            }

            $resultado = $pedidoModel->confirmarPago($pedido_id, $_SESSION['admin_id']);
            if ($resultado['success']) {
                echo json_encode(['success' => true, 'message' => 'Pago confirmado', 'estado' => $resultado['estado']]);
            } else {
                echo json_encode(['success' => false, 'message' => $resultado['message'] ?? 'Error al confirmar pago']);
            }
            exit;
    }
}

// Obtener todos los pedidos
$pedidos = $pedidoModel->getAll();

// Estados con emojis y colores
$estados = [
    'pendiente' => [
        'emoji' => '⏳', 
        'color' => 'bg-yellow-100 text-yellow-700 border-yellow-300', 
        'colorBoton' => 'bg-yellow-500 hover:bg-yellow-600',
        'nombre' => 'Pendiente'
    ],
    'por_verificar' => [
        'emoji' => '🔍', 
        'color' => 'bg-orange-100 text-orange-700 border-orange-300', 
        'colorBoton' => 'bg-orange-500 hover:bg-orange-600',
        'nombre' => 'Por Verificar'
    ],
    'confirmado' => [
        'emoji' => '✅', 
        'color' => 'bg-blue-100 text-blue-700 border-blue-300', 
        'colorBoton' => 'bg-blue-500 hover:bg-blue-600',
        'nombre' => 'Confirmado'
    ],
    'en_ruta' => [
        'emoji' => '🚚', 
        'color' => 'bg-purple-100 text-purple-700 border-purple-300', 
        'colorBoton' => 'bg-purple-500 hover:bg-purple-600',
        'nombre' => 'En Ruta'
    ],
    'entregado' => [
        'emoji' => '📦', 
        'color' => 'bg-green-100 text-green-700 border-green-300', 
        'colorBoton' => 'bg-green-500 hover:bg-green-600',
        'nombre' => 'Entregado'
    ],
    'cancelado' => [
        'emoji' => '❌', 
        'color' => 'bg-red-100 text-red-700 border-red-300', 
        'colorBoton' => 'bg-red-500 hover:bg-red-600',
        'nombre' => 'Cancelado'
    ],
];
?>

<?php include '../includes/header.php'; ?>

<style>
/* ============================================================
   PEDIDOS — OPS DASHBOARD
   ============================================================ */
:root {
  --brand:      var(--accent);
  --brand-dark: var(--accent-hover);
  --ink:        var(--text-primary);
  --sub:        var(--text-secondary);
  --muted:      var(--text-secondary);
  --faint:      var(--text-muted);
  --paper:      var(--bg-page);
  --panel:      var(--bg-card);
  --line:       var(--border-card);
  --field:      var(--bg-card-hover);
  --danger:     #dc2626;
}

body { background: var(--paper) !important; font-family: var(--font-base); color: var(--ink); }
.f-jakarta { font-family: var(--font-base); }
.f-mono    { font-family: var(--font-mono); }

/* ---- Status palette ---- */
.s-pendiente     { --sc:#b45309; --sb:#fef3c7; --sd:#d97706; }
.s-por_verificar { --sc:#c2410c; --sb:#ffedd5; --sd:#ea580c; }
.s-confirmado    { --sc:#1d4ed8; --sb:#dbeafe; --sd:#2563eb; }
.s-en_ruta       { --sc:#6d28d9; --sb:#ede9fe; --sd:#7c3aed; }
.s-entregado     { --sc:#15803d; --sb:#dcfce7; --sd:#16a34a; }
.s-cancelado     { --sc:#b91c1c; --sb:#fee2e2; --sd:#dc2626; }

/* ---- Layout ---- */
.pdx { max-width: 1280px; margin: 0 auto; padding: 32px 16px 72px; }

/* ---- Header ---- */
.pdx-header {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 24px;
}
.pdx-title {
  font-size: 1.875rem; font-weight: 800; letter-spacing: 0;
  color: var(--ink); margin: 0 0 8px; line-height: 1.15;
}
.pdx-subtitle { color: var(--muted); font-size: 1rem; margin-top: 0; font-weight: 500; }
.pdx-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.pdx-btn-ghost {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 12px 20px; border-radius: 12px;
  background: var(--panel); border: 1px solid var(--line);
  color: var(--sub); font-size: 13px; font-weight: 600;
  text-decoration: none; transition: border-color .15s, background .15s;
}
.pdx-btn-ghost:hover { background: var(--field); border-color: #d1d5db; }

.pdx-btn-brand {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 12px 20px; border-radius: 12px;
  background: var(--brand); color: #fff;
  font-size: 13px; font-weight: 600;
  text-decoration: none; border: none; cursor: pointer;
  transition: background .15s;
}
.pdx-btn-brand:hover { background: var(--brand-dark); }
.vista-tab {
  display: inline-block;
  padding: 7px 16px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 700;
  border: 1px solid transparent;
  text-decoration: none;
  transition: background .15s, color .15s;
  color: var(--text-primary, #0f172a);
  background: transparent;
}
.vista-tab.active {
  background: linear-gradient(to right, var(--tw-neu-800, #1e293b), var(--tw-neu-900, #0f172a));
  color: #fff;
}

/* ---- Stats ---- */
.pdx-stats {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 16px; margin-bottom: 24px;
}
@media (max-width: 900px) { .pdx-stats { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 520px)  { .pdx-stats { grid-template-columns: repeat(2, 1fr); } }

.pdx-stat {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 12px; padding: 16px;
  box-shadow: 0 1px 3px rgba(15,23,42,.08);
}
.pdx-stat-n {
  font-family: var(--font-mono);
  font-size: 1.5rem; font-weight: 700; line-height: 1;
  color: var(--sd, var(--ink)); letter-spacing: -.03em;
}
.pdx-stat-l {
  font-size: .875rem; font-weight: 500; text-transform: none;
  letter-spacing: 0; color: var(--muted); margin-top: 6px;
}

/* ---- Filters ---- */
.pdx-filters {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 12px; padding: 16px; margin-bottom: 24px;
  box-shadow: 0 1px 3px rgba(15,23,42,.08);
}
.pdx-frow { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.pdx-frow + .pdx-frow {
  margin-top: 10px; padding-top: 10px;
  border-top: 1px solid var(--field);
}
.pdx-flabel {
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--faint); min-width: 46px; flex-shrink: 0;
}
.pdx-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 11px; border-radius: 16px;
  font-size: 12px; font-weight: 600;
  cursor: pointer; border: 1px solid var(--line);
  background: var(--field); color: var(--muted);
  transition: all .12s; white-space: nowrap; user-select: none;
}
.pdx-pill:hover         { background: #e5e7eb; color: var(--sub); }
.pdx-pill.p-active      { background: var(--ink); color: #fff; border-color: var(--ink); }
.pdx-pill.p-active-teal { background: var(--brand); color: #fff; border-color: var(--brand); }

/* ---- Order list ---- */
.pdx-list-wrap {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 4px 14px rgba(15,23,42,.08);
}
.pdx-list-head {
  display: grid;
  grid-template-columns: 28px 92px minmax(180px,1fr) 220px 120px 150px 100px;
  gap: 10px;
  align-items: center;
  padding: 12px 14px;
  background: linear-gradient(to right, #334155, #1e293b);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
}
@media (max-width: 980px) { .pdx-list-head { display: none; } }
.pdx-list { display: flex; flex-direction: column; gap: 6px; }

.pdx-row {
  background: var(--panel);
  border: 1px solid transparent;
  border-left: 3px solid var(--sd, #d1d5db);
  border-radius: 0; overflow: hidden;
  transition: box-shadow .18s, border-color .18s;
}
.pdx-row:hover   { box-shadow: 0 2px 12px rgba(0,0,0,.06); border-left-color: var(--sd); }
.pdx-row.is-open { box-shadow: 0 4px 20px rgba(0,0,0,.08); }

/* Summary bar */
.pdx-sum {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 14px 11px 13px;
  cursor: pointer; user-select: none;
}
.pdx-sum-chevron {
  color: var(--faint); flex-shrink: 0;
  transition: transform .22s; width: 16px; height: 16px;
}
.pdx-row.is-open .pdx-sum-chevron { transform: rotate(180deg); color: var(--muted); }

/* ID column */
.pdx-sum-id {
  font-family: var(--font-mono);
  font-size: 12px; font-weight: 600; color: var(--sub);
  flex-shrink: 0; min-width: 72px; line-height: 1.4;
}
.pdx-sum-id small {
  display: block; font-size: 10px; font-weight: 400;
  color: var(--faint); letter-spacing: .01em;
}

/* Client column */
.pdx-sum-client { flex: 1; min-width: 0; }
.pdx-sum-client-name {
  font-size: 13px; font-weight: 600; color: var(--ink);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  line-height: 1.3;
}
.pdx-sum-client-phone { font-size: 11px; color: var(--faint); margin-top: 1px; }

/* Tags column */
.pdx-sum-tags { display: flex; gap: 3px; flex-wrap: wrap; min-width: 0; flex: 0 1 190px; }
@media (max-width: 980px) { .pdx-sum-tags { display: none; } }

.pdx-tag {
  display: inline-flex; align-items: center;
  font-size: 10px; font-weight: 700; letter-spacing: .02em;
  padding: 2px 7px; border-radius: 4px; white-space: nowrap;
}

/* Amount column — clean, no circus */
.pdx-sum-meta { flex-shrink: 0; text-align: right; min-width: 88px; }
.pdx-sum-total {
  font-family: var(--font-mono);
  font-size: 13px; font-weight: 600;
  color: var(--ink); letter-spacing: -.01em; line-height: 1;
}
.pdx-sum-total .cur { font-size: 10px; font-weight: 500; color: var(--muted); margin-right: 1px; }
.pdx-sum-prods { font-size: 10px; color: var(--faint); margin-top: 2px; font-weight: 500; }

/* Status select */
.pdx-status-sel {
  appearance: none; flex-shrink: 0;
  border: 1px solid var(--line);
  background: var(--sb, var(--field));
  color: var(--sc, var(--muted));
  border-radius: 7px; padding: 6px 24px 6px 9px;
  font-family: var(--font-base);
  font-size: 11.5px; font-weight: 700;
  cursor: pointer; min-width: 134px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23aaa' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 7px center;
  transition: box-shadow .12s;
}
.pdx-status-sel:focus { outline: none; box-shadow: 0 0 0 2px rgba(14,124,123,.25); }
@media (max-width: 700px) { .pdx-status-sel { display: none; } }

/* Action buttons */
.pdx-actions { display: flex; gap: 5px; align-items: center; flex-shrink: 0; }

.pdx-act {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 6px 10px; border-radius: 7px;
  font-size: 12px; font-weight: 600;
  border: none; cursor: pointer;
  text-decoration: none; transition: background .12s;
  position: relative; white-space: nowrap;
}
.pdx-act-chat       { background: var(--field); color: var(--brand); border: 1px solid var(--line); }
.pdx-act-chat:hover { background: #e0f2f1; border-color: #b2dfdb; }
.pdx-act-approve    { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.pdx-act-approve:hover { background: #dcfce7; }

.pdx-badge {
  position: absolute; top: -6px; right: -6px;
  background: var(--danger); color: #fff;
  font-size: 9px; font-weight: 800;
  border-radius: 50%; min-width: 15px; height: 15px;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid var(--panel);
}

/* ---- Detail accordion ---- */
.pdx-detail {
  display: none;
  border-top: 1px solid var(--line);
  background: var(--field);
  padding: 18px 16px 20px 18px;
}
.pdx-row.is-open .pdx-detail { display: block; animation: pdxIn .18s ease; }

@keyframes pdxIn { from { opacity:0; transform: translateY(-3px); } to { opacity:1; transform:none; } }

.pdx-prod-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 8px; margin-bottom: 14px;
}
.pdx-prod {
  display: flex; align-items: center; gap: 10px;
  background: var(--panel); border: 1px solid var(--line);
  border-radius: 8px; padding: 9px 11px;
}
.pdx-prod-img {
  width: 44px; height: 44px; border-radius: 6px;
  object-fit: cover; flex-shrink: 0; background: var(--line);
}
.pdx-prod-ph {
  width: 44px; height: 44px; border-radius: 6px; background: var(--line);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.pdx-prod-name { font-size: 12.5px; font-weight: 600; color: var(--ink); line-height: 1.3; }
.pdx-prod-qty  { font-size: 11px; color: var(--muted); }
.pdx-prod-sub  {
  font-family: var(--font-mono);
  font-size: 12px; font-weight: 600; color: var(--sub); margin-top: 2px;
}

.pdx-extras { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px; }
.pdx-info {
  flex: 1; min-width: 172px;
  background: var(--panel); border: 1px solid var(--line);
  border-radius: 8px; padding: 11px 13px;
}
.pdx-info-ttl {
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--faint); margin-bottom: 5px;
}
/* Amount in detail — clean mono, not giant display */
.pdx-info-total {
  font-family: var(--font-mono);
  font-size: 17px; font-weight: 600;
  color: var(--ink); letter-spacing: -.02em; margin-top: 3px;
}
.pdx-info-total .cur { font-size: 12px; font-weight: 500; color: var(--muted); }

/* ---- Empty state ---- */
.pdx-empty {
  background: var(--panel); border: 1px solid var(--line);
  border-radius: 12px; padding: 56px 28px; text-align: center;
}
.pdx-empty-icon  { font-size: 40px; margin-bottom: 12px; }
.pdx-empty-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.pdx-empty-sub   { color: var(--muted); font-size: 13px; margin-top: 4px; }
</style>

<div class="pdx">

  <?php
  $total_pedidos     = count($pedidos);
  $pendientes        = count(array_filter($pedidos, fn($p) => $p['estado'] === 'pendiente'));
  $por_verificar     = count(array_filter($pedidos, fn($p) => $p['estado'] === 'por_verificar'));
  $confirmados       = count(array_filter($pedidos, fn($p) => $p['estado'] === 'confirmado'));
  $en_ruta           = count(array_filter($pedidos, fn($p) => $p['estado'] === 'en_ruta'));
  $entregados        = count(array_filter($pedidos, fn($p) => $p['estado'] === 'entregado'));
  $web_directos      = count(array_filter($pedidos, fn($p) => ($p['canal'] ?? 'cliente_directo') === 'cliente_directo'));
  $rep_qr            = count(array_filter($pedidos, fn($p) => ($p['canal'] ?? '') === 'representante_qr'));
  $rep_directos      = count(array_filter($pedidos, fn($p) => (($p['canal'] ?? '') === 'representante_directo') || ((int)($p['entrega_directa'] ?? 0) === 1)));
  $cfdi_pendientes   = count(array_filter($pedidos, fn($p) => !empty($p['requiere_factura']) && empty($p['factura_pdf']) && empty($p['factura_xml'])));
  $efectivo_pendiente= count(array_filter($pedidos, fn($p) => ($p['estado_liquidacion'] ?? '') === 'pendiente'));
  $contadores = [];
  foreach ($pedidos as $p) {
    $contadores[$p['estado']] = ($contadores[$p['estado']] ?? 0) + 1;
  }
  ?>

  <!-- Header con Estadísticas -->
  <div class="pdx-header">
    <div>
        <h1 class="pdx-title">📦 Pedidos</h1>
        <p class="pdx-subtitle">Vista Lista · <span id="pdxVisCount"><?= $total_pedidos ?></span> visibles.</p>
      </div>
      <div class="pdx-header-actions">
        <div class="flex gap-1 p-1 rounded-xl" style="background:var(--field,#f1f5f9);border:1px solid #e2e8f0">
          <a href="kanban.php"  class="vista-tab">⊞ Tablero</a>
          <a href="pedidos.php" class="vista-tab active">☰ Lista</a>
        </div>

  <!-- Stats -->
  <div class="pdx-stats">
    <div class="pdx-stat" style="--sd:var(--brand);">
      <div class="pdx-stat-n"><?= $total_pedidos ?></div>
      <div class="pdx-stat-l">Total</div>
    </div>
    <div class="pdx-stat s-pendiente">
      <div class="pdx-stat-n"><?= $pendientes ?></div>
      <div class="pdx-stat-l">Pendientes</div>
    </div>
    <div class="pdx-stat s-por_verificar">
      <div class="pdx-stat-n"><?= $por_verificar ?></div>
      <div class="pdx-stat-l">Por Verificar</div>
    </div>
    <div class="pdx-stat s-confirmado">
      <div class="pdx-stat-n"><?= $confirmados ?></div>
      <div class="pdx-stat-l">Confirmados</div>
    </div>
    <div class="pdx-stat s-en_ruta">
      <div class="pdx-stat-n"><?= $en_ruta ?></div>
      <div class="pdx-stat-l">En Ruta</div>
    </div>
    <div class="pdx-stat s-entregado">
      <div class="pdx-stat-n"><?= $entregados ?></div>
      <div class="pdx-stat-l">Entregados</div>
    </div>
  </div>

  <!-- Filters -->
  <?php if (!empty($pedidos)): ?>
  <div class="pdx-filters">
    <div class="pdx-frow">
      <span class="pdx-flabel">Canal</span>
      <button data-op="todos"                 onclick="pdxOp('todos')"                 class="pdx-pill p-active">Todos (<?= $total_pedidos ?>)</button>
      <button data-op="cliente_directo"       onclick="pdxOp('cliente_directo')"       class="pdx-pill">Web (<?= $web_directos ?>)</button>
      <button data-op="representante_qr"      onclick="pdxOp('representante_qr')"      class="pdx-pill">QR Rep (<?= $rep_qr ?>)</button>
      <button data-op="representante_directo" onclick="pdxOp('representante_directo')" class="pdx-pill" style="border-color:#a7f3d0;background:#f0fdf4;color:#15803d;">Directa (<?= $rep_directos ?>)</button>
      <button data-op="efectivo_pendiente"    onclick="pdxOp('efectivo_pendiente')"    class="pdx-pill" style="border-color:#fde68a;background:#fffbeb;color:#92400e;">Efectivo (<?= $efectivo_pendiente ?>)</button>
      <button data-op="cfdi_pendiente"        onclick="pdxOp('cfdi_pendiente')"        class="pdx-pill" style="border-color:#c4b5fd;background:#f5f3ff;color:#5b21b6;">CFDI (<?= $cfdi_pendientes ?>)</button>
    </div>
    <div class="pdx-frow">
      <span class="pdx-flabel">Estado</span>
      <button data-st="todos" onclick="pdxSt('todos')" class="pdx-pill p-active-teal">Todos (<?= $total_pedidos ?>)</button>
      <?php foreach ($estados as $sk => $sv): ?>
        <?php if (!empty($contadores[$sk])): ?>
        <button data-st="<?= $sk ?>" onclick="pdxSt('<?= $sk ?>')"
                class="pdx-pill s-<?= $sk ?>" style="border-color:var(--sc);background:var(--sb);color:var(--sc);">
          <?= $sv['emoji'] ?> <?= $sv['nombre'] ?> (<?= $contadores[$sk] ?>)
        </button>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Orders -->
  <?php if (empty($pedidos)): ?>
    <div class="pdx-empty">
      <div class="pdx-empty-icon">📦</div>
      <div class="pdx-empty-title">Sin pedidos registrados</div>
      <div class="pdx-empty-sub">Los pedidos aparecerán aquí cuando sean creados.</div>
    </div>
  <?php else: ?>
    <div class="pdx-list-wrap">
      <div class="pdx-list-head">
        <span></span>
        <span>Pedido</span>
        <span>Cliente</span>
        <span>Canal</span>
        <span class="text-right">Total</span>
        <span>Estado</span>
        <span class="text-center">Acciones</span>
      </div>
      <div class="pdx-list" id="pdxList">
      <?php foreach ($pedidos as $pedido): ?>
        <?php
          $detalle = $pedidoModel->getDetalle($pedido['id']);
          $est     = $estados[$pedido['estado']];
          $es_directa    = (($pedido['canal'] ?? '') === 'representante_directo') || ((int)($pedido['entrega_directa'] ?? 0) === 1);
          $fac_pendiente = !empty($pedido['requiere_factura']) && empty($pedido['factura_pdf']) && empty($pedido['factura_xml']);
          $msgs_noleidos = $mensajeModel->contarNoLeidosAdmin($pedido['id']);
          $n_prods       = count($detalle);
        ?>
        <div class="pdx-row s-<?= $pedido['estado'] ?>"
             data-estado="<?= $pedido['estado'] ?>"
             data-pedido-id="<?= $pedido['id'] ?>"
             data-canal="<?= htmlspecialchars($pedido['canal'] ?? 'cliente_directo') ?>"
             data-entrega-directa="<?= $es_directa ? '1' : '0' ?>"
             data-cfdi-pendiente="<?= $fac_pendiente ? '1' : '0' ?>"
             data-liquidacion="<?= htmlspecialchars($pedido['estado_liquidacion'] ?? 'no_aplica') ?>">

          <!-- Summary bar -->
          <div class="pdx-sum" onclick="pdxToggle(this.closest('.pdx-row'))">

            <svg class="pdx-sum-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>

            <div class="pdx-sum-id f-mono">
              #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?>
              <small><?= date('d/m H:i', strtotime($pedido['created_at'])) ?></small>
            </div>

            <div class="pdx-sum-client">
              <div class="pdx-sum-client-name"><?= htmlspecialchars($pedido['nombre'] ?: '—') ?></div>
              <div class="pdx-sum-client-phone"><?= htmlspecialchars($pedido['telefono']) ?></div>
            </div>

            <div class="pdx-sum-tags">
              <?php if ($es_directa): ?>
                <span class="pdx-tag" style="background:#f0fdf4;color:#15803d;">↦ Directa</span>
              <?php elseif (($pedido['canal'] ?? '') === 'representante_qr'): ?>
                <span class="pdx-tag" style="background:#f1f5f9;color:#475569;">QR</span>
              <?php elseif (($pedido['canal'] ?? 'cliente_directo') === 'cliente_directo' && !empty($pedido['representante_admin_id'])): ?>
                <span class="pdx-tag" style="background:#e0f2fe;color:#0369a1;">Tienda</span>
              <?php else: ?>
                <span class="pdx-tag" style="background:#eff6ff;color:#1d4ed8;">Web</span>
              <?php endif; ?>
              <?php if (!empty($pedido['representante_nombre_real'])): ?>
                <span class="pdx-tag" style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;" title="<?= htmlspecialchars($pedido['representante_nombre_real']) ?>">
                  <?= htmlspecialchars(mb_substr($pedido['representante_nombre_real'], 0, 13)) ?>
                </span>
              <?php endif; ?>
              <?php if (($pedido['estado_liquidacion'] ?? '') === 'pendiente'): ?>
                <span class="pdx-tag" style="background:#fffbeb;color:#92400e;">Efectivo</span>
              <?php endif; ?>
              <?php if ($fac_pendiente): ?>
                <span class="pdx-tag" style="background:#f5f3ff;color:#5b21b6;">CFDI</span>
              <?php endif; ?>
            </div>

            <!-- Amount — limpio y legible -->
            <div class="pdx-sum-meta">
              <div class="pdx-sum-total f-mono"><span class="cur">$</span><?= number_format($pedido['total'], 2) ?></div>
              <div class="pdx-sum-prods"><?= $n_prods ?> prod<?= $n_prods != 1 ? 's' : '' ?></div>
            </div>

            <!-- Status select -->
            <div onclick="event.stopPropagation()">
              <select class="pdx-status-sel s-<?= $pedido['estado'] ?>"
                      onchange="pdxCambiarEstado(<?= $pedido['id'] ?>, this.value)">
                <?php foreach ($estados as $key => $eo): ?>
                  <option value="<?= $key ?>" <?= $pedido['estado'] === $key ? 'selected' : '' ?>>
                    <?= $eo['emoji'] ?> <?= $eo['nombre'] ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Actions -->
            <div class="pdx-actions" onclick="event.stopPropagation()">
              <a href="chat-admin.php?pedido_id=<?= $pedido['id'] ?>&return=pedidos"
                 class="pdx-act pdx-act-chat" title="Chat">
                💬
                <?php if ($msgs_noleidos > 0): ?>
                  <span class="pdx-badge"><?= $msgs_noleidos ?></span>
                <?php endif; ?>
              </a>
              <?php if ($pedido['estado'] === 'por_verificar' && (!empty($pedido['comprobante_pago']) || ($pedido['metodo_pago'] ?? '') === 'efectivo')): ?>
                <button onclick="pdxAprobar(<?= $pedido['id'] ?>)" class="pdx-act pdx-act-approve" title="Aprobar pago">✅</button>
              <?php endif; ?>
            </div>

          </div><!-- /pdx-sum -->

          <!-- Expanded detail -->
          <div class="pdx-detail">
            <div class="pdx-prod-grid">
              <?php foreach ($detalle as $item): ?>
              <div class="pdx-prod">
                <?php if ($item['imagen']): ?>
                  <img src="../uploads/productos/<?= htmlspecialchars($item['imagen']) ?>" alt="" class="pdx-prod-img">
                <?php else: ?>
                  <div class="pdx-prod-ph">
                    <svg width="18" height="18" fill="none" stroke="#c4c4c4" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  </div>
                <?php endif; ?>
                <div style="min-width:0;">
                  <div class="pdx-prod-name"><?= htmlspecialchars($item['producto']) ?></div>
                  <div class="pdx-prod-qty"><?= $item['cantidad'] ?> × $<?= number_format($item['precio_unitario'], 2) ?></div>
                  <div class="pdx-prod-sub">$<?= number_format($item['subtotal'], 2) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="pdx-extras">
              <?php if (!empty($pedido['notas'])): ?>
              <div class="pdx-info" style="border-left:2px solid #fbbf24;">
                <div class="pdx-info-ttl">Notas</div>
                <div style="font-size:13px;color:var(--sub);line-height:1.5;"><?= nl2br(htmlspecialchars($pedido['notas'])) ?></div>
              </div>
              <?php endif; ?>

              <?php if (!empty($pedido['metodo_pago'])): ?>
              <div class="pdx-info" style="border-left:2px solid #93c5fd;">
                <div class="pdx-info-ttl">Método de Pago</div>
                <div style="font-size:13px;font-weight:600;color:var(--sub);text-transform:capitalize;"><?= htmlspecialchars($pedido['metodo_pago']) ?></div>
                <?php if (!empty($pedido['comprobante_pago'])): ?>
                  <a href="../uploads/comprobantes/<?= htmlspecialchars($pedido['comprobante_pago']) ?>"
                     target="_blank"
                     style="display:inline-flex;align-items:center;gap:4px;margin-top:7px;font-size:12px;font-weight:700;color:var(--brand);text-decoration:none;">
                    🧾 Ver comprobante ↗
                  </a>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if (!empty($pedido['comprobante_envio'])): ?>
              <div class="pdx-info" style="border-left:2px solid #6ee7b7;">
                <div class="pdx-info-ttl">Guía de Envío</div>
                <a href="../uploads/guias/<?= htmlspecialchars($pedido['comprobante_envio']) ?>"
                   target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;margin-top:4px;font-size:12px;font-weight:700;color:var(--brand);text-decoration:none;">
                  📦 Ver guía ↗
                </a>
              </div>
              <?php endif; ?>

              <?php if (!empty($pedido['factura_pdf']) || !empty($pedido['factura_xml'])): ?>
              <div class="pdx-info" style="border-left:2px solid #c4b5fd;">
                <div class="pdx-info-ttl">
                  Factura
                  <?php if (!empty($pedido['num_factura'])): ?>
                    <span style="font-weight:400;font-size:11px;color:var(--faint);margin-left:4px;">#<?= htmlspecialchars($pedido['num_factura']) ?></span>
                  <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap;">
                  <?php if (!empty($pedido['factura_pdf'])): ?>
                    <a href="../uploads/facturas/<?= htmlspecialchars($pedido['factura_pdf']) ?>"
                       target="_blank"
                       style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:#7c3aed;text-decoration:none;">
                      📄 PDF ↗
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($pedido['factura_xml'])): ?>
                    <a href="../uploads/facturas/<?= htmlspecialchars($pedido['factura_xml']) ?>"
                       target="_blank"
                       style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:#7c3aed;text-decoration:none;">
                      🗂 XML ↗
                    </a>
                  <?php endif; ?>
                </div>
              </div>
              <?php endif; ?>

              <div class="pdx-info" style="border-left:2px solid #a78bfa;">
                <div class="pdx-info-ttl">Resumen</div>
                <div style="font-size:11px;color:var(--faint);font-weight:500;"><?= date('d/m/Y · H:i', strtotime($pedido['created_at'])) ?></div>
                <?php
                  $subtotal_prods = array_sum(array_column($detalle, 'subtotal'));
                  $descuento      = (float)($pedido['cupon_descuento'] ?? 0);
                  $costo_envio    = round((float)$pedido['total'] - $subtotal_prods + $descuento, 2);
                ?>
                <table style="width:100%;margin-top:6px;font-size:12px;border-collapse:collapse;">
                  <tr>
                    <td style="color:var(--sub);padding:2px 0;">Subtotal productos</td>
                    <td style="text-align:right;font-family:monospace;color:var(--ink);">$<?= number_format($subtotal_prods, 2) ?></td>
                  </tr>
                  <?php if ($descuento > 0): ?>
                  <tr>
                    <td style="color:#15803d;padding:2px 0;">
                      Cupón
                      <?php if (!empty($pedido['cupon_codigo'])): ?>
                        <code style="background:#f0fdf4;padding:1px 5px;border-radius:3px;font-size:11px;"><?= htmlspecialchars($pedido['cupon_codigo']) ?></code>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-family:monospace;color:#15803d;">−$<?= number_format($descuento, 2) ?></td>
                  </tr>
                  <?php endif; ?>
                  <?php if ($costo_envio > 0): ?>
                  <tr>
                    <td style="color:var(--sub);padding:2px 0;">Envío</td>
                    <td style="text-align:right;font-family:monospace;color:var(--ink);">$<?= number_format($costo_envio, 2) ?></td>
                  </tr>
                  <?php endif; ?>
                  <tr style="border-top:1px solid #e5e7eb;">
                    <td style="font-weight:700;color:var(--ink);padding-top:4px;">Total</td>
                    <td style="text-align:right;font-family:monospace;font-weight:700;color:var(--ink);padding-top:4px;">$<?= number_format($pedido['total'], 2) ?></td>
                  </tr>
                </table>
              </div>
            </div>
          </div><!-- /pdx-detail -->

        </div><!-- /pdx-row -->
      <?php endforeach; ?>
      </div><!-- /pdxList -->
    </div><!-- /pdx-list-wrap -->

    <div id="pdxNoResults" class="pdx-empty" style="display:none;margin-top:10px;">
      <div class="pdx-empty-icon">🔍</div>
      <div class="pdx-empty-title">Sin resultados</div>
      <div class="pdx-empty-sub">No hay pedidos con los filtros seleccionados.</div>
    </div>
  <?php endif; ?>

</div><!-- /pdx -->

<script>
function showToast(mensaje, tipo = 'success') {
  if (typeof mostrarAlerta === 'function') {
    mostrarAlerta(mensaje, tipo);
    return;
  }
  const bg = tipo === 'error' ? '#EF4444' : tipo === 'warning' ? '#F59E0B' : 'var(--accent)';
  const toast = document.createElement('div');
  toast.style.cssText = `position:fixed;top:1rem;left:50%;transform:translateX(-50%);background:${bg};color:#fff;padding:.85rem 1.5rem;border-radius:.75rem;font-size:.875rem;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.18);z-index:9999;`;
  toast.textContent = mensaje;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

let pdxStAct = localStorage.getItem('pdxSt') || 'todos';
let pdxOpAct = localStorage.getItem('pdxOp') || 'todos';

function pdxToggle(row) {
  row.classList.toggle('is-open');
}

function pdxSt(st) {
  pdxStAct = st;
  localStorage.setItem('pdxSt', st);
  document.querySelectorAll('[data-st]').forEach(b => {
    const on = b.dataset.st === st;
    b.classList.toggle('p-active-teal', on);
    b.classList.remove('p-active');
    b.style.opacity = (st === 'todos' || on) ? '1' : '.5';
  });
  pdxApply();
}

function pdxOp(op) {
  pdxOpAct = op;
  localStorage.setItem('pdxOp', op);
  document.querySelectorAll('[data-op]').forEach(b => {
    b.classList.toggle('p-active', b.dataset.op === op);
  });
  pdxApply();
}

function pdxMatchOp(card, op) {
  if (op === 'todos')                return true;
  if (op === 'cliente_directo')      return card.dataset.canal === 'cliente_directo';
  if (op === 'representante_qr')     return card.dataset.canal === 'representante_qr';
  if (op === 'representante_directo')return card.dataset.entregaDirecta === '1';
  if (op === 'efectivo_pendiente')   return card.dataset.liquidacion === 'pendiente';
  if (op === 'cfdi_pendiente')       return card.dataset.cfdiPendiente === '1';
  return true;
}

function pdxApply() {
  const cards = document.querySelectorAll('.pdx-row');
  let vis = 0;
  cards.forEach((c) => {
    const ok = (pdxStAct === 'todos' || c.dataset.estado === pdxStAct) && pdxMatchOp(c, pdxOpAct);
    c.style.display = ok ? '' : 'none';
    if (ok) {
      c.style.opacity = '0'; c.style.transform = 'translateY(4px)';
      setTimeout(() => { c.style.transition = 'opacity .18s,transform .18s'; c.style.opacity = '1'; c.style.transform = ''; }, 20 * vis);
      vis++;
    }
  });
  const vc = document.getElementById('pdxVisCount');
  if (vc) vc.textContent = vis;
  const nr = document.getElementById('pdxNoResults');
  if (nr) nr.style.display = vis === 0 ? 'block' : 'none';
}

function pdxCambiarEstado(id, estado) {
  const fd = new FormData();
  fd.append('action', 'cambiar_estado');
  fd.append('pedido_id', id);
  fd.append('estado', estado);
  fetch('pedidos.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 900); }
      else showToast(d.message || 'Error', 'error');
    })
    .catch(() => showToast('Error de conexión', 'error'));
}

function pdxAprobar(id) {
  if (!confirm('¿Confirmar que el pago fue verificado?')) return;
  const fd = new FormData();
  fd.append('action', 'confirmar_pago');
  fd.append('pedido_id', id);
  fetch('pedidos.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (d.success) { showToast('Pago aprobado · pedido confirmado', 'success'); setTimeout(() => location.reload(), 900); }
      else showToast(d.message || 'Error', 'error');
    })
    .catch(() => showToast('Error de conexión', 'error'));
}

async function pdxBadges() {
  const ids = [...document.querySelectorAll('[data-pedido-id]')].map(e => e.dataset.pedidoId);
  if (!ids.length) return;
  const fd = new FormData();
  fd.append('action', 'obtener_conteo_mensajes');
  fd.append('pedidos_ids', JSON.stringify(ids));
  try {
    const data = await (await fetch('pedidos.php', { method:'POST', body:fd })).json();
    if (!data.success) return;
    Object.entries(data.conteos).forEach(([id, count]) => {
      const btn = document.querySelector(`a.pdx-act-chat[href*="pedido_id=${id}"]`);
      if (!btn) return;
      let badge = btn.querySelector('.pdx-badge');
      if (count > 0) {
        if (!badge) { badge = document.createElement('span'); badge.className = 'pdx-badge'; btn.appendChild(badge); }
        badge.textContent = count;
      } else if (badge) badge.remove();
    });
  } catch {}
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-st]').forEach(b => {
    b.classList.toggle('p-active-teal', b.dataset.st === pdxStAct);
    b.style.opacity = (pdxStAct === 'todos' || b.dataset.st === pdxStAct) ? '1' : '.5';
  });
  document.querySelectorAll('[data-op]').forEach(b => b.classList.toggle('p-active', b.dataset.op === pdxOpAct));
  pdxApply();
  pdxBadges();
  setInterval(pdxBadges, 10000);
});
</script>

<?php include '../includes/footer.php'; ?>
