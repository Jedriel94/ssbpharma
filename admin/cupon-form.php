<?php
require_once '../includes/auth_admin.php';
require_once '../models/Configuracion.php';
require_once '../models/Cupon.php';
require_once '../models/Producto.php';
require_once '../models/Kit.php';

$cuponModel = new Cupon();
$productoModel = new Producto();
$kitModel = new Kit();

function getUsuariosRepresentantesCuponForm() {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.id as admin_id,
            a.nombre,
            rp.codigo,
            rp.email
        FROM administradores a
        INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
        INNER JOIN representante_perfiles rp ON rp.admin_id = a.id
        WHERE a.activo = 1 AND rp.activo = 1
        ORDER BY a.nombre ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Determinar si es edición o creación
$isEdit = isset($_GET['id']);
$cuponId = $isEdit ? intval($_GET['id']) : 0;
$cupon = $isEdit ? $cuponModel->getById($cuponId) : null;

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datos = [
        'codigo' => strtoupper(trim($_POST['codigo'] ?? '')),
        'descripcion' => trim($_POST['descripcion'] ?? ''),
        'tipo_descuento' => $_POST['tipo_descuento'] ?? 'porcentaje',
        'valor_descuento' => floatval($_POST['valor_descuento'] ?? 0),
        'tipo_aplicacion' => $_POST['tipo_aplicacion'] ?? 'general',
        'aplicacion_ids' => '',
        'aplicacion_admin_ids' => '',
        'aplicacion_tags' => '',
        'minimo_compra' => floatval($_POST['minimo_compra'] ?? 0),
        'fecha_inicio' => $_POST['fecha_inicio'] ?? date('Y-m-d H:i:s'),
        'fecha_expiracion' => $_POST['fecha_expiracion'] ?? date('Y-m-d H:i:s', strtotime('+1 year')),
        'usos_maximos' => !empty($_POST['usos_maximos']) ? intval($_POST['usos_maximos']) : null,
        'activo' => isset($_POST['activo']) ? 1 : 0
    ];
    
    // Procesar IDs seleccionados según el tipo de aplicación
    switch ($datos['tipo_aplicacion']) {
        case 'productos':
            $datos['aplicacion_ids'] = isset($_POST['productos']) ? implode(',', $_POST['productos']) : '';
            break;
        case 'kits':
            $datos['aplicacion_ids'] = isset($_POST['kits']) ? implode(',', $_POST['kits']) : '';
            break;
        case 'representantes':
            $representantesSeleccionados = $_POST['representantes'] ?? [];
            $datos['aplicacion_admin_ids'] = $representantesSeleccionados ? implode(',', $representantesSeleccionados) : '';
            $datos['aplicacion_ids'] = '';
            break;
        case 'tags':
            $datos['aplicacion_tags'] = isset($_POST['tags']) ? implode(',', $_POST['tags']) : '';
            break;
    }
    
    if ($isEdit) {
        if ($cuponModel->update($cuponId, $datos)) {
            header('Location: cupones.php?success=updated');
            exit;
        } else {
            $error = 'Error al actualizar el cupón';
        }
    } else {
        if ($cuponModel->create($datos)) {
            header('Location: cupones.php?success=created');
            exit;
        } else {
            $error = 'Error al crear el cupón';
        }
    }
}

// Obtener datos para los listados
$productos = $productoModel->getAllActivos();
$kits = $kitModel->obtenerTodosLosKits();
$representantes = getUsuariosRepresentantesCuponForm();
$tags = $productoModel->getAllTags();

// IDs seleccionados (en caso de edición)
$selectedProductos = [];
$selectedKits = [];
$selectedRepresentantes = [];
$selectedTags = [];

