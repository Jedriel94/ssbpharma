<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Producto.php';
require_once __DIR__ . '/models/Cliente.php';
require_once __DIR__ . '/models/Pedido.php';
require_once __DIR__ . '/models/Configuracion.php';
require_once __DIR__ . '/models/Kit.php';

// Obtener conexión a base de datos
$db = Database::getInstance();
$pdo = $db->getConnection();

$productoModel = new Producto();
$clienteModel = new Cliente();
$pedidoModel = new Pedido();
$kitModel = new Kit();

function obtenerRepresentantePublico(PDO $pdo) {
    $representante_admin_id = isset($_COOKIE['botikit_rep_admin']) ? (int)$_COOKIE['botikit_rep_admin'] : null;

    if ($representante_admin_id) {
        $stmt = $pdo->prepare("
            SELECT
                a.id AS representante_admin_id,
                a.nombre,
                rp.tags_permitidos
            FROM representante_perfiles rp
            INNER JOIN administradores a ON a.id = rp.admin_id
            WHERE rp.admin_id = ?
              AND rp.activo = 1
              AND a.activo = 1
            LIMIT 1
        ");
        $stmt->execute([$representante_admin_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return null;
}

// Cargar configuración desde base de datos
$_mmeg = Configuracion::get('monto_minimo_envio_gratis', 1900.00);
$MONTO_MINIMO_ENVIO_GRATIS = ($_mmeg === '' || $_mmeg === null) ? 1900.00 : (float)$_mmeg;
$_ce = Configuracion::get('costo_envio', 160.00);
$COSTO_ENVIO = ($_ce === '' || $_ce === null) ? 160.00 : (float)$_ce;
$MOSTRAR_STOCK = (bool) Configuracion::get('mostrar_stock', 1);

// Procesar peticiones AJAX primero (antes de validar teléfono)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_precio':
            $producto_id = $_POST['producto_id'] ?? 0;
            $cantidad = $_POST['cantidad'] ?? 1;
            
            $precio = $productoModel->getPrecioByQuantity($producto_id, $cantidad);
            
            echo json_encode([
                'success' => true,
                'precio' => $precio ? floatval($precio) : 0
            ]);
            exit;
            
        case 'create_pedido':
            // Obtener teléfono del cliente desde POST
            $telefono = $_POST['telefono'] ?? '';
            
            if (empty($telefono) || !is_numeric($telefono) || strlen($telefono) != 10) {
                echo json_encode(['success' => false, 'message' => 'Teléfono debe tener 10 dígitos']);
                exit;
            }
            
            $cliente = $clienteModel->getOrCreate($telefono);
            $items = json_decode($_POST['items'], true);
            $total = floatval($_POST['total']);
            $notas = $_POST['notas'] ?? null;
            $cupon_codigo = $_POST['cupon_codigo'] ?? null;
            $cupon_descuento = floatval($_POST['cupon_descuento'] ?? 0);
            $cupon_id = isset($_POST['cupon_id']) ? intval($_POST['cupon_id']) : null;
            
            if (empty($items)) {
                echo json_encode(['success' => false, 'message' => 'No hay productos en el pedido']);
                exit;
            }
            
            try {
                // Crear pedido con cupón
                $pedido_id = $pedidoModel->create($cliente['id'], $total, $notas, $cupon_codigo, $cupon_descuento);
                
                if ($pedido_id) {
                    // Agregar detalles (productos y kits)
                    foreach ($items as $item) {
                        $tipo = $item['tipo'] ?? 'producto'; // Por defecto producto para compatibilidad
                        
                        if ($tipo === 'kit') {
                            // Procesar kit
                            $kit_id = $item['kit_id'];
                            $cantidad = $item['cantidad'];
                            
                            // Usar el método venderKit que descuenta automáticamente los productos
                            $resultado = $kitModel->venderKit($kit_id, $pedido_id, $cantidad);
                            
                            if (!$resultado['success']) {
                                throw new Exception('Error al vender kit: ' . $resultado['mensaje']);
                            }
                        } else {
                            // Procesar producto regular
                            $pedidoModel->addDetalle(
                                $pedido_id,
                                $item['producto_id'],
                                $item['cantidad'],
                                $item['precio_unitario']
                            );
                            
                            // Actualizar existencia
                            $pedidoModel->actualizarExistencia($item['producto_id'], $item['cantidad']);
                        }
                    }
                    
                    // Registrar uso del cupón si se aplicó
                    if ($cupon_id && $cupon_descuento > 0) {
                        require_once __DIR__ . '/models/Cupon.php';
                        $cuponModel = new Cupon();
                        
                        $representante = obtenerRepresentantePublico($pdo);
                        $representante_admin_id = $representante['representante_admin_id'] ?? null;
                        $subtotal_con_descuento = $total + $cupon_descuento; // El total ya tiene el descuento aplicado
                        
                        $cuponModel->registrarUso(
                            $cupon_id,
                            $pedido_id,
                            $cupon_descuento,
                            $subtotal_con_descuento,
                            $cliente['id'],
                            $representante_admin_id
                        );
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Pedido creado exitosamente',
                        'pedido_id' => $pedido_id
                    ]);
                } else {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Error al crear el pedido en la base de datos'
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error: ' . $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            exit;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
            exit;
    }
}

// Obtener teléfono del cliente desde la URL (para renderizar la página)
$telefono = $_GET['telefono'] ?? '';

// Validar teléfono
if (empty($telefono) || !is_numeric($telefono) || strlen($telefono) != 10) {
    header('Location: index.php');
    exit;
}

// Obtener o crear cliente
$cliente = $clienteModel->getOrCreate($telefono);

// ========================================
// FILTRADO POR TAGS DE REPRESENTANTE
// ========================================
// Si el usuario entró con enlace de representante, filtrar productos por sus tags
$tags_representante = null;
$nombre_representante = null;
$representante = obtenerRepresentantePublico($pdo);

if ($representante) {
    $tags_representante = $representante['tags_permitidos'];
    $nombre_representante = $representante['nombre'];
}

// Obtener productos activos filtrados por tags del representante
if ($tags_representante) {
    $productos = $productoModel->getAllActivosByTags($tags_representante);
} else {
    $productos = $productoModel->getAllActivos();
}

// Obtener kits disponibles (filtrados por tags del representante si aplica)
$kits = $kitModel->obtenerKitsDisponibles($tags_representante);

// Carrusel de productos/kits destacados
$carrusel_productos = $productoModel->getCarrusel($tags_representante);
$carrusel_kits = $kitModel->getCarrusel($tags_representante);
$items_carrusel = [];
foreach ($carrusel_productos as $p) {
    $items_carrusel[] = [
        'tipo'   => 'producto',
        'id'     => $p['id'],
        'nombre' => $p['producto'],
        'precio' => $p['precio_base'],
        'imagen' => $p['imagen'] ?? null,
    ];
}
foreach ($carrusel_kits as $k) {
    $items_carrusel[] = [
        'tipo'   => 'kit',
        'id'     => $k['id'],
        'nombre' => $k['nombre'],
        'precio' => $k['precio_kit'],
        'imagen' => $k['imagen'] ?? null,
    ];
}

// Debug: Log kits disponibles
error_log("Kits disponibles en crear-pedido: " . count($kits));
foreach ($kits as $kit) {
    error_log("Kit ID {$kit['id']}: nombre='{$kit['nombre']}', imagen='" . ($kit['imagen'] ?? 'NULL') . "'");
}
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= asset('css/cliente-mobile.css') ?>">
<script>document.body.classList.add('cliente-app');</script>

<style>
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Efecto de resaltado en el buscador */
#buscadorProductos:focus {
    box-shadow: 0 10px 25px -5px rgba(191, 160, 148, 0.3);
}

/* Animación suave para ocultar productos */
.producto-item {
    transition: all 0.3s ease;
    position: relative;
}

/* Efecto hover en las tarjetas de producto */
.producto-item:hover {
    transform: translateY(-4px);
}

/* Mensaje de sin resultados */
.sin-resultados {
    animation: slideDown 0.5s ease;
    background: linear-gradient(135deg, #fff 0%, #fef2f2 100%);
    border: 2px dashed #f87171 !important;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-10px); }
    75% { transform: translateX(10px); }
}

/* Peek del carrusel en móvil */
.carrusel-wrapper {
    -webkit-mask-image: linear-gradient(to right, black 87%, transparent 100%);
    mask-image: linear-gradient(to right, black 87%, transparent 100%);
}
@media (min-width: 640px) {
    .carrusel-wrapper {
        overflow: hidden;
        -webkit-mask-image: none;
        mask-image: none;
    }
}</style>

<div id="mainWrapper" class="cliente-catalog w-full px-4 pt-4 sm:pt-8 pb-32">
    
    <!-- Header -->
    <div class="cliente-page-head mb-3 sm:mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-1">Realiza tu Pedido </h1>
        <p class="text-slate-600">
            Cliente: <span class="font-semibold text-terracotta-600"><?= htmlspecialchars($telefono) ?></span>
            <?php if (!empty($cliente['nombre'])): ?>
                - <?= htmlspecialchars($cliente['nombre']) ?>
            <?php endif; ?>
        </p>
    </div>
    
    <?php if ($nombre_representante): ?>
    <!-- Indicador de representante (una línea) -->
    <div class="mb-4 flex items-center gap-2">
        <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-purple-100 text-purple-800 border border-purple-200 rounded-full text-sm font-medium">
            Rep: <?= htmlspecialchars($nombre_representante) ?>
        </span>
    </div>
    <?php endif; ?>

    <?php if (!empty($items_carrusel)): ?>
    <!-- Carrusel de productos/kits destacados -->
    <div class="mb-4 sm:mb-6">
        <h2 class="text-sm sm:text-base font-semibold text-slate-700 mb-2">Promociones</h2>
        <div class="relative max-w-lg mx-auto">
            <!-- Botón anterior -->
            <button id="carrusel-prev" onclick="moverCarrusel(-1)"
                    class="absolute left-0 top-1/2 -translate-y-1/2 -translate-x-3 z-10 w-8 h-8 bg-white border border-slate-200 rounded-full shadow flex items-center justify-center text-slate-600 hover:bg-slate-50 transition">
                ‹
            </button>
            <!-- Track -->
            <div class="mx-4 overflow-hidden">
                <div id="carrusel-track" class="flex gap-3 transition-transform duration-300 ease-in-out">
                    <?php foreach ($items_carrusel as $item): ?>
                    <a href="#<?= $item['tipo'] === 'kit' ? 'kit-' : 'producto-' ?><?= $item['id'] ?>"
                       class="flex-none card rounded-xl shadow-sm overflow-hidden hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 cursor-pointer"
                       onclick="scrollAItem('<?= $item['tipo'] ?>', <?= $item['id'] ?>); return false;">
                        <!-- Imagen -->
                        <?php if ($item['imagen']): ?>
                        <div class="w-full aspect-[4/3] bg-cream-50 overflow-hidden flex items-center justify-center">
                            <img src="uploads/<?= $item['tipo'] === 'kit' ? 'kits' : 'productos' ?>/<?= htmlspecialchars($item['imagen']) ?>"
                                 alt="<?= htmlspecialchars($item['nombre']) ?>"
                                 class="w-full h-full object-contain">
                        </div>
                        <?php else: ?>
                        <div class="w-full aspect-[4/3] bg-cream-100 flex items-center justify-center text-3xl">
                            <?= $item['tipo'] === 'kit' ? '' : '' ?>
                        </div>
                        <?php endif; ?>
                        <!-- Info -->
                        <div class="p-2 text-center">
                            <p class="text-xs font-semibold text-slate-800 line-clamp-2 leading-snug"><?= htmlspecialchars($item['nombre']) ?></p>
                            <?php if ($item['precio']): ?>
                            <p class="text-xs text-terracotta-600 font-bold mt-1">$<?= number_format($item['precio'], 0, ',', '.') ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Botón siguiente -->
            <button id="carrusel-next" onclick="moverCarrusel(1)"
                    class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-3 z-10 w-8 h-8 bg-white border border-slate-200 rounded-full shadow flex items-center justify-center text-slate-600 hover:bg-slate-50 transition">
                ›
            </button>
        </div>
        <!-- Indicadores de posición -->
        <div id="carrusel-dots" class="flex justify-center gap-1 mt-3"></div>
    </div>

    <script>
    (function() {
        const track = document.getElementById('carrusel-track');
        const dotsContainer = document.getElementById('carrusel-dots');
        const GAP = 12; // gap-3 en px
        let currentIndex = 0;
        let autoTimer = null;

        function visibleCards() {
            return window.innerWidth >= 640 ? 2 : 1;
        }

        function cardWidth() {
            const vis = visibleCards();
            return (track.parentElement.offsetWidth - GAP * (vis - 1)) / vis;
        }

        function applyCardWidths() {
            const w = cardWidth();
            Array.from(track.children).forEach(c => { c.style.width = w + 'px'; });
        }

        function totalItems() {
            return track.children.length;
        }

        function maxIndex() {
            return Math.max(0, totalItems() - visibleCards());
        }

        function buildDots() {
            dotsContainer.innerHTML = '';
            const pages = maxIndex() + 1;
            for (let i = 0; i < pages; i++) {
                const d = document.createElement('button');
                d.className = 'w-2 h-2 rounded-full transition-all duration-200 ' +
                    (i === currentIndex ? 'bg-terracotta-500 w-4' : 'bg-slate-300');
                d.onclick = () => { goTo(i); };
                dotsContainer.appendChild(d);
            }
        }

        function goTo(idx) {
            currentIndex = Math.max(0, Math.min(idx, maxIndex()));
            track.style.transform = 'translateX(-' + (currentIndex * (cardWidth() + GAP)) + 'px)';
            buildDots();
        }

        window.addEventListener('resize', () => {
            applyCardWidths();
            goTo(Math.min(currentIndex, maxIndex()));
        });

        window.moverCarrusel = function(dir) {
            goTo(currentIndex + dir);
            resetAuto();
        };

        const carruselIntervalo = <?= (int)Configuracion::get('carrusel_intervalo', 4) * 1000 ?>;

        function resetAuto() {
            clearInterval(autoTimer);
            autoTimer = setInterval(() => {
                const next = currentIndex >= maxIndex() ? 0 : currentIndex + 1;
                goTo(next);
            }, carruselIntervalo);
        }

        window.scrollAItem = function(tipo, id) {
            const prefix = tipo === 'kit' ? 'kit-' : 'producto-';
            const el = document.getElementById(prefix + id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.add('ring-2', 'ring-terracotta-400');
                setTimeout(() => el.classList.remove('ring-2', 'ring-terracotta-400'), 2000);
            }
        };

        applyCardWidths();
        buildDots();
        resetAuto();
    })();
    </script>
    <?php endif; ?>

    <!-- Buscador de Productos -->
    <div class="mb-4 sm:mb-6 sticky top-0 z-10 bg-gradient-to-br from-terracotta-50 to-sage-50 py-2 sm:py-4 -mx-4 px-4">
        <div class="max-w-2xl mx-auto">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input 
                    type="text" 
                    id="buscadorProductos"
                    placeholder="Buscar productos por nombre..."
                    class="w-full pl-12 pr-12 py-3 sm:py-4 bg-white rounded-2xl shadow-lg border-2 border-transparent focus:border-terracotta-500 focus:outline-none transition-all text-slate-900 placeholder-slate-400"
                    autocomplete="off"
                    oninput="buscarProductos()"
                >
                <button 
                    id="btnLimpiarBusqueda"
                    onclick="limpiarBusqueda()"
                    class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-600 transition hidden"
                    title="Limpiar búsqueda"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Contador de resultados -->
            <div id="contadorResultados" class="mt-2 text-center text-sm text-slate-600">
                <span id="resultadosEncontrados"><?= count($productos) + count($kits) ?></span> productos disponibles
            </div>

            <!-- Chips: Productos / Kits -->
            <div class="flex justify-center gap-2 mt-3">
                <button type="button" id="chip-producto" onclick="filtrarTipo('producto')"
                        class="chip-tipo px-6 py-2 rounded-full text-sm font-bold bg-terracotta-500 text-white transition">
                    Productos
                </button>
                <button type="button" id="chip-kit" onclick="filtrarTipo('kit')"
                        class="chip-tipo px-6 py-2 rounded-full text-sm font-bold bg-white text-slate-600 border border-slate-200 transition">
                    Kits
                </button>
            </div>
        </div>
    </div>

    <!-- Grid de Productos (2 columnas en móvil) -->
    <div id="gridProductos" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
        <?php foreach ($productos as $producto): ?>
        <div id="producto-<?= $producto['id'] ?>" class="producto-item card rounded-2xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col"
             data-producto-id="<?= $producto['id'] ?>"
             data-producto-nombre="<?= strtolower(htmlspecialchars($producto['producto'])) ?>"
             data-producto-imagen="<?= htmlspecialchars($producto['imagen']) ?>"
             data-sin-cargo-envio="<?= $producto['sin_cargo_envio'] ?? 0 ?>">
            
            <!-- Imagen del Producto -->
            <?php if ($producto['imagen']): ?>
                <div class="w-full aspect-square bg-gradient-to-br from-cream-100 to-cream-200 flex items-center justify-center overflow-hidden">
                    <img src="<?= url('uploads/productos/' . htmlspecialchars($producto['imagen'])) ?>" 
                         alt="<?= htmlspecialchars($producto['producto']) ?>"
                         class="w-full h-full object-cover">
                </div>
            <?php else: ?>
                <div class="w-full aspect-square bg-gradient-to-br from-cream-100 to-cream-200 flex items-center justify-center">
                    <svg class="w-12 h-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
            <?php endif; ?>
            
            <div class="p-3 flex flex-col flex-1">
                <!-- Nombre del producto -->
                <h3 class="text-sm font-bold text-slate-900 mb-2 line-clamp-2"><?= htmlspecialchars($producto['producto']) ?></h3>
                
                <?php if ($producto['sin_cargo_envio']): ?>
                <div class="mb-2">
                    <span class="inline-block bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full font-semibold">
                        Envío Gratis
                    </span>
                </div>
                <?php endif; ?>
                
                <!-- Precio inicial -->
                <p class="precio-display text-terracotta-600 font-bold text-base mb-2 mt-auto"
                   data-producto-id="<?= $producto['id'] ?>">
                    $<span class="precio-valor">0.00</span>
                </p>
                
                <!-- Spinner de cantidad -->
                <div class="flex items-center justify-between mb-3 bg-cream-100 rounded-lg p-2">
                    <button type="button" 
                            onclick="decrementarCantidad(<?= $producto['id'] ?>)"
                            class="w-8 h-8 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700 font-bold transition">
                        -
                    </button>
                    <input type="number" 
                           id="cantidad-<?= $producto['id'] ?>"
                           value="0"
                           min="0"
                           max="<?= $producto['existencia'] ?>"
                           class="w-12 text-center font-bold text-slate-900 bg-transparent border-0 focus:outline-none"
                           onchange="actualizarPrecio(<?= $producto['id'] ?>)"
                           readonly>
                    <button type="button" 
                            onclick="incrementarCantidad(<?= $producto['id'] ?>, <?= $producto['existencia'] ?>)"
                            class="w-8 h-8 bg-terracotta-500 hover:bg-terracotta-600 rounded-lg text-white font-bold transition">
                        +
                    </button>
                </div>
                
                <!-- Stock disponible -->
                <?php if ($MOSTRAR_STOCK): ?>
                <p class="text-xs text-slate-500 text-center">
                    Stock: <?= $producto['existencia'] ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Renderizar Kits -->
        <?php foreach ($kits as $kit): ?>
        <div id="kit-<?= $kit['id'] ?>" class="producto-item card rounded-2xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl" 
             data-producto-id="kit-<?= $kit['id'] ?>"
             data-producto-nombre="<?= strtolower(htmlspecialchars($kit['nombre'])) ?>"
             data-producto-imagen="<?= htmlspecialchars($kit['imagen'] ?? '') ?>"
             data-es-kit="1"
             data-kit-id="<?= $kit['id'] ?>">
            
            <!-- Badge de Kit -->
            <div class="absolute top-2 left-2 z-10">
                <span class="bg-purple-500 text-white text-xs px-2 py-1 rounded-full font-bold shadow-lg">
                    KIT
                </span>
            </div>
            
            <!-- Imagen del Kit -->
            <!-- DEBUG: imagen='<?= htmlspecialchars($kit['imagen'] ?? 'NULL') ?>' url='<?= url('uploads/kits/' . htmlspecialchars($kit['imagen'] ?? '')) ?>' -->
            <?php if ($kit['imagen']): ?>
                <div class="w-full aspect-square bg-gradient-to-br from-purple-100 to-purple-200 flex items-center justify-center overflow-hidden">
                    <img src="<?= url('uploads/kits/' . htmlspecialchars($kit['imagen'])) ?>" 
                         alt="<?= htmlspecialchars($kit['nombre']) ?>"
                         class="w-full h-full object-cover"
                         onerror="console.error('Error cargando imagen kit:', this.src); this.parentElement.innerHTML='<div class=\'text-red-500 text-xs\'>Error: Imagen no encontrada</div>'">
                </div>
            <?php else: ?>
                <div class="w-full aspect-square bg-gradient-to-br from-purple-100 to-purple-200 flex items-center justify-center">
                    <svg class="w-12 h-12 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
            <?php endif; ?>
            
            <div class="p-3">
                <!-- Nombre del kit -->
                <h3 class="text-sm font-bold text-slate-900 mb-2 line-clamp-2"><?= htmlspecialchars($kit['nombre']) ?></h3>
                
                <!-- Descripción del kit (si existe) -->
                <?php if (!empty($kit['descripcion'])): ?>
                <p class="text-xs text-slate-600 mb-2 line-clamp-2"><?= htmlspecialchars($kit['descripcion']) ?></p>
                <?php endif; ?>
                
                <!-- Precio fijo del kit -->
                <p class="precio-display text-purple-600 font-bold text-base mb-2" 
                   data-producto-id="kit-<?= $kit['id'] ?>">
                    $<span class="precio-valor"><?= number_format($kit['precio_kit'], 2) ?></span>
                </p>
                
                <!-- Spinner de cantidad para kits -->
                <div class="flex items-center justify-between mb-3 bg-purple-50 rounded-lg p-2">
                    <button type="button" 
                            onclick="decrementarCantidadKit(<?= $kit['id'] ?>)"
                            class="w-8 h-8 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700 font-bold transition">
                        -
                    </button>
                    <input type="number" 
                           id="cantidad-kit-<?= $kit['id'] ?>"
                           value="0"
                           min="0"
                           max="<?= $kit['stock_disponible'] ?? 999 ?>"
                           class="w-12 text-center font-bold text-slate-900 bg-transparent border-0 focus:outline-none"
                           onchange="actualizarCarritoKit(<?= $kit['id'] ?>)"
                           readonly>
                    <button type="button" 
                            onclick="incrementarCantidadKit(<?= $kit['id'] ?>, <?= $kit['stock_disponible'] ?? 999 ?>)"
                            class="w-8 h-8 bg-purple-500 hover:bg-purple-600 rounded-lg text-white font-bold transition">
                        +
                    </button>
                </div>
                
                <!-- Stock disponible -->
                <?php if ($MOSTRAR_STOCK): ?>
                <p class="text-xs text-slate-500 text-center">
                    Stock: <?= $kit['stock_disponible'] ?? 999 ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($productos) && empty($kits)): ?>
        <div class="col-span-full card rounded-2xl shadow-lg p-12 text-center">
            <p class="text-slate-600 text-lg">No hay productos disponibles</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Footer Flotante con Resumen del Pedido -->
<div id="footerResumen" class="fixed bottom-0 left-0 right-0 bg-white shadow-2xl border-t-4 border-terracotta-500 transition-all duration-300 transform translate-y-full">
    <div class="px-4 py-4">
        
        <!-- Botón para expandir/colapsar -->
        <button onclick="toggleResumen()" class="w-full flex justify-between items-center mb-3">
            <div class="flex items-center gap-2">
                <span class="text-2xl"></span>
                <div class="text-left">
                    <p class="font-bold text-slate-900">
                        <span id="totalItems">0</span> productos
                    </p>
                    <p class="text-sm text-slate-600">
                        Total: $<span id="totalPedido">0.00</span>
                    </p>
                    <div id="infoEnvio"></div>
                </div>
            </div>
            <svg id="iconToggle" class="w-6 h-6 text-slate-600 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
            </svg>
        </button>
        
        <!-- Lista de productos (colapsable) -->
        <div id="listaResumen" class="hidden max-h-48 overflow-y-auto mb-4 space-y-2">
            <!-- Se llenará dinámicamente -->
        </div>
        
        <!-- Botón Confirmar Pedido -->
        <button id="btnConfirmar" 
                onclick="mostrarModalConfirmacion()"
                disabled
                class="w-full btn-primary text-white py-4 rounded-xl font-bold text-lg disabled:opacity-50 disabled:cursor-not-allowed">
            Confirmar Pedido
        </button>
    </div>
</div>

<!-- Modal de Confirmación -->
<div id="modalConfirmacion" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4" onclick="cerrarModalConfirmacion()">
    <div class="card rounded-3xl shadow-2xl max-w-md w-full p-8 transform scale-95 transition-transform" onclick="event.stopPropagation()">
        <div class="text-center mb-6">
            <div class="w-20 h-20 bg-terracotta-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-10 h-10 text-terracotta-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-slate-900 mb-2">¿Confirmar Pedido?</h2>
            <div class="text-slate-600 space-y-1">
                <p><span class="font-semibold text-terracotta-600" id="confirmTotalItems">0</span> productos</p>
                <div class="text-sm space-y-1">
                    <div class="flex justify-between">
                        <span>Subtotal:</span>
                        <span class="font-semibold">$<span id="confirmSubtotal">0.00</span></span>
                    </div>
                    <div id="lineaCupon" class="flex justify-between text-green-600 hidden">
                        <span>Descuento:</span>
                        <span class="font-semibold">-$<span id="confirmDescuento">0.00</span></span>
                    </div>
                    <div id="lineaEnvio" class="flex justify-between hidden">
                        <span>Envío:</span>
                        <span class="font-semibold">$<span id="confirmEnvio">0.00</span></span>
                    </div>
                    <div class="flex justify-between text-lg font-bold text-terracotta-600 pt-2 border-t border-slate-200">
                        <span>Total:</span>
                        <span>$<span id="confirmTotal">0.00</span></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="bg-cream-50 rounded-xl p-4 mb-6 max-h-48 overflow-y-auto" id="confirmItems">
            <!-- Lista de productos se llenará aquí -->
        </div>
        
        <!-- Campo de Cupón -->
        <div class="mb-6 border-t border-slate-200 pt-4">
            <label class="block text-sm font-medium text-slate-700 mb-2">¿Tienes un cupón?</label>
            <div class="flex gap-2">
                <input type="text" id="input_cupon" placeholder="Código del cupón"
                       class="min-w-0 flex-1 px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 uppercase text-sm"
                       maxlength="50">
                <button type="button" onclick="aplicarCupon()" id="btn_aplicar_cupon"
                        class="shrink-0 px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 transition text-sm">
                    Aplicar
                </button>
            </div>
            <div id="mensaje_cupon" class="mt-2 text-sm"></div>
            <div id="descuento_aplicado" class="hidden mt-2 p-3 bg-green-50 border border-green-200 rounded-xl">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-semibold text-green-800">Cupón aplicado: <span id="cupon_codigo"></span></p>
                        <p class="text-xs text-green-600" id="cupon_descripcion"></p>
                    </div>
                    <button type="button" onclick="removerCupon()" class="text-red-600 hover:text-red-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="flex gap-3">
            <button onclick="cerrarModalConfirmacion()" 
                    class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-semibold hover:bg-slate-300 transition">
                Cancelar
            </button>
            <button onclick="procesarPedido()" 
                    class="flex-1 btn-primary text-white py-3 rounded-xl font-semibold">
                Sí, Confirmar
            </button>
        </div>
    </div>
</div>

<!-- Modal Pedido Exitoso -->
<div id="modalExito" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="card rounded-3xl shadow-2xl max-w-md w-full p-8 text-center" onclick="event.stopPropagation()">
        <!-- Icono de éxito -->
        <div class="w-20 h-20 bg-sage-100 rounded-full flex items-center justify-center mx-auto mb-6 animate-bounce">
            <span class="text-5xl"></span>
        </div>
        
        <h2 class="text-2xl font-bold text-slate-900 mb-2">¡Pedido Creado Exitosamente!</h2>
        <p class="text-slate-600 mb-2">Tu pedido ha sido registrado</p>
        <p class="text-sm text-terracotta-600 font-semibold mb-6" id="numeroPedido">Pedido #<span id="pedidoNumero"></span></p>
        
        <!-- Resumen del pedido -->
        <div class="bg-cream-100 rounded-xl p-4 mb-6 text-left">
            <div class="flex justify-between items-center mb-2">
                <span class="text-slate-600">Total del pedido:</span>
                <span class="text-2xl font-bold text-terracotta-600" id="totalPedidoModal">$0.00</span>
            </div>
            <p class="text-xs text-slate-500">IVA incluido</p>
        </div>
        
        <!-- Botones de acción -->
        <div class="space-y-3">
            <!-- Botón principal: Proceder al Pago -->
            <button onclick="procederAlPago()" 
                    class="w-full btn-primary text-white py-4 rounded-xl font-semibold flex items-center justify-center gap-2">
                <span class="text-xl"></span>
                <span>Proceder al Pago</span>
            </button>
            
            <!-- Botón secundario: Ver seguimiento -->
            <button onclick="verSeguimiento()" 
                    class="w-full btn-secondary text-white py-4 rounded-xl font-semibold flex items-center justify-center gap-2">
                <span class="text-xl"></span>
                <span>Ver Seguimiento del Pedido</span>
            </button>
            
            <!-- Botón terciario: Hacer otro pedido -->
            <button onclick="cerrarModalExito()" 
                    class="w-full bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300">
                Hacer Otro Pedido
            </button>
        </div>
        
        <p class="text-xs text-slate-500 mt-4">
            Puedes consultar el estado de tu pedido en cualquier momento desde "Seguimiento"
        </p>
    </div>
</div>

<script>
// ========================================
// FUNCIONES DEL BUSCADOR
// ========================================

let tipoActivo = 'producto';

function filtrarTipo(tipo) {
    tipoActivo = tipo;
    document.querySelectorAll('.chip-tipo').forEach(c => {
        const activo = c.id === 'chip-' + tipo;
        c.classList.toggle('bg-terracotta-500', activo);
        c.classList.toggle('text-white', activo);
        c.classList.toggle('bg-white', !activo);
        c.classList.toggle('text-slate-600', !activo);
        c.classList.toggle('border', !activo);
        c.classList.toggle('border-slate-200', !activo);
    });
    buscarProductos();
}

document.addEventListener('DOMContentLoaded', function () {
    filtrarTipo('producto');
});

function buscarProductos() {
    const input = document.getElementById('buscadorProductos');
    const btnLimpiar = document.getElementById('btnLimpiarBusqueda');
    const filtro = input.value.toLowerCase().trim();
    const productos = document.querySelectorAll('.producto-item');
    const grid = document.getElementById('gridProductos');
    let resultadosEncontrados = 0;
    
    // Mostrar/ocultar botón de limpiar
    if (filtro.length > 0) {
        btnLimpiar.classList.remove('hidden');
    } else {
        btnLimpiar.classList.add('hidden');
    }
    
    // Remover mensaje de "sin resultados" previo
    const mensajePrevio = document.getElementById('mensajeSinResultados');
    if (mensajePrevio) {
        mensajePrevio.remove();
    }
    
    // Filtrar productos
    productos.forEach(producto => {
        const nombreProducto = producto.getAttribute('data-producto-nombre');
        const esKit = producto.getAttribute('data-es-kit') === '1';
        const tipoItem = esKit ? 'kit' : 'producto';

        if (nombreProducto.includes(filtro) && tipoItem === tipoActivo) {
            producto.style.display = 'block';
            // Animación de aparición
            producto.style.animation = 'fadeIn 0.3s ease';
            resultadosEncontrados++;
        } else {
            producto.style.display = 'none';
        }
    });
    
    // Mostrar mensaje si no hay resultados
    if (resultadosEncontrados === 0 && filtro.length > 0) {
        const mensajeSinResultados = document.createElement('div');
        mensajeSinResultados.id = 'mensajeSinResultados';
        mensajeSinResultados.className = 'col-span-full card rounded-2xl shadow-lg p-12 text-center sin-resultados';
        mensajeSinResultados.innerHTML = `
            <div class="text-6xl mb-4"></div>
            <p class="text-slate-900 text-xl font-bold mb-2">No se encontraron productos</p>
            <p class="text-slate-600 mb-4">
                No hay productos que coincidan con "<span class="font-semibold text-terracotta-600">${filtro}</span>"
            </p>
            <button onclick="limpiarBusqueda()" class="bg-terracotta-500 hover:bg-terracotta-600 text-white px-6 py-3 rounded-xl font-semibold transition">
                Limpiar búsqueda
            </button>
        `;
        grid.appendChild(mensajeSinResultados);
    }
    
    // Actualizar contador de resultados
    actualizarContadorResultados(resultadosEncontrados, filtro);
}

function limpiarBusqueda() {
    const input = document.getElementById('buscadorProductos');
    const btnLimpiar = document.getElementById('btnLimpiarBusqueda');
    
    input.value = '';
    btnLimpiar.classList.add('hidden');
    buscarProductos(); // Mostrar todos los productos
    input.focus(); // Enfocar de nuevo el input
}

function actualizarContadorResultados(cantidad, filtro) {
    const contador = document.getElementById('contadorResultados');
    const span = document.getElementById('resultadosEncontrados');
    
    span.textContent = cantidad;
    
    if (filtro.length > 0) {
        if (cantidad === 0) {
            contador.innerHTML = `
                <span class="text-red-600 font-semibold">
                    No se encontraron productos con "${filtro}"
                </span>
            `;
        } else if (cantidad === 1) {
            contador.innerHTML = `
                <span class="text-green-600 font-semibold">
                    <span id="resultadosEncontrados">${cantidad}</span> producto encontrado
                </span>
            `;
        } else {
            contador.innerHTML = `
                <span class="text-green-600 font-semibold">
                    <span id="resultadosEncontrados">${cantidad}</span> productos encontrados
                </span>
            `;
        }
    } else {
        contador.innerHTML = `
            <span id="resultadosEncontrados">${cantidad}</span> productos disponibles
        `;
    }
}

// Atajo de teclado: Ctrl/Cmd + K para enfocar el buscador
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        document.getElementById('buscadorProductos').focus();
    }
});

