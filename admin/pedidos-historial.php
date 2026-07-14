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
    }
}

// Obtener TODOS los pedidos (sin filtro de fecha)
$pedidos = $pedidoModel->getAll();

// Estados con emojis y colores
$estados = [
    'pendiente' => ['emoji' => '', 'color' => 'bg-yellow-100 text-yellow-700', 'nombre' => 'Pendiente'],
    'por_verificar' => ['emoji' => '', 'color' => 'bg-orange-100 text-orange-700', 'nombre' => 'Por Verificar'],
    'confirmado' => ['emoji' => '', 'color' => 'bg-blue-100 text-blue-700', 'nombre' => 'Confirmado'],
    'en_ruta' => ['emoji' => '', 'color' => 'bg-purple-100 text-purple-700', 'nombre' => 'En Ruta'],
    'entregado' => ['emoji' => '', 'color' => 'bg-green-100 text-green-700', 'nombre' => 'Entregado'],
    'cancelado' => ['emoji' => '', 'color' => 'bg-red-100 text-red-700', 'nombre' => 'Cancelado'],
];

// Contar pedidos por estado
$total_pedidos = count($pedidos);
$pendientes = count(array_filter($pedidos, fn($p) => $p['estado'] === 'pendiente'));
$por_verificar = count(array_filter($pedidos, fn($p) => $p['estado'] === 'por_verificar'));
$confirmados = count(array_filter($pedidos, fn($p) => $p['estado'] === 'confirmado'));
$en_ruta = count(array_filter($pedidos, fn($p) => $p['estado'] === 'en_ruta'));
$entregados = count(array_filter($pedidos, fn($p) => $p['estado'] === 'entregado'));
$cancelados = count(array_filter($pedidos, fn($p) => $p['estado'] === 'cancelado'));
?>

<?php include '../includes/header.php'; ?>

<style>
    .ph-shell {
        max-width: 1280px;
        margin: 0 auto;
    }

    .ph-stat {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(15, 23, 42, 0.08));
    }
</style>

