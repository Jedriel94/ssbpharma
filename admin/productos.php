<?php
require_once '../includes/auth_admin.php'; // Proteger página
require_once '../models/Producto.php';

$productoModel = new Producto();

// Función para manejar subida de imágenes
function handleImageUpload($file) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // Validar tipo de archivo
    if (!in_array($file['type'], $allowedTypes)) {
        return false;
    }
    
    // Validar tamaño
    if ($file['size'] > $maxSize) {
        return false;
    }
    
    // Verificar que la imagen sea cuadrada
    $imageInfo = getimagesize($file['tmp_name']);
    if (!$imageInfo) {
        return false;
    }
    
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    
    // Validar que sea cuadrada (ancho = alto)
    if ($width !== $height) {
        return false;
    }
    
    // Generar nombre único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $nombreArchivo = uniqid('prod_') . '.' . $extension;
    $rutaDestino = '../uploads/productos/' . $nombreArchivo;
    
    // Mover archivo sin modificar (mantener tamaño original cuadrado)
    if (move_uploaded_file($file['tmp_name'], $rutaDestino)) {
        return $nombreArchivo;
    }
    
    return false;
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $producto = $_POST['producto'] ?? '';
            $existencia = $_POST['existencia'] ?? 0;
            $activo = $_POST['activo'] ?? 1;
            $sin_cargo_envio = isset($_POST['sin_cargo_envio']) ? 1 : 0;
            $en_carrusel = isset($_POST['en_carrusel']) ? 1 : 0;
            $tags = trim($_POST['tags'] ?? '');
            $codigo_barras = trim($_POST['codigo_barras'] ?? '') ?: null;
            $marca = trim($_POST['marca'] ?? '') ?: null;
            $impuesto = max(0, min(1, (float)($_POST['impuesto_pct'] ?? 16) / 100));
            $imagen = null;
            
            // Manejar subida de imagen
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $imagen = handleImageUpload($_FILES['imagen']);
                if ($imagen === false) {
                    echo json_encode(['success' => false, 'message' => 'Error: La imagen debe ser cuadrada (mismo ancho y alto). Ej: 500x500, 1000x1000']);
                    exit;
                }
            }
            
            if ($productoModel->create($producto, $existencia, $imagen, $activo, $tags, $sin_cargo_envio, $en_carrusel, $codigo_barras, $marca, $impuesto)) {
                echo json_encode(['success' => true, 'message' => 'Producto creado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al crear producto']);
            }
            exit;
            
        case 'update':
            $id = $_POST['id'] ?? 0;
            $producto = $_POST['producto'] ?? '';
            $existencia = $_POST['existencia'] ?? 0;
            $activo = $_POST['activo'] ?? 1;
            $sin_cargo_envio = isset($_POST['sin_cargo_envio']) ? 1 : 0;
            $en_carrusel = isset($_POST['en_carrusel']) ? 1 : 0;
            $tags = trim($_POST['tags'] ?? '');
            $codigo_barras = trim($_POST['codigo_barras'] ?? '') ?: null;
            $marca = trim($_POST['marca'] ?? '') ?: null;
            $impuesto = max(0, min(1, (float)($_POST['impuesto_pct'] ?? 16) / 100));
            $imagen = null;
            
            // Manejar subida de imagen
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $imagen = handleImageUpload($_FILES['imagen']);
                if ($imagen === false) {
                    echo json_encode(['success' => false, 'message' => 'Error: La imagen debe ser cuadrada (mismo ancho y alto). Ej: 500x500, 1000x1000']);
                    exit;
                }
                
                // Eliminar imagen anterior si existe
                $productoActual = $productoModel->getById($id);
                if ($productoActual && $productoActual['imagen']) {
                    $rutaAnterior = '../uploads/productos/' . $productoActual['imagen'];
                    if (file_exists($rutaAnterior)) {
                        unlink($rutaAnterior);
                    }
                }
            }
            
            if ($productoModel->update($id, $producto, $existencia, $imagen, $activo, $tags, $sin_cargo_envio, $en_carrusel, $codigo_barras, $marca, $impuesto)) {
                echo json_encode(['success' => true, 'message' => 'Producto actualizado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar producto']);
            }
            exit;
            
        case 'delete':
            $id = $_POST['id'] ?? 0;

            if ($productoModel->delete($id)) {
                echo json_encode(['success' => true, 'message' => 'Producto eliminado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al eliminar producto']);
            }
            exit;

        case 'reordenar':
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['success' => false, 'message' => 'Orden inválido']);
                exit;
            }
            $productoModel->actualizarOrden($ids);
            echo json_encode(['success' => true, 'message' => 'Orden guardado']);
            exit;
            
        case 'get':
            $id = $_POST['id'] ?? 0;
            $producto = $productoModel->getById($id);
            echo json_encode(['success' => true, 'producto' => $producto]);
            exit;
            
        case 'getTags':
            $tags = $productoModel->getAllTags();
            echo json_encode(['success' => true, 'tags' => $tags]);
            exit;
            
        case 'add_rango':
            $producto_id = $_POST['producto_id'] ?? 0;
            $cantidad_min = $_POST['cantidad_min'] ?? 0;
            $cantidad_max = $_POST['cantidad_max'] ?? null;
            $precio = $_POST['precio'] ?? 0;
            
            if (empty($cantidad_max)) $cantidad_max = null;
            
            if ($productoModel->createRangoPrecio($producto_id, $cantidad_min, $cantidad_max, $precio)) {
                echo json_encode(['success' => true, 'message' => 'Rango de precio agregado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al agregar rango']);
            }
            exit;
            
        case 'delete_rango':
            $id = $_POST['id'] ?? 0;
            
            if ($productoModel->deleteRangoPrecio($id)) {
                echo json_encode(['success' => true, 'message' => 'Rango eliminado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al eliminar rango']);
            }
            exit;
            
        case 'get_rango':
            $id = $_POST['id'] ?? 0;
            $rango = $productoModel->getRangoPrecioById($id);
            
            if ($rango) {
                echo json_encode(['success' => true, 'rango' => $rango]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Rango no encontrado']);
            }
            exit;
            
        case 'update_rango':
            $id = $_POST['id'] ?? 0;
            $cantidad_min = $_POST['cantidad_min'] ?? 0;
            $cantidad_max = $_POST['cantidad_max'] ?? null;
            $precio = $_POST['precio'] ?? 0;
            
            if (empty($cantidad_max)) $cantidad_max = null;
            
            if ($productoModel->updateRangoPrecio($id, $cantidad_min, $cantidad_max, $precio)) {
                echo json_encode(['success' => true, 'message' => 'Rango actualizado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar rango']);
            }
            exit;
            
        case 'get_rangos':
            $producto_id = $_POST['producto_id'] ?? 0;
            $rangos = $productoModel->getRangosPrecios($producto_id);
            echo json_encode(['success' => true, 'rangos' => $rangos]);
            exit;
    }
}

