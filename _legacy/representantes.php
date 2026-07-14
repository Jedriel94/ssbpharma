<?php
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Representante.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$representanteModel = new Representante($pdo);

// Procesar acciones
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'crear':
                throw new Exception('Alta congelada: crea representantes desde Usuarios del Sistema asignando el rol Representante');
                break;
                
            case 'editar':
                $id = $_POST['id'] ?? 0;
                $nombre = trim($_POST['nombre'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $activo = isset($_POST['activo']) ? 1 : 0;
                $tags_permitidos = trim($_POST['tags_permitidos'] ?? '');
                
                if ($representanteModel->update($id, $nombre, $telefono, $email, $activo, $tags_permitidos)) {
                    $mensaje = "✅ Representante actualizado exitosamente";
                    $tipo_mensaje = 'success';
                } else {
                    throw new Exception('Error al actualizar representante');
                }
                break;
                
            case 'eliminar':
                $id = $_POST['id'] ?? 0;
                $representanteModel->eliminar($id);
                $mensaje = "✅ Representante eliminado";
                $tipo_mensaje = 'success';
                break;
                
            case 'desactivar':
                $id = $_POST['id'] ?? 0;
                $representanteModel->desactivar($id);
                $mensaje = "✅ Representante desactivado";
                $tipo_mensaje = 'success';
                break;
                
            case 'activar':
                $id = $_POST['id'] ?? 0;
                $representanteModel->activar($id);
                $mensaje = "✅ Representante activado";
                $tipo_mensaje = 'success';
                break;
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// Obtener todos los representantes
$representantes = $representanteModel->getAll();

// Generar código único para nuevo representante
$codigo_sugerido = $representanteModel->generarCodigoUnico();

$pageTitle = "Representantes Legacy";
include '../includes/header.php';
?>

<div class="p-6">
    
    <!-- Header legacy -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Representantes legacy</h1>
            <p class="text-slate-600 mt-1">Consulta y mantenimiento temporal durante la migración hacia usuarios</p>
        </div>
        <a href="usuarios-sistema.php" class="btn-primary px-6 py-3 rounded-xl font-semibold text-white flex items-center gap-2">
            Crear usuario representante
        </a>
    </div>

    <div class="mb-6 p-4 rounded-xl bg-amber-50 border-2 border-amber-200 text-amber-900">
        <p class="font-bold">Migración en curso</p>
        <p class="text-sm mt-1">
            Esta pantalla queda como compatibilidad para registros existentes, QR y enlaces históricos.
            Las nuevas altas deben hacerse desde <strong>Usuarios del Sistema</strong> con rol <strong>Representante</strong>.
        </p>
    </div>
    
    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="mb-6 p-4 rounded-xl <?= $tipo_mensaje === 'success' ? 'bg-green-50 border-2 border-green-300 text-green-900' : 'bg-red-50 border-2 border-red-300 text-red-900' ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>
    
    <!-- Tabla de representantes -->
    <div class="card rounded-2xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase">Código</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase">Nombre</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase">Contacto</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase">Enlace</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase">Estado</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase">Estadísticas</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-slate-700 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($representantes as $rep): 
                        $stats = $representanteModel->getEstadisticas($rep['id']);
                        $url_enlace = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                                      "://" . $_SERVER['HTTP_HOST'] . "/r.php?c=" . urlencode($rep['codigo']);
                    ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-6 py-4">
                                <span class="font-mono font-bold text-purple-700"><?= htmlspecialchars($rep['codigo']) ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($rep['nombre']) ?></div>
                                <div class="text-xs text-slate-500">ID: <?= $rep['id'] ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($rep['telefono']): ?>
                                    <div class="text-sm">📞 <?= htmlspecialchars($rep['telefono']) ?></div>
                                <?php endif; ?>
                                <?php if ($rep['email']): ?>
                                    <div class="text-sm text-slate-600">✉️ <?= htmlspecialchars($rep['email']) ?></div>
                                <?php endif; ?>
                                <?php if (!$rep['telefono'] && !$rep['email']): ?>
                                    <span class="text-slate-400 text-sm">Sin contacto</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <a href="<?= $url_enlace ?>" target="_blank" class="text-blue-600 hover:text-blue-800 text-xs underline flex items-center gap-1">
                                    🔗 Ver enlace
                                </a>
                                <button onclick="copiarEnlace('<?= $url_enlace ?>')" class="text-green-600 hover:text-green-800 text-xs mt-1">
                                    📋 Copiar
                                </button>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($rep['activo']): ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                        ✅ Activo
                                    </span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                        ❌ Inactivo
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm space-y-1">
                                    <div class="font-semibold text-green-600">$<?= number_format($stats['total_ventas'], 2) ?></div>
                                    <div class="text-xs text-slate-600"><?= $stats['total_pedidos'] ?> pedidos</div>
                                    <div class="text-xs text-slate-500"><?= $stats['total_clientes'] ?> clientes</div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-2">
                                    <span class="p-2 text-slate-300 cursor-not-allowed" title="QR disponible desde Usuarios del sistema">
                                        📱
                                    </span>
                                    <button onclick="modalEditar(<?= htmlspecialchars(json_encode($rep)) ?>)" 
                                            class="p-2 hover:bg-blue-100 rounded-lg transition" title="Editar">
                                        ✏️
                                    </button>
                                    <?php if ($rep['activo']): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('¿Desactivar este representante?')">
                                            <input type="hidden" name="action" value="desactivar">
                                            <input type="hidden" name="id" value="<?= $rep['id'] ?>">
                                            <button type="submit" class="p-2 hover:bg-amber-100 rounded-lg transition" title="Desactivar">
                                                🚫
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="activar">
                                            <input type="hidden" name="id" value="<?= $rep['id'] ?>">
                                            <button type="submit" class="p-2 hover:bg-green-100 rounded-lg transition" title="Activar">
                                                ✅
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($stats['total_pedidos'] == 0): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar permanentemente?')">
                                            <input type="hidden" name="action" value="eliminar">
                                            <input type="hidden" name="id" value="<?= $rep['id'] ?>">
                                            <button type="submit" class="p-2 hover:bg-red-100 rounded-lg transition" title="Eliminar">
                                                🗑️
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($representantes)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-500">
                                <div class="text-6xl mb-4">👥</div>
                                <p class="text-lg font-semibold">No hay representantes registrados</p>
                                <p class="text-sm mt-2">Crea el primer representante para comenzar</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Crear Representante -->
<div id="modalCrearRep" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-50">
    <div class="card rounded-2xl shadow-2xl max-w-md w-full p-6 bg-white">
        <h2 class="text-2xl font-bold text-slate-900 mb-4">➕ Nuevo Representante</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="crear">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Código Único *</label>
                    <input type="text" name="codigo" value="<?= $codigo_sugerido ?>" required
                           class="input-field w-full px-4 py-3 rounded-xl uppercase" 
                           placeholder="REP001" maxlength="50">
                    <p class="text-xs text-slate-500 mt-1">Este código se usará en el enlace de referido</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Nombre Completo *</label>
                    <input type="text" name="nombre" required
                           class="input-field w-full px-4 py-3 rounded-xl" 
                           placeholder="María García López">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Teléfono</label>
                    <input type="tel" name="telefono" 
                           class="input-field w-full px-4 py-3 rounded-xl" 
                           placeholder="5512345678" maxlength="15">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Email</label>
                    <input type="email" name="email" 
                           class="input-field w-full px-4 py-3 rounded-xl" 
                           placeholder="maria@ejemplo.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">🏷️ Tags Permitidos</label>
                    <input type="text" name="tags_permitidos" id="tags_permitidos"
                           class="input-field w-full px-4 py-3 rounded-xl" 
                           placeholder="* (todos) o: cosmetico,natural,vegano">
                    <p class="text-xs text-slate-500 mt-1">
                        Escribe <strong>*</strong> para permitir todos los productos, o lista tags separados por comas
                    </p>
                    <div id="tagsSugerenciasRep" class="mt-2 flex flex-wrap gap-1"></div>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="cerrarModal('modalCrearRep')" 
                        class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300 transition">
                    Cancelar
                </button>
                <button type="submit" 
                        class="flex-1 btn-primary text-white py-3 rounded-xl font-medium">
                    Crear Representante
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Representante -->
<div id="modalEditarRep" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-50">
    <div class="card rounded-2xl shadow-2xl max-w-md w-full p-6 bg-white">
        <h2 class="text-2xl font-bold text-slate-900 mb-4">✏️ Editar Representante</h2>
        
        <form method="POST" id="formEditar">
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Código</label>
                    <input type="text" id="edit_codigo" disabled
                           class="input-field w-full px-4 py-3 rounded-xl bg-slate-100">
                    <p class="text-xs text-slate-500 mt-1">El código no se puede modificar</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Nombre Completo *</label>
                    <input type="text" name="nombre" id="edit_nombre" required
                           class="input-field w-full px-4 py-3 rounded-xl">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Teléfono</label>
                    <input type="tel" name="telefono" id="edit_telefono"
                           class="input-field w-full px-4 py-3 rounded-xl" maxlength="15">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Email</label>
                    <input type="email" name="email" id="edit_email"
                           class="input-field w-full px-4 py-3 rounded-xl">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">🏷️ Tags Permitidos</label>
                    <input type="text" name="tags_permitidos" id="edit_tags_permitidos"
                           class="input-field w-full px-4 py-3 rounded-xl" 
                           placeholder="* (todos) o: cosmetico,natural,vegano">
                    <p class="text-xs text-slate-500 mt-1">
                        Escribe <strong>*</strong> para permitir todos los productos, o lista tags separados por comas
                    </p>
                    <div id="tagsSugerenciasRepEdit" class="mt-2 flex flex-wrap gap-1"></div>
                </div>
                
                <div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="activo" id="edit_activo" value="1"
                               class="w-5 h-5 text-green-600 rounded focus:ring-2 focus:ring-green-500">
                        <span class="text-sm font-medium text-slate-700">Representante activo</span>
                    </label>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="cerrarModal('modalEditarRep')" 
                        class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300 transition">
                    Cancelar
                </button>
                <button type="submit" 
                        class="flex-1 btn-primary text-white py-3 rounded-xl font-medium">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function modalCrear() {
    document.getElementById('tags_permitidos').value = '';
    cargarSugerenciasTagsRep('tagsSugerenciasRep', 'tags_permitidos');
    document.getElementById('modalCrearRep').classList.remove('hidden');
}

function modalEditar(rep) {
    document.getElementById('edit_id').value = rep.id;
    document.getElementById('edit_codigo').value = rep.codigo;
    document.getElementById('edit_nombre').value = rep.nombre;
    document.getElementById('edit_telefono').value = rep.telefono || '';
    document.getElementById('edit_email').value = rep.email || '';
    document.getElementById('edit_tags_permitidos').value = rep.tags_permitidos || '';
    document.getElementById('edit_activo').checked = rep.activo == 1;
    
    cargarSugerenciasTagsRep('tagsSugerenciasRepEdit', 'edit_tags_permitidos');
    document.getElementById('modalEditarRep').classList.remove('hidden');
}

function cargarSugerenciasTagsRep(containerId, inputId) {
    fetch('../admin/productos.php?action=getTags')
    .then(res => res.json())
    .then(data => {
        if (data.success && data.tags) {
            const container = document.getElementById(containerId);
            container.innerHTML = '<small class="text-gray-600">Tags disponibles: </small>';
            
            // Botón para todos
            const btnTodos = document.createElement('span');
            btnTodos.className = 'inline-block bg-purple-100 text-purple-800 text-xs px-2 py-1 rounded cursor-pointer hover:bg-purple-200 mr-1 mb-1 font-bold';
            btnTodos.textContent = '* (todos)';
            btnTodos.onclick = () => {
                document.getElementById(inputId).value = '*';
            };
            container.appendChild(btnTodos);
            
            // Tags individuales
            data.tags.forEach(tag => {
                const badge = document.createElement('span');
                badge.className = 'inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded cursor-pointer hover:bg-blue-200 mr-1 mb-1';
                badge.textContent = tag;
                badge.onclick = () => agregarTagSugeridoRep(tag, inputId);
                container.appendChild(badge);
            });
        }
    });
}

function agregarTagSugeridoRep(tag, inputId) {
    const input = document.getElementById(inputId);
    if (input.value === '*') {
        input.value = tag;
        return;
    }
    const currentTags = input.value.split(',').map(t => t.trim()).filter(t => t && t !== '*');
    if (!currentTags.includes(tag)) {
        currentTags.push(tag);
        input.value = currentTags.join(',');
    }
}

function cerrarModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function copiarEnlace(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert('✅ Enlace copiado al portapapeles:\n' + url);
    }).catch(err => {
        prompt('Copia este enlace:', url);
    });
}

// Cerrar modales al hacer clic fuera
document.querySelectorAll('.modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