// ========================================
// FUNCIONES DEL CARRITO
// ========================================

// Carrito de compras
let carrito = {};
let cuponActual = null; // Para almacenar el cupón aplicado
let descuentoCupon = 0;
let preciosCache = {};
let preciosIniciales = {}; // Guardar precios iniciales (cantidad mínima)

// Configuración de envío (cargada desde base de datos)
const MONTO_MINIMO_ENVIO_GRATIS = <?php echo $MONTO_MINIMO_ENVIO_GRATIS; ?>;
const COSTO_ENVIO = <?php echo $COSTO_ENVIO; ?>;

// Función para formatear números con comas
function formatearPrecio(precio) {
    return parseFloat(precio).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Función para capitalizar primera letra de cada palabra
function capitalizeWords(str) {
    return str.toLowerCase().split(' ').map(word => 
        word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
}

// Inicializar precios al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    console.log('Página cargada, inicializando precios...');
    <?php foreach ($productos as $producto): ?>
        cargarPrecioInicial(<?= $producto['id'] ?>);
    <?php endforeach; ?>
    
    // Los kits ya tienen su precio fijo mostrado en el HTML
    console.log('Kits cargados: <?= count($kits) ?>');
});

function cargarPrecioInicial(productoId) {
    console.log('Cargando precio inicial para producto:', productoId);
    // Obtener precio inicial (cantidad = 1)
    fetch('crear-pedido.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=get_precio&producto_id=${productoId}&cantidad=1`
    })
    .then(res => res.json())
    .then(data => {
        console.log('Respuesta precio inicial producto', productoId, ':', data);
        if (data.success && data.precio > 0) {
            preciosIniciales[productoId] = parseFloat(data.precio);
            // Mostrar precio inicial con formato
            const precioElement = document.querySelector(`.precio-display[data-producto-id="${productoId}"] .precio-valor`);
            if (precioElement) {
                precioElement.textContent = formatearPrecio(data.precio);
            }
        }
    })
    .catch(error => {
        console.error('Error cargando precio:', error);
    });
}