$productos = $productoModel->getAll();
$totalProductos = count($productos);
$productosActivos = count(array_filter($productos, fn($p) => !empty($p['activo'])));
$productosInactivos = $totalProductos - $productosActivos;
$productosBajoStock = count(array_filter($productos, fn($p) => (int)$p['existencia'] > 0 && (int)$p['existencia'] <= 5));
$productosAgotados = count(array_filter($productos, fn($p) => (int)$p['existencia'] <= 0));
$productosSinCodigo = count(array_filter($productos, fn($p) => empty($p['codigo_barras'])));
$productosSinImagen = count(array_filter($productos, fn($p) => empty($p['imagen'])));
?>

<?php include '../includes/header.php'; ?>

<style>
    .prod-shell {
        max-width: 1280px;
        margin: 0 auto;
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .prod-stat,
    .prod-filter,
    .prod-table-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(15, 23, 42, 0.08));
    }

    .prod-chip {
        background: var(--bg-secondary);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
    }

    .prod-chip:hover,
    .prod-chip.is-active {
        background: var(--text-primary);
        color: var(--bg-card);
        border-color: var(--text-primary);
    }

    .prod-thumb {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        background: var(--bg-secondary);
        overflow: hidden;
        flex: 0 0 auto;
    }

    .prod-stock-dot {
        width: 0.55rem;
        height: 0.55rem;
        border-radius: 999px;
        display: inline-block;
    }

    .prod-empty {
        border: 1px dashed var(--border-color);
        background: var(--bg-secondary);
        color: var(--text-muted);
    }

    .prod-modal-section {
        background: color-mix(in srgb, var(--bg-secondary) 70%, var(--bg-card));
        border: 1px solid var(--border-color);
    }
</style>

