<?php
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../models/Pedido.php';
require_once __DIR__ . '/../models/Cliente.php';

$pedidoModel = new Pedido();
$clienteModel = new Cliente();

$pedido_id = $_GET['pedido_id'] ?? 0;
$return_to = $_GET['return'] ?? 'pedidos'; // Por defecto 'pedidos'
$full_view = isset($_GET['full']) && (int)$_GET['full'] === 1;

$return_url = 'pedidos.php';
if ($return_to === 'kanban') {
    $return_url = 'kanban.php';
} elseif ($return_to === 'historial') {
    $return_url = 'pedidos-historial.php';
} elseif ($return_to === 'inventario_consignacion') {
    $return_url = 'inventario-consignacion.php';
}

if (!$pedido_id) {
    echo '<div class="text-center py-12"><p class="text-red-600">ID de pedido no válido</p></div>';
    exit;
}

$pedido = $pedidoModel->getById($pedido_id);
if (!$pedido) {
    echo '<div class="text-center py-12"><p class="text-red-600">Pedido no encontrado</p></div>';
    exit;
}

$detalle = $pedidoModel->getDetalle($pedido_id);
$cliente = $clienteModel->getById($pedido['cliente_id']);

$estados = [
    'pendiente' => ['emoji' => '', 'color' => 'bg-yellow-100 text-yellow-700', 'nombre' => 'Pendiente'],
    'por_verificar' => ['emoji' => '', 'color' => 'bg-orange-100 text-orange-700', 'nombre' => 'Por Verificar'],
    'confirmado' => ['emoji' => '', 'color' => 'bg-blue-100 text-blue-700', 'nombre' => 'Confirmado'],
    'en_ruta' => ['emoji' => '', 'color' => 'bg-purple-100 text-purple-700', 'nombre' => 'En Ruta'],
    'entregado' => ['emoji' => '', 'color' => 'bg-green-100 text-green-700', 'nombre' => 'Entregado'],
    'cancelado' => ['emoji' => '', 'color' => 'bg-red-100 text-red-700', 'nombre' => 'Cancelado'],
];

$estado = $estados[$pedido['estado']];
$es_entrega_directa = (($pedido['canal'] ?? '') === 'representante_directo') || ((int)($pedido['entrega_directa'] ?? 0) === 1);
?>

<?php if ($full_view): ?>
<?php include '../includes/header.php'; ?>
<div class="p-4 md:p-6 max-w-5xl mx-auto">
<?php endif; ?>

<!-- Header del Modal -->
<div class="flex justify-between items-start mb-6 pb-4 border-b-2 border-slate-200">
    <div>
        <h2 class="text-2xl font-bold text-slate-900 mb-1">
            Pedido #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?>
        </h2>
        <p class="text-sm text-slate-600">
            <?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?>
        </p>
    </div>
    <div class="flex items-center gap-3">
        <span class="<?= $estado['color'] ?> px-4 py-2 rounded-full text-sm font-semibold">
            <?= $estado['emoji'] ?> <?= $estado['nombre'] ?>
        </span>
        <button onclick="cerrarModal()" class="text-slate-400 hover:text-slate-600 text-2xl">
            
        </button>
    </div>
</div>

<?php if ($es_entrega_directa || !empty($pedido['representante_admin_id'])): ?>
    <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-emerald-50 border-2 border-emerald-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-emerald-700 uppercase mb-1">Origen</p>
            <p class="font-bold text-emerald-900">
                <?= $es_entrega_directa ? 'Entrega directa' : 'Representante / QR' ?>
            </p>
        </div>
        <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-blue-700 uppercase mb-1">Representante</p>
            <p class="font-bold text-blue-900">
                <?= htmlspecialchars($pedido['representante_nombre_real'] ?? $pedido['nombre_representante'] ?? 'Sin dato') ?>
            </p>
        </div>
        <div class="bg-orange-50 border-2 border-orange-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-orange-700 uppercase mb-1">Liquidacion</p>
            <p class="font-bold text-orange-900">
                <?= htmlspecialchars($pedido['estado_liquidacion'] ?? 'no_aplica') ?>
            </p>
            <?php if (!empty($pedido['fecha_confirmacion_pago'])): ?>
                <p class="text-xs text-orange-700 mt-1">
                    Pago confirmado: <?= date('d/m/Y H:i', strtotime($pedido['fecha_confirmacion_pago'])) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Información del Cliente -->