function incrementarCantidad(productoId, maxStock) {
    const input = document.getElementById(`cantidad-${productoId}`);
    let cantidad = parseInt(input.value) || 0;
    
    if (cantidad < maxStock) {
        cantidad++;
        input.value = cantidad;
        actualizarPrecio(productoId);
    } else {
        mostrarAlerta('Stock máximo alcanzado', 'warning');
    }
}

function decrementarCantidad(productoId) {
    const input = document.getElementById(`cantidad-${productoId}`);
    let cantidad = parseInt(input.value) || 0;
    
    if (cantidad > 0) {
        cantidad--;
        input.value = cantidad;
        actualizarPrecio(productoId);
    }
}

function actualizarPrecio(productoId) {
    const cantidad = parseInt(document.getElementById(`cantidad-${productoId}`).value) || 0;
    
    if (cantidad === 0) {
        // Eliminar del carrito pero mostrar precio inicial
        delete carrito[productoId];
        const precioInicial = preciosIniciales[productoId] || 0;
        document.querySelector(`.precio-display[data-producto-id="${productoId}"] .precio-valor`).textContent = formatearPrecio(precioInicial);
        actualizarResumen();
        return;
    }
    
    // Obtener precio según cantidad
    fetch('crear-pedido.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=get_precio&producto_id=${productoId}&cantidad=${cantidad}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const precio = parseFloat(data.precio);
            preciosCache[productoId] = precio;
            
            // Actualizar display del precio con formato
            document.querySelector(`.precio-display[data-producto-id="${productoId}"] .precio-valor`).textContent = formatearPrecio(precio);
            
            // Actualizar carrito
            const card = document.querySelector(`[data-producto-id="${productoId}"]`);
            carrito[productoId] = {
                nombre: card.dataset.productoNombre,
                imagen: card.dataset.productoImagen,
                cantidad: cantidad,
                precio_unitario: precio,
                subtotal: cantidad * precio,
                sin_cargo_envio: parseInt(card.dataset.sinCargoEnvio) || 0
            };
            
            actualizarResumen();
        }
    });
}