<div class="container mx-auto px-4 py-8">
    
    <!-- Header con Estadísticas -->
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-800 mb-2">Gestión de Productos</h1>
                <p class="text-slate-600">Inventario, códigos de barras, disponibilidad y rangos de precios.</p>
            </div>
            <button onclick="abrirModalProducto()" class="btn-primary px-5 py-3 rounded-xl shadow flex items-center gap-2">
            Nuevo Producto
            </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
            <div class="prod-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Total</div>
                <div class="text-2xl font-bold text-slate-800"><?= $totalProductos ?></div>
            </div>
            <div class="prod-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Activos</div>
                <div class="text-2xl font-bold text-green-700"><?= $productosActivos ?></div>
            </div>
            <div class="prod-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Inactivos</div>
                <div class="text-2xl font-bold text-slate-700"><?= $productosInactivos ?></div>
            </div>
            <div class="prod-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Bajo stock</div>
                <div class="text-2xl font-bold text-amber-700"><?= $productosBajoStock ?></div>
            </div>
            <div class="prod-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Sin código</div>
                <div class="text-2xl font-bold text-blue-700"><?= $productosSinCodigo ?></div>
            </div>
            <div class="prod-stat rounded-xl p-4">
                <div class="text-slate-600 text-xs mb-1">Sin imagen</div>
                <div class="text-2xl font-bold text-red-700"><?= $productosSinImagen ?></div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="prod-filter rounded-xl p-4 mb-6">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Buscar producto</label>
                <input id="filtro-productos" type="search" placeholder="Nombre, tag o código de barras"
                       class="input-field w-full px-4 py-3 rounded-xl">
            </div>
            <div class="flex items-end">
                <button type="button" onclick="limpiarFiltrosProductos()" class="btn-secondary px-4 py-3 rounded-xl font-semibold">
                    Limpiar
                </button>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-4">
            <button type="button" data-prod-filter="todos" onclick="filtrarProductos('todos')" class="prod-chip prod-filter-btn is-active px-3 py-2 rounded-lg text-xs font-bold">Todos (<?= $totalProductos ?>)</button>
            <button type="button" data-prod-filter="activos" onclick="filtrarProductos('activos')" class="prod-chip prod-filter-btn px-3 py-2 rounded-lg text-xs font-bold">Activos (<?= $productosActivos ?>)</button>
            <button type="button" data-prod-filter="inactivos" onclick="filtrarProductos('inactivos')" class="prod-chip prod-filter-btn px-3 py-2 rounded-lg text-xs font-bold">Inactivos (<?= $productosInactivos ?>)</button>
            <button type="button" data-prod-filter="bajo_stock" onclick="filtrarProductos('bajo_stock')" class="prod-chip prod-filter-btn px-3 py-2 rounded-lg text-xs font-bold">Bajo stock (<?= $productosBajoStock ?>)</button>
            <button type="button" data-prod-filter="agotados" onclick="filtrarProductos('agotados')" class="prod-chip prod-filter-btn px-3 py-2 rounded-lg text-xs font-bold">Agotados (<?= $productosAgotados ?>)</button>
            <button type="button" data-prod-filter="sin_codigo" onclick="filtrarProductos('sin_codigo')" class="prod-chip prod-filter-btn px-3 py-2 rounded-lg text-xs font-bold">Sin código (<?= $productosSinCodigo ?>)</button>
            <button type="button" data-prod-filter="sin_imagen" onclick="filtrarProductos('sin_imagen')" class="prod-chip prod-filter-btn px-3 py-2 rounded-lg text-xs font-bold">Sin imagen (<?= $productosSinImagen ?>)</button>
        </div>
        <p class="text-sm text-slate-600 mt-4">Mostrando <span id="productos-visibles" class="font-bold text-slate-900"><?= $totalProductos ?></span> de <?= $totalProductos ?> productos</p>
    </div>

    <!-- Tabla de Productos -->
    <?php if (empty($productos)): ?>
        <div class="prod-empty rounded-xl p-12 text-center">
            <p class="text-slate-600 text-lg">No hay productos registrados</p>
            <p class="text-slate-500 text-sm mt-2">Haz clic en "Nuevo Producto" para comenzar</p>
        </div>
    <?php else: ?>
        <div class="prod-table-card rounded-xl overflow-hidden">
            <div class="px-6 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-2" style="border-bottom:1px solid var(--border-card)">
                <div>
                    <h2 class="text-xl font-bold" style="color:var(--text-primary)">Catálogo operativo</h2>
                    <p class="text-sm" style="color:var(--text-muted)">Productos listos para búsqueda, inventario y edición rápida.</p>
                </div>
                <span class="text-sm" style="color:var(--text-muted)"><?= $totalProductos ?> total</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Producto</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Código</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase">Stock</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase">Estado</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Tags</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200" id="productos-table-body">
                        <?php foreach ($productos as $producto): ?>
                            <?php
                            $stock = (int)$producto['existencia'];
                            $stockEstado = $stock <= 0 ? 'agotado' : ($stock <= 5 ? 'bajo_stock' : 'disponible');
                            $stockColor = $stock <= 0 ? 'bg-red-500' : ($stock <= 5 ? 'bg-amber-500' : 'bg-green-500');
                            $tagsProducto = trim((string)($producto['tags'] ?? ''));
                            ?>
                            <tr class="producto-row hover:bg-slate-50 transition <?= $producto['activo'] ? '' : 'opacity-70' ?>"
                                data-id="<?= (int)$producto['id'] ?>"
                                data-nombre="<?= htmlspecialchars(mb_strtolower($producto['producto'] ?? ''), ENT_QUOTES) ?>"
                                data-codigo="<?= htmlspecialchars(mb_strtolower($producto['codigo_barras'] ?? ''), ENT_QUOTES) ?>"
                                data-tags="<?= htmlspecialchars(mb_strtolower($tagsProducto), ENT_QUOTES) ?>"
                                data-activo="<?= !empty($producto['activo']) ? '1' : '0' ?>"
                                data-stock="<?= $stockEstado ?>"
                                data-imagen="<?= !empty($producto['imagen']) ? '1' : '0' ?>"
                                data-codigo-ok="<?= !empty($producto['codigo_barras']) ? '1' : '0' ?>">
                                <td class="px-4 py-4 min-w-[280px]">
                                    <div class="flex items-center gap-3">
                                        <span class="drag-handle cursor-move text-slate-300 hover:text-slate-500 flex-shrink-0" title="Arrastra para reordenar">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.6"/><circle cx="15" cy="5" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="19" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>
                                        </span>
                                        <div class="prod-thumb">
                                            <?php if ($producto['imagen']): ?>
                                                <img src="../uploads/productos/<?= htmlspecialchars($producto['imagen']) ?>"
                                                     alt="<?= htmlspecialchars($producto['producto']) ?>"
                                                     class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div class="w-full h-full flex items-center justify-center text-slate-400">
                                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold text-slate-900 truncate"><?= htmlspecialchars($producto['producto']) ?></div>
                                            <?php if (!empty($producto['marca'])): ?>
                                                <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($producto['marca']) ?></div>
                                            <?php endif; ?>
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                <?php
                                                    $iva_pct = round((float)($producto['impuesto'] ?? 0.16) * 100, 2);
                                                    $iva_color = $iva_pct == 0 ? 'bg-slate-50 text-slate-500 border-slate-200' : ($iva_pct == 16 ? 'bg-violet-50 text-violet-700 border-violet-200' : 'bg-orange-50 text-orange-700 border-orange-200');
                                                ?>
                                                <span class="text-[11px] px-2 py-0.5 rounded-full border <?= $iva_color ?>">IVA <?= $iva_pct ?>%</span>
                                                <?php if (!empty($producto['sin_cargo_envio'])): ?>
                                                    <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">Envío gratis</span>
                                                <?php endif; ?>
                                                <?php if (!empty($producto['en_carrusel'])): ?>
                                                    <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">Carrusel</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if (!empty($producto['codigo_barras'])): ?>
                                        <button type="button" onclick="copiarCodigoBarras('<?= htmlspecialchars($producto['codigo_barras'], ENT_QUOTES) ?>')" class="font-mono text-xs tracking-widest px-2 py-1 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 hover:bg-slate-200 transition">
                                            <?= htmlspecialchars($producto['codigo_barras']) ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Sin código</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <div class="inline-flex items-center gap-2">
                                        <span class="prod-stock-dot <?= $stockColor ?>"></span>
                                        <span class="font-bold text-slate-800"><?= $stock ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="<?= $producto['activo'] ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-600 border-slate-200' ?> text-xs px-3 py-1 rounded-full font-semibold border">
                                        <?= $producto['activo'] ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 min-w-[180px]">
                                    <?php if ($tagsProducto !== ''): ?>
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $tagsProducto))), 0, 3) as $tag): ?>
                                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200"><?= htmlspecialchars($tag) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Sin tags</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex justify-center gap-2">
                                        <button onclick="editarProducto(<?= $producto['id'] ?>)" class="px-3 py-2 rounded-lg text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                                            Editar
                                        </button>
                                        <button type="button"
                                                data-producto-id="<?= (int)$producto['id'] ?>"
                                                data-producto-nombre="<?= htmlspecialchars($producto['producto']) ?>"
                                                onclick="abrirRangosProducto(this)"
                                                class="px-3 py-2 rounded-lg text-xs font-semibold btn-secondary">
                                            Rangos
                                        </button>
                                        <button onclick="eliminarProducto(<?= $producto['id'] ?>)" class="px-3 py-2 rounded-lg text-xs font-semibold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition">
                                            Eliminar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="productos-empty-filter" class="hidden prod-empty m-4 rounded-xl p-8 text-center text-sm">
                No hay productos con los filtros seleccionados.
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal Producto -->
<div id="modalProducto" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="card rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-5 border-b border-slate-200 flex items-center justify-between">
            <div>
                <h2 id="modalTitle" class="text-2xl font-bold text-slate-900">Nuevo Producto</h2>
                <p class="text-sm text-slate-500 mt-1">Información comercial, inventario e identificadores.</p>
            </div>
            <button onclick="cerrarModalProducto()" class="text-slate-500 hover:text-slate-900 p-2 rounded-lg hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <form id="formProducto" onsubmit="guardarProducto(event)" enctype="multipart/form-data" class="p-6">
            <input type="hidden" id="producto_id" name="id">
            <input type="hidden" id="imagen_actual" name="imagen_actual">
            
            <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-5">
                <!-- Imagen -->
                <div class="prod-modal-section rounded-xl p-4">
                    <h3 class="font-bold text-slate-800 mb-3">Imagen</h3>
                    <div id="previewContainer" class="hidden mb-3">
                        <div class="w-full aspect-square overflow-hidden rounded-xl border border-slate-200">
                            <img id="imagePreview" src="" alt="Preview" class="w-full h-full object-cover">
                        </div>
                        <p class="text-xs text-sage-600 mt-2 text-center">Vista previa cuadrada</p>
                    </div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Archivo del producto</label>
                    <input type="file" id="producto_imagen" name="imagen" accept="image/*"
                           class="input-field w-full px-4 py-3 rounded-xl text-sm"
                           onchange="previewImage(this)">
                    <p class="text-xs text-slate-500 mt-2">JPG, PNG, GIF, WEBP. Máx. 5MB. Debe ser cuadrada.</p>
                </div>

                <div class="space-y-4">
                    <div class="prod-modal-section rounded-xl p-4">
                        <h3 class="font-bold text-slate-800 mb-4">Información básica</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 mb-2">Nombre del Producto</label>
                                <input type="text" id="producto_nombre" name="producto" required
                                       class="input-field w-full px-4 py-3 rounded-xl">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Marca</label>
                                <input type="text" id="producto_marca" name="marca"
                                       placeholder="Ej: Solumedic, Columbia"
                                       class="input-field w-full px-4 py-3 rounded-xl">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Existencia</label>
                                <input type="number" id="producto_existencia" name="existencia" required min="0"
                                       class="input-field w-full px-4 py-3 rounded-xl">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Código de barras</label>
                                <input type="text" id="producto_codigo_barras" name="codigo_barras"
                                       placeholder="Ej: 7501234567890"
                                       class="input-field w-full px-4 py-3 rounded-xl font-mono tracking-widest">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">IVA (%)</label>
                                <div class="relative">
                                    <input type="number" id="producto_impuesto_pct" name="impuesto_pct"
                                           min="0" max="100" step="0.01" value="16"
                                           class="input-field w-full px-4 py-3 pr-10 rounded-xl">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold pointer-events-none">%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="prod-modal-section rounded-xl p-4">
                        <h3 class="font-bold text-slate-800 mb-4">Tags y configuración</h3>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Tags/Etiquetas</label>
                        <input type="text" id="producto_tags" name="tags" 
                               placeholder="Ej: cosmetico,natural,vegano,premium"
                               class="input-field w-full px-4 py-3 rounded-xl">
                        <p class="text-xs text-slate-500 mt-1">Separa las etiquetas con comas.</p>
                        <div id="tagsSugerencias" class="mt-2 flex flex-wrap gap-1"></div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                            <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 p-3 bg-white/50">
                                <input type="checkbox" id="producto_activo" name="activo" value="1" checked
                                       class="w-5 h-5 text-sage-600 rounded focus:ring-sage-500">
                                <span>
                                    <span class="block text-sm font-semibold text-slate-700">Activo</span>
                                    <span class="block text-xs text-slate-500">Visible en pedidos</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 p-3 bg-white/50">
                                <input type="checkbox" id="producto_sin_cargo_envio" name="sin_cargo_envio" value="1"
                                       class="w-5 h-5 text-blue-600 rounded focus:ring-blue-500">
                                <span>
                                    <span class="block text-sm font-semibold text-slate-700">Envío gratis</span>
                                    <span class="block text-xs text-slate-500">Sin cargo de envío</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 p-3 bg-white/50">
                                <input type="checkbox" id="producto_en_carrusel" name="en_carrusel" value="1"
                                       class="w-5 h-5 text-blue-600 rounded focus:ring-blue-500">
                                <span>
                                    <span class="block text-sm font-semibold text-slate-700">Carrusel</span>
                                    <span class="block text-xs text-slate-500">Destacado</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-3 mt-6">
                <button type="button" onclick="cerrarModalProducto()" 
                        class="flex-1 btn-secondary py-3 rounded-xl font-medium">
                    Cancelar
                </button>
                <button type="submit" 
                        class="flex-1 btn-primary py-3 rounded-xl font-medium">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Rangos de Precios -->
