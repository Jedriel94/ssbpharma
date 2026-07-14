<?php
session_start();
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/models/Pedido.php';
require_once __DIR__ . '/models/Cliente.php';
require_once __DIR__ . '/models/MensajePedido.php';

$pedidoModel = new Pedido();
$clienteModel = new Cliente();
$mensajeModel = new MensajePedido();

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'cancelar_pedido':
            $pedido_id = (int)($_POST['pedido_id'] ?? 0);
            $telefono_post = trim($_POST['telefono'] ?? '');

            if ($pedido_id <= 0 || $telefono_post === '') {
                echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
                exit;
            }

            $pedido = $pedidoModel->getById($pedido_id);
            if (!$pedido || $pedido['telefono'] !== $telefono_post) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }

            $esEntregaDirecta = (($pedido['canal'] ?? '') === 'representante_directo') || ((int)($pedido['entrega_directa'] ?? 0) === 1);
            if ($esEntregaDirecta) {
                echo json_encode(['success' => false, 'message' => 'Este pedido se cancela desde el módulo de representantes']);
                exit;
            }

            $resultadoCancelacion = $pedidoModel->cancelarPedidoTienda($pedido_id);
            if (empty($resultadoCancelacion['success'])) {
                echo json_encode(['success' => false, 'message' => $resultadoCancelacion['message'] ?? 'No se pudo cancelar el pedido']);
                exit;
            }

            $mensajeModel->create($pedido_id, 'cliente', 'El cliente canceló el pedido antes de procesar el pago.');

            echo json_encode(['success' => true, 'message' => $resultadoCancelacion['message'] ?? 'Pedido cancelado correctamente']);
            exit;

        case 'obtener_conteo_mensajes':
            // Decodificar el JSON que viene del JavaScript
            $pedidos_ids = isset($_POST['pedidos_ids']) ? json_decode($_POST['pedidos_ids'], true) : [];
            
            if (empty($pedidos_ids) || !is_array($pedidos_ids)) {
                echo json_encode(['success' => false, 'conteos' => [], 'error' => 'IDs vacíos o inválidos']);
                exit;
            }
            
            $conteos = [];
            foreach ($pedidos_ids as $pedido_id) {
                // Contar solo mensajes NO LEÍDOS del admin (para el cliente)
                $conteos[$pedido_id] = $mensajeModel->contarNoLeidosCliente($pedido_id);
            }
            
            echo json_encode(['success' => true, 'conteos' => $conteos]);
            exit;
            
        case 'solicitar_factura':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $telefono_post = $_POST['telefono'] ?? '';
            $rfc = trim($_POST['rfc'] ?? '');
            $razon_social = trim($_POST['razon_social'] ?? '');
            $email_factura = trim($_POST['email_factura'] ?? '');
            $codigo_postal = trim($_POST['codigo_postal'] ?? '');
            $regimen_fiscal = trim($_POST['regimen_fiscal'] ?? '');
            $uso_cfdi = trim($_POST['uso_cfdi'] ?? '');
            
            // Validar que el pedido pertenezca al cliente
            $pedido = $pedidoModel->getById($pedido_id);
            if (!$pedido || $pedido['telefono'] !== $telefono_post) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            
            // Validar que el pedido esté en un estado válido
            $estados_validos = ['confirmado', 'en_ruta', 'entregado'];
            if (!in_array($pedido['estado'], $estados_validos)) {
                echo json_encode(['success' => false, 'message' => 'Solo se puede solicitar factura en pedidos confirmados, en ruta o entregados']);
                exit;
            }
            
            // Validar campos requeridos
            if (empty($rfc) || empty($razon_social) || empty($email_factura)) {
                echo json_encode(['success' => false, 'message' => 'RFC, Razón Social y Email son obligatorios']);
                exit;
            }
            
            // Actualizar el pedido con los datos fiscales
            $sql = "UPDATE pedidos SET 
                    requiere_factura = 1,
                    rfc = :rfc,
                    razon_social = :razon_social,
                    email_factura = :email_factura,
                    codigo_postal = :codigo_postal,
                    regimen_fiscal = :regimen_fiscal,
                    uso_cfdi = :uso_cfdi
                    WHERE id = :pedido_id";
            
            $stmt = $pedidoModel->db->prepare($sql);
            $stmt->bindParam(':rfc', $rfc);
            $stmt->bindParam(':razon_social', $razon_social);
            $stmt->bindParam(':email_factura', $email_factura);
            $stmt->bindParam(':codigo_postal', $codigo_postal);
            $stmt->bindParam(':regimen_fiscal', $regimen_fiscal);
            $stmt->bindParam(':uso_cfdi', $uso_cfdi);
            $stmt->bindParam(':pedido_id', $pedido_id);
            
            if ($stmt->execute()) {
                // Crear un mensaje automático en el chat para notificar al admin
                $mensajeModel->create($pedido_id, 'cliente', 'El cliente ha solicitado factura electrónica para este pedido.');
                
                echo json_encode(['success' => true, 'message' => 'Factura solicitada exitosamente. El administrador generará tu factura pronto.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al procesar la solicitud']);
            }
            exit;
    }
}

// Obtener teléfono del cliente desde la URL
$telefono = $_GET['telefono'] ?? '';

// Validar teléfono
if (empty($telefono) || !is_numeric($telefono) || strlen($telefono) != 10) {
    header('Location: index.php');
    exit;
}

// Obtener cliente
$cliente = $clienteModel->getByTelefono($telefono);

if (!$cliente) {
    header('Location: index.php');
    exit;
}

// Actualizar representante si no tiene uno asignado (respeta el primero)
$clienteModel->actualizarRepresentanteSiNoTiene($telefono);

// Verificar contraseña si el cliente tiene una configurada
$requierePassword = !empty($cliente['password']);
$passwordVerificada = false;