// ========================================
// FUNCIONES PARA KITS
// ========================================

function incrementarCantidadKit(kitId, maxStock) {
    const input = document.getElementById(`cantidad-kit-${kitId}`);
    let cantidad = parseInt(input.value) || 0;
    
    if (cantidad < maxStock) {
        cantidad++;
        input.value = cantidad;
        actualizarCarritoKit(kitId);
    } else {
        mostrarAlerta('Stock máximo alcanzado', 'warning');
    }
}

function decrementarCantidadKit(kitId) {
    const input = document.getElementById(`cantidad-kit-${kitId}`);
    let cantidad = parseInt(input.value) || 0;
    
    if (cantidad > 0) {
        cantidad--;
        input.value = cantidad;
        actualizarCarritoKit(kitId);
    }
}

function actualizarCarritoKit(kitId) {
    const cantidad = parseInt(document.getElementById(`cantidad-kit-${kitId}`).value) || 0;
    const kitKey = `kit-${kitId}`;
    
    if (cantidad === 0) {
        // Eliminar del carrito
        delete carrito[kitKey];
        actualizarResumen();
        return;
    }
    
    // Obtener datos del kit desde el DOM
    const card = document.querySelector(`[data-kit-id="${kitId}"]`);
    const precioElemento = document.querySelector(`.precio-display[data-producto-id="kit-${kitId}"] .precio-valor`);
    const precioUnitario = parseFloat(precioElemento.textContent.replace(',', ''));
    
    // Actualizar carrito
    carrito[kitKey] = {
        nombre: card.dataset.productoNombre,
        imagen: card.dataset.productoImagen,
        cantidad: cantidad,
        precio_unitario: precioUnitario,
        subtotal: cantidad * precioUnitario,
        sin_cargo_envio: 0,
        es_kit: true,
        kit_id: kitId
    };
    
    actualizarResumen();
}