<div id="modalRangos" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="card rounded-3xl shadow-2xl max-w-2xl w-full p-8 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-900">Rangos de Precios</h2>
            <button onclick="cerrarModalRangos()" class="text-slate-600 hover:text-slate-900">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <p id="rangoProductoNombre" class="text-slate-600 mb-6"></p>
        
        <!-- Formulario Nuevo/Editar Rango -->
        <form id="formRango" onsubmit="guardarRango(event)" class="card p-4 rounded-2xl mb-6">
            <input type="hidden" id="rango_producto_id">
            <input type="hidden" id="rango_id">
            <h3 id="tituloFormRango" class="font-semibold text-slate-900 mb-4">Agregar Nuevo Rango</h3>
            
            <div class="grid grid-cols-3 gap-3 mb-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Cantidad Mín</label>
                    <input type="number" id="cantidad_min" required min="1"
                           class="input-field w-full px-3 py-2 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Cantidad Máx</label>
                    <input type="number" id="cantidad_max" min="1" placeholder="Ilimitado"
                           class="input-field w-full px-3 py-2 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Precio</label>
                    <input type="number" id="precio" required min="0" step="0.01"
                           class="input-field w-full px-3 py-2 rounded-lg text-sm">
                </div>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="cancelarEdicionRango()" id="btnCancelarRango"
                        class="hidden flex-1 bg-slate-200 text-slate-700 py-2 rounded-lg text-sm font-medium hover:bg-slate-300">
                    Cancelar
                </button>
                <button type="submit" id="btnGuardarRango"
                        class="flex-1 btn-secondary text-white py-2 rounded-lg text-sm">
                    Agregar Rango
                </button>
            </div>
        </form>
        
        <!-- Lista de Rangos -->
        <div id="listaRangos" class="space-y-3">
            <!-- Se llenará dinámicamente -->
        </div>
    </div>