<div class="mb-6">
    <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
        Información del Cliente
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl">
        <div>
            <p class="text-xs font-semibold text-slate-500 mb-1">Teléfono</p>
            <p class="font-semibold text-slate-900"><?= htmlspecialchars($pedido['telefono']) ?></p>
        </div>
        <?php if (!empty($pedido['nombre'])): ?>
            <div>
                <p class="text-xs font-semibold text-slate-500 mb-1">Nombre</p>
                <p class="font-semibold text-slate-900"><?= htmlspecialchars($pedido['nombre']) ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Datos de Envío (si existen) -->
<?php if (!empty($pedido['calle']) || !empty($pedido['cp_envio'])): ?>
    <div class="mb-6">
        <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
            Datos de Envío
        </h3>
        <div class="bg-blue-50 p-4 rounded-xl border-2 border-blue-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <?php if (!empty($pedido['calle']) || !empty($pedido['numero'])): ?>
                    <div>
                        <p class="font-semibold text-blue-900">Dirección:</p>
                        <p class="text-blue-800"><?= htmlspecialchars($pedido['calle']) ?> <?= htmlspecialchars($pedido['numero']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['colonia'])): ?>
                    <div>
                        <p class="font-semibold text-blue-900">Colonia:</p>
                        <p class="text-blue-800"><?= htmlspecialchars($pedido['colonia']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['ciudad']) || !empty($pedido['estado_envio'])): ?>
                    <div>
                        <p class="font-semibold text-blue-900">Ciudad/Estado:</p>
                        <p class="text-blue-800"><?= htmlspecialchars($pedido['ciudad']) ?>, <?= htmlspecialchars($pedido['estado_envio']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['cp_envio'])): ?>
                    <div>
                        <p class="font-semibold text-blue-900">CP:</p>
                        <p class="text-blue-800"><?= htmlspecialchars($pedido['cp_envio']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['referencias'])): ?>
                    <div class="md:col-span-2">
                        <p class="font-semibold text-blue-900">Referencias:</p>
                        <p class="text-blue-800"><?= htmlspecialchars($pedido['referencias']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['quien_recibe'])): ?>
                    <div>
                        <p class="font-semibold text-blue-900">Recibe:</p>
                        <p class="text-blue-800"><?= htmlspecialchars($pedido['quien_recibe']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Datos Adicionales (Médico/Representante) -->
<?php if (!empty($pedido['nombre_medico']) || !empty($pedido['telefono_medico']) || !empty($pedido['nombre_representante'])): ?>
    <div class="mb-6">
        <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
            Datos Adicionales
        </h3>
        <div class="bg-purple-50 p-4 rounded-xl border-2 border-purple-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <?php if (!empty($pedido['nombre_medico'])): ?>
                    <div>
                        <p class="font-semibold text-purple-900">Médico:</p>
                        <p class="text-purple-800"><?= htmlspecialchars($pedido['nombre_medico']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['telefono_medico'])): ?>
                    <div>
                        <p class="font-semibold text-purple-900">Teléfono del Médico:</p>
                        <p class="text-purple-800"><?= htmlspecialchars($pedido['telefono_medico']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['nombre_representante'])): ?>
                    <div>
                        <p class="font-semibold text-purple-900">Representante:</p>
                        <p class="text-purple-800"><?= htmlspecialchars($pedido['nombre_representante']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Datos Fiscales (si requiere factura) -->
<?php if ($pedido['requiere_factura']): ?>
    <div class="mb-6">
        <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
            Datos Fiscales
        </h3>
        <div class="bg-amber-50 p-4 rounded-xl border-2 border-amber-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <?php if (!empty($pedido['rfc'])): ?>
                    <div>
                        <p class="font-semibold text-amber-900">RFC:</p>
                        <p class="text-amber-800 font-mono"><?= htmlspecialchars($pedido['rfc']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['razon_social'])): ?>
                    <div>
                        <p class="font-semibold text-amber-900">Razón Social:</p>
                        <p class="text-amber-800"><?= htmlspecialchars($pedido['razon_social']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['email_factura'])): ?>
                    <div>
                        <p class="font-semibold text-amber-900">Email:</p>
                        <p class="text-amber-800"><?= htmlspecialchars($pedido['email_factura']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['codigo_postal'])): ?>
                    <div>
                        <p class="font-semibold text-amber-900">CP Fiscal:</p>
                        <p class="text-amber-800"><?= htmlspecialchars($pedido['codigo_postal']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['regimen_fiscal'])): ?>
                    <div>
                        <p class="font-semibold text-amber-900">Régimen Fiscal:</p>
                        <p class="text-amber-800"><?= htmlspecialchars($pedido['regimen_fiscal']) ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($pedido['uso_cfdi'])): ?>
                    <div>
                        <p class="font-semibold text-amber-900">Uso CFDI:</p>
                        <p class="text-amber-800"><?= htmlspecialchars($pedido['uso_cfdi']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Productos del Pedido -->
<div class="mb-6">
    <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
        Productos (<?= count($detalle) ?>)
    </h3>
    <div class="space-y-3">
        <?php foreach ($detalle as $item): ?>
            <div class="flex items-center gap-4 bg-cream-50 p-4 rounded-xl border border-cream-200">
                <?php if ($item['imagen']): ?>
                    <img src="../uploads/productos/<?= htmlspecialchars($item['imagen']) ?>" 
                         alt="<?= htmlspecialchars($item['producto']) ?>"
                         class="w-20 h-20 object-cover rounded-lg">
                <?php else: ?>
                    <div class="w-20 h-20 bg-slate-200 rounded-lg flex items-center justify-center">
                        <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                <?php endif; ?>
                
                <div class="flex-1">
                    <h4 class="font-bold text-slate-900"><?= htmlspecialchars($item['producto']) ?></h4>
                    <p class="text-sm text-slate-600">
                        <?= $item['cantidad'] ?> × $<?= number_format($item['precio_unitario'], 2) ?>
                    </p>
                </div>
                
                <div class="text-right">
                    <p class="font-bold text-terracotta-600 text-lg">
                        $<?= number_format($item['subtotal'], 2) ?>
                    </p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Total del Pedido -->
<div class="mb-6 p-4 bg-gradient-to-r from-terracotta-50 to-terracotta-100 rounded-xl border-2 border-terracotta-300">
    <div class="flex justify-between items-center">
        <span class="text-lg font-bold text-terracotta-900">Total del Pedido:</span>
        <span class="text-3xl font-bold text-terracotta-600">
            $<?= number_format($pedido['total'], 2) ?>
        </span>
    </div>
</div>

<!-- Notas del Pedido -->
<?php if (!empty($pedido['notas'])): ?>
    <div class="mb-6">
        <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
            Notas
        </h3>
        <div class="bg-yellow-50 p-4 rounded-xl border-l-4 border-yellow-400">
            <p class="text-sm text-yellow-800"><?= nl2br(htmlspecialchars($pedido['notas'])) ?></p>
        </div>
    </div>
<?php endif; ?>

<!-- Información de Pago -->
<?php if (!empty($pedido['metodo_pago'])): ?>
    <div class="mb-6">
        <h3 class="text-lg font-bold text-slate-900 mb-3 flex items-center gap-2">
            Información de Pago
        </h3>
        <div class="bg-blue-50 p-4 rounded-xl border-l-4 border-blue-400">
            <p class="text-sm font-semibold text-blue-800 mb-2">
                Método: <span class="capitalize"><?= htmlspecialchars($pedido['metodo_pago']) ?></span>
            </p>
            <?php if (!empty($pedido['comprobante_pago'])): ?>
                <a href="../uploads/comprobantes/<?= htmlspecialchars($pedido['comprobante_pago']) ?>" 
                   target="_blank"
                   class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 font-semibold">
                    Ver Comprobante de Pago
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Botones de Acción -->
<div class="flex gap-3 pt-4 border-t-2 border-slate-200">
    <a href="chat-admin.php?pedido_id=<?= $pedido['id'] ?>&return=<?= htmlspecialchars($return_to) ?>" 
       class="flex-1 bg-sage-500 hover:bg-sage-600 text-white px-6 py-3 rounded-xl font-semibold text-center transition">
        Ir al Chat
    </a>
    <button onclick="cerrarModal()" 
            class="flex-1 bg-slate-500 hover:bg-slate-600 text-white px-6 py-3 rounded-xl font-semibold transition">
        Cerrar
    </button>
</div>

<?php if ($full_view): ?>
</div>
<script>
function cerrarModal() {
    // Si se abrio en una pestana nueva, intentar cerrarla.
    // Si el navegador bloquea window.close(), regresar a la URL de retorno.
    window.close();
    setTimeout(function () {
        window.location.href = '<?= htmlspecialchars($return_url, ENT_QUOTES) ?>';
    }, 150);
}
</script>
<?php include '../includes/footer.php'; ?>
<?php endif; ?>