<div class="container mx-auto px-4 py-8">
    
    <!-- Header con Estadísticas -->
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-800 mb-2">Historial de Pedidos</h1>
                <p class="text-slate-600">Todos los pedidos del sistema sin límite de tiempo</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="kanban.php" class="btn-secondary px-4 py-2 rounded-xl flex items-center gap-2 transition shadow">
                    Vista Kanban
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-7 gap-4">
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Total</div>
                <div class="text-2xl font-bold text-slate-800"><?= $total_pedidos ?></div>
            </div>
            
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Pendientes</div>
                <div class="text-2xl font-bold text-yellow-700"><?= $pendientes ?></div>
            </div>
            
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Por Verificar</div>
                <div class="text-2xl font-bold text-orange-700"><?= $por_verificar ?></div>
            </div>
            
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Confirmados</div>
                <div class="text-2xl font-bold text-blue-700"><?= $confirmados ?></div>
            </div>
            
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">En Ruta</div>
                <div class="text-2xl font-bold text-purple-700"><?= $en_ruta ?></div>
            </div>
            
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Entregados</div>
                <div class="text-2xl font-bold text-green-700"><?= $entregados ?></div>
            </div>
            
            <div class="ph-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Cancelados</div>
                <div class="text-2xl font-bold text-red-700"><?= $cancelados ?></div>
            </div>
        </div>
    </div>

    <!-- Filtros de Búsqueda -->
    <div class="card rounded-xl shadow p-4 mb-6">
        <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
            Filtros de Búsqueda
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <!-- Filtro por ID de Pedido -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">ID de Pedido</label>
                <input type="text" 
                       id="filtro-pedido" 
                       placeholder="Ej: 1, 0001, 23"
                       class="input-field w-full px-4 py-2 rounded-xl">
            </div>
            
            <!-- Filtro por Cliente -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Cliente</label>
                <input type="text" 
                       id="filtro-cliente" 
                       placeholder="Teléfono o nombre"
                       class="input-field w-full px-4 py-2 rounded-xl">
            </div>
            
            <!-- Filtro por Estado -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Estado</label>
                <select id="filtro-estado" 
                        class="input-field w-full px-4 py-2 rounded-xl">
                    <option value="">Todos los estados</option>
                    <option value="pendiente">Pendiente</option>
                    <option value="por_verificar">Por Verificar</option>
                    <option value="confirmado">Confirmado</option>
                    <option value="en_ruta">En Ruta</option>
                    <option value="entregado">Entregado</option>
                    <option value="cancelado">Cancelado</option>
                </select>
            </div>

            <!-- Filtro por Origen -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Origen</label>
                <select id="filtro-origen"
                        class="input-field w-full px-4 py-2 rounded-xl">
                    <option value="">Todos</option>
                    <option value="cliente_directo">Web directo</option>
                    <option value="representante_qr">QR representante</option>
                    <option value="representante_directo">Entrega directa</option>
                    <option value="efectivo_pendiente">Efectivo pendiente</option>
                    <option value="cfdi_pendiente">CFDI pendiente</option>
                </select>
            </div>
            
            <!-- Botón Limpiar Filtros -->
            <div class="flex items-end">
                <button onclick="limpiarFiltros()" 
                        class="btn-secondary w-full px-4 py-2 rounded-xl font-semibold transition flex items-center justify-center gap-2">
                    Limpiar
                </button>
            </div>
        </div>
        
        <!-- Contador de resultados -->
        <div class="mt-4 pt-4 border-t border-slate-200">
            <p class="text-sm text-slate-600">
                Mostrando <span id="contador-resultados" class="font-bold text-terracotta-600">0</span> de <?= $total_pedidos ?> pedidos
            </p>
        </div>
    </div>

    <!-- Tabla de Historial -->
    <?php if (empty($pedidos)): ?>
        <div class="card rounded-xl shadow p-12 text-center">
            <div class="text-6xl mb-4"></div>
            <p class="text-slate-600 text-lg mb-2">No hay pedidos registrados</p>
            <p class="text-slate-500 text-sm">Los pedidos aparecerán aquí</p>
        </div>
    <?php else: ?>
        <div class="card rounded-xl shadow overflow-hidden">
            <!-- Header de la tabla -->
            <div class="px-6 py-4 border-b border-slate-200">
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    Lista Completa de Pedidos
                    <span class="text-sm font-normal bg-slate-100 text-slate-600 px-3 py-1 rounded-full">
                        <?= $total_pedidos ?> total
                    </span>
                </h2>
            </div>
            
            <!-- Tabla -->
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gradient-to-r from-slate-700 to-slate-800 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Fecha</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Cliente</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase">Estado</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase">Total</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($pedidos as $pedido): ?>
                            <?php 
                            $estado = $estados[$pedido['estado']];
                            $detalle = $pedidoModel->getDetalle($pedido['id']);
                            $es_entrega_directa = (($pedido['canal'] ?? '') === 'representante_directo') || ((int)($pedido['entrega_directa'] ?? 0) === 1);
                            $factura_pendiente = !empty($pedido['requiere_factura']) && empty($pedido['factura_pdf']) && empty($pedido['factura_xml']);
                            ?>
                            <tr class="pedido-row hover:bg-slate-50 transition"
                                data-pedido-id="<?= $pedido['id'] ?>"
                                data-pedido-id-formatted="<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?>"
                                data-cliente-telefono="<?= htmlspecialchars($pedido['telefono']) ?>"
                                data-cliente-nombre="<?= htmlspecialchars($pedido['nombre'] ?? '') ?>"
                                data-estado="<?= $pedido['estado'] ?>"
                                data-canal="<?= htmlspecialchars($pedido['canal'] ?? 'cliente_directo') ?>"
                                data-representante-admin-id="<?= htmlspecialchars((string)($pedido['representante_admin_id'] ?? '')) ?>"
                                data-entrega-directa="<?= $es_entrega_directa ? '1' : '0' ?>"
                                data-cfdi-pendiente="<?= $factura_pendiente ? '1' : '0' ?>"
                                data-liquidacion="<?= htmlspecialchars($pedido['estado_liquidacion'] ?? 'no_aplica') ?>">
                                <!-- ID -->
                                <td class="px-4 py-4">
                                    <span class="font-mono font-bold text-slate-900">
                                        #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?>
                                    </span>
                                </td>
                                
                                <!-- Fecha -->
                                <td class="px-4 py-4">
                                    <div class="text-sm">
                                        <div class="font-semibold text-slate-900">
                                            <?= date('d/m/Y', strtotime($pedido['created_at'])) ?>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            <?= date('H:i', strtotime($pedido['created_at'])) ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Cliente -->
                                <td class="px-4 py-4">
                                    <div class="text-sm">
                                        <div class="font-semibold text-slate-900">
                                            <?= htmlspecialchars($pedido['telefono']) ?>
                                        </div>
                                        <?php if (!empty($pedido['nombre'])): ?>
                                            <div class="text-xs text-slate-500">
                                                <?= htmlspecialchars($pedido['nombre']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex flex-wrap gap-1 mt-2">
                                            <?php if ($es_entrega_directa): ?>
                                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Entrega directa</span>
                                            <?php elseif (($pedido['canal'] ?? '') === 'representante_qr'): ?>
                                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200">QR rep</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Web</span>
                                            <?php endif; ?>

                                            <?php if (($pedido['estado_liquidacion'] ?? '') === 'pendiente'): ?>
                                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Efectivo pendiente</span>
                                            <?php endif; ?>

                                            <?php if (!empty($pedido['representante_nombre_real'])): ?>
                                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Rep: <?= htmlspecialchars($pedido['representante_nombre_real']) ?></span>
                                            <?php endif; ?>

                                            <?php if ($factura_pendiente): ?>
                                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">CFDI pendiente</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Estado -->
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex items-center gap-1 <?= $estado['color'] ?> px-3 py-1 rounded-full text-xs font-semibold">
                                        <?= $estado['emoji'] ?> <?= $estado['nombre'] ?>
                                    </span>
                                </td>
                                
                                <!-- Total -->
                                <td class="px-4 py-4 text-right">
                                    <div class="font-bold text-terracotta-600 text-lg">
                                        $<?= number_format($pedido['total'], 2) ?>
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        <?= count($detalle) ?> producto<?= count($detalle) != 1 ? 's' : '' ?>
                                    </div>
                                </td>
                                
                                <!-- Acciones -->
                                <td class="px-4 py-4">
                                    <div class="flex justify-center gap-2">
                                        <button onclick="verDetallePedido(<?= $pedido['id'] ?>)" 
                                                class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                            Ver
                                        </button>
                                        <a href="chat-admin.php?pedido_id=<?= $pedido['id'] ?>&return=historial" 
                                           class="bg-sage-500 hover:bg-sage-600 text-white px-3 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                            Chat
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal para ver detalle del pedido -->
<div id="modalDetalle" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4" onclick="cerrarModal(event)">
    <div class="card rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div id="contenidoModal" class="p-6">
            <!-- El contenido se cargará aquí -->
        </div>
    </div>
</div>

<script>
function verDetallePedido(pedidoId) {
    const modal = document.getElementById('modalDetalle');
    const contenido = document.getElementById('contenidoModal');
    
    // Mostrar modal con loading
    contenido.innerHTML = `
        <div class="text-center py-12">
            <svg class="animate-spin h-12 w-12 text-terracotta-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="text-slate-600">Cargando información del pedido...</p>
        </div>
    `;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    // Cargar datos del pedido
    fetch(`pedido-detalle.php?pedido_id=${pedidoId}`)
        .then(res => res.text())
        .then(html => {
            contenido.innerHTML = html;
        })
        .catch(error => {
            contenido.innerHTML = `
                <div class="text-center py-12">
                    <div class="text-6xl mb-4"></div>
                    <p class="text-slate-600 text-lg mb-2">Error al cargar el pedido</p>
                    <button onclick="cerrarModal()" class="mt-4 bg-slate-600 hover:bg-slate-700 text-white px-6 py-2 rounded-lg">
                        Cerrar
                    </button>
                </div>
            `;
        });
}

function cerrarModal(event) {
    if (!event || event.target.id === 'modalDetalle') {
        const modal = document.getElementById('modalDetalle');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

// Cerrar modal con tecla Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModal();
    }
});