</div>

<script>
let editandoId = null;
let filtroProductosActivo = 'todos';

function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const container = document.getElementById('previewContainer');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.classList.remove('hidden');
        };
        
        reader.readAsDataURL(input.files[0]);
    }
}

function abrirModalProducto() {
    editandoId = null;
    document.getElementById('modalTitle').textContent = 'Nuevo Producto';
    document.getElementById('formProducto').reset();
    document.getElementById('producto_activo').checked = true;
    document.getElementById('producto_tags').value = '';
    document.getElementById('producto_codigo_barras').value = '';
    document.getElementById('producto_marca').value = '';
    document.getElementById('producto_impuesto_pct').value = '16';
    document.getElementById('previewContainer').classList.add('hidden');
    cargarSugerenciasTags();
    document.getElementById('modalProducto').classList.remove('hidden');
}

function cerrarModalProducto() {
    document.getElementById('modalProducto').classList.add('hidden');
    document.getElementById('previewContainer').classList.add('hidden');
}

function editarProducto(id) {
    editandoId = id;
    fetch('productos.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get&id=' + id
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('modalTitle').textContent = 'Editar Producto';
            document.getElementById('producto_id').value = data.producto.id;
            document.getElementById('producto_nombre').value = data.producto.producto;
            document.getElementById('producto_existencia').value = data.producto.existencia;
            document.getElementById('producto_tags').value = data.producto.tags || '';
            document.getElementById('producto_activo').checked = data.producto.activo == 1;
            document.getElementById('producto_sin_cargo_envio').checked = data.producto.sin_cargo_envio == 1;
            document.getElementById('producto_en_carrusel').checked = data.producto.en_carrusel == 1;
            document.getElementById('producto_codigo_barras').value = data.producto.codigo_barras || '';
            document.getElementById('producto_marca').value = data.producto.marca || '';
            document.getElementById('producto_impuesto_pct').value = parseFloat(((data.producto.impuesto ?? 0.16) * 100).toFixed(4));
            document.getElementById('imagen_actual').value = data.producto.imagen || '';
            
            // Mostrar imagen actual si existe
            if (data.producto.imagen) {
                document.getElementById('imagePreview').src = '../uploads/productos/' + data.producto.imagen;
                document.getElementById('previewContainer').classList.remove('hidden');
            } else {
                document.getElementById('previewContainer').classList.add('hidden');
            }
            
            cargarSugerenciasTags();
            document.getElementById('modalProducto').classList.remove('hidden');
        }
    });
}

