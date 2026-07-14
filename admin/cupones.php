<?php
// Procesar endpoints de API PRIMERO antes de cualquier output
if (isset($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Iniciar output buffering para capturar cualquier output no deseado
    ob_start();
    
    require_once '../includes/auth_admin.php';
    require_once '../models/Producto.php';
    require_once '../models/Kit.php';
    require_once '../models/Cupon.php';
    
    // Limpiar cualquier output capturado
    ob_end_clean();
    
    // Ahora enviar JSON limpio
    header('Content-Type: application/json');
    
    $productoModel = new Producto();
    $kitModel = new Kit();
    $cuponModel = new Cupon();
    
    switch ($_GET['action']) {
        case 'getById':
            $id = intval($_GET['id'] ?? 0);
            $cupon = $cuponModel->getById($id);
            echo json_encode(['success' => true, 'cupon' => $cupon]);
            exit;
            
        case 'getProductos':
            $productos = $productoModel->getAllActivos();
            echo json_encode(['success' => true, 'productos' => $productos]);
            exit;
            
        case 'getKits':
            $kits = $kitModel->obtenerTodosLosKits();
            echo json_encode(['success' => true, 'kits' => $kits]);
            exit;
            
        case 'getRepresentantes':
            $representantes = getUsuariosRepresentantes();
            echo json_encode(['success' => true, 'representantes' => $representantes]);
            exit;
            
        case 'getTags':
            $tags = $productoModel->getAllTags();
            echo json_encode(['success' => true, 'tags' => $tags]);
            exit;
            
        case 'getEstadisticas':
            $stats = $cuponModel->getEstadisticas();
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;
    }
}

// Ahora incluir para el resto de la página
require_once '../includes/auth_admin.php';
require_once '../models/Configuracion.php';
require_once '../models/Cupon.php';
require_once '../models/Producto.php';
require_once '../models/Kit.php';

$cuponModel = new Cupon();
$productoModel = new Producto();
$kitModel = new Kit();

function getUsuariosRepresentantes() {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.id as admin_id,
            a.nombre,
            rp.codigo,
            rp.email,
            rp.telefono
        FROM administradores a
        INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
        INNER JOIN representante_perfiles rp ON rp.admin_id = a.id
        WHERE a.activo = 1 AND rp.activo = 1
        ORDER BY a.nombre ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $datos = [
                'codigo' => strtoupper(trim($_POST['codigo'] ?? '')),
                'descripcion' => trim($_POST['descripcion'] ?? ''),
                'tipo_descuento' => $_POST['tipo_descuento'] ?? 'porcentaje',
                'valor_descuento' => floatval($_POST['valor_descuento'] ?? 0),
                'tipo_aplicacion' => $_POST['tipo_aplicacion'] ?? 'general',
                'aplicacion_ids' => trim($_POST['aplicacion_ids'] ?? ''),
                'aplicacion_admin_ids' => trim($_POST['aplicacion_admin_ids'] ?? ''),
                'aplicacion_tags' => trim($_POST['aplicacion_tags'] ?? ''),
                'minimo_compra' => floatval($_POST['minimo_compra'] ?? 0),
                'fecha_inicio' => $_POST['fecha_inicio'] ?? date('Y-m-d H:i:s'),
                'fecha_expiracion' => $_POST['fecha_expiracion'] ?? date('Y-m-d H:i:s', strtotime('+1 year')),
                'usos_maximos' => !empty($_POST['usos_maximos']) ? intval($_POST['usos_maximos']) : null,
                'activo' => isset($_POST['activo']) ? 1 : 0
            ];

            if ($datos['tipo_aplicacion'] === 'representantes') {
                $datos['aplicacion_admin_ids'] = $datos['aplicacion_admin_ids'] ?: $datos['aplicacion_ids'];
                $datos['aplicacion_ids'] = '';
            } else {
                $datos['aplicacion_admin_ids'] = '';
            }
            
            if ($cuponModel->create($datos)) {
                echo json_encode(['success' => true, 'message' => 'Cupón creado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al crear cupón']);
            }
            exit;
            
        case 'update':
            $id = intval($_POST['id'] ?? 0);
            $datos = [
                'codigo' => strtoupper(trim($_POST['codigo'] ?? '')),
                'descripcion' => trim($_POST['descripcion'] ?? ''),
                'tipo_descuento' => $_POST['tipo_descuento'] ?? 'porcentaje',
                'valor_descuento' => floatval($_POST['valor_descuento'] ?? 0),
                'tipo_aplicacion' => $_POST['tipo_aplicacion'] ?? 'general',
                'aplicacion_ids' => trim($_POST['aplicacion_ids'] ?? ''),
                'aplicacion_admin_ids' => trim($_POST['aplicacion_admin_ids'] ?? ''),
                'aplicacion_tags' => trim($_POST['aplicacion_tags'] ?? ''),
                'minimo_compra' => floatval($_POST['minimo_compra'] ?? 0),
                'fecha_inicio' => $_POST['fecha_inicio'] ?? date('Y-m-d H:i:s'),
                'fecha_expiracion' => $_POST['fecha_expiracion'] ?? date('Y-m-d H:i:s'),
                'usos_maximos' => !empty($_POST['usos_maximos']) ? intval($_POST['usos_maximos']) : null,
                'activo' => isset($_POST['activo']) ? 1 : 0
            ];

            if ($datos['tipo_aplicacion'] === 'representantes') {
                $datos['aplicacion_admin_ids'] = $datos['aplicacion_admin_ids'] ?: $datos['aplicacion_ids'];
                $datos['aplicacion_ids'] = '';
            } else {
                $datos['aplicacion_admin_ids'] = '';
            }
            
            if ($cuponModel->update($id, $datos)) {
                echo json_encode(['success' => true, 'message' => 'Cupón actualizado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar cupón']);
            }
            exit;
            
        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            if ($cuponModel->delete($id)) {
                echo json_encode(['success' => true, 'message' => 'Cupón eliminado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al eliminar cupón']);
            }
            exit;
            
        case 'getHistorial':
            $id = intval($_POST['id'] ?? 0);
            $historial = $cuponModel->getHistorialUso($id);
            echo json_encode(['success' => true, 'historial' => $historial]);
            exit;
    }
}

