<?php
require_once '../includes/auth_admin.php';
require_once '../config/database.php';
require_once '../models/Kit.php';
require_once '../models/Producto.php';

$kitModel = new Kit();
$productoModel = new Producto();

// Obtener todos los kits para administración
$kits = $kitModel->obtenerTodosLosKits();

// Obtener productos activos para el selector
$productos = $productoModel->getAllActivos();

// Precios de referencia: precio para cantidad_min más baja (precio unitario más alto) por producto
$db = Database::getInstance()->getConnection();
$stmtRef = $db->query(
    "SELECT rp.producto_id, rp.precio AS precio_ref
     FROM rangos_precios rp
     INNER JOIN (
         SELECT producto_id, MIN(cantidad_min) AS min_cant
         FROM rangos_precios
         GROUP BY producto_id
     ) base ON rp.producto_id = base.producto_id AND rp.cantidad_min = base.min_cant"
);
$preciosRefRaw = $stmtRef->fetchAll(PDO::FETCH_ASSOC);
$preciosRef = [];
foreach ($preciosRefRaw as $r) {
    $preciosRef[(int)$r['producto_id']] = (float)$r['precio_ref'];
}
?>
<?php include '../includes/header.php'; ?>

<!-- Título y botón crear -->
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Gestión de Kits</h1>
            <p class="text-slate-600 mt-1">Crea kits que agrupan varios productos</p>
        </div>
        <button onclick="mostrarModalCrear()" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold shadow-lg flex items-center gap-2">
            <span class="text-xl"></span>
            <span>Crear Kit</span>
        </button>
    </div>

    <!-- Información sobre el sistema de kits -->
    <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4 mb-6">
        <div class="flex items-start gap-3">
            <span class="text-2xl">ℹ</span>
            <div>
                <h3 class="font-bold text-blue-900 mb-1">¿Cómo funcionan los kits?</h3>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>• Un kit agrupa varios productos y se vende como unidad</li>
                    <li>• Cuando se vende un kit, se descuentan los productos individuales del inventario</li>
                    <li>• El sistema lleva control estadístico de cuántos kits se han vendido</li>
                    <li>• Un kit solo está disponible si HAY STOCK de TODOS sus productos</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Tabla de Kits -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
                    <tr>
                        <th class="px-6 py-4 text-left">ID</th>
                        <th class="px-6 py-4 text-left">Kit</th>
                        <th class="px-6 py-4 text-left">Productos</th>
                        <th class="px-6 py-4 text-left">Stock</th>
                        <th class="px-6 py-4 text-left">Precio</th>
                        <th class="px-6 py-4 text-left">Vendidos</th>
                        <th class="px-6 py-4 text-center">Estado</th>
                        <th class="px-6 py-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200" id="kits-table-body">
                    <?php if (empty($kits)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center gap-3 text-slate-400">
                                    <span class="text-6xl"></span>
                                    <p class="text-lg font-semibold">No hay kits creados</p>
                                    <p class="text-sm">Crea tu primer kit para comenzar</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($kits as $kit): ?>
                            <tr class="kit-row hover:bg-slate-50 transition" data-id="<?= $kit['id'] ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="kit-drag-handle cursor-move text-slate-300 hover:text-slate-500 flex-shrink-0" title="Arrastra para reordenar">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.6"/><circle cx="15" cy="5" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="19" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>
                                        </span>
                                        <span class="font-mono text-sm text-slate-600">#<?= $kit['id'] ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php if ($kit['imagen']): ?>
                                            <img src="<?= uploads_url('kits/' . $kit['imagen']) ?>" 
                                                 alt="<?= htmlspecialchars($kit['nombre']) ?>" 
                                                 class="w-12 h-12 rounded-lg object-cover">
                                        <?php else: ?>
                                            <div class="w-12 h-12 rounded-lg bg-slate-200 flex items-center justify-center">
                                                <span class="text-2xl"></span>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <p class="font-semibold text-slate-900"><?= htmlspecialchars($kit['nombre']) ?></p>
                                            <?php if ($kit['descripcion']): ?>
                                                <p class="text-xs text-slate-500 line-clamp-1"><?= htmlspecialchars($kit['descripcion']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-sm font-semibold">
                                        <span></span>
                                        <span><?= $kit['total_productos'] ?> productos</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php 
                                    $stock = $kit['stock_disponible'] ?? 0;
                                    $colorStock = $stock > 10 ? 'green' : ($stock > 0 ? 'yellow' : 'red');
                                    ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-<?= $colorStock ?>-100 text-<?= $colorStock ?>-700 rounded-full text-sm font-semibold">
                                        <?= $stock ?> disponibles
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-lg font-bold text-slate-900">$<?= number_format($kit['precio_kit'], 2) ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="text-2xl"></span>
                                        <span class="font-semibold text-slate-700"><?= $kit['total_vendidos'] ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($kit['activo']): ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-semibold">
                                            Activo
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm font-semibold">
                                            Inactivo
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($kit['en_carrusel'])): ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-sm font-semibold mt-1">Carrusel</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <button onclick="verDetalleKit(<?= $kit['id'] ?>)" 
                                                class="p-2 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-lg transition"
                                                title="Ver detalle">
                                            👁️
                                        </button>
                                        <button onclick="editarKit(<?= $kit['id'] ?>)" 
                                                class="p-2 bg-yellow-100 hover:bg-yellow-200 text-yellow-700 rounded-lg transition"
                                                title="Editar">
                                            ✏️
                                        </button>
                                        <button onclick="toggleEstadoKit(<?= $kit['id'] ?>, <?= $kit['activo'] ? 'false' : 'true' ?>)" 
                                                class="p-2 bg-<?= $kit['activo'] ? 'red' : 'green' ?>-100 hover:bg-<?= $kit['activo'] ? 'red' : 'green' ?>-200 text-<?= $kit['activo'] ? 'red' : 'green' ?>-700 rounded-lg transition"
                                                title="<?= $kit['activo'] ? 'Desactivar' : 'Activar' ?>">
                                            <?= $kit['activo'] ? '🚫' : '✅' ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Crear/Editar Kit -->
<div id="modalKit" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 px-6 py-4 flex justify-between items-center" style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
            <h2 id="modalTitulo" class="text-2xl font-bold">Crear Kit</h2>
            <button onclick="cerrarModal()" class="text-white hover:bg-white/20 rounded-lg p-2 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <form id="formKit" class="p-6 space-y-6">
            <input type="hidden" id="kit_id" name="kit_id">
            
            <!-- Nombre del Kit -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Nombre del Kit *</label>
                <input type="text" id="nombre" name="nombre" required
                       class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 transition"
                       placeholder="Ej: BotiKit Completo">
            </div>

            <!-- Descripción -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Descripción</label>
                <textarea id="descripcion" name="descripcion" rows="3"
                          class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 transition"
                          placeholder="Descripción del kit..."></textarea>
            </div>

            <!-- Imagen del Kit -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Imagen del Kit</label>
                <div class="space-y-3">
                    <!-- Preview de imagen actual/nueva -->
                    <div id="imagenPreview" class="hidden">
                        <div class="relative inline-block">
                            <img id="imagenPreviewImg" src="" alt="Preview" class="w-32 h-32 object-cover rounded-xl border-2 border-slate-200">
                            <button type="button" onclick="eliminarImagenPreview()" 
                                    class="absolute -top-2 -right-2 bg-red-500 hover:bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center text-sm font-bold transition">
                                ×
                            </button>
                        </div>
                    </div>
                    
                    <!-- Input de archivo -->
                    <div>
                        <input type="file" id="imagen" name="imagen" accept="image/*"
                               onchange="previsualizarImagen(this)"
                               class="block w-full text-sm text-slate-500
                                      file:mr-4 file:py-2 file:px-4
                                      file:rounded-lg file:border-0
                                      file:text-sm file:font-semibold
                                      file:bg-blue-50 file:text-blue-700
                                      hover:file:bg-blue-100
                                      cursor-pointer">
                        <p class="text-xs text-slate-500 mt-1">JPG, PNG o GIF. Máximo 2MB</p>
                    </div>
                    
                    <!-- Campo oculto para guardar nombre de imagen existente -->
                    <input type="hidden" id="imagen_actual" name="imagen_actual">
                </div>
            </div>

            <!-- Precio del Kit -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Precio del Kit * ($)</label>
                <input type="number" id="precio_kit" name="precio_kit" step="0.01" min="0" required
                       onchange="actualizarSumaPrecios()" oninput="actualizarSumaPrecios()"
                       class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 transition"
                       placeholder="299.00">
                <p class="text-xs text-slate-500 mt-1">Precio especial del kit (puede ser menor que la suma de productos individuales)</p>
            </div>

            <!-- Orden de visualización -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Orden</label>
                <input type="number" id="orden" name="orden" min="0" value="0"
                       class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 transition"
                       placeholder="0">
                <p class="text-xs text-slate-500 mt-1">Orden de aparición (menor número = aparece primero)</p>
            </div>

            <!-- Estado -->
            <div class="flex items-center gap-3">
                <input type="checkbox" id="activo" name="activo" checked
                       class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                <label for="activo" class="text-sm font-semibold text-slate-700">Kit activo</label>
            </div>

            <!-- Carrusel -->
            <div class="flex items-center gap-3">
                <input type="checkbox" id="en_carrusel" name="en_carrusel"
                       class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                <label for="en_carrusel" class="text-sm font-semibold text-slate-700">Mostrar en carrusel</label>
            </div>

            <!-- Productos del Kit -->
            <div class="border-t-2 border-slate-200 pt-6">
                <div class="flex justify-between items-center mb-3">
                    <label class="block text-sm font-semibold text-slate-700">Productos del Kit *</label>
                    <div class="flex gap-2">
                        <button type="button" onclick="distribuirPrecios()" class="bg-amber-100 hover:bg-amber-200 text-amber-800 px-3 py-2 rounded-lg text-xs font-semibold transition">
                            Distribuir precios
                        </button>
                        <button type="button" onclick="agregarProducto()" class="btn-secondary text-white px-4 py-2 rounded-lg text-sm font-semibold">
                            Agregar Producto
                        </button>
                    </div>
                </div>
                <!-- Indicador suma vs precio_kit -->
                <div id="sumaIndicador" class="mb-3 text-xs font-semibold hidden"></div>
                <div id="productosContainer" class="space-y-3">
                    <!-- Se llenarán dinámicamente -->
                </div>
            </div>

            <!-- Botones -->
            <div class="flex gap-3 pt-4">
                <button type="submit" class="flex-1 btn-primary text-white px-6 py-3 rounded-xl font-semibold">
                    Guardar Kit
                </button>
                <button type="button" onclick="cerrarModal()" class="px-6 py-3 bg-slate-200 text-slate-700 rounded-xl font-semibold hover:bg-slate-300 transition">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detalle Kit -->
<div id="modalDetalle" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 px-6 py-4 flex justify-between items-center" style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
            <h2 class="text-2xl font-bold">Detalle del Kit</h2>
            <button onclick="cerrarModalDetalle()" class="text-white hover:bg-white/20 rounded-lg p-2 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <div id="detalleContent" class="p-6">
            <!-- Se llenará dinámicamente -->
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= url("") ?>';
const productosDisponibles = <?= json_encode($productos) ?>;
const preciosReferencia = <?= json_encode($preciosRef) ?>; // { producto_id: precio_max }
let contadorProductos = 0;

// Debug: Verificar BASE_URL
console.log('BASE_URL configurado:', BASE_URL);

// Mostrar modal crear
function mostrarModalCrear() {
    document.getElementById('modalTitulo').textContent = 'Crear Kit';
    document.getElementById('formKit').reset();
    document.getElementById('kit_id').value = '';
    document.getElementById('productosContainer').innerHTML = '';
    contadorProductos = 0;
    
    // Limpiar preview de imagen
    eliminarImagenPreview();
    
    document.getElementById('modalKit').classList.remove('hidden');
    document.getElementById('modalKit').classList.add('flex');
    
    // Agregar un producto por defecto
    agregarProducto();
}

// Cerrar modal
function cerrarModal() {
    document.getElementById('modalKit').classList.add('hidden');
    document.getElementById('modalKit').classList.remove('flex');
}

function cerrarModalDetalle() {
    document.getElementById('modalDetalle').classList.add('hidden');
    document.getElementById('modalDetalle').classList.remove('flex');
}

// Agregar producto al kit
function agregarProducto(productoId = '', cantidad = 1, precioUnitario = '') {
    const container = document.getElementById('productosContainer');
    const id = ++contadorProductos;
    
    const refPrecio = productoId ? (preciosReferencia[parseInt(productoId)] ?? null) : null;
    const refText   = refPrecio !== null ? `$${parseFloat(refPrecio).toFixed(2)}` : '—';

    const div = document.createElement('div');
    div.id = `producto_${id}`;
    div.className = 'grid grid-cols-12 gap-2 items-start bg-slate-50 p-4 rounded-xl';
    div.innerHTML = `
        <div class="col-span-4">
            <label class="text-xs text-slate-500 mb-1 block">Producto</label>
            <select name="productos[${id}][producto_id]" required
                    onchange="onProductoChange(this, ${id})"
                    class="w-full px-3 py-2 border-2 border-slate-200 rounded-lg focus:outline-none focus:border-blue-500 transition text-sm">
                <option value="">Seleccionar...</option>
                ${productosDisponibles.map(p => `<option value="${p.id}" ${parseInt(p.id) === parseInt(productoId) ? 'selected' : ''}>${p.producto} (Stock: ${p.existencia})</option>`).join('')}
            </select>
        </div>
        <div class="col-span-2">
            <label class="text-xs text-slate-500 mb-1 block">Cantidad</label>
            <input type="number" name="productos[${id}][cantidad]" min="1" value="${cantidad}" required
                   onchange="actualizarSumaPrecios()"
                   class="w-full px-3 py-2 border-2 border-slate-200 rounded-lg focus:outline-none focus:border-blue-500 transition text-sm"
                   placeholder="1">
        </div>
        <div class="col-span-2">
            <label class="text-xs text-slate-500 mb-1 block text-amber-600">Precio ref.</label>
            <div id="ref_${id}" class="w-full px-3 py-2 bg-amber-50 border-2 border-amber-200 rounded-lg text-sm font-semibold text-amber-700 text-center">${refText}</div>
        </div>
        <div class="col-span-3">
            <label class="text-xs text-slate-500 mb-1 block">Precio en kit ($)</label>
            <input type="number" name="productos[${id}][precio_unitario]" min="0" step="0.01" value="${precioUnitario}" required
                   onchange="actualizarSumaPrecios()"
                   class="w-full px-3 py-2 border-2 border-slate-200 rounded-lg focus:outline-none focus:border-blue-500 transition text-sm"
                   placeholder="0.00">
        </div>
        <div class="col-span-1 pt-5">
            <button type="button" onclick="eliminarProducto(${id})" 
                    class="p-2 bg-red-100 hover:bg-red-200 text-red-700 rounded-lg transition">
                
            </button>
        </div>
    `;
    
    container.appendChild(div);
    actualizarSumaPrecios();
}

// Actualizar precio de referencia al cambiar el select de producto
function onProductoChange(selectEl, id) {
    const productoId = parseInt(selectEl.value);
    const ref = preciosReferencia[productoId] ?? null;
    const refDiv = document.getElementById(`ref_${id}`);
    if (refDiv) refDiv.textContent = ref !== null ? `$${parseFloat(ref).toFixed(2)}` : '—';
    actualizarSumaPrecios();
}

// Eliminar producto del kit
function eliminarProducto(id) {
    const elemento = document.getElementById(`producto_${id}`);
    if (elemento) {
        elemento.remove();
        actualizarSumaPrecios();
    }
}

// Calcular y mostrar suma de (cantidad * precio_unitario) vs precio_kit
function actualizarSumaPrecios() {
    const indicador = document.getElementById('sumaIndicador');
    if (!indicador) return;
    const precioKit = parseFloat(document.getElementById('precio_kit')?.value) || 0;
    let suma = 0;
    document.querySelectorAll('#productosContainer > div').forEach(row => {
        const cant  = parseFloat(row.querySelector('input[name*="[cantidad]"]')?.value) || 0;
        const prec  = parseFloat(row.querySelector('input[name*="[precio_unitario]"]')?.value) || 0;
        suma += cant * prec;
    });
    suma = Math.round(suma * 100) / 100;
    const diff = Math.round((precioKit - suma) * 100) / 100;
    if (precioKit === 0) { indicador.classList.add('hidden'); return; }
    indicador.classList.remove('hidden');
    const ok = Math.abs(diff) < 0.02;
    indicador.className = `mb-3 text-xs font-semibold px-3 py-2 rounded-lg ${ok ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`;
    indicador.textContent = ok
        ? `Los precios suman $${suma.toFixed(2)} — coincide con el precio del kit`
        : `Los precios suman $${suma.toFixed(2)} de $${precioKit.toFixed(2)} (diferencia: ${diff > 0 ? '+' : ''}${diff.toFixed(2)})`;
}

// Distribuir precio_kit equitativamente entre los componentes (por unidades)
function distribuirPrecios() {
    const precioKit = parseFloat(document.getElementById('precio_kit')?.value) || 0;
    if (precioKit <= 0) { alert('Ingresa primero el precio del kit'); return; }
    const rows = [...document.querySelectorAll('#productosContainer > div')];

    // Peso de cada fila = cantidad × precio_referencia (importe proporcional)
    // Si no hay referencia, usa el precio_unitario ingresado manualmente
    const datos = rows.map(row => {
        const productoId = parseInt(row.querySelector('select[name*="[producto_id]"]')?.value) || 0;
        const cantidad   = parseFloat(row.querySelector('input[name*="[cantidad]"]')?.value) || 1;
        const ref        = productoId ? (preciosReferencia[productoId] ?? null) : null;
        const precioBase = (ref !== null && ref > 0)
            ? ref
            : (parseFloat(row.querySelector('input[name*="[precio_unitario]"]')?.value) || 0);
        return { cantidad, peso: precioBase * cantidad }; // importe = cant × PU
    });

    const sumaPesos = datos.reduce((a, d) => a + d.peso, 0);

    if (sumaPesos <= 0) {
        // Fallback: equitativo por número de filas
        const igual = precioKit / rows.length;
        rows.forEach(row => {
            const inp = row.querySelector('input[name*="[precio_unitario]"]');
            if (inp) inp.value = igual.toFixed(2);
        });
        actualizarSumaPrecios();
        return;
    }

    // Distribuir proporcionalmente y dividir entre cantidad para obtener precio por unidad
    let asignado = 0;
    rows.forEach((row, i) => {
        const inputPrecio = row.querySelector('input[name*="[precio_unitario]"]');
        if (!inputPrecio) return;
        const { cantidad, peso } = datos[i];
        let totalFila;
        if (i === rows.length - 1) {
            totalFila = Math.max(0, precioKit - asignado);
        } else {
            totalFila = Math.round((peso / sumaPesos) * precioKit * 100) / 100;
            asignado += totalFila;
        }
        // precio_unitario = importe asignado a esta fila / cantidad
        inputPrecio.value = (totalFila / cantidad).toFixed(2);
    });
    actualizarSumaPrecios();
}

// Previsualizar imagen
function previsualizarImagen(input) {
    const preview = document.getElementById('imagenPreview');
    const previewImg = document.getElementById('imagenPreviewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function eliminarImagenPreview() {
    const preview = document.getElementById('imagenPreview');
    const previewImg = document.getElementById('imagenPreviewImg');
    const inputImagen = document.getElementById('imagen');
    const imagenActual = document.getElementById('imagen_actual');
    
    previewImg.src = '';
    preview.classList.add('hidden');
    inputImagen.value = '';
    imagenActual.value = '';
}

// Subir imagen al servidor
async function subirImagenKit(file) {
    const formData = new FormData();
    formData.append('imagen', file);
    formData.append('action', 'upload_imagen');
    
    const response = await fetch(BASE_URL + 'api/kits.php', {
        method: 'POST',
        body: formData
    });
    
    if (!response.ok) {
        throw new Error(`Error HTTP: ${response.status}`);
    }
    
    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.mensaje || 'Error al subir imagen');
    }
    
    return result.nombre_archivo;
}