function cargarSugerenciasTags() {
    const formData = new FormData();
    formData.append('action', 'getTags');

    fetch('productos.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.tags) {
            const container = document.getElementById('tagsSugerencias');
            container.innerHTML = '<small class="text-gray-600">Tags existentes: </small>';
            data.tags.forEach(tag => {
                const badge = document.createElement('span');
                badge.className = 'inline-block bg-slate-100 text-slate-700 border border-slate-200 text-xs px-2 py-1 rounded cursor-pointer hover:bg-slate-200 mr-1 mb-1';
                badge.textContent = tag;
                badge.onclick = () => agregarTagSugerido(tag);
                container.appendChild(badge);
            });
        }
    });
}

function aplicarFiltrosProductos() {
    const q = (document.getElementById('filtro-productos')?.value || '').toLowerCase().trim();
    const filas = document.querySelectorAll('.producto-row');
    let visibles = 0;

    filas.forEach(fila => {
        const texto = [
            fila.dataset.nombre || '',
            fila.dataset.codigo || '',
            fila.dataset.tags || ''
        ].join(' ');

        const cumpleTexto = !q || texto.includes(q);
        const cumpleFiltro =
            filtroProductosActivo === 'todos' ||
            (filtroProductosActivo === 'activos' && fila.dataset.activo === '1') ||
            (filtroProductosActivo === 'inactivos' && fila.dataset.activo === '0') ||
            (filtroProductosActivo === 'bajo_stock' && fila.dataset.stock === 'bajo_stock') ||
            (filtroProductosActivo === 'agotados' && fila.dataset.stock === 'agotado') ||
            (filtroProductosActivo === 'sin_codigo' && fila.dataset.codigoOk === '0') ||
            (filtroProductosActivo === 'sin_imagen' && fila.dataset.imagen === '0');

        const mostrar = cumpleTexto && cumpleFiltro;
        fila.classList.toggle('hidden', !mostrar);
        if (mostrar) visibles++;
    });

    const contador = document.getElementById('productos-visibles');
    if (contador) contador.textContent = visibles;

    const empty = document.getElementById('productos-empty-filter');
    if (empty) empty.classList.toggle('hidden', visibles !== 0);
}