// ==================== FILTROS ====================

// Función para aplicar filtros
function aplicarFiltros() {
    const filtroPedido = document.getElementById('filtro-pedido').value.toLowerCase().trim();
    const filtroCliente = document.getElementById('filtro-cliente').value.toLowerCase().trim();
    const filtroEstado = document.getElementById('filtro-estado').value;
    const filtroOrigen = document.getElementById('filtro-origen').value;
    
    const filas = document.querySelectorAll('.pedido-row');
    let contador = 0;
    
    filas.forEach(fila => {
        const pedidoId = fila.getAttribute('data-pedido-id');
        const pedidoIdFormatted = fila.getAttribute('data-pedido-id-formatted');
        const clienteTelefono = fila.getAttribute('data-cliente-telefono').toLowerCase();
        const clienteNombre = fila.getAttribute('data-cliente-nombre').toLowerCase();
        const estado = fila.getAttribute('data-estado');
        const canal = fila.getAttribute('data-canal');
        const entregaDirecta = fila.getAttribute('data-entrega-directa');
        const cfdiPendiente = fila.getAttribute('data-cfdi-pendiente');
        const liquidacion = fila.getAttribute('data-liquidacion');
        
        // Verificar cada filtro
        const matchPedido = !filtroPedido || 
                           pedidoId.includes(filtroPedido) || 
                           pedidoIdFormatted.includes(filtroPedido);
        
        const matchCliente = !filtroCliente || 
                            clienteTelefono.includes(filtroCliente) || 
                            clienteNombre.includes(filtroCliente);
        
        const matchEstado = !filtroEstado || estado === filtroEstado;
        const matchOrigen = !filtroOrigen ||
                            (filtroOrigen === 'cliente_directo' && canal === 'cliente_directo') ||
                            (filtroOrigen === 'representante_qr' && canal === 'representante_qr') ||
                            (filtroOrigen === 'representante_directo' && entregaDirecta === '1') ||
                            (filtroOrigen === 'efectivo_pendiente' && liquidacion === 'pendiente') ||
                            (filtroOrigen === 'cfdi_pendiente' && cfdiPendiente === '1');
        
        // Mostrar u ocultar fila según coincidencia
        if (matchPedido && matchCliente && matchEstado && matchOrigen) {
            fila.style.display = '';
            contador++;
        } else {
            fila.style.display = 'none';
        }
    });
    
    // Actualizar contador
    document.getElementById('contador-resultados').textContent = contador;
}