$cupones = $cuponModel->getAll();
$estadisticas = $cuponModel->getEstadisticas();
?>
<?php
$_pageTitle = '🎟️ Cupones';
?>
<?php include '../includes/header.php'; ?>

<style>
.cup-page { background: var(--bg-page); min-height: 100vh; }
.cup-card  { background: var(--bg-card); border: 1px solid var(--border-card); }
.cup-thead { background: linear-gradient(to right, var(--tw-neu-800), var(--tw-neu-900)); color: #fff; }
.cup-btn-primary {
    background: var(--accent); color: var(--accent-text);
    padding: 10px 20px; border-radius: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; border: none; cursor: pointer;
    transition: background .15s;
}
.cup-btn-primary:hover { background: var(--accent-hover); }
.cup-modal-bg  { background: var(--bg-card); }
.cup-modal-hdr { background: var(--accent); color: var(--accent-text); }
.cup-input {
    background: var(--bg-input); border: 1px solid var(--border-input);
    color: var(--text-primary);
    width: 100%; padding: 10px 14px; border-radius: 12px;
    font-family: var(--font-base); font-size: 14px;
}
.cup-input:focus { outline: 2px solid var(--border-focus); border-color: var(--border-focus); }
.cup-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; display: block; }
.cup-stat  { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 12px; padding: 16px; }
.cup-row:hover { background: var(--bg-card-hover); }
.cup-divider { border-top: 1px solid var(--border-card); }
.cup-close { background: var(--bg-card-hover); border: 1px solid var(--border-card); color: var(--text-secondary);
    padding: 10px; border-radius: 12px; cursor: pointer; width: 100%; font-weight: 500; transition: background .15s; }
.cup-close:hover { background: var(--bg-menu-item); color: var(--text-primary); }
.cup-selector { background: var(--bg-page); border: 1px solid var(--border-input); border-radius: 12px;
    padding: 10px 14px; max-height: 256px; overflow-y: auto; }
</style>