function filtrarProductos(filtro) {
    filtroProductosActivo = filtro;
    document.querySelectorAll('.prod-filter-btn').forEach(btn => {
        btn.classList.toggle('is-active', btn.dataset.prodFilter === filtro);
    });
    aplicarFiltrosProductos();
}

function limpiarFiltrosProductos() {
    const input = document.getElementById('filtro-productos');
    if (input) input.value = '';
    filtrarProductos('todos');
}

function copiarCodigoBarras(codigo) {
    if (!codigo) return;
    if (!navigator.clipboard) {
        mostrarAlerta('Tu navegador no permite copiar automáticamente', 'error');
        return;
    }

    navigator.clipboard.writeText(codigo)
        .then(() => mostrarAlerta('Código copiado', 'success'))
        .catch(() => mostrarAlerta('No se pudo copiar el código', 'error'));
}

function agregarTagSugerido(tag) {
    const input = document.getElementById('producto_tags');
    const currentTags = input.value.split(',').map(t => t.trim()).filter(t => t);
    if (!currentTags.includes(tag)) {
        currentTags.push(tag);
        input.value = currentTags.join(',');
    }
}

function guardarProducto(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', editandoId ? 'update' : 'create');
    formData.append('activo', document.getElementById('producto_activo').checked ? 1 : 0);
    
    fetch('productos.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        mostrarAlerta(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            cerrarModalProducto();
            setTimeout(() => location.reload(), 1000);
        }
    });
}

function eliminarProducto(id) {
    if (confirm('¿Estás seguro de eliminar este producto?')) {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        fetch('productos.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            mostrarAlerta(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                setTimeout(() => location.reload(), 1000);
            }
        });
    }
}

function verRangos(productoId, nombreProducto) {
    document.getElementById('rango_producto_id').value = productoId;
    document.getElementById('rangoProductoNombre').textContent = 'Producto: ' + nombreProducto;
    cancelarEdicionRango(); // Resetear formulario
    cargarRangos(productoId);
    document.getElementById('modalRangos').classList.remove('hidden');
}

function abrirRangosProducto(button) {
    verRangos(button.dataset.productoId, button.dataset.productoNombre);
}

function cerrarModalRangos() {
    document.getElementById('modalRangos').classList.add('hidden');
    cancelarEdicionRango();
}

function cargarRangos(productoId) {
    const formData = new FormData();
    formData.append('action', 'get_rangos');
    formData.append('producto_id', productoId);
    const lista = document.getElementById('listaRangos');
    lista.innerHTML = '<p class="text-slate-500 text-center py-4">Cargando rangos...</p>';
    
    fetch('productos.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (data.rangos.length === 0) {
                lista.innerHTML = '<p class="text-slate-500 text-center py-4">No hay rangos de precios definidos</p>';
            } else {
                lista.innerHTML = data.rangos.map(rango => `
                    <div class="card p-4 rounded-xl flex justify-between items-center">
                        <div>
                            <p class="font-semibold text-slate-900">
                                ${rango.cantidad_min} - ${rango.cantidad_max || '∞'} unidades
                            </p>
                            <p class="text-terracotta-600 font-bold text-lg">$${parseFloat(rango.precio).toFixed(2)}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button"
                                    onclick="editarRango(${rango.id}, event)" 
                                    class="text-sage-600 hover:text-sage-700 p-2 rounded-lg hover:bg-sage-50"
                                    title="Editar">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            <button type="button"
                                    onclick="eliminarRango(${rango.id}, ${productoId}, event)" 
                                    class="text-red-500 hover:text-red-700 p-2 rounded-lg hover:bg-red-50"
                                    title="Eliminar">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                `).join('');
            }
        } else {
            lista.innerHTML = '<p class="text-red-500 text-center py-4">No se pudieron cargar los rangos</p>';
        }
    })
    .catch(() => {
        lista.innerHTML = '<p class="text-red-500 text-center py-4">Error de conexión al cargar rangos</p>';
    });
}