// Función para limpiar filtros
function limpiarFiltros() {
    document.getElementById('filtro-pedido').value = '';
    document.getElementById('filtro-cliente').value = '';
    document.getElementById('filtro-estado').value = '';
    document.getElementById('filtro-origen').value = '';
    aplicarFiltros();
}

// Event listeners para los filtros
document.addEventListener('DOMContentLoaded', function() {
    // Aplicar filtros en tiempo real
    document.getElementById('filtro-pedido').addEventListener('input', aplicarFiltros);
    document.getElementById('filtro-cliente').addEventListener('input', aplicarFiltros);
    document.getElementById('filtro-estado').addEventListener('change', aplicarFiltros);
    document.getElementById('filtro-origen').addEventListener('change', aplicarFiltros);
    
    // Inicializar contador
    aplicarFiltros();
    
    // Iniciar actualización automática de badges de mensajes
    actualizarBadgesMensajes();
    setInterval(actualizarBadgesMensajes, 10000); // Cada 10 segundos
});

// ACTUALIZAR BADGES DE MENSAJES (sin recargar página)
async function actualizarBadgesMensajes() {
    // Obtener todos los IDs de pedidos visibles en la tabla
    const pedidosFilas = document.querySelectorAll('.pedido-row');
    const pedidosIds = Array.from(pedidosFilas).map(fila => fila.dataset.pedidoId);
    
    if (pedidosIds.length === 0) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'obtener_conteo_mensajes');
        formData.append('pedidos_ids', JSON.stringify(pedidosIds));
        
        const response = await fetch('pedidos-historial.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.conteos) {
            // Actualizar cada badge
            Object.keys(data.conteos).forEach(pedidoId => {
                const count = data.conteos[pedidoId];
                const chatLink = document.querySelector(`a[href*="pedido_id=${pedidoId}"][href*="chat-admin"]`);
                
                if (!chatLink) return;
                
                let badge = chatLink.querySelector('.bg-red-500');
                
                if (count > 0) {
                    if (badge) {
                        // Actualizar el badge existente
                        badge.textContent = count;
                    } else {
                        // Crear el badge si no existe
                        const newBadge = document.createElement('span');
                        newBadge.className = 'ml-2 bg-red-500 text-white text-xs font-bold rounded-full px-2 py-0.5';
                        newBadge.textContent = count;
                        chatLink.appendChild(newBadge);
                    }
                } else {
                    // Remover el badge si no hay mensajes
                    if (badge) {
                        badge.remove();
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error actualizando badges de mensajes:', error);
    }
}
</script>

</body>
</html>