if ($requierePassword) {
    // Verificar si ya está autenticado en la sesión
    if (isset($_SESSION['cliente_autenticado']) && $_SESSION['cliente_autenticado'] === $telefono) {
        $passwordVerificada = true;
    }
    
    // Procesar verificación de contraseña vía AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verificar_password') {
        $password = $_POST['password'] ?? '';
        
        if (password_verify($password, $cliente['password'])) {
            $_SESSION['cliente_autenticado'] = $telefono;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta']);
        }
        exit;
    }
    
    // Si no está autenticado, mostrar modal de contraseña
    if (!$passwordVerificada) {
        // Código HTML para mostrar solo el modal de contraseña
        include 'includes/header.php';
        ?>
        
        <div id="modalPassword" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
                <div class="p-6">
                    <div class="text-center mb-6">
                        <div class="text-6xl mb-4"></div>
                        <h2 class="text-2xl font-bold text-slate-900 mb-2">Verificación requerida</h2>
                        <p class="text-slate-600">Ingresa tu contraseña para ver tus pedidos</p>
                        <p class="text-sm text-terracotta-600 mt-2"><?= htmlspecialchars($telefono) ?></p>
                    </div>
                    
                    <form id="formPassword" onsubmit="verificarPassword(event)">
                        <div class="mb-4">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Contraseña</label>
                            <input type="password" 
                                   id="passwordInput"
                                   class="input-field w-full px-4 py-3 rounded-xl"
                                   placeholder="Ingresa tu contraseña"
                                   required
                                   autofocus>
                        </div>
                        
                        <div id="errorPassword" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
                        </div>
                        
                        <div class="flex gap-3">
                            <a href="index.php" 
                               class="flex-1 px-4 py-3 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200 transition text-center">
                                Cancelar
                            </a>
                            <button type="submit" 
                                    class="flex-1 btn-primary text-white px-4 py-3 rounded-xl font-semibold shadow-lg">
                                Verificar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script>
        async function verificarPassword(event) {
            event.preventDefault();
            
            const password = document.getElementById('passwordInput').value;
            const errorDiv = document.getElementById('errorPassword');
            
            try {
                const response = await fetch('seguimiento.php?telefono=<?= urlencode($telefono) ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=verificar_password&password=${encodeURIComponent(password)}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Recargar la página para mostrar los pedidos
                    window.location.reload();
                } else {
                    errorDiv.textContent = '' + (data.message || 'Contraseña incorrecta');
                    errorDiv.classList.remove('hidden');
                    document.getElementById('passwordInput').value = '';
                    document.getElementById('passwordInput').focus();
                }
            } catch (error) {
                errorDiv.textContent = 'Error al verificar la contraseña';
                errorDiv.classList.remove('hidden');
            }
        }
        </script>
        
        </body>
        </html>
        <?php
        exit;
    }
}

// Obtener pedidos del cliente
$pedidos = $pedidoModel->getByTelefono($telefono);

// Cerrar sesión si se solicita
if (isset($_GET['logout'])) {
    unset($_SESSION['cliente_autenticado']);
    header('Location: index.php');
    exit;
}

// Estados con emojis y colores
$estados = [
    'pendiente' => [
        'emoji' => '', 
        'color' => 'bg-yellow-100 text-yellow-700', 
        'colorBoton' => 'bg-yellow-500 hover:bg-yellow-600',
        'nombre' => 'Pendiente'
    ],
    'por_verificar' => [
        'emoji' => '', 
        'color' => 'bg-orange-100 text-orange-700', 
        'colorBoton' => 'bg-orange-500 hover:bg-orange-600',
        'nombre' => 'Por Verificar'
    ],
    'confirmado' => [
        'emoji' => '', 
        'color' => 'bg-blue-100 text-blue-700', 
        'colorBoton' => 'bg-blue-500 hover:bg-blue-600',
        'nombre' => 'Confirmado'
    ],
    'en_ruta' => [
        'emoji' => '', 
        'color' => 'bg-purple-100 text-purple-700', 
        'colorBoton' => 'bg-purple-500 hover:bg-purple-600',
        'nombre' => 'En Ruta'
    ],
    'entregado' => [
        'emoji' => '', 
        'color' => 'bg-green-100 text-green-700', 
        'colorBoton' => 'bg-green-500 hover:bg-green-600',
        'nombre' => 'Entregado'
    ],
    'cancelado' => [
        'emoji' => '', 
        'color' => 'bg-red-100 text-red-700', 
        'colorBoton' => 'bg-red-500 hover:bg-red-600',
        'nombre' => 'Cancelado'
    ],
];
?>

<?php include 'includes/header.php'; ?>

<!-- Estilos para visualización de guía de envío -->
<link rel="stylesheet" href="<?= asset('css/guia-envio.css') ?>">

<!-- Estilos para filtros de estado -->
<style>
.filtro-estado {
    position: relative;
    overflow: hidden;
    min-width: fit-content;
}

.filtro-estado::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.5s;
}

.filtro-estado:hover::before {
    left: 100%;
}

.filtro-activo {
    opacity: 1 !important;
    transform: scale(1.05);
    box-shadow: 0 8px 16px rgba(0,0,0,0.15) !important;
    position: relative;
}

.filtro-activo::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80%;
    height: 3px;
    background: white;
    border-radius: 2px 2px 0 0;
    box-shadow: 0 -2px 8px rgba(255,255,255,0.5);
}

.pedido-card {
    transition: all 0.3s ease;
}

/* Animación de entrada */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Animación de descarga exitosa */
@keyframes downloadSuccess {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(0.95); }
}

/* Efecto de ripple en botones de descarga */
.btn-descarga {
    position: relative;
    overflow: hidden;
}

.btn-descarga::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255,255,255,0.5);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.btn-descarga:active::after {
    width: 300px;
    height: 300px;
}

/* Animación de spinner para factura en proceso */
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Responsive: scroll horizontal en móviles */
@media (max-width: 640px) {
    .flex.flex-wrap.gap-3 {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        scrollbar-color: rgba(0,0,0,0.3) rgba(0,0,0,0.1);
        padding-bottom: 8px;
    }
    
    .flex.flex-wrap.gap-3::-webkit-scrollbar {
        height: 6px;
    }
    
    .flex.flex-wrap.gap-3::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.1);
        border-radius: 3px;
    }
    
    .flex.flex-wrap.gap-3::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.3);
        border-radius: 3px;
    }
    
    .filtro-estado {
        white-space: nowrap;
        font-size: 0.875rem;
    }
}
</style>

<link rel="stylesheet" href="<?= asset('css/cliente-mobile.css') ?>">
<script>document.body.classList.add('cliente-app');</script>