function actualizarResumen() {
    const itemsArray = Object.entries(carrito);
    const totalItems = itemsArray.reduce((sum, [_, item]) => sum + item.cantidad, 0);
    const subtotalProductos = itemsArray.reduce((sum, [_, item]) => sum + item.subtotal, 0);
    
    // Verificar si algún producto tiene envío gratis
    const tieneProductoSinCargoEnvio = itemsArray.some(([_, item]) => item.sin_cargo_envio === 1);
    
    // Calcular envío
    let costoEnvio = 0;
    if (subtotalProductos > 0 && !tieneProductoSinCargoEnvio && subtotalProductos < MONTO_MINIMO_ENVIO_GRATIS) {
        costoEnvio = COSTO_ENVIO;
    }
    const totalPedido = subtotalProductos + costoEnvio;
    
    // Si hay un cupón aplicado, revalidarlo
    if (cuponActual) {
        revalidarCupon();
    }
    
    document.getElementById('totalItems').textContent = totalItems;
    document.getElementById('totalPedido').textContent = formatearPrecio(totalPedido);
    
    // Actualizar información de envío
    const infoEnvio = document.getElementById('infoEnvio');
    if (subtotalProductos > 0) {
        if (tieneProductoSinCargoEnvio) {
            infoEnvio.innerHTML = `
                <div class="text-xs text-green-600 font-semibold">
                    ¡Envío gratis! (producto especial)
                </div>
            `;
        } else if (costoEnvio > 0) {
            infoEnvio.innerHTML = `
                <div class="text-xs text-orange-600 font-semibold">
                    Envío: $${formatearPrecio(COSTO_ENVIO)}
                </div>
            `;
        } else {
            const faltante = MONTO_MINIMO_ENVIO_GRATIS - subtotalProductos;
            if (faltante > 0 && faltante < MONTO_MINIMO_ENVIO_GRATIS) {
                infoEnvio.innerHTML = `
                    <div class="text-xs text-green-600 font-semibold">
                        ¡Agrega $${formatearPrecio(faltante)} más para envío gratis!
                    </div>
                `;
            } else {
                infoEnvio.innerHTML = `
                    <div class="text-xs text-green-600 font-semibold">
                        ¡Envío gratis!
                    </div>
                `;
            }
        }
    } else {
        infoEnvio.innerHTML = '';
    }
    
    // Habilitar/deshabilitar botón
    const btnConfirmar = document.getElementById('btnConfirmar');
    btnConfirmar.disabled = totalItems === 0;
    
    // Mostrar/ocultar footer y ajustar padding del contenido
    const footer = document.getElementById('footerResumen');
    const wrapper = document.getElementById('mainWrapper');
    if (totalItems > 0) {
        footer.classList.remove('translate-y-full');
        // Espera a que termine la transición para medir altura real
        setTimeout(() => {
            wrapper.style.paddingBottom = (footer.offsetHeight + 16) + 'px';
        }, 310);
    } else {
        footer.classList.add('translate-y-full');
        wrapper.style.paddingBottom = '';
        document.getElementById('listaResumen').classList.add('hidden');
        document.getElementById('iconToggle').classList.remove('rotate-180');
    }
    
    // Actualizar lista de productos
    const listaResumen = document.getElementById('listaResumen');
    listaResumen.innerHTML = itemsArray.map(([id, item]) => {
        // Determinar la carpeta de imagen según si es kit o producto
        const carpetaImagen = item.es_kit ? 'kits' : 'productos';
        const badgeKit = item.es_kit ? '<span class="text-xs bg-purple-500 text-white px-1 rounded">KIT</span>' : '';
        
        return `
            <div class="flex items-center gap-3 bg-cream-50 p-3 rounded-lg">
                ${item.imagen ? 
                    `<img src="${window.BASE_PATH}uploads/${carpetaImagen}/${item.imagen}" class="w-12 h-12 object-cover rounded-lg">` :
                    `<div class="w-12 h-12 bg-slate-200 rounded-lg flex items-center justify-center">
                        ${item.es_kit ? '' : ''}
                    </div>`
                }
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-slate-900 text-sm truncate">
                        ${badgeKit} ${capitalizeWords(item.nombre)}
                    </p>
                    <p class="text-xs text-slate-600">${item.cantidad} × $${formatearPrecio(item.precio_unitario)}</p>
                </div>
                <p class="font-bold ${item.es_kit ? 'text-purple-600' : 'text-terracotta-600'}">$${formatearPrecio(item.subtotal)}</p>
            </div>
        `;
    }).join('');
}