function guardarRango(e) {
    e.preventDefault();
    const rangoId = document.getElementById('rango_id').value;
    const productoId = document.getElementById('rango_producto_id').value;
    const action = rangoId ? 'update_rango' : 'add_rango';
    
    const formData = new FormData();
    formData.append('action', action);
    formData.append('producto_id', productoId);
    
    if (rangoId) {
        formData.append('id', rangoId);
    }
    
    formData.append('cantidad_min', document.getElementById('cantidad_min').value);
    formData.append('cantidad_max', document.getElementById('cantidad_max').value);
    formData.append('precio', document.getElementById('precio').value);
    
    fetch('productos.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        mostrarAlerta(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            cancelarEdicionRango();
            cargarRangos(productoId);
        }
    })
    .catch(() => {
        mostrarAlerta('Error de conexión al guardar el rango', 'error');
    });
}

function editarRango(id, event) {
    if (event) event.stopPropagation();
    const formData = new FormData();
    formData.append('action', 'get_rango');
    formData.append('id', id);
    
    fetch('productos.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.rango) {
            const rango = data.rango;
            
            // Llenar formulario
            document.getElementById('rango_id').value = rango.id;
            document.getElementById('cantidad_min').value = rango.cantidad_min;
            document.getElementById('cantidad_max').value = rango.cantidad_max || '';
            document.getElementById('precio').value = rango.precio;
            
            // Cambiar UI del formulario
            document.getElementById('tituloFormRango').textContent = 'Editar Rango de Precio';
            document.getElementById('btnGuardarRango').innerHTML = 'Actualizar Rango';
            document.getElementById('btnCancelarRango').classList.remove('hidden');
            
            // Scroll al formulario
            document.getElementById('formRango').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            document.getElementById('cantidad_min').focus();
        } else {
            mostrarAlerta(data.message || 'No se pudo cargar el rango', 'error');
        }
    })
    .catch(() => {
        mostrarAlerta('Error de conexión al cargar el rango', 'error');
    });
}

function cancelarEdicionRango() {
    document.getElementById('formRango').reset();
    document.getElementById('rango_id').value = '';
    document.getElementById('tituloFormRango').textContent = 'Agregar Nuevo Rango';
    document.getElementById('btnGuardarRango').innerHTML = 'Agregar Rango';
    document.getElementById('btnCancelarRango').classList.add('hidden');
}

function eliminarRango(id, productoId, event) {
    if (event) event.stopPropagation();
    if (confirm('¿Eliminar este rango de precio?')) {
        const formData = new FormData();
        formData.append('action', 'delete_rango');
        formData.append('id', id);
        
        fetch('productos.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            mostrarAlerta(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                cargarRangos(productoId);
            }
        })
        .catch(() => {
            mostrarAlerta('Error de conexión al eliminar el rango', 'error');
        });
    }
}

// Cerrar modales al hacer clic fuera
document.getElementById('modalProducto').addEventListener('click', cerrarModalProducto);
document.getElementById('modalRangos').addEventListener('click', cerrarModalRangos);

document.addEventListener('DOMContentLoaded', () => {
    const filtro = document.getElementById('filtro-productos');
    if (filtro) {
        filtro.addEventListener('input', aplicarFiltrosProductos);
    }
});
</script>

<!-- Reordenar productos: arrastrar y soltar (drag & drop) -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function () {
    const tbody = document.getElementById('productos-table-body');
    if (!tbody || typeof Sortable === 'undefined') return;

    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'bg-slate-100',
        onEnd: function () {
            const ids = Array.from(tbody.querySelectorAll('tr.producto-row'))
                             .map(tr => tr.dataset.id)
                             .filter(Boolean);
            const fd = new FormData();
            fd.append('action', 'reordenar');
            fd.append('ids', JSON.stringify(ids));
            fetch('productos.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (typeof mostrarAlerta === 'function') {
                        mostrarAlerta(d.success ? 'Orden guardado' : (d.message || 'Error al guardar el orden'), d.success ? 'success' : 'error');
                    }
                })
                .catch(() => {
                    if (typeof mostrarAlerta === 'function') mostrarAlerta('Error de conexión al guardar el orden', 'error');
                });
        }
    });
})();
</script>

</body>
</html>