<div class="cliente-shell cliente-screen w-full px-4 py-8">
    
    <!-- Header -->
    <div class="cliente-page-head mb-6">
        <div class="flex justify-between items-start mb-2">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 mb-2">Mis Pedidos</h1>
                <p class="text-slate-600">
                    Cliente: <span class="font-semibold text-terracotta-600"><?= htmlspecialchars($telefono) ?></span>
                    <?php if (!empty($cliente['nombre'])): ?>
                        - <?= htmlspecialchars($cliente['nombre']) ?>
                    <?php endif; ?>
                </p>
            </div>
            
            <?php if ($requierePassword): ?>
                <a href="seguimiento.php?telefono=<?= urlencode($telefono) ?>&logout=1" 
                   class="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-200 transition inline-flex items-center gap-2"
                   onclick="return confirm('¿Cerrar sesión?')">
                    Cerrar Sesión
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Botón Nuevo Pedido -->
    <div class="mb-6">
        <a href="crear-pedido.php?telefono=<?= urlencode($telefono) ?>" 
           class="btn-primary text-white px-6 py-3 rounded-xl shadow-lg inline-flex items-center gap-2">
            Nuevo Pedido
        </a>
    </div>

    <!-- Filtros de Estado -->
    <?php if (!empty($pedidos)): ?>
        <?php
        // Contar pedidos por estado
        $contadores = [];
        foreach ($pedidos as $pedido) {
            $estado_key = $pedido['estado'];
            if (!isset($contadores[$estado_key])) {
                $contadores[$estado_key] = 0;
            }
            $contadores[$estado_key]++;
        }
        ?>
        
        <div class="card rounded-2xl shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-900">Filtrar por Estado</h2>
                <div class="hidden sm:block text-xs text-slate-500">
                    <span id="contadorVisible"><?= count($pedidos) ?></span> de <?= count($pedidos) ?> pedidos
                </div>
            </div>
            
            <div class="relative">
                <!-- Indicador de scroll en móviles -->
                <div class="sm:hidden absolute right-0 top-0 bottom-0 w-8 bg-gradient-to-l from-white to-transparent pointer-events-none z-10 rounded-r-xl"></div>
                
                <div class="flex flex-wrap gap-3">
                <!-- Botón Todos -->
                <button onclick="filtrarPorEstado('todos')" 
                        data-estado="todos"
                        class="filtro-estado filtro-activo px-4 py-2 rounded-xl font-semibold transition-all shadow-sm hover:shadow-md bg-slate-600 hover:bg-slate-700 text-white">
                    Todos (<?= count($pedidos) ?>)
                </button>
                
                <!-- Botones por estado -->
                <?php foreach ($estados as $estado_key => $estado_info): ?>
                    <?php if (isset($contadores[$estado_key]) && $contadores[$estado_key] > 0): ?>
                        <button onclick="filtrarPorEstado('<?= $estado_key ?>')" 
                                data-estado="<?= $estado_key ?>"
                                class="filtro-estado px-4 py-2 rounded-xl font-semibold transition-all shadow-sm hover:shadow-md <?= $estado_info['colorBoton'] ?> text-white opacity-60 hover:opacity-100">
                            <?= $estado_info['emoji'] ?> <?= $estado_info['nombre'] ?> (<?= $contadores[$estado_key] ?>)
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Indicador de filtro activo -->
            <div id="filtroActivo" class="mt-4 hidden">
                <div class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="font-semibold">Mostrando:</span>
                    <span id="filtroTexto" class="px-3 py-1 bg-slate-100 rounded-lg"></span>
                    <button onclick="filtrarPorEstado('todos')" class="text-terracotta-600 hover:text-terracotta-700 font-semibold ml-2">
                        Limpiar filtro
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Lista de Pedidos -->
    <?php if (empty($pedidos)): ?>
        <div class="card rounded-2xl shadow-lg p-12 text-center">
            <div class="text-6xl mb-4"></div>
            <p class="text-slate-600 text-lg mb-2">No tienes pedidos registrados</p>
            <p class="text-slate-500 text-sm">Haz tu primer pedido para comenzar</p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($pedidos as $pedido): ?>
                <?php 
                $detalle = $pedidoModel->getDetalle($pedido['id']);
                $estado = $estados[$pedido['estado']] ?? ['emoji' => '', 'color' => 'bg-gray-100 text-gray-700', 'nombre' => 'Desconocido'];
                $canalPedido = $pedido['canal'] ?? 'cliente_directo';
                $esEntregaDirecta = $canalPedido === 'representante_directo' || (int)($pedido['entrega_directa'] ?? 0) === 1;
                $badgeCanal = $esEntregaDirecta
                    ? ['texto' => 'Entrega directa', 'clases' => 'bg-emerald-100 text-emerald-700 border border-emerald-200']
                    : ($canalPedido === 'representante_qr'
                        ? ['texto' => 'QR rep', 'clases' => 'bg-slate-100 text-slate-700 border border-slate-200']
                        : (($canalPedido === 'cliente_directo' && !empty($pedido['representante_admin_id']))
                            ? ['texto' => 'Tienda', 'clases' => 'bg-sky-100 text-sky-700 border border-sky-200']
                            : ['texto' => 'Web', 'clases' => 'bg-blue-100 text-blue-700 border border-blue-200']));
                ?>
                
                <div class="card rounded-2xl shadow-lg overflow-hidden pedido-card" data-estado="<?= $pedido['estado'] ?>">
                    <!-- Header del Pedido -->
                    <div class="bg-gradient-to-r from-cream-100 to-cream-200 px-6 py-4 border-b border-cream-300">
                        <div class="flex justify-between items-start mb-2">
                            <div>
                                <h3 class="text-lg font-bold text-slate-900">
                                    Pedido #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?>
                                </h3>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm text-slate-600">
                                        <?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?>
                                    </p>
                                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold <?= $badgeCanal['clases'] ?>">
                                        <?= $badgeCanal['texto'] ?>
                                    </span>
                                </div>
                            </div>
                            <span class="<?= $estado['color'] ?> px-4 py-2 rounded-full text-sm font-semibold">
                                <?= $estado['emoji'] ?> <?= $estado['nombre'] ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <p class="text-slate-700">
                                <span class="font-semibold"><?= count($detalle) ?></span> 
                                producto<?= count($detalle) != 1 ? 's' : '' ?>
                            </p>
                            <p class="text-2xl font-bold text-terracotta-600">
                                $<?= number_format($pedido['total'], 2) ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Detalle del Pedido -->
                    <div class="p-6">
                        <div class="space-y-3">
                            <?php foreach ($detalle as $item): ?>
                                <div class="flex items-center gap-4 bg-cream-50 p-3 rounded-xl">
                                    <!-- Imagen del producto -->
                                    <?php if ($item['imagen']): ?>
                                        <img src="<?= url('uploads/productos/' . htmlspecialchars($item['imagen'])) ?>" 
                                             alt="<?= htmlspecialchars($item['producto']) ?>"
                                             class="w-16 h-16 object-cover rounded-lg">
                                    <?php else: ?>
                                        <div class="w-16 h-16 bg-slate-200 rounded-lg flex items-center justify-center">
                                            <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Info del producto -->
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-slate-900"><?= htmlspecialchars($item['producto']) ?></h4>
                                        <p class="text-sm text-slate-600">
                                            <?= $item['cantidad'] ?> unidad<?= $item['cantidad'] != 1 ? 'es' : '' ?> 
                                            × $<?= number_format($item['precio_unitario'], 2) ?>
                                        </p>
                                    </div>
                                    
                                    <!-- Subtotal -->
                                    <div class="text-right">
                                        <p class="font-bold text-slate-900">
                                            $<?= number_format($item['subtotal'], 2) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Notas del pedido -->
                        <?php if (!empty($pedido['notas'])): ?>
                            <div class="mt-4 p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded">
                                <p class="text-sm font-semibold text-yellow-800 mb-1">Notas:</p>
                                <p class="text-sm text-yellow-700"><?= nl2br(htmlspecialchars($pedido['notas'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Documentos de Factura (si requiere factura) -->
                        <?php if ($pedido['requiere_factura']): ?>
                            <div class="mt-4 p-4 bg-gradient-to-r from-amber-50 to-orange-50 border-2 border-amber-300 rounded-xl shadow-sm">
                                <div class="flex items-start gap-3 mb-3">
                                    <div class="text-3xl"></div>
                                    <div class="flex-1">
                                        <p class="text-base font-bold text-amber-900 mb-1">Factura Electrónica</p>
                                        <p class="text-sm text-amber-700">
                                            <?php if (!empty($pedido['factura_pdf']) || !empty($pedido['factura_xml'])): ?>
                                                Tu factura está lista para descargar
                                            <?php else: ?>
                                                Factura solicitada - En proceso de generación
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <?php if (!empty($pedido['factura_pdf']) || !empty($pedido['factura_xml'])): ?>
                                    <!-- Botones de descarga -->
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <?php if (!empty($pedido['factura_pdf'])): ?>
                                            <a href="<?= url('uploads/facturas/' . htmlspecialchars($pedido['factura_pdf'])) ?>" 
                                               target="_blank"
                                               download
                                               class="btn-descarga flex items-center justify-center gap-2 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-lg font-semibold shadow-md hover:shadow-lg transition-all transform hover:scale-105">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                                </svg>
                                                Descargar PDF
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($pedido['factura_xml'])): ?>
                                            <a href="<?= url('uploads/facturas/' . htmlspecialchars($pedido['factura_xml'])) ?>" 
                                               download
                                               class="btn-descarga flex items-center justify-center gap-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-lg font-semibold shadow-md hover:shadow-lg transition-all transform hover:scale-105">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"></path>
                                                </svg>
                                                Descargar XML
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Información adicional de la factura -->
                                    <div class="mt-4 pt-3 border-t border-amber-200">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-amber-800">
                                            <?php if (!empty($pedido['rfc'])): ?>
                                                <div>
                                                    <span class="font-semibold">RFC:</span> 
                                                    <span class="font-mono"><?= htmlspecialchars($pedido['rfc']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($pedido['razon_social'])): ?>
                                                <div>
                                                    <span class="font-semibold">Razón Social:</span> 
                                                    <?= htmlspecialchars($pedido['razon_social']) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($pedido['uso_cfdi'])): ?>
                                                <div>
                                                    <span class="font-semibold">Uso CFDI:</span> 
                                                    <?= htmlspecialchars($pedido['uso_cfdi']) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($pedido['email_factura'])): ?>
                                                <div>
                                                    <span class="font-semibold">Email:</span> 
                                                    <?= htmlspecialchars($pedido['email_factura']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 flex items-center gap-2 text-xs text-amber-600">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <span>Los archivos PDF y XML son válidos ante el SAT</span>
                                    </div>
                                <?php else: ?>
                                    <!-- Factura en proceso -->
                                    <div class="flex items-center gap-3 p-3 bg-amber-100 rounded-lg">
                                        <div class="flex-shrink-0">
                                            <svg class="animate-spin h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-amber-900">Factura en proceso</p>
                                            <p class="text-xs text-amber-700">Estamos generando tu factura electrónica. Te notificaremos cuando esté lista.</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Datos fiscales proporcionados -->
                                    <?php if (!empty($pedido['rfc']) || !empty($pedido['razon_social'])): ?>
                                        <div class="mt-3 p-3 bg-white bg-opacity-50 rounded-lg">
                                            <p class="text-xs font-semibold text-amber-800 mb-2">Datos fiscales registrados:</p>
                                            <div class="grid grid-cols-1 gap-1 text-xs text-amber-700">
                                                <?php if (!empty($pedido['rfc'])): ?>
                                                    <div>
                                                        <span class="font-semibold">RFC:</span> 
                                                        <span class="font-mono"><?= htmlspecialchars($pedido['rfc']) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($pedido['razon_social'])): ?>
                                                    <div>
                                                        <span class="font-semibold">Razón Social:</span> 
                                                        <?= htmlspecialchars($pedido['razon_social']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Botón Chat con Proveedor -->
                        <div class="mt-4 pt-4 border-t border-cream-200">
                            <?php 
                            // Contar solo mensajes NO LEÍDOS del admin
                            $mensajes_admin_no_leidos = $mensajeModel->contarNoLeidosCliente($pedido['id']);
                            ?>
                            <a href="chat-pedido.php?pedido_id=<?= $pedido['id'] ?>&telefono=<?= urlencode($telefono) ?>" 
                               data-pedido-id="<?= $pedido['id'] ?>"
                               data-chat-link
                               class="bg-sage-500 hover:bg-sage-600 text-white px-6 py-3 rounded-xl shadow-md inline-flex items-center gap-2 w-full justify-center font-semibold transition relative">
                                Chat con Proveedor
                                <?php if ($mensajes_admin_no_leidos > 0): ?>
                                    <span class="badge-mensajes absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center animate-pulse">
                                        <?= $mensajes_admin_no_leidos ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </div>
                        
                        <!-- Botón Solicitar Factura (si NO requiere factura y está confirmado/en_ruta/entregado) -->
                        <?php if (!$pedido['requiere_factura'] && in_array($pedido['estado'], ['confirmado', 'en_ruta', 'entregado'])): ?>
                            <div class="mt-3">
                                <button onclick="abrirModalSolicitarFactura(<?= $pedido['id'] ?>)"
                                        class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-3 rounded-xl shadow-md inline-flex items-center gap-2 w-full justify-center font-semibold transition">
                                    Solicitar Factura
                                </button>
                                <p class="text-xs text-slate-500 text-center mt-2">
                                    Puedes solicitar tu factura electrónica
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Botón Proceder al Pago (solo para pedidos pendientes) -->
                        <?php if ($pedido['estado'] === 'pendiente' && !$esEntregaDirecta): ?>
                            <div class="mt-4">
                                <a href="procesar-pago.php?pedido_id=<?= $pedido['id'] ?>&telefono=<?= urlencode($telefono) ?>" 
                                   class="btn-primary text-white px-6 py-3 rounded-xl shadow-md inline-flex items-center gap-2 w-full justify-center font-semibold hover:shadow-lg transition">
                                    Proceder al Pago
                                </a>
                                <p class="text-xs text-slate-500 text-center mt-2">
                                    Este pedido está pendiente de pago
                                </p>
                            </div>
                        <?php elseif ($pedido['estado'] === 'pendiente' && $esEntregaDirecta): ?>
                            <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-xl text-center">
                                <p class="text-sm font-semibold text-amber-800">Este pedido se procesa desde el módulo de representantes</p>
                                <p class="text-xs text-amber-700 mt-1">No puedes cancelar ni procesar el pago desde seguimiento</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($pedido['estado'] === 'pendiente' && !$esEntregaDirecta): ?>
                            <div class="mt-3">
                                <button type="button"
                                        onclick="cancelarPedido(<?= $pedido['id'] ?>)"
                                        class="w-full px-6 py-3 rounded-xl border-2 border-red-300 text-red-700 bg-red-50 hover:bg-red-100 font-semibold transition">
                                    Cancelar pedido
                                </button>
                                <p class="text-xs text-slate-500 text-center mt-2">
                                    Solo disponible antes de procesar el pago
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Información de pago verificado (para pedidos por_verificar) -->
                        <?php if ($pedido['estado'] === 'por_verificar'): ?>
                            <div class="mt-4 p-4 bg-orange-50 border-l-4 border-orange-400 rounded">
                                <p class="text-sm font-semibold text-orange-800 mb-1">Pago en Verificación</p>
                                <p class="text-sm text-orange-700">Hemos recibido tu comprobante de pago. Estamos verificando la información y te notificaremos pronto.</p>
                                <?php if (!empty($pedido['metodo_pago'])): ?>
                                    <p class="text-xs text-orange-600 mt-2">
                                        Método: <span class="font-semibold capitalize"><?= htmlspecialchars($pedido['metodo_pago']) ?></span>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Información de pedido confirmado -->
                        <?php if ($pedido['estado'] === 'confirmado'): ?>
                            <div class="mt-4 p-4 bg-blue-50 border-l-4 border-blue-400 rounded">
                                <p class="text-sm font-semibold text-blue-800 mb-1">Pago Confirmado</p>
                                <p class="text-sm text-blue-700">Tu pago ha sido verificado exitosamente. Estamos preparando tu pedido para el envío.</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Información de envío (para pedidos en_ruta) -->
                        <?php if ($pedido['estado'] === 'en_ruta' && !empty($pedido['comprobante_envio'])): ?>
                            <div class="mt-4 p-4 bg-purple-50 border-l-4 border-purple-400 rounded">
                                <p class="text-sm font-semibold text-purple-800 mb-3">Tu Pedido Está En Camino</p>
                                <p class="text-sm text-purple-700 mb-4">Hemos enviado tu pedido. Aquí está la guía de envío:</p>
                                
                                <!-- Visualizador de Guía de Envío -->
                                <div class="bg-white rounded-lg p-3 border-2 border-purple-200">
                                    <?php 
                                    $extension = strtolower(pathinfo($pedido['comprobante_envio'], PATHINFO_EXTENSION));
                                    $rutaComprobante = url('uploads/comprobantes_envio/' . htmlspecialchars($pedido['comprobante_envio']));
                                    ?>
                                    
                                    <?php if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                        <!-- Imagen de la guía -->
                                        <div class="text-center">
                                            <img src="<?= $rutaComprobante ?>" 
                                                 alt="Guía de envío"
                                                 class="max-w-full h-auto rounded-lg shadow-md cursor-pointer hover:shadow-xl transition"
                                                 onclick="abrirImagenCompleta('<?= $rutaComprobante ?>')">
                                            <p class="text-xs text-slate-500 mt-2">
                                                Haz clic en la imagen para verla en tamaño completo
                                            </p>
                                        </div>
                                    <?php elseif ($extension === 'pdf'): ?>
                                        <!-- Visor de PDF -->
                                        <div class="space-y-3">
                                            <div class="flex items-center justify-between p-3 bg-purple-100 rounded-lg">
                                                <div class="flex items-center gap-3">
                                                    <span class="text-3xl"></span>
                                                    <div>
                                                        <p class="font-semibold text-purple-900">Guía de Envío (PDF)</p>
                                                        <p class="text-xs text-purple-700">Haz clic para abrir</p>
                                                    </div>
                                                </div>
                                                <a href="<?= $rutaComprobante ?>" 
                                                   target="_blank"
                                                   class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                    </svg>
                                                    Abrir
                                                </a>
                                            </div>
                                            <!-- Embed del PDF -->
                                            <iframe src="<?= $rutaComprobante ?>" 
                                                    class="w-full h-96 rounded-lg border-2 border-purple-200"
                                                    frameborder="0">
                                            </iframe>
                                        </div>
                                    <?php else: ?>
                                        <!-- Otros formatos -->
                                        <div class="flex items-center justify-between p-3 bg-purple-100 rounded-lg">
                                            <div class="flex items-center gap-3">
                                                <span class="text-3xl"></span>
                                                <div>
                                                    <p class="font-semibold text-purple-900">Guía de Envío</p>
                                                    <p class="text-xs text-purple-700">Archivo adjunto</p>
                                                </div>
                                            </div>
                                            <a href="<?= $rutaComprobante ?>" 
                                               target="_blank"
                                               download
                                               class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                                                Descargar
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="text-xs text-purple-600 mt-3 text-center">
                                    Guarda esta información para rastrear tu pedido
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Información de pedido entregado -->
                        <?php if ($pedido['estado'] === 'entregado'): ?>
                            <div class="mt-4 p-4 bg-green-50 border-l-4 border-green-400 rounded">
                                <p class="text-sm font-semibold text-green-800 mb-1">¡Pedido Entregado!</p>
                                <p class="text-sm text-green-700">Tu pedido ha sido entregado exitosamente. ¡Gracias por tu compra!</p>
                                
                                <!-- Mostrar guía de envío también en entregado -->
                                <?php if (!empty($pedido['comprobante_envio'])): ?>
                                    <div class="mt-3 pt-3 border-t border-green-200">
                                        <p class="text-xs font-semibold text-green-800 mb-2">Información de Envío:</p>
                                        <?php 
                                        $extension = strtolower(pathinfo($pedido['comprobante_envio'], PATHINFO_EXTENSION));
                                        $rutaComprobante = url('uploads/comprobantes_envio/' . htmlspecialchars($pedido['comprobante_envio']));
                                        ?>
                                        <a href="<?= $rutaComprobante ?>" 
                                           target="_blank"
                                           class="inline-flex items-center gap-2 text-sm text-green-700 hover:text-green-900 font-semibold">
                                            <?php if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                                Ver guía de envío
                                            <?php elseif ($extension === 'pdf'): ?>
                                                Ver guía de envío (PDF)
                                            <?php else: ?>
                                                Descargar guía de envío
                                            <?php endif; ?>
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                            </svg>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal para ver imagen en tamaño completo -->
<div id="modalImagen" class="fixed inset-0 bg-black bg-opacity-90 z-50 hidden flex items-center justify-center p-4" onclick="cerrarModalImagen()">
    <div class="relative max-w-6xl max-h-screen">
        <button onclick="cerrarModalImagen()" class="absolute -top-12 right-0 text-white hover:text-gray-300 text-4xl font-bold">
            
        </button>
        <img id="imagenCompleta" src="" alt="Guía de envío" class="max-w-full max-h-screen rounded-lg shadow-2xl">
    </div>
</div>

<!-- Modal para Solicitar Factura -->
<div id="modalSolicitarFactura" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 mb-1">Solicitar Factura Electrónica</h2>
                    <p class="text-sm text-slate-600">Pedido #<span id="pedidoIdFactura"></span></p>
                </div>
                <button onclick="cerrarModalSolicitarFactura()" class="text-slate-400 hover:text-slate-600 text-2xl">
                    
                </button>
            </div>
            
            <!-- Formulario -->
            <form id="formSolicitarFactura" onsubmit="enviarSolicitudFactura(event)">
                <input type="hidden" id="pedidoIdInput" name="pedido_id">
                <input type="hidden" name="telefono" value="<?= htmlspecialchars($telefono) ?>">
                
                <div class="space-y-4">
                    <!-- RFC -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            RFC <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               name="rfc"
                               id="rfcInput"
                               class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-terracotta-500"
                               placeholder="Ej: XAXX010101000"
                               pattern="[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}"
                               maxlength="13"
                               required
                               oninput="this.value = this.value.toUpperCase()">
                        <p class="text-xs text-slate-500 mt-1">12 o 13 caracteres</p>
                    </div>
                    
                    <!-- Razón Social -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Razón Social <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               name="razon_social"
                               class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-terracotta-500"
                               placeholder="Nombre o empresa"
                               required>
                    </div>
                    
                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Correo Electrónico <span class="text-red-500">*</span>
                        </label>
                        <input type="email" 
                               name="email_factura"
                               class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-terracotta-500"
                               placeholder="correo@ejemplo.com"
                               required>
                        <p class="text-xs text-slate-500 mt-1">Aquí recibirás tu factura</p>
                    </div>
                    
                    <!-- Código Postal -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Código Postal Fiscal
                        </label>
                        <input type="text" 
                               name="codigo_postal"
                               class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-terracotta-500"
                               placeholder="00000"
                               pattern="[0-9]{5}"
                               maxlength="5">
                    </div>
                    
                    <!-- Régimen Fiscal -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Régimen Fiscal
                        </label>
                        <select name="regimen_fiscal"
                                class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-terracotta-500">
                            <option value="">Selecciona un régimen</option>
                            <option value="601">601 - General de Ley Personas Morales</option>
                            <option value="603">603 - Personas Morales con Fines no Lucrativos</option>
                            <option value="605">605 - Sueldos y Salarios e Ingresos Asimilados a Salarios</option>
                            <option value="606">606 - Arrendamiento</option>
                            <option value="607">607 - Régimen de Enajenación o Adquisición de Bienes</option>
                            <option value="608">608 - Demás ingresos</option>
                            <option value="610">610 - Residentes en el Extranjero sin Establecimiento Permanente</option>
                            <option value="611">611 - Ingresos por Dividendos (socios y accionistas)</option>
                            <option value="612">612 - Personas Físicas con Actividades Empresariales y Profesionales</option>
                            <option value="614">614 - Ingresos por intereses</option>
                            <option value="615">615 - Régimen de los ingresos por obtención de premios</option>
                            <option value="616">616 - Sin obligaciones fiscales</option>
                            <option value="620">620 - Sociedades Cooperativas de Producción que optan por diferir sus ingresos</option>
                            <option value="621">621 - Incorporación Fiscal</option>
                            <option value="622">622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras</option>
                            <option value="623">623 - Opcional para Grupos de Sociedades</option>
                            <option value="624">624 - Coordinados</option>
                            <option value="625">625 - Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas</option>
                            <option value="626">626 - Régimen Simplificado de Confianza</option>
                        </select>
                    </div>
                    
                    <!-- Uso CFDI -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Uso de CFDI
                        </label>
                        <select name="uso_cfdi"
                                class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-terracotta-500">
                            <option value="">Selecciona el uso</option>
                            <option value="G01">G01 - Adquisición de mercancías</option>
                            <option value="G02">G02 - Devoluciones, descuentos o bonificaciones</option>
                            <option value="G03">G03 - Gastos en general</option>
                            <option value="I01">I01 - Construcciones</option>
                            <option value="I02">I02 - Mobilario y equipo de oficina por inversiones</option>
                            <option value="I03">I03 - Equipo de transporte</option>
                            <option value="I04">I04 - Equipo de cómputo y accesorios</option>
                            <option value="I05">I05 - Dados, troqueles, moldes, matrices y herramental</option>
                            <option value="I06">I06 - Comunicaciones telefónicas</option>
                            <option value="I07">I07 - Comunicaciones satelitales</option>
                            <option value="I08">I08 - Otra maquinaria y equipo</option>
                            <option value="D01">D01 - Honorarios médicos, dentales y gastos hospitalarios</option>
                            <option value="D02">D02 - Gastos médicos por incapacidad o discapacidad</option>
                            <option value="D03">D03 - Gastos funerales</option>
                            <option value="D04">D04 - Donativos</option>
                            <option value="D05">D05 - Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)</option>
                            <option value="D06">D06 - Aportaciones voluntarias al SAR</option>
                            <option value="D07">D07 - Primas por seguros de gastos médicos</option>
                            <option value="D08">D08 - Gastos de transportación escolar obligatoria</option>
                            <option value="D09">D09 - Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones</option>
                            <option value="D10">D10 - Pagos por servicios educativos (colegiaturas)</option>
                            <option value="S01">S01 - Sin efectos fiscales</option>
                            <option value="CP01">CP01 - Pagos</option>
                            <option value="CN01">CN01 - Nómina</option>
                        </select>
                    </div>
                </div>
                
                <!-- Mensaje de error -->
                <div id="errorSolicitudFactura" class="hidden mt-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
                </div>
                
                <!-- Mensaje de éxito -->
                <div id="exitoSolicitudFactura" class="hidden mt-4 p-3 bg-green-50 border border-green-200 rounded-xl text-green-600 text-sm">
                </div>
                
                <!-- Botones -->
                <div class="flex gap-3 mt-6">
                    <button type="button" 
                            onclick="cerrarModalSolicitarFactura()"
                            class="flex-1 px-4 py-3 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200 transition">
                        Cancelar
                    </button>
                    <button type="submit" 
                            id="btnEnviarSolicitud"
                            class="flex-1 btn-primary text-white px-4 py-3 rounded-xl font-semibold shadow-lg">
                        Solicitar Factura
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Filtrar pedidos por estado
function filtrarPorEstado(estadoFiltro) {
    const pedidoCards = document.querySelectorAll('.pedido-card');
    const botonesFiltro = document.querySelectorAll('.filtro-estado');
    const filtroActivoDiv = document.getElementById('filtroActivo');
    const filtroTexto = document.getElementById('filtroTexto');
    const contadorVisible = document.getElementById('contadorVisible');
    
    // Guardar filtro en localStorage
    localStorage.setItem('filtroEstadoPedidos', estadoFiltro);
    
    // Actualizar botones activos
    botonesFiltro.forEach(btn => {
        if (btn.dataset.estado === estadoFiltro) {
            btn.classList.add('filtro-activo');
            btn.classList.remove('opacity-60');
            btn.classList.add('shadow-lg');
        } else {
            btn.classList.remove('filtro-activo');
            btn.classList.add('opacity-60');
            btn.classList.remove('shadow-lg');
        }
    });
    
    // Mostrar/ocultar pedidos
    let pedidosVisibles = 0;
    pedidoCards.forEach(card => {
        if (estadoFiltro === 'todos' || card.dataset.estado === estadoFiltro) {
            card.style.display = 'block';
            // Animación de entrada
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.3s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50 * pedidosVisibles);
            pedidosVisibles++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Actualizar contador
    if (contadorVisible) {
        contadorVisible.textContent = pedidosVisibles;
        // Animación del contador
        contadorVisible.style.transform = 'scale(1.3)';
        contadorVisible.style.color = '#dc2626';
        setTimeout(() => {
            contadorVisible.style.transition = 'all 0.3s ease';
            contadorVisible.style.transform = 'scale(1)';
            contadorVisible.style.color = '';
        }, 200);
    }
    
    // Actualizar indicador de filtro activo
    if (estadoFiltro === 'todos') {
        filtroActivoDiv.classList.add('hidden');
    } else {
        filtroActivoDiv.classList.remove('hidden');
        const btnActivo = document.querySelector(`[data-estado="${estadoFiltro}"]`);
        filtroTexto.textContent = btnActivo ? btnActivo.textContent : estadoFiltro;
    }
    
    // Mostrar mensaje si no hay pedidos
    mostrarMensajeSinResultados(pedidosVisibles);
    
    // Scroll suave al inicio de la lista
    if (pedidosVisibles > 0) {
        const primeraCard = document.querySelector('.pedido-card[style*="display: block"]');
        if (primeraCard) {
            setTimeout(() => {
                primeraCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        }
    }
}

// Mostrar mensaje cuando no hay resultados
function mostrarMensajeSinResultados(cantidad) {
    let mensajeDiv = document.getElementById('mensajeSinResultados');
    
    if (cantidad === 0) {
        if (!mensajeDiv) {
            mensajeDiv = document.createElement('div');
            mensajeDiv.id = 'mensajeSinResultados';
            mensajeDiv.className = 'card rounded-2xl shadow-lg p-12 text-center';
            mensajeDiv.innerHTML = `
                <div class="text-6xl mb-4"></div>
                <p class="text-slate-600 text-lg mb-2">No hay pedidos con este estado</p>
                <p class="text-slate-500 text-sm">Intenta con otro filtro</p>
            `;
            document.querySelector('.space-y-4').appendChild(mensajeDiv);
        }
        mensajeDiv.style.display = 'block';
    } else {
        if (mensajeDiv) {
            mensajeDiv.style.display = 'none';
        }
    }
}

// Feedback visual para descargas
function mostrarMensajeDescarga(tipo) {
    const mensaje = document.createElement('div');
    mensaje.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-3 animate-bounce';
    mensaje.innerHTML = `
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <span class="font-semibold">¡Descargando ${tipo}!</span>
    `;
    document.body.appendChild(mensaje);
    
    setTimeout(() => {
        mensaje.style.transition = 'all 0.3s ease';
        mensaje.style.opacity = '0';
        mensaje.style.transform = 'translateY(-20px)';
        setTimeout(() => mensaje.remove(), 300);
    }, 2000);
}

// Event listeners para botones de descarga
document.addEventListener('DOMContentLoaded', function() {
    // Agregar eventos a botones de descarga
    document.querySelectorAll('.btn-descarga').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const texto = this.textContent.trim();
            if (texto.includes('PDF')) {
                mostrarMensajeDescarga('factura PDF');
            } else if (texto.includes('XML')) {
                mostrarMensajeDescarga('archivo XML');
            }
        });
    });
});