function toggleResumen() {
    const lista = document.getElementById('listaResumen');
    const icon = document.getElementById('iconToggle');
    
    lista.classList.toggle('hidden');
    icon.classList.toggle('rotate-180');

    // Recalcular padding al expandir/colapsar
    const footer = document.getElementById('footerResumen');
    const wrapper = document.getElementById('mainWrapper');
    setTimeout(() => {
        wrapper.style.paddingBottom = (footer.offsetHeight + 16) + 'px';
    }, 310);
}

function mostrarModalConfirmacion() {
    if (Object.keys(carrito).length === 0) {
        mostrarAlerta('El carrito está vacío', 'warning');
        return;
    }
    
    // Actualizar datos del modal
    const itemsArray = Object.entries(carrito);
    const totalItems = itemsArray.reduce((sum, [_, item]) => sum + item.cantidad, 0);
    
    document.getElementById('confirmTotalItems').textContent = totalItems;
    
    // Actualizar totales (incluye lógica de cupón)
    actualizarTotalesModal();
    
    // Llenar lista de productos
    const confirmItems = document.getElementById('confirmItems');
    confirmItems.innerHTML = itemsArray.map(([id, item]) => {
        const carpetaImagen = item.es_kit ? 'kits' : 'productos';
        const badgeKit = item.es_kit ? '<span class="text-xs bg-purple-500 text-white px-1 rounded">KIT</span> ' : '';
        
        return `
            <div class="flex items-center gap-3 mb-2">
                ${item.imagen ? 
                    `<img src="${window.BASE_PATH}uploads/${carpetaImagen}/${item.imagen}" class="w-12 h-12 object-cover rounded-lg">` :
                    `<div class="w-12 h-12 bg-slate-200 rounded-lg flex items-center justify-center">
                        ${item.es_kit ? '' : ''}
                    </div>`
                }
                <div class="flex-1">
                    <p class="font-semibold text-slate-900 text-sm">${badgeKit}${capitalizeWords(item.nombre)}</p>
                    <p class="text-xs text-slate-600">${item.cantidad} × $${formatearPrecio(item.precio_unitario)}</p>
                </div>
                <p class="font-bold ${item.es_kit ? 'text-purple-600' : 'text-terracotta-600'}">$${formatearPrecio(item.subtotal)}</p>
            </div>
        `;
    }).join('');
    
    // Mostrar modal
    document.getElementById('modalConfirmacion').classList.remove('hidden');
    // Animar entrada
    setTimeout(() => {
        document.querySelector('#modalConfirmacion > div').classList.remove('scale-95');
        document.querySelector('#modalConfirmacion > div').classList.add('scale-100');
    }, 10);
}