if ($isEdit && $cupon) {
    switch ($cupon['tipo_aplicacion']) {
        case 'productos':
            $selectedProductos = $cupon['aplicacion_ids'] ? explode(',', $cupon['aplicacion_ids']) : [];
            break;
        case 'kits':
            $selectedKits = $cupon['aplicacion_ids'] ? explode(',', $cupon['aplicacion_ids']) : [];
            break;
        case 'representantes':
            $selectedRepresentantes = !empty($cupon['aplicacion_admin_ids'])
                ? explode(',', $cupon['aplicacion_admin_ids'])
                : [];
            break;
        case 'tags':
            $selectedTags = $cupon['aplicacion_tags'] ? explode(',', $cupon['aplicacion_tags']) : [];
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? '✏️ Editar' : '➕ Crear' ?> Cupón - <?= htmlspecialchars(Configuracion::get('nombre_tienda', 'Admin')) ?> Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .checkbox-item {
            transition: all 0.2s;
        }
        .checkbox-item:hover {
            background-color: #f1f5f9;
            transform: translateX(4px);
        }
        .checkbox-item input:checked ~ .checkbox-label {
            color: #1e40af;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100">
    
    <?php include '../includes/header.php'; ?>
    
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center gap-4 mb-4">
                <a href="cupones.php" class="text-slate-600 hover:text-slate-800">
                    <i class="fas fa-arrow-left text-2xl"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-slate-800">
                        <?= $isEdit ? '✏️ Editar Cupón' : '➕ Crear Nuevo Cupón' ?>
                    </h1>
                    <p class="text-slate-600">Completa los datos del cupón de descuento</p>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Formulario -->
        <form method="POST" class="space-y-6">
            <!-- Información Básica -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    Información Básica
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Código del Cupón *
                        </label>
                        <input type="text" name="codigo" required
                               value="<?= $isEdit ? htmlspecialchars($cupon['codigo']) : '' ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent uppercase font-mono"
                               placeholder="Ej: VERANO2026">
                        <p class="text-xs text-slate-500 mt-1">Código único que los clientes usarán</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Descripción *
                        </label>
                        <input type="text" name="descripcion" required
                               value="<?= $isEdit ? htmlspecialchars($cupon['descripcion']) : '' ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Ej: Descuento de verano">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Tipo de Descuento *
                        </label>
                        <select name="tipo_descuento" required
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="porcentaje" <?= ($isEdit && $cupon['tipo_descuento'] === 'porcentaje') ? 'selected' : '' ?>>
                                📊 Porcentaje (%)
                            </option>
                            <option value="fijo" <?= ($isEdit && $cupon['tipo_descuento'] === 'fijo') ? 'selected' : '' ?>>
                                💵 Monto Fijo ($)
                            </option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Valor del Descuento *
                        </label>
                        <input type="number" name="valor_descuento" step="0.01" min="0" required
                               value="<?= $isEdit ? $cupon['valor_descuento'] : '' ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Ej: 15">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Monto Mínimo de Compra
                        </label>
                        <input type="number" name="minimo_compra" step="0.01" min="0"
                               value="<?= $isEdit ? $cupon['minimo_compra'] : '0' ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="0">
                        <p class="text-xs text-slate-500 mt-1">0 = sin mínimo</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Usos Máximos
                        </label>
                        <input type="number" name="usos_maximos" min="0"
                               value="<?= $isEdit && $cupon['usos_maximos'] ? $cupon['usos_maximos'] : '' ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Dejar vacío para ilimitado">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Fecha de Inicio *
                        </label>
                        <input type="datetime-local" name="fecha_inicio" required
                               value="<?= $isEdit ? date('Y-m-d\TH:i', strtotime($cupon['fecha_inicio'])) : date('Y-m-d\TH:i') ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                            Fecha de Expiración *
                        </label>
                        <input type="datetime-local" name="fecha_expiracion" required
                               value="<?= $isEdit ? date('Y-m-d\TH:i', strtotime($cupon['fecha_expiracion'])) : date('Y-m-d\TH:i', strtotime('+1 year')) ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div class="mt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="activo" value="1"
                               <?= ($isEdit && $cupon['activo']) || !$isEdit ? 'checked' : '' ?>
                               class="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                        <span class="font-semibold text-slate-700">Cupón Activo</span>
                    </label>
                </div>
            </div>
            
            <!-- Aplicación del Cupón -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-filter text-purple-500"></i>
                    ¿A qué se aplica este cupón?
                </h2>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-3">
                        Selecciona el tipo de aplicación *
                    </label>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                        <label class="cursor-pointer">
                            <input type="radio" name="tipo_aplicacion" value="general" 
                                   <?= (!$isEdit || $cupon['tipo_aplicacion'] === 'general') ? 'checked' : '' ?>
                                   onchange="toggleAplicacion()"
                                   class="peer sr-only">
                            <div class="border-2 border-slate-300 rounded-lg p-4 text-center hover:border-blue-500 peer-checked:border-blue-600 peer-checked:bg-blue-50 transition">
                                <div class="text-3xl mb-2">🌐</div>
                                <div class="font-semibold text-sm">General</div>
                                <div class="text-xs text-slate-500">Todo el sitio</div>
                            </div>
                        </label>
                        
                        <label class="cursor-pointer">
                            <input type="radio" name="tipo_aplicacion" value="productos" 
                                   <?= ($isEdit && $cupon['tipo_aplicacion'] === 'productos') ? 'checked' : '' ?>
                                   onchange="toggleAplicacion()"
                                   class="peer sr-only">
                            <div class="border-2 border-slate-300 rounded-lg p-4 text-center hover:border-blue-500 peer-checked:border-blue-600 peer-checked:bg-blue-50 transition">
                                <div class="text-3xl mb-2">📦</div>
                                <div class="font-semibold text-sm">Productos</div>
                                <div class="text-xs text-slate-500">Específicos</div>
                            </div>
                        </label>
                        
                        <label class="cursor-pointer">
                            <input type="radio" name="tipo_aplicacion" value="tags" 
                                   <?= ($isEdit && $cupon['tipo_aplicacion'] === 'tags') ? 'checked' : '' ?>
                                   onchange="toggleAplicacion()"
                                   class="peer sr-only">
                            <div class="border-2 border-slate-300 rounded-lg p-4 text-center hover:border-blue-500 peer-checked:border-blue-600 peer-checked:bg-blue-50 transition">
                                <div class="text-3xl mb-2">🏷️</div>
                                <div class="font-semibold text-sm">Tags</div>
                                <div class="text-xs text-slate-500">Por categoría</div>
                            </div>
                        </label>
                        
                        <label class="cursor-pointer">
                            <input type="radio" name="tipo_aplicacion" value="kits" 
                                   <?= ($isEdit && $cupon['tipo_aplicacion'] === 'kits') ? 'checked' : '' ?>
                                   onchange="toggleAplicacion()"
                                   class="peer sr-only">
                            <div class="border-2 border-slate-300 rounded-lg p-4 text-center hover:border-blue-500 peer-checked:border-blue-600 peer-checked:bg-blue-50 transition">
                                <div class="text-3xl mb-2">🎁</div>
                                <div class="font-semibold text-sm">Kits</div>
                                <div class="text-xs text-slate-500">Solo kits</div>
                            </div>
                        </label>
                        
                        <label class="cursor-pointer">
                            <input type="radio" name="tipo_aplicacion" value="representantes" 
                                   <?= ($isEdit && $cupon['tipo_aplicacion'] === 'representantes') ? 'checked' : '' ?>
                                   onchange="toggleAplicacion()"
                                   class="peer sr-only">
                            <div class="border-2 border-slate-300 rounded-lg p-4 text-center hover:border-blue-500 peer-checked:border-blue-600 peer-checked:bg-blue-50 transition">
                                <div class="text-3xl mb-2">👤</div>
                                <div class="font-semibold text-sm">Representantes</div>
                                <div class="text-xs text-slate-500">Por vendedor</div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Listado de Productos -->
                <div id="productos-section" class="hidden">
                    <div class="border-t pt-6">
                        <h3 class="font-bold text-slate-700 mb-3 flex items-center gap-2">
                            <i class="fas fa-box text-blue-500"></i>
                            Selecciona los productos aplicables
                        </h3>
                        <div class="bg-slate-50 rounded-lg p-4 max-h-96 overflow-y-auto">
                            <div class="mb-3">
                                <input type="text" id="search-productos" 
                                       placeholder="🔍 Buscar productos..."
                                       onkeyup="filtrarItems('productos')"
                                       class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="space-y-2" id="lista-productos">
                                <?php foreach ($productos as $producto): ?>
                                <label class="checkbox-item flex items-center gap-3 p-3 bg-white rounded-lg border border-slate-200 cursor-pointer">
                                    <input type="checkbox" name="productos[]" value="<?= $producto['id'] ?>"
                                           <?= in_array($producto['id'], $selectedProductos) ? 'checked' : '' ?>
                                           class="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                                    <?php if (!empty($producto['imagen'])): ?>
                                        <img src="<?= htmlspecialchars($producto['imagen']) ?>" 
                                             class="w-12 h-12 object-cover rounded"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <div class="flex-1 checkbox-label">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($producto['producto']) ?></div>
                                        <div class="text-sm text-slate-500">Stock: <?= $producto['existencia'] ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Listado de Tags -->
                <div id="tags-section" class="hidden">
                    <div class="border-t pt-6">
                        <h3 class="font-bold text-slate-700 mb-3 flex items-center gap-2">
                            <i class="fas fa-tags text-purple-500"></i>
                            Selecciona los tags aplicables
                        </h3>
                        <div class="bg-slate-50 rounded-lg p-4">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                <?php foreach ($tags as $tag): ?>
                                <label class="checkbox-item flex items-center gap-2 p-3 bg-white rounded-lg border border-slate-200 cursor-pointer">
                                    <input type="checkbox" name="tags[]" value="<?= htmlspecialchars($tag) ?>"
                                           <?= in_array($tag, $selectedTags) ? 'checked' : '' ?>
                                           class="w-5 h-5 text-purple-600 rounded focus:ring-2 focus:ring-purple-500">
                                    <div class="checkbox-label">
                                        <span class="font-semibold text-slate-800">🏷️ <?= htmlspecialchars($tag) ?></span>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Listado de Kits -->
                <div id="kits-section" class="hidden">
                    <div class="border-t pt-6">
                        <h3 class="font-bold text-slate-700 mb-3 flex items-center gap-2">
                            <i class="fas fa-gift text-green-500"></i>
                            Selecciona los kits aplicables
                        </h3>
                        <div class="bg-slate-50 rounded-lg p-4 max-h-96 overflow-y-auto">
                            <div class="mb-3">
                                <input type="text" id="search-kits" 
                                       placeholder="🔍 Buscar kits..."
                                       onkeyup="filtrarItems('kits')"
                                       class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="space-y-2" id="lista-kits">
                                <?php foreach ($kits as $kit): ?>
                                <label class="checkbox-item flex items-center gap-3 p-3 bg-white rounded-lg border border-slate-200 cursor-pointer">
                                    <input type="checkbox" name="kits[]" value="<?= $kit['id'] ?>"
                                           <?= in_array($kit['id'], $selectedKits) ? 'checked' : '' ?>
                                           class="w-5 h-5 text-green-600 rounded focus:ring-2 focus:ring-green-500">
                                    <?php if (!empty($kit['imagen'])): ?>
                                        <img src="<?= htmlspecialchars($kit['imagen']) ?>" 
                                             class="w-12 h-12 object-cover rounded"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <div class="flex-1 checkbox-label">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($kit['nombre']) ?></div>
                                        <div class="text-sm text-slate-500"><?= htmlspecialchars($kit['descripcion'] ?? '') ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Listado de Representantes -->
                <div id="representantes-section" class="hidden">
                    <div class="border-t pt-6">
                        <h3 class="font-bold text-slate-700 mb-3 flex items-center gap-2">
                            <i class="fas fa-user-tie text-orange-500"></i>
                            Selecciona los representantes aplicables
                        </h3>
                        <div class="bg-slate-50 rounded-lg p-4 max-h-96 overflow-y-auto">
                            <div class="mb-3">
                                <input type="text" id="search-representantes" 
                                       placeholder="🔍 Buscar representantes..."
                                       onkeyup="filtrarItems('representantes')"
                                       class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="space-y-2" id="lista-representantes">
                                <?php foreach ($representantes as $rep): ?>
                                <label class="checkbox-item flex items-center gap-3 p-3 bg-white rounded-lg border border-slate-200 cursor-pointer">
                                    <input type="checkbox" name="representantes[]" value="<?= $rep['id'] ?>"
                                           <?= in_array($rep['id'], $selectedRepresentantes) ? 'checked' : '' ?>
                                           class="w-5 h-5 text-orange-600 rounded focus:ring-2 focus:ring-orange-500">
                                    <div class="flex-1 checkbox-label">
                                        <div class="font-semibold text-slate-800">👤 <?= htmlspecialchars($rep['nombre']) ?></div>
                                        <div class="text-sm text-slate-500"><?= htmlspecialchars($rep['codigo']) ?><?= !empty($rep['email']) ? ' · ' . htmlspecialchars($rep['email']) : '' ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Botones de Acción -->
            <div class="flex gap-4 justify-end">
                <a href="cupones.php" 
                   class="px-6 py-3 border-2 border-slate-300 text-slate-700 rounded-lg font-semibold hover:bg-slate-50 transition">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit"
                        class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg font-semibold shadow-lg hover:shadow-xl transition">
                    <i class="fas fa-save"></i> <?= $isEdit ? 'Actualizar' : 'Crear' ?> Cupón
                </button>
            </div>
        </form>
    </div>
    
    <script>
        // Mostrar/ocultar secciones según tipo de aplicación
        function toggleAplicacion() {
            const tipo = document.querySelector('input[name="tipo_aplicacion"]:checked').value;
            
            // Ocultar todas las secciones
            document.getElementById('productos-section').classList.add('hidden');
            document.getElementById('tags-section').classList.add('hidden');
            document.getElementById('kits-section').classList.add('hidden');
            document.getElementById('representantes-section').classList.add('hidden');
            
            // Mostrar la sección correspondiente
            if (tipo === 'productos') {
                document.getElementById('productos-section').classList.remove('hidden');
            } else if (tipo === 'tags') {
                document.getElementById('tags-section').classList.remove('hidden');
            } else if (tipo === 'kits') {
                document.getElementById('kits-section').classList.remove('hidden');
            } else if (tipo === 'representantes') {
                document.getElementById('representantes-section').classList.remove('hidden');
            }
        }
        
        // Filtrar items en los listados
        function filtrarItems(tipo) {
            const input = document.getElementById(`search-${tipo}`);
            const filter = input.value.toLowerCase();
            const lista = document.getElementById(`lista-${tipo}`);
            const items = lista.getElementsByTagName('label');
            
            for (let i = 0; i < items.length; i++) {
                const text = items[i].textContent || items[i].innerText;
                if (text.toLowerCase().indexOf(filter) > -1) {
                    items[i].style.display = '';
                } else {
                    items[i].style.display = 'none';
                }
            }
        }
        
        // Inicializar al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            toggleAplicacion();
        });
    </script>
</body>
</html>