// Inicializar notificaciones
document.addEventListener('DOMContentLoaded', function() {
    // Restaurar filtro guardado
    const filtroGuardado = localStorage.getItem('filtroEstadoPedidos');
    if (filtroGuardado && filtroGuardado !== 'todos') {
        // Verificar que el botón de filtro existe
        const botonFiltro = document.querySelector(`[data-estado="${filtroGuardado}"]`);
        if (botonFiltro) {
            filtrarPorEstado(filtroGuardado);
        }
    }
    
    if (window.notificationManager) {
        // Mostrar botón para activar notificaciones
        notificationManager.showPermissionButton();
        
        // Si ya tiene permisos, iniciar monitoreo de estado
        if (Notification.permission === 'granted') {
            <?php foreach ($pedidos as $pedido): ?>
                <?php if ($pedido['estado'] !== 'entregado' && $pedido['estado'] !== 'cancelado'): ?>
                    // Monitorear cambios de estado del pedido #<?= $pedido['id'] ?>
                    notificationManager.startStatusMonitoring(<?= $pedido['id'] ?>);
                <?php endif; ?>
            <?php endforeach; ?>
        }
    }
});

// Función para abrir imagen en modal
function abrirImagenCompleta(url) {
    const modal = document.getElementById('modalImagen');
    const imagen = document.getElementById('imagenCompleta');
    imagen.src = url;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

// Función para cerrar modal
function cerrarModalImagen() {
    const modal = document.getElementById('modalImagen');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Funciones para modal de solicitar factura
function abrirModalSolicitarFactura(pedidoId) {
    const modal = document.getElementById('modalSolicitarFactura');
    document.getElementById('pedidoIdFactura').textContent = String(pedidoId).padStart(4, '0');
    document.getElementById('pedidoIdInput').value = pedidoId;
    
    // Limpiar formulario
    document.getElementById('formSolicitarFactura').reset();
    document.getElementById('pedidoIdInput').value = pedidoId;
    document.getElementById('errorSolicitudFactura').classList.add('hidden');
    document.getElementById('exitoSolicitudFactura').classList.add('hidden');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function cerrarModalSolicitarFactura() {
    const modal = document.getElementById('modalSolicitarFactura');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

async function cancelarPedido(pedidoId) {
    const ejecutarCancelacion = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'cancelar_pedido');
            formData.append('pedido_id', pedidoId);
            formData.append('telefono', '<?= urlencode($telefono) ?>');

            const response = await fetch('seguimiento.php?telefono=<?= urlencode($telefono) ?>', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (data.success) {
                if (window.showToast) {
                    showToast(data.message || 'Pedido cancelado', 'success');
                } else {
                    alert('' + (data.message || 'Pedido cancelado'));
                }
                window.location.reload();
            } else {
                if (window.showToast) {
                    showToast(data.message || 'No se pudo cancelar el pedido', 'error');
                } else {
                    alert('' + (data.message || 'No se pudo cancelar el pedido'));
                }
            }
        } catch (error) {
            if (window.showToast) {
                showToast('Error de red al cancelar el pedido', 'error');
            } else {
                alert('Error de red al cancelar el pedido');
            }
        }
    };

    if (window.showConfirm) {
        showConfirm(
            '¿Seguro que deseas cancelar este pedido? Solo podrás hacerlo si aún no se ha procesado el pago.',
            ejecutarCancelacion,
            { labelOk: 'Sí, cancelar', labelCan: 'No', danger: true }
        );
        return;
    }

    if (!confirm('¿Seguro que deseas cancelar este pedido? Solo podrás hacerlo si aún no se ha procesado el pago.')) {
        return;
    }

    ejecutarCancelacion();
}

async function enviarSolicitudFactura(event) {
    event.preventDefault();
    
    const btn = document.getElementById('btnEnviarSolicitud');
    const errorDiv = document.getElementById('errorSolicitudFactura');
    const exitoDiv = document.getElementById('exitoSolicitudFactura');
    
    errorDiv.classList.add('hidden');
    exitoDiv.classList.add('hidden');
    
    btn.disabled = true;
    btn.textContent = 'Procesando...';
    
    try {
        const formData = new FormData(document.getElementById('formSolicitarFactura'));
        formData.append('action', 'solicitar_factura');
        
        const response = await fetch('seguimiento.php?telefono=<?= urlencode($telefono) ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            exitoDiv.textContent = '' + data.message;
            exitoDiv.classList.remove('hidden');
            
            // Recargar la página después de 2 segundos
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            errorDiv.textContent = '' + data.message;
            errorDiv.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Solicitar Factura';
        }
    } catch (error) {
        errorDiv.textContent = 'Error al procesar la solicitud';
        errorDiv.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Solicitar Factura';
    }
}

// Cerrar modal con tecla Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalImagen();
        cerrarModalSolicitarFactura();
    }
});
</script>