function cerrarModalConfirmacion() {
    const modal = document.getElementById('modalConfirmacion');
    const card = modal.querySelector('div');
    
    // Animar salida
    card.classList.remove('scale-100');
    card.classList.add('scale-95');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 200);
}

function procesarPedido() {
    // Cerrar modal
    cerrarModalConfirmacion();
    
    const items = Object.entries(carrito).map(([id, item]) => {
        // Si es un kit, el id será "kit-123"
        if (item.es_kit) {
            return {
                tipo: 'kit',
                kit_id: parseInt(item.kit_id),
                cantidad: item.cantidad,
                precio_unitario: item.precio_unitario
            };
        } else {
            // Es un producto regular
            return {
                tipo: 'producto',
                producto_id: parseInt(id),
                cantidad: item.cantidad,
                precio_unitario: item.precio_unitario
            };
        }
    });
    
    // Calcular total con descuento de cupón
    const subtotal = Object.values(carrito).reduce((sum, item) => sum + item.subtotal, 0);
    const itemsArray = Object.entries(carrito);
    const tieneProductoSinCargoEnvio = itemsArray.some(([_, item]) => item.sin_cargo_envio === 1);
    let costoEnvio = 0;
    let subtotalConDescuento = subtotal - descuentoCupon;
    
    if (!tieneProductoSinCargoEnvio && subtotalConDescuento < MONTO_MINIMO_ENVIO_GRATIS) {
        costoEnvio = COSTO_ENVIO;
    }
    
    const total = subtotalConDescuento + costoEnvio;
    
    const formData = new FormData();
    formData.append('action', 'create_pedido');
    formData.append('telefono', '<?= $telefono ?>');
    formData.append('items', JSON.stringify(items));
    formData.append('total', total);
    
    // Agregar datos del cupón si existe
    if (cuponActual && descuentoCupon > 0) {
        formData.append('cupon_id', cuponActual.id);
        formData.append('cupon_codigo', cuponActual.codigo);
        formData.append('cupon_descuento', descuentoCupon);
    }
    
    fetch('crear-pedido.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Guardar datos del pedido
            window.ultimoPedidoId = data.pedido_id;
            window.ultimoPedidoTotal = total;
            
            // Cerrar modal de confirmación
            cerrarModalConfirmacion();
            
            // Mostrar modal de éxito
            document.getElementById('pedidoNumero').textContent = data.pedido_id;
            document.getElementById('totalPedidoModal').textContent = '$' + formatearPrecio(total);
            document.getElementById('modalExito').classList.remove('hidden');
            
            // Limpiar carrito y cupón
            carrito = {};
            cuponActual = null;
            descuentoCupon = 0;
            actualizarResumen();
        } else {
            mostrarAlerta(data.message || 'Error al crear el pedido', 'error');
            console.error('Error del servidor:', data);
        }
    })
    .catch(error => {
        mostrarAlerta('Error al crear el pedido: ' + error.message, 'error');
        console.error('Error de red:', error);
    });
}

// Funciones para el modal de éxito
function cerrarModalExito() {
    document.getElementById('modalExito').classList.add('hidden');
    // Recargar página para hacer otro pedido
    location.reload();
}

function procederAlPago() {
    window.location.href = `procesar-pago.php?pedido_id=${window.ultimoPedidoId}&telefono=<?= $telefono ?>`;
}

function verSeguimiento() {
    window.location.href = `seguimiento.php?telefono=<?= $telefono ?>`;
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalExito').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalExito();
    }
});

// ========================================
// FUNCIONES DE CUPONES
// ========================================