<div class="cup-page">
<div class="container mx-auto px-4 py-8">
        <!-- Mensajes de éxito -->
        <?php if (isset($_GET['success'])): ?>
            <div class="mb-6 p-4 rounded shadow-lg animate-pulse" style="background:rgba(22,163,74,.12);border-left:4px solid #16a34a;color:#15803d">
                <i class="fas fa-check-circle"></i>
                <?php if ($_GET['success'] === 'created'): ?>
                    Cupón creado exitosamente
                <?php elseif ($_GET['success'] === 'updated'): ?>
                    Cupón actualizado exitosamente
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Header con Estadísticas -->
        <div class="mb-8">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-3xl font-bold mb-2" style="color:var(--text-primary)">🎟️ Gestión de Cupones</h1>
                    <p style="color:var(--text-secondary)">Administra cupones de descuento y promociones</p>
                </div>
                <a href="cupon-form.php" 
                   class="cup-btn-primary">
                    <i class="fas fa-plus"></i> Nuevo Cupón
                </a>
            </div>
            
            <!-- Estadísticas -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="cup-stat">
                    <div class="text-sm mb-1" style="color:var(--text-secondary)">Total Cupones</div>
                    <div class="text-2xl font-bold" style="color:var(--text-primary)"><?= $estadisticas['total_cupones'] ?></div>
                </div>
                <div class="cup-stat">
                    <div class="text-sm mb-1" style="color:var(--text-secondary)">Activos</div>
                    <div class="text-2xl font-bold" style="color:#16a34a"><?= $estadisticas['activos'] ?></div>
                </div>
                <div class="cup-stat">
                    <div class="text-sm mb-1" style="color:var(--text-secondary)">Usos Totales</div>
                    <div class="text-2xl font-bold" style="color:var(--accent)"><?= number_format($estadisticas['total_usos']) ?></div>
                </div>
                <div class="cup-stat">
                    <div class="text-sm mb-1" style="color:var(--text-secondary)">Total Descontado</div>
                    <div class="text-2xl font-bold" style="color:#dc2626">$<?= number_format($estadisticas['total_descontado'], 2) ?></div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Cupones -->
        <div class="cup-card rounded-xl overflow-hidden" style="box-shadow:0 4px 14px rgba(15,23,42,.08)">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="cup-thead">
                        <tr>
                            <th class="px-4 py-3 text-left">Código</th>
                            <th class="px-4 py-3 text-left">Descripción</th>
                            <th class="px-4 py-3 text-left">Tipo</th>
                            <th class="px-4 py-3 text-left">Descuento</th>
                            <th class="px-4 py-3 text-left">Aplicación</th>
                            <th class="px-4 py-3 text-center">Mín. Compra</th>
                            <th class="px-4 py-3 text-center">Usos</th>
                            <th class="px-4 py-3 text-center">Vigencia</th>
                            <th class="px-4 py-3 text-center">Estado</th>
                            <th class="px-4 py-3 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cupones as $cupon): ?>
                        <tr class="cup-row transition" style="border-top:1px solid var(--border-card)">
                            <td class="px-4 py-3">
                                <span class="font-mono font-bold" style="color:var(--accent)"><?= htmlspecialchars($cupon['codigo']) ?></span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="max-w-xs truncate text-sm"><?= htmlspecialchars($cupon['descripcion']) ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-1 rounded-full <?= $cupon['tipo_descuento'] === 'porcentaje' ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800' ?>">
                                    <?= $cupon['tipo_descuento'] === 'porcentaje' ? '📊 %' : '💵 $' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-bold">
                                <?php if ($cupon['tipo_descuento'] === 'porcentaje'): ?>
                                    <?= $cupon['valor_descuento'] ?>%
                                <?php else: ?>
                                    $<?= number_format($cupon['valor_descuento'], 2) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php
                                $iconos = [
                                    'general' => '🌐',
                                    'productos' => '📦',
                                    'tags' => '🏷️',
                                    'kits' => '🎁',
                                    'representantes' => '👤'
                                ];
                                $nombres = [
                                    'general' => 'General',
                                    'productos' => 'Productos',
                                    'tags' => 'Tags',
                                    'kits' => 'Kits',
                                    'representantes' => 'Representantes'
                                ];
                                ?>
                                <span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-800">
                                    <?= $iconos[$cupon['tipo_aplicacion']] ?> <?= $nombres[$cupon['tipo_aplicacion']] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                $<?= number_format($cupon['minimo_compra'], 0) ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?= $cupon['usos_actuales'] ?><?= $cupon['usos_maximos'] ? ' / ' . $cupon['usos_maximos'] : ' / ∞' ?>
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                <?= date('d/m/Y', strtotime($cupon['fecha_inicio'])) ?><br>
                                <span class="text-slate-500">→</span><br>
                                <?= date('d/m/Y', strtotime($cupon['fecha_expiracion'])) ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php
                                $estados = [
                                    'Activo' => 'bg-green-100 text-green-800',
                                    'Inactivo' => 'bg-slate-100 text-slate-600',
                                    'Expirado' => 'bg-red-100 text-red-800',
                                    'Programado' => 'bg-yellow-100 text-yellow-800',
                                    'Agotado' => 'bg-orange-100 text-orange-800'
                                ];
                                ?>
                                <span class="text-xs px-2 py-1 rounded-full <?= $estados[$cupon['estado']] ?>">
                                    <?= $cupon['estado'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex gap-2 justify-center">
                                    <button onclick="verHistorial(<?= $cupon['id'] ?>)" 
                                            class="text-blue-600 hover:text-blue-800" title="Ver historial">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <a href="cupon-form.php?id=<?= $cupon['id'] ?>" 
                                       class="text-green-600 hover:text-green-800" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button onclick="eliminarCupon(<?= $cupon['id'] ?>, '<?= htmlspecialchars($cupon['codigo']) ?>')" 
                                            class="text-red-600 hover:text-red-800" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Modal Crear/Editar Cupón -->
    <div id="modalCupon" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="cup-modal-bg rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="cup-modal-hdr p-6 rounded-t-2xl">
                <h2 id="modalTitulo" class="text-2xl font-bold">Nuevo Cupón</h2>
            </div>
            
            <form id="formCupon" class="p-6 space-y-4">
                <input type="hidden" id="cupon_id" name="id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Código -->
                    <div>
                        <label class="cup-label">🎟️ Código del Cupón*</label>
                        <input type="text" id="cupon_codigo" name="codigo" required maxlength="50"
                               placeholder="Ej: VERANO2026"
                               class="cup-input font-mono" style="text-transform:uppercase">
                    </div>
                    
                    <!-- Tipo de Descuento -->
                    <div>
                        <label class="cup-label">📊 Tipo de Descuento*</label>
                        <select id="cupon_tipo_descuento" name="tipo_descuento" required onchange="actualizarPlaceholder()"
                                class="cup-input">
                            <option value="porcentaje">Porcentaje (%)</option>
                            <option value="monto">Monto Fijo ($)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Valor del Descuento -->
                <div>
                    <label class="cup-label">💰 Valor del Descuento*</label>
                    <input type="number" id="cupon_valor_descuento" name="valor_descuento" required min="0" step="0.01"
                           placeholder="Ej: 10 o 100.00"
                           class="cup-input">
                    <p id="descuento_hint" class="text-xs mt-1" style="color:var(--text-muted)">Porcentaje de descuento (0-100)</p>
                </div>
                
                <!-- Descripción -->
                <div>
                    <label class="cup-label">📝 Descripción</label>
                    <textarea id="cupon_descripcion" name="descripcion" rows="2"
                              placeholder="Descripción del cupón para administración"
                              class="cup-input"></textarea>
                </div>
                
                <!-- Tipo de Aplicación -->
                <div>
                    <label class="cup-label">🎯 Aplicar A*</label>
                    <select id="cupon_tipo_aplicacion" name="tipo_aplicacion" required onchange="actualizarCamposAplicacion()"
                            class="cup-input">
                        <option value="general">🌐 General (Todos los productos)</option>
                        <option value="productos">📦 Productos Específicos</option>
                        <option value="tags">🏷️ Grupo de Productos (Tags)</option>
                        <option value="kits">🎁 Kits Específicos</option>
                        <option value="representantes">👤 Representantes Específicos</option>
                    </select>
                </div>
                
                <!-- Campo dinámico para IDs -->
                <div id="campo_ids" class="hidden">
                    <label class="block text-sm font-medium text-slate-700 mb-2" id="label_ids">Seleccionar</label>
                    
                    <!-- Buscador y contador -->
                    <div class="mb-2">
                        <input type="text" id="buscador_items" placeholder="🔍 Buscar..." 
                               oninput="filtrarItems()" 
                               class="cup-input" style="font-size:13px;padding:8px 12px">
                    </div>
                    <div class="flex justify-between items-center mb-2 px-2">
                        <span id="contador_seleccionados" class="text-xs" style="color:var(--text-secondary)">0 seleccionados</span>
                        <div class="flex gap-2">
                            <button type="button" onclick="seleccionarTodos()" class="text-xs text-blue-600 hover:text-blue-800">Seleccionar todos</button>
                            <button type="button" onclick="limpiarSeleccion()" class="text-xs text-red-600 hover:text-red-800">Limpiar</button>
                        </div>
                    </div>
                    
                    <!-- Lista con scroll -->
                    <div id="selector_ids" class="cup-selector"></div>
                </div>
                
                <!-- Campo para Tags -->
                <div id="campo_tags" class="hidden">
                    <label class="cup-label">🏷️ Tags Aplicables</label>
                    <input type="text" id="cupon_aplicacion_tags" name="aplicacion_tags"
                           placeholder="Ej: natural,vegano,premium"
                           class="cup-input">
                    <div id="tags_sugerencias" class="mt-2 flex flex-wrap gap-1"></div>
                </div>
                
                <!-- Restricciones -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 cup-divider pt-4">
                    <div>
                        <label class="cup-label">💳 Mínimo de Compra</label>
                        <input type="number" id="cupon_minimo_compra" name="minimo_compra" min="0" step="0.01" value="0"
                               class="cup-input">
                    </div>
                    
                    <div>
                        <label class="cup-label">🔢 Usos Máximos</label>
                        <input type="number" id="cupon_usos_maximos" name="usos_maximos" min="1"
                               placeholder="Dejar vacío para ilimitado"
                               class="cup-input">
                    </div>
                </div>
                
                <!-- Fechas -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="cup-label">📅 Fecha Inicio*</label>
                        <input type="datetime-local" id="cupon_fecha_inicio" name="fecha_inicio" required
                               class="cup-input">
                    </div>
                    
                    <div>
                        <label class="cup-label">📅 Fecha Expiración*</label>
                        <input type="datetime-local" id="cupon_fecha_expiracion" name="fecha_expiracion" required
                               class="cup-input">
                    </div>
                </div>
                
                <!-- Estado -->
                <div class="flex items-center gap-3 cup-divider pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cupon_activo" name="activo" value="1" checked
                               class="w-5 h-5 rounded">
                        <span class="text-sm font-medium" style="color:var(--text-secondary)">✓ Cupón Activo</span>
                    </label>
                </div>
                
                <!-- Botones -->
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="cerrarModal()" class="flex-1 cup-close">
                        Cancelar
                    </button>
                    <button type="submit" class="flex-1 cup-btn-primary justify-center">
                        Guardar Cupón
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Historial -->
    <div id="modalHistorial" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="cup-modal-bg rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="cup-modal-hdr p-6 rounded-t-2xl">
                <h2 class="text-2xl font-bold">📊 Historial de Uso</h2>
            </div>
            
            <div class="p-6">
                <div id="contenido_historial" class="overflow-x-auto"></div>
                <button onclick="cerrarHistorial()" class="mt-4 cup-close">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
    
    <script>
    let editandoId = null;
    let datosCache = {
        productos: [],
        kits: [],
        representantes: [],
        tags: []
    };
    
    // Cargar datos al inicio
    async function cargarDatos() {
        try {
            console.log('Iniciando carga de datos...');
            
            const [productosRes, kitsRes, representantesRes, tagsRes] = await Promise.all([
                fetch('cupones.php?action=getProductos'),
                fetch('cupones.php?action=getKits'),
                fetch('cupones.php?action=getRepresentantes'),
                fetch('cupones.php?action=getTags')
            ]);
            
            console.log('Respuestas recibidas. Status:', {
                productos: productosRes.status,
                kits: kitsRes.status,
                representantes: representantesRes.status,
                tags: tagsRes.status
            });
            
            const [productos, kits, representantes, tags] = await Promise.all([
                productosRes.json(),
                kitsRes.json(),
                representantesRes.json(),
                tagsRes.json()
            ]);
            
            console.log('JSON parseado:', {productos, kits, representantes, tags});
            
            datosCache.productos = productos.productos || [];
            datosCache.kits = kits.kits || [];
            datosCache.representantes = representantes.representantes || [];
            datosCache.tags = tags.tags || [];
            
            console.log('Datos cargados en cache:', {
                productos: datosCache.productos.length,
                kits: datosCache.kits.length,
                representantes: datosCache.representantes.length,
                tags: datosCache.tags.length
            });
            
            // Mostrar primeros 3 productos para debug
            if (datosCache.productos.length > 0) {
                console.log('Primeros productos:', datosCache.productos.slice(0, 3));
            } else {
                console.warn('⚠️ No se cargaron productos. Verificar respuesta de API.');
            }
        } catch (error) {
            console.error('❌ Error cargando datos:', error);
        }
    }
    
    async function abrirModalCrear() {
        // Asegurarse de que los datos estén cargados
        if (datosCache.productos.length === 0) {
            await cargarDatos();
        }
        
        editandoId = null;
        document.getElementById('modalTitulo').textContent = 'Nuevo Cupón';
        document.getElementById('formCupon').reset();
        document.getElementById('cupon_activo').checked = true;
        
        // Fechas por defecto
        const ahora = new Date();
        const unAnio = new Date(ahora.getFullYear() + 1, ahora.getMonth(), ahora.getDate());
        document.getElementById('cupon_fecha_inicio').value = formatDateTimeLocal(ahora);
        document.getElementById('cupon_fecha_expiracion').value = formatDateTimeLocal(unAnio);
        
        actualizarCamposAplicacion();
        document.getElementById('modalCupon').classList.remove('hidden');
    }
    
    async function editarCupon(id) {
        editandoId = id;
        document.getElementById('modalTitulo').textContent = 'Editar Cupón';
        
        const response = await fetch(`cupones.php?action=getById&id=${id}`);
        const data = await response.json();
        
            if (data.success) {
                const cupon = data.cupon;
            document.getElementById('cupon_id').value = cupon.id;
            document.getElementById('cupon_codigo').value = cupon.codigo;
            document.getElementById('cupon_descripcion').value = cupon.descripcion || '';
            document.getElementById('cupon_tipo_descuento').value = cupon.tipo_descuento;
            document.getElementById('cupon_valor_descuento').value = cupon.valor_descuento;
            document.getElementById('cupon_tipo_aplicacion').value = cupon.tipo_aplicacion;
            document.getElementById('cupon_aplicacion_tags').value = cupon.aplicacion_tags || '';
            document.getElementById('cupon_minimo_compra').value = cupon.minimo_compra;
            document.getElementById('cupon_fecha_inicio').value = formatDateTimeLocal(new Date(cupon.fecha_inicio));
            document.getElementById('cupon_fecha_expiracion').value = formatDateTimeLocal(new Date(cupon.fecha_expiracion));
            document.getElementById('cupon_usos_maximos').value = cupon.usos_maximos || '';
            document.getElementById('cupon_activo').checked = cupon.activo == 1;
            
            actualizarPlaceholder();
            actualizarCamposAplicacion();
            
            // Pre-seleccionar IDs si aplica
            const idsSeleccionados = cupon.tipo_aplicacion === 'representantes' && cupon.aplicacion_admin_ids
                ? cupon.aplicacion_admin_ids.split(',')
                : (cupon.aplicacion_ids ? cupon.aplicacion_ids.split(',') : []);

            if (idsSeleccionados.length > 0) {
                setTimeout(() => {
                    idsSeleccionados.forEach(id => {
                        const checkbox = document.querySelector(`input[value="${id}"]`);
                        if (checkbox) checkbox.checked = true;
                    });
                    actualizarContador();
                }, 100);
            }
            
            document.getElementById('modalCupon').classList.remove('hidden');
        }
    }
    
    function cerrarModal() {
        document.getElementById('modalCupon').classList.add('hidden');
        document.getElementById('formCupon').reset();
        const buscador = document.getElementById('buscador_items');
        if (buscador) buscador.value = '';
        editandoId = null;
    }
    
    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    function actualizarPlaceholder() {
        const tipo = document.getElementById('cupon_tipo_descuento').value;
        const hint = document.getElementById('descuento_hint');
        
        if (tipo === 'porcentaje') {
            hint.textContent = 'Porcentaje de descuento (0-100)';
        } else {
            hint.textContent = 'Monto fijo de descuento en pesos';
        }
    }
    
    function actualizarCamposAplicacion() {
        const tipo = document.getElementById('cupon_tipo_aplicacion').value;
        const campoIds = document.getElementById('campo_ids');
        const campoTags = document.getElementById('campo_tags');
        const labelIds = document.getElementById('label_ids');
        const selectorIds = document.getElementById('selector_ids');
        const buscador = document.getElementById('buscador_items');
        
        // Ocultar todos y limpiar buscador
        campoIds.classList.add('hidden');
        campoTags.classList.add('hidden');
        if (buscador) buscador.value = '';
        
        switch(tipo) {
            case 'general':
                // No mostrar nada
                break;
                
            case 'productos':
                labelIds.textContent = '📦 Seleccionar Productos';
                selectorIds.innerHTML = generarCheckboxes(datosCache.productos, 'producto');
                campoIds.classList.remove('hidden');
                actualizarContador();
                break;
                
            case 'tags':
                campoTags.classList.remove('hidden');
                cargarSugerenciasTags();
                break;
                
            case 'kits':
                labelIds.textContent = '🎁 Seleccionar Kits';
                selectorIds.innerHTML = generarCheckboxes(datosCache.kits, 'nombre');
                campoIds.classList.remove('hidden');
                actualizarContador();
                break;
                
            case 'representantes':
                labelIds.textContent = '👤 Seleccionar Representantes';
                selectorIds.innerHTML = generarCheckboxes(datosCache.representantes, 'nombre', 'codigo');
                campoIds.classList.remove('hidden');
                actualizarContador();
                break;
        }
    }
    
    function generarCheckboxes(items, nombreProp, extraProp = null) {
        if (!items || items.length === 0) {
            return '<p class="text-slate-500 p-4">No hay elementos disponibles</p>';
        }
        
        console.log(`Generando checkboxes para ${items.length} items, propiedad: ${nombreProp}`);
        
        return items.map(item => {
            const nombre = item[nombreProp] || item.nombre || 'Sin nombre';
            const extra = extraProp && item[extraProp] ? ` (${item[extraProp]})` : '';
            const textoCompleto = `${nombre}${extra}`.toLowerCase();
            
            return `
                <label class="checkbox-item flex items-center gap-3 p-3 hover:bg-white rounded-lg cursor-pointer transition-colors border-b border-slate-200 last:border-b-0" 
                       data-search="${textoCompleto}">
                    <input type="checkbox" value="${item.id}" class="aplicacion-checkbox w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500" 
                           onchange="actualizarContador()">
                    <span class="text-sm text-slate-700 flex-1">${nombre}${extra}</span>
                </label>
            `;
        }).join('');
    }
    
    function cargarSugerenciasTags() {
        const container = document.getElementById('tags_sugerencias');
        container.innerHTML = '';
        
        datosCache.tags.forEach(tag => {
            const badge = document.createElement('span');
            badge.className = 'inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded cursor-pointer hover:bg-blue-200';
            badge.textContent = tag;
            badge.onclick = () => agregarTag(tag);
            container.appendChild(badge);
        });
    }
    
    function agregarTag(tag) {
        const input = document.getElementById('cupon_aplicacion_tags');
        const tags = input.value.split(',').map(t => t.trim()).filter(t => t);
        if (!tags.includes(tag)) {
            tags.push(tag);
            input.value = tags.join(',');
        }
    }
    
    function filtrarItems() {
        const busqueda = document.getElementById('buscador_items').value.toLowerCase();
        const items = document.querySelectorAll('.checkbox-item');
        let visibles = 0;
        
        console.log(`Buscando: "${busqueda}", Total items: ${items.length}`);
        
        items.forEach(item => {
            const texto = item.getAttribute('data-search');
            if (texto && texto.includes(busqueda)) {
                item.style.display = 'flex';
                visibles++;
            } else {
                item.style.display = 'none';
            }
        });
        
        console.log(`Items visibles: ${visibles}`);
        
        // Mostrar mensaje si no hay resultados
        const selector = document.getElementById('selector_ids');
        const mensajeVacio = selector.querySelector('.mensaje-sin-resultados');
        
        if (visibles === 0 && busqueda) {
            if (!mensajeVacio) {
                const mensaje = document.createElement('p');
                mensaje.className = 'mensaje-sin-resultados text-slate-500 text-sm text-center py-4';
                mensaje.textContent = `No se encontraron resultados para "${busqueda}"`;
                selector.appendChild(mensaje);
            }
        } else if (mensajeVacio) {
            mensajeVacio.remove();
        }
    }
    
    function actualizarContador() {
        const checkboxes = document.querySelectorAll('.aplicacion-checkbox');
        const seleccionados = document.querySelectorAll('.aplicacion-checkbox:checked');
        const contador = document.getElementById('contador_seleccionados');
        
        if (contador) {
            const total = checkboxes.length;
            const seleccionadosCount = seleccionados.length;
            contador.textContent = `${seleccionadosCount} de ${total} seleccionados`;
            contador.className = seleccionadosCount > 0 
                ? 'text-xs font-medium text-blue-600' 
                : 'text-xs text-slate-600';
        }
    }
    
    function seleccionarTodos() {
        const checkboxes = document.querySelectorAll('.checkbox-item:not([style*="display: none"]) .aplicacion-checkbox');
        checkboxes.forEach(cb => cb.checked = true);
        actualizarContador();
    }
    
    function limpiarSeleccion() {
        const checkboxes = document.querySelectorAll('.aplicacion-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
        actualizarContador();
    }
    
    document.getElementById('formCupon').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        formData.append('action', editandoId ? 'update' : 'create');
        
        // Recopilar IDs seleccionados
        const checkboxes = document.querySelectorAll('.aplicacion-checkbox:checked');
        const ids = Array.from(checkboxes).map(cb => cb.value).join(',');
        const tipoAplicacion = document.getElementById('cupon_tipo_aplicacion').value;

        if (tipoAplicacion === 'representantes') {
            formData.set('aplicacion_admin_ids', ids);
            formData.set('aplicacion_ids', '');
        } else {
            formData.set('aplicacion_ids', ids);
            formData.set('aplicacion_admin_ids', '');
        }
        
        try {
            const response = await fetch('cupones.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        } catch (error) {
            showToast('Error de conexión: ' + error.message, 'error');
        }
    });
    
    async function eliminarCupon(id, codigo) {
        if (!confirm(`¿Estás seguro de eliminar el cupón "${codigo}"?\n\nEsto también eliminará su historial de usos.`)) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        try {
            const response = await fetch('cupones.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        } catch (error) {
            showToast('Error de conexión: ' + error.message, 'error');
        }
    }
    
    async function verHistorial(id) {
        const formData = new FormData();
        formData.append('action', 'getHistorial');
        formData.append('id', id);
        
        try {
            const response = await fetch('cupones.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                mostrarHistorial(data.historial);
            }
        } catch (error) {
            showToast('Error al cargar historial: ' + error.message, 'error');
        }
    }
    
    function mostrarHistorial(historial) {
        const contenido = document.getElementById('contenido_historial');
        
        if (historial.length === 0) {
            contenido.innerHTML = '<p class="text-slate-500 text-center py-8">Este cupón aún no ha sido usado</p>';
        } else {
            contenido.innerHTML = `
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-3 py-2 text-left">Fecha</th>
                            <th class="px-3 py-2 text-left">Pedido</th>
                            <th class="px-3 py-2 text-left">Cliente</th>
                            <th class="px-3 py-2 text-left">Representante</th>
                            <th class="px-3 py-2 text-right">Subtotal</th>
                            <th class="px-3 py-2 text-right">Descuento</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        ${historial.map(uso => `
                            <tr>
                                <td class="px-3 py-2">${new Date(uso.fecha_uso).toLocaleString('es-MX')}</td>
                                <td class="px-3 py-2 font-mono">#${uso.pedido_folio}</td>
                                <td class="px-3 py-2">${uso.cliente_nombre || '-'}</td>
                                <td class="px-3 py-2">${uso.representante_nombre || '-'}</td>
                                <td class="px-3 py-2 text-right">$${parseFloat(uso.subtotal_pedido).toFixed(2)}</td>
                                <td class="px-3 py-2 text-right font-bold text-red-600">-$${parseFloat(uso.monto_descuento).toFixed(2)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }
        
        document.getElementById('modalHistorial').classList.remove('hidden');
    }
    
    function cerrarHistorial() {
        document.getElementById('modalHistorial').classList.add('hidden');
    }
    
    // Inicializar
    cargarDatos();
    </script>
</div><!-- /.cup-page -->