<!-- Configuración de rutas para JavaScript -->
<script>
    window.BASE_PATH = '<?= BASE_PATH ?>';
</script>

<!-- Notificaciones Push -->
<script src="<?= asset('js/notifications.js') ?>"></script>
<script src="<?= asset('js/ui-toast.js') ?>"></script>

<!-- Sistema de actualización automática de badges de mensajes -->
<?php if (!empty($pedidos)): ?>
<script>
// ACTUALIZAR BADGES DE MENSAJES - Cliente
async function actualizarBadgesMensajes() {
    const pedidosActivos = <?= json_encode(array_column($pedidos, 'id')) ?>;
    
    if (pedidosActivos.length === 0) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'obtener_conteo_mensajes');
        formData.append('pedidos_ids', JSON.stringify(pedidosActivos));
        
        const response = await fetch('seguimiento.php?telefono=<?= urlencode($telefono) ?>', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        console.log('Respuesta del servidor:', data);
        
        if (data.success && data.conteos) {
            // Actualizar cada badge
            Object.keys(data.conteos).forEach(pedidoId => {
                const count = data.conteos[pedidoId];
                
                // Buscar el enlace del chat para este pedido
                const chatLink = document.querySelector(`a[data-chat-link][data-pedido-id="${pedidoId}"]`);
                
                if (!chatLink) {
                    console.log('No se encontró enlace de chat para pedido:', pedidoId);
                    return;
                }
                
                // Buscar el badge existente
                let badge = chatLink.querySelector('.badge-mensajes');
                
                if (count > 0) {
                    if (badge) {
                        // Actualizar el badge existente
                        badge.textContent = count;
                        console.log(`Badge actualizado para pedido ${pedidoId}: ${count} mensajes`);
                    } else {
                        // Crear el badge si no existe
                        const newBadge = document.createElement('span');
                        newBadge.className = 'badge-mensajes absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center animate-pulse';
                        newBadge.textContent = count;
                        chatLink.appendChild(newBadge);
                        console.log(`Badge creado para pedido ${pedidoId}: ${count} mensajes`);
                    }
                } else {
                    // Remover el badge si no hay mensajes
                    if (badge) {
                        badge.remove();
                        console.log(`Badge eliminado para pedido ${pedidoId}`);
                    }
                }
            });
        } else {
            console.error('Error en respuesta:', data);
        }
    } catch (error) {
        console.error('Error actualizando badges de mensajes:', error);
    }
}

// Ejecutar inmediatamente y luego cada 10 segundos
console.log('Iniciando sistema de actualización de badges (Cliente)...');
actualizarBadgesMensajes();
setInterval(actualizarBadgesMensajes, 10000);
</script>
<?php endif; ?>

</body>
</html>