function mostrarAlerta(mensaje, tipo = 'info') {
    const iconos = {
        'success': '',
        'error': '',
        'warning': '',
        'info': 'ℹ'
    };
    
    const colores = {
        'success': 'bg-green-100 border-green-500 text-green-800',
        'error': 'bg-red-100 border-red-500 text-red-800',
        'warning': 'bg-yellow-100 border-yellow-500 text-yellow-800',
        'info': 'bg-blue-100 border-blue-500 text-blue-800'
    };
    
    // Crear elemento de alerta
    const alerta = document.createElement('div');
    alerta.className = `fixed top-4 right-4 z-50 ${colores[tipo] || colores.info} px-6 py-4 rounded-xl border-l-4 shadow-lg transform transition-all duration-300 max-w-md`;
    alerta.innerHTML = `
        <div class="flex items-center gap-3">
            <span class="text-2xl">${iconos[tipo] || iconos.info}</span>
            <p class="font-medium">${mensaje}</p>
        </div>
    `;
    
    document.body.appendChild(alerta);
    
    // Animar entrada
    setTimeout(() => alerta.classList.add('translate-x-0'), 10);
    
    // Remover después de 5 segundos
    setTimeout(() => {
        alerta.style.opacity = '0';
        alerta.style.transform = 'translateX(100%)';
        setTimeout(() => alerta.remove(), 300);
    }, 5000);
}

async function aplicarCupon() {
    const codigo = document.getElementById('input_cupon').value.trim();
    
    if (!codigo) {
        mostrarMensajeCupon('Por favor ingresa un código de cupón', 'error');
        return;
    }
    
    // Obtener carrito en formato para API
    const carritoArray = Object.entries(carrito).map(([id, item]) => {
        if (item.es_kit) {
            return {
                id: item.kit_id,
                cantidad: item.cantidad,
                precio: item.precio_unitario,
                es_kit: true
            };
        } else {
            return {
                id: parseInt(id),
                cantidad: item.cantidad,
                precio: item.precio_unitario,
                es_kit: false
            };
        }
    });
    
    // Obtener referencia de representante si existe
    const representante_admin_id = <?= !empty($_COOKIE['botikit_rep_admin']) ? (int)$_COOKIE['botikit_rep_admin'] : 'null' ?>;
    
    const formData = new FormData();
    formData.append('action', 'validar');
    formData.append('codigo', codigo);
    formData.append('carrito', JSON.stringify(carritoArray));
    if (representante_admin_id) {
        formData.append('representante_admin_id', representante_admin_id);
    }
    
    try {
        const response = await fetch('<?= BASE_PATH ?>api/cupones.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.valido) {
            // Cupón válido
            cuponActual = data.cupon;
            descuentoCupon = parseFloat(data.descuento);
            
            document.getElementById('descuento_aplicado').classList.remove('hidden');
            document.getElementById('cupon_codigo').textContent = data.cupon.codigo;
            document.getElementById('cupon_descripcion').textContent = data.cupon.descripcion || `Descuento: ${data.descuento_texto}`;
            document.getElementById('input_cupon').value = '';
            document.getElementById('input_cupon').disabled = true;
            document.getElementById('btn_aplicar_cupon').disabled = true;
            
            mostrarMensajeCupon(data.message, 'success');
            
            // Actualizar totales del modal
            actualizarTotalesModal();
        } else {
            mostrarMensajeCupon(data.message, 'error');
            cuponActual = null;
            descuentoCupon = 0;
        }
    } catch (error) {
        mostrarMensajeCupon('Error al validar cupón: ' + error.message, 'error');
    }
}

function removerCupon() {
    cuponActual = null;
    descuentoCupon = 0;
    
    document.getElementById('descuento_aplicado').classList.add('hidden');
    document.getElementById('input_cupon').value = '';
    document.getElementById('input_cupon').disabled = false;
    document.getElementById('btn_aplicar_cupon').disabled = false;
    document.getElementById('mensaje_cupon').innerHTML = '';
    
    actualizarTotalesModal();
}

async function revalidarCupon() {
    if (!cuponActual) return;
    
    // Obtener carrito en formato para API
    const carritoArray = Object.entries(carrito).map(([id, item]) => {
        if (item.es_kit) {
            return {
                id: item.kit_id,
                cantidad: item.cantidad,
                precio: item.precio_unitario,
                es_kit: true
            };
        } else {
            return {
                id: parseInt(id),
                cantidad: item.cantidad,
                precio: item.precio_unitario,
                es_kit: false
            };
        }
    });
    
    // Si el carrito está vacío, remover cupón
    if (carritoArray.length === 0) {
        removerCupon();
        return;
    }
    
    // Obtener referencia de representante si existe
    const representante_admin_id = <?= !empty($_COOKIE['botikit_rep_admin']) ? (int)$_COOKIE['botikit_rep_admin'] : 'null' ?>;
    
    const formData = new FormData();
    formData.append('action', 'validar');
    formData.append('codigo', cuponActual.codigo);
    formData.append('carrito', JSON.stringify(carritoArray));
    if (representante_admin_id) {
        formData.append('representante_admin_id', representante_admin_id);
    }
    
    try {
        const response = await fetch('<?= BASE_PATH ?>api/cupones.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && data.valido) {
            // Actualizar descuento
            descuentoCupon = parseFloat(data.descuento);
            actualizarTotalesModal();
        } else {
            // El cupón ya no es válido (no aplica a productos actuales)
            mostrarMensajeCupon('El cupón ya no aplica a los productos en tu carrito', 'error');
            removerCupon();
        }
    } catch (error) {
        console.error('Error al revalidar cupón:', error);
    }
}

function mostrarMensajeCupon(mensaje, tipo) {
    const contenedor = document.getElementById('mensaje_cupon');
    const colores = {
        'success': 'text-green-600',
        'error': 'text-red-600',
        'info': 'text-blue-600'
    };
    
    contenedor.innerHTML = `<span class="${colores[tipo] || colores.info}">${mensaje}</span>`;
    
    // Limpiar después de 5 segundos
    setTimeout(() => {
        if (tipo !== 'success') {
            contenedor.innerHTML = '';
        }
    }, 5000);
}

function actualizarTotalesModal() {
    const itemsArray = Object.entries(carrito);
    const subtotalProductos = itemsArray.reduce((sum, [_, item]) => sum + item.subtotal, 0);
    
    // Verificar si algún producto tiene envío gratis
    const tieneProductoSinCargoEnvio = itemsArray.some(([_, item]) => item.sin_cargo_envio === 1);
    
    // Calcular envío
    let costoEnvio = 0;
    let subtotalConDescuento = subtotalProductos - descuentoCupon;
    
    if (!tieneProductoSinCargoEnvio && subtotalConDescuento < MONTO_MINIMO_ENVIO_GRATIS) {
        costoEnvio = COSTO_ENVIO;
    }
    
    const totalPedido = subtotalConDescuento + costoEnvio;
    
    // Actualizar valores
    document.getElementById('confirmSubtotal').textContent = formatearPrecio(subtotalProductos);
    document.getElementById('confirmDescuento').textContent = formatearPrecio(descuentoCupon);
    document.getElementById('confirmEnvio').textContent = formatearPrecio(costoEnvio);
    document.getElementById('confirmTotal').textContent = formatearPrecio(totalPedido);
    
    // Mostrar/ocultar líneas
    const lineaCupon = document.getElementById('lineaCupon');
    const lineaEnvio = document.getElementById('lineaEnvio');
    
    if (descuentoCupon > 0) {
        lineaCupon.classList.remove('hidden');
    } else {
        lineaCupon.classList.add('hidden');
    }
    
    if (costoEnvio > 0) {
        lineaEnvio.classList.remove('hidden');
    } else {
        lineaEnvio.classList.add('hidden');
    }
}

</script>

</body>
</html>