// Guardar kit
document.getElementById('formKit').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const kit_id = formData.get('kit_id');
    
    // Manejar imagen
    let nombreImagen = formData.get('imagen_actual') || null;
    const archivoImagen = formData.get('imagen');
    
    // Si hay un archivo nuevo, subirlo primero
    if (archivoImagen && archivoImagen.size > 0) {
        try {
            nombreImagen = await subirImagenKit(archivoImagen);
        } catch (error) {
            mostrarAlerta('Error al subir imagen: ' + error.message, 'error');
            return;
        }
    }
    
    // Construir datos del kit
    const datos = {
        nombre: formData.get('nombre'),
        descripcion: formData.get('descripcion'),
        precio_kit: parseFloat(formData.get('precio_kit')),
        orden: parseInt(formData.get('orden')) || 0,
        activo: formData.get('activo') ? 1 : 0,
        en_carrusel: formData.get('en_carrusel') ? 1 : 0,
        productos: []
    };
    
    // Recopilar productos correctamente
    const productosMap = new Map();
    for (let [key, value] of formData.entries()) {
        if (key.startsWith('productos[')) {
            const match = key.match(/productos\[(\d+)\]\[(producto_id|cantidad|precio_unitario)\]/);
            if (match) {
                const index = match[1];
                const field = match[2];
                if (!productosMap.has(index)) productosMap.set(index, {});
                productosMap.get(index)[field] = value;
            }
        }
    }
    
    // Convertir Map a array y validar
    for (let [index, producto] of productosMap) {
        if (producto.producto_id && producto.cantidad) {
            datos.productos.push({
                producto_id:    parseInt(producto.producto_id),
                cantidad:       parseInt(producto.cantidad),
                precio_unitario: parseFloat(producto.precio_unitario) || 0,
            });
        }
    }
    
    if (datos.productos.length === 0) {
        mostrarAlerta('Debes agregar al menos un producto al kit', 'error');
        return;
    }
    
    // Agregar imagen a los datos
    if (nombreImagen) {
        datos.imagen = nombreImagen;
    } else {
        // Asegurar que no se envíe string vacío
        datos.imagen = null;
    }
    
    // Si estamos editando, agregar kit_id al objeto datos
    if (kit_id) {
        datos.kit_id = parseInt(kit_id);
    }
    
    console.log('Enviando datos:', datos); // Debug
    
    try {
        const action = kit_id ? 'update' : 'create';
        const url = BASE_URL + 'api/kits.php';
        
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action, kit_id, ...datos})
        });
        
        // Verificar si la respuesta HTTP es exitosa
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Error HTTP:', response.status, errorText);
            throw new Error(`Error HTTP ${response.status}: ${errorText.substring(0, 100)}`);
        }
        
        const result = await response.json();
        console.log('Respuesta API:', result); // Debug
        
        if (result.success) {
            mostrarAlerta(result.mensaje || 'Kit guardado exitosamente', 'success');
            cerrarModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            mostrarAlerta(result.mensaje || 'Error al guardar kit', 'error');
        }
    } catch (error) {
        console.error('Error completo:', error);
        mostrarAlerta('Error al guardar kit: ' + error.message, 'error');
    }
});

// Ver detalle del kit
async function verDetalleKit(kit_id) {
    try {
        const response = await fetch(BASE_URL + `api/kits.php?action=detalle&kit_id=${kit_id}`);
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            const kit = result.kit;
            const productos = result.productos;
            
            let html = `
                <div class="space-y-4">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900">${kit.nombre}</h3>
                        ${kit.descripcion ? `<p class="text-slate-600 mt-1">${kit.descripcion}</p>` : ''}
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50 p-4 rounded-xl">
                            <p class="text-sm text-slate-600">Precio del Kit</p>
                            <p class="text-2xl font-bold text-blue-700">$${parseFloat(kit.precio_kit).toFixed(2)}</p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-xl">
                            <p class="text-sm text-slate-600">Stock Disponible</p>
                            <p class="text-2xl font-bold text-green-700">${kit.stock_disponible || 0} kits</p>
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="font-bold text-slate-900 mb-3">Productos Incluidos:</h4>
                        <div class="space-y-2">
            `;
            
            productos.forEach(p => {
                html += `
                    <div class="flex items-center justify-between bg-slate-50 p-3 rounded-lg">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl"></span>
                            <div>
                                <p class="font-semibold text-slate-900">${p.nombre}</p>
                                <p class="text-xs text-slate-500">Stock: ${p.existencia} unidades</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm font-semibold">
                                x${p.cantidad_en_kit}
                            </span>
                            ${parseFloat(p.precio_unitario) > 0
                                ? `<p class="text-xs text-green-700 font-semibold mt-1">$${parseFloat(p.precio_unitario).toFixed(2)} en kit</p>`
                                : '<p class="text-xs text-amber-600 mt-1">Sin precio asignado</p>'}
                        </div>
                    </div>
                `;
            });
            
            html += `
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('detalleContent').innerHTML = html;
            document.getElementById('modalDetalle').classList.remove('hidden');
            document.getElementById('modalDetalle').classList.add('flex');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al cargar detalle', 'error');
    }
}

// Toggle estado kit
async function toggleEstadoKit(kit_id, nuevoEstado) {
    if (!confirm('¿Cambiar el estado de este kit?')) return;
    
    try {
        const response = await fetch(BASE_URL + 'api/kits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'toggle_estado',
                kit_id,
                activo: nuevoEstado
            })
        });
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            mostrarAlerta('Estado actualizado', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            mostrarAlerta(result.mensaje || 'Error al actualizar', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al actualizar estado', 'error');
    }
}

// Editar kit
async function editarKit(kit_id) {
    try {
        const response = await fetch(BASE_URL + `api/kits.php?action=detalle&kit_id=${kit_id}`);
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        
        const result = await response.json();
        
        console.log('Respuesta API detalle kit:', result); // Debug
        console.log('Kit imagen:', result.kit?.imagen); // Debug
        
        if (result.success) {
            const kit = result.kit;
            const productos = result.productos;
            
            // Cambiar título del modal
            document.getElementById('modalTitulo').textContent = 'Editar Kit';
            
            // Rellenar formulario
            document.getElementById('kit_id').value = kit_id;
            document.getElementById('nombre').value = kit.nombre;
            document.getElementById('descripcion').value = kit.descripcion || '';
            document.getElementById('precio_kit').value = kit.precio_kit;
            document.getElementById('orden').value = kit.orden || 0;
            document.getElementById('activo').checked = kit.activo == 1;
            document.getElementById('en_carrusel').checked = kit.en_carrusel == 1;
            
            // Cargar imagen existente
            const imagenActual = document.getElementById('imagen_actual');
            const imagenPreview = document.getElementById('imagenPreview');
            const imagenPreviewImg = document.getElementById('imagenPreviewImg');
            const inputImagen = document.getElementById('imagen');
            
            if (kit.imagen) {
                imagenActual.value = kit.imagen;
                
                // Construir URL correctamente - asegurar barras correctas
                const baseUrl = BASE_URL.endsWith('/') ? BASE_URL : BASE_URL + '/';
                const imagenUrl = '<?= uploads_url('kits') ?>/' + kit.imagen;
                
                console.log('Cargando imagen desde:', imagenUrl); // Debug
                console.log('BASE_URL original:', BASE_URL); // Debug
                console.log('Nombre archivo:', kit.imagen); // Debug
                
                // Resetear cualquier estilo previo y asegurar visibilidad
                imagenPreviewImg.style.display = '';
                imagenPreviewImg.src = imagenUrl;
                
                // Forzar que el contenedor sea visible
                imagenPreview.classList.remove('hidden');
                imagenPreview.style.display = 'block';
                
                console.log('Clases de imagenPreview:', imagenPreview.className);
                console.log('Display de imagenPreview:', imagenPreview.style.display);
                
                // Log de eventos de carga
                imagenPreviewImg.onload = function() {
                    console.log('Imagen cargada correctamente');
                };
                
                imagenPreviewImg.onerror = function() {
                    console.error('Error al cargar imagen:', imagenUrl);
                    console.error('Verifica la ruta y permisos del archivo');
                };
            } else {
                imagenActual.value = '';
                imagenPreview.classList.add('hidden');
                imagenPreview.style.display = '';
            }
            
            // Limpiar el input file
            inputImagen.value = '';
            
            // Cargar productos del kit
            const container = document.getElementById('productosContainer');
            container.innerHTML = '';
            contadorProductos = 0;
            
            productos.forEach(p => {
                agregarProducto(p.producto_id, parseInt(p.cantidad_en_kit), parseFloat(p.precio_unitario || 0).toFixed(2));
            });
            actualizarSumaPrecios();
            
            // Mostrar modal
            document.getElementById('modalKit').classList.remove('hidden');
            document.getElementById('modalKit').classList.add('flex');
        } else {
            mostrarAlerta(result.mensaje || 'Error al cargar kit', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al cargar kit: ' + error.message, 'error');
    }
}
</script>

<!-- Reordenar kits: arrastrar y soltar (drag & drop) -->
<script src="<?= asset('js/Sortable.min.js') ?>"></script>
<script>
(function () {
    const tbody = document.getElementById('kits-table-body');
    if (!tbody) return;
    if (typeof Sortable === 'undefined') { console.error('SortableJS no se cargó — el reordenar no funcionará.'); return; }

    Sortable.create(tbody, {
        handle: '.kit-drag-handle',
        animation: 150,
        ghostClass: 'bg-slate-100',
        delay: 120,
        delayOnTouchOnly: true,
        touchStartThreshold: 4,
        onEnd: function () {
            const ids = Array.from(tbody.querySelectorAll('tr.kit-row')).map(tr => tr.dataset.id).filter(Boolean);
            const fd = new FormData();
            fd.append('action', 'reordenar');
            fd.append('ids', JSON.stringify(ids));
            fetch(BASE_URL + 'api/kits.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (typeof mostrarAlerta === 'function') {
                        mostrarAlerta(d.success ? 'Orden guardado' : (d.mensaje || 'Error al guardar el orden'), d.success ? 'success' : 'error');
                    }
                })
                .catch(() => { if (typeof mostrarAlerta === 'function') mostrarAlerta('Error de conexión al guardar el orden', 'error'); });
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
