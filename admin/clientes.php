<?php
require_once '../includes/auth_admin.php'; // Proteger página
require_once '../models/Cliente.php';

$clienteModel = new Cliente();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $telefono = $_POST['telefono'] ?? '';
            $nombre = $_POST['nombre'] ?? null;
            
            if ($clienteModel->create($telefono, $nombre)) {
                echo json_encode(['success' => true, 'message' => 'Cliente creado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'El teléfono ya está registrado']);
            }
            exit;
            
        case 'update':
            $id = $_POST['id'] ?? 0;
            $telefono = $_POST['telefono'] ?? '';
            $password = $_POST['password'] ?? '';
            
            // Preparar hash de password solo si se proporciona
            $password_hash = null;
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
            }
            
            // Usar updateDatos para actualizar todos los campos
            if ($clienteModel->updateDatos(
                $telefono,
                trim($_POST['nombre'] ?? ''),
                // Datos de Envío
                trim($_POST['calle'] ?? ''),
                trim($_POST['numero'] ?? ''),
                trim($_POST['colonia'] ?? ''),
                trim($_POST['cp'] ?? ''),
                trim($_POST['estado'] ?? ''),
                trim($_POST['ciudad'] ?? ''),
                trim($_POST['referencias'] ?? ''),
                trim($_POST['quien_recibe'] ?? ''),
                // Datos Médicos
                trim($_POST['nombre_medico'] ?? ''),
                trim($_POST['telefono_medico'] ?? ''),
                trim($_POST['nombre_representante'] ?? ''),
                // Datos Fiscales
                trim($_POST['rfc'] ?? ''),
                trim($_POST['razon_social'] ?? ''),
                trim($_POST['email_factura'] ?? ''),
                trim($_POST['codigo_postal'] ?? ''),
                null, // empresa eliminado
                trim($_POST['regimen'] ?? ''),
                trim($_POST['uso_cfdi'] ?? ''),
                trim($_POST['regimen_fiscal'] ?? ''),
                // Archivos
                $_POST['constancia_fiscal'] ?? null,
                // Password
                $password_hash
            )) {
                echo json_encode(['success' => true, 'message' => 'Cliente actualizado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar cliente']);
            }
            exit;
            
        case 'delete':
            $id = $_POST['id'] ?? 0;
            
            if ($clienteModel->delete($id)) {
                echo json_encode(['success' => true, 'message' => 'Cliente eliminado exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al eliminar cliente']);
            }
            exit;
            
        case 'get':
            $id = $_POST['id'] ?? 0;
            $cliente = $clienteModel->getById($id);
            echo json_encode(['success' => true, 'cliente' => $cliente]);
            exit;

        case 'update_notif':
            $id   = (int)($_POST['id'] ?? 0);
            $n_conf = isset($_POST['notif_confirmacion']) ? (int)$_POST['notif_confirmacion'] : 0;
            $n_fact = isset($_POST['notif_factura'])      ? (int)$_POST['notif_factura']      : 0;
            if ($clienteModel->updateNotif($id, $n_conf, $n_fact)) {
                echo json_encode(['success' => true, 'message' => 'Preferencias guardadas']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al guardar preferencias']);
            }
            exit;
    }
}

$clientes = $clienteModel->getAll();
?>

<?php include '../includes/header.php'; ?>

<?php
$totalClientes = count($clientes);
$clientesConNombre = count(array_filter($clientes, fn($c) => !empty($c['nombre'])));
$clientesConDireccion = count(array_filter($clientes, fn($c) => !empty($c['calle']) || !empty($c['colonia']) || !empty($c['cp'])));
$clientesNuevosHoy = count(array_filter($clientes, fn($c) => date('Y-m-d', strtotime($c['created_at'])) === date('Y-m-d')));
?>

<div class="container mx-auto px-4 py-8">
    <!-- Header con Estadísticas -->
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-800 mb-2">Gestión de Clientes</h1>
                <p class="text-slate-600">Administra la base de clientes, datos fiscales, médicos y de envío.</p>
            </div>
            <a href="../mis-datos.php"
               class="btn-primary text-white px-6 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transition flex items-center justify-center gap-2">
                <span>+</span> Alta desde Mis Datos
            </a>
        </div>

        <!-- Estadísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Total Clientes</div>
                <div class="text-2xl font-bold text-slate-800"><?= $totalClientes ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Con Nombre</div>
                <div class="text-2xl font-bold text-green-600"><?= $clientesConNombre ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Con Dirección</div>
                <div class="text-2xl font-bold text-blue-600"><?= $clientesConDireccion ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Nuevos Hoy</div>
                <div class="text-2xl font-bold text-red-600"><?= $clientesNuevosHoy ?></div>
            </div>
        </div>
    </div>

    <!-- Búsqueda -->
    <div class="card rounded-xl shadow p-4 mb-6">
        <label for="searchClientes" class="block text-sm font-semibold text-slate-700 mb-2">Buscar cliente</label>
        <input type="text"
               id="searchClientes"
               placeholder="Buscar por teléfono o nombre..."
               class="input-field w-full px-5 py-3 rounded-xl"
               onkeyup="filtrarClientes()">
    </div>

    <!-- Lista de Clientes -->
    <div class="card rounded-xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead style="background:linear-gradient(to right,var(--tw-neu-800),var(--tw-neu-900));color:#fff;">
                    <tr>
                        <th class="px-4 py-3 text-left">ID</th>
                        <th class="px-4 py-3 text-left">Teléfono</th>
                        <th class="px-4 py-3 text-left">Nombre</th>
                        <th class="px-4 py-3 text-left">Dirección</th>
                        <th class="px-4 py-3 text-left">Médico</th>
                        <th class="px-4 py-3 text-left">RFC</th>
                        <th class="px-4 py-3 text-center">Password</th>
                        <th class="px-4 py-3 text-center">Notif.</th>
                        <th class="px-4 py-3 text-left">Registro</th>
                        <th class="px-4 py-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tablaClientes" class="divide-y divide-slate-200">
                    <?php foreach ($clientes as $cliente): ?>
                    <?php 
                    $direccion = trim(implode(', ', array_filter([
                        $cliente['calle'] ?? '',
                        $cliente['numero'] ?? '',
                        $cliente['colonia'] ?? '',
                        $cliente['cp'] ?? ''
                    ])));
                    ?>
                    <tr class="hover:bg-slate-50 transition cliente-row" 
                        data-id="<?= (int)$cliente['id'] ?>"
                        data-telefono="<?= htmlspecialchars($cliente['telefono']) ?>"
                        data-nombre="<?= htmlspecialchars($cliente['nombre'] ?? '') ?>">
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs font-bold text-slate-500">#<?= (int)$cliente['id'] ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono font-bold text-blue-600"><?= htmlspecialchars($cliente['telefono']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-slate-700 text-sm"><?= $cliente['nombre'] ? htmlspecialchars($cliente['nombre']) : '<em class="text-slate-400">Sin nombre</em>' ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-slate-600 text-sm" title="<?= htmlspecialchars($direccion) ?>">
                                <?= $direccion ? htmlspecialchars(substr($direccion, 0, 30)) . (strlen($direccion) > 30 ? '...' : '') : '<em class="text-slate-400">-</em>' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-slate-600 text-sm">
                                <?= !empty($cliente['nombre_medico']) ? htmlspecialchars($cliente['nombre_medico']) : '<em class="text-slate-400">-</em>' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-slate-600 text-sm">
                                <?= !empty($cliente['rfc']) ? htmlspecialchars($cliente['rfc']) : '<em class="text-slate-400">-</em>' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if (!empty($cliente['password'])): ?>
                                <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-800" title="Tiene contraseña">Activa</span>
                            <?php else: ?>
                                <span class="text-xs px-2 py-1 rounded-full bg-slate-100 text-slate-600" title="Sin contraseña">Sin clave</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center gap-1">
                                <?php if ($cliente['notif_confirmacion'] ?? 1): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700" title="Recibe confirmación de pedido">Conf</span>
                                <?php else: ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-400" title="No recibe confirmación">Conf</span>
                                <?php endif; ?>
                                <?php if ($cliente['notif_factura'] ?? 1): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700" title="Recibe factura por correo">Fact</span>
                                <?php else: ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-400" title="No recibe factura">Fact</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600 text-sm">
                            <?= date('d/m/Y H:i', strtotime($cliente['created_at'])) ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-center gap-2">
                                <a href="javascript:void(0)" 
                                   onclick="verDetalles(<?= $cliente['id'] ?>)"
                                   class="text-blue-600 hover:text-blue-800 p-2 rounded-lg hover:bg-blue-50"
                                   title="Ver detalles completos">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                </a>
                                
                                <a href="../mis-datos.php?telefono=<?= urlencode($cliente['telefono']) ?>" 
                                   target="_blank"
                                   class="text-green-600 hover:text-green-800 p-2 rounded-lg hover:bg-green-50"
                                   title="Editar datos completos">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </a>
                                
                                <button onclick="eliminarCliente(<?= $cliente['id'] ?>)" 
                                        class="text-red-500 hover:text-red-700 p-2 rounded-lg hover:bg-red-50"
                                        title="Eliminar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($clientes)): ?>
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center">
                            <p class="text-slate-600 text-lg font-semibold">No hay clientes registrados</p>
                            <p class="text-slate-500 text-sm mt-2">Los clientes se registrarán automáticamente al crear pedidos</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Paginador -->
        <div class="pag-bar" id="cl-pag-bar">
            <div class="pag-left">
                <span class="pag-info" id="cl-pag-info"></span>
                <select class="pag-size" id="cl-pag-size">
                    <option value="10" selected>10 / pág</option>
                    <option value="25">25 / pág</option>
                    <option value="50">50 / pág</option>
                    <option value="100">100 / pág</option>
                </select>
            </div>
            <div class="pag-controls" id="cl-pag-ctrl"></div>
        </div>
    </div>
</div>

<!-- Modal Detalles Cliente -->
<div id="modalDetalles" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="card rounded-3xl shadow-2xl max-w-3xl w-full p-8 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-slate-900">Detalles del Cliente</h2>
            <button onclick="cerrarModalDetalles()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div id="detallesContent" class="space-y-6">
            <!-- Datos Básicos -->
            <div class="border-l-4 border-terracotta-400 pl-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-3">Datos Básicos</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Teléfono</p>
                        <p class="text-slate-900 font-medium" id="det_telefono">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Nombre</p>
                        <p class="text-slate-900 font-medium" id="det_nombre">-</p>
                    </div>
                </div>
            </div>

            <!-- Datos de Envío -->
            <div class="border-l-4 border-sage-400 pl-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-3">Datos de Envío</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Calle</p>
                        <p class="text-slate-900" id="det_calle">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Número</p>
                        <p class="text-slate-900" id="det_numero">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Colonia</p>
                        <p class="text-slate-900" id="det_colonia">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Código Postal</p>
                        <p class="text-slate-900" id="det_cp">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Estado</p>
                        <p class="text-slate-900" id="det_estado">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Quien Recibe</p>
                        <p class="text-slate-900" id="det_quien_recibe">-</p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="text-xs text-slate-500 uppercase">Referencias</p>
                        <p class="text-slate-900" id="det_referencias">-</p>
                    </div>
                </div>
            </div>

            <!-- Médico -->
            <div class="border-l-4 border-blue-400 pl-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-3">Médico</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Nombre Médico</p>
                        <p class="text-slate-900" id="det_nombre_medico">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Nombre Representante</p>
                        <p class="text-slate-900" id="det_nombre_representante">-</p>
                    </div>
                </div>
            </div>

            <!-- Datos Fiscales -->
            <div class="border-l-4 border-purple-400 pl-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-3">Datos Fiscales</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase">RFC</p>
                        <p class="text-slate-900 font-mono" id="det_rfc">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Razón Social</p>
                        <p class="text-slate-900" id="det_razon_social">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Régimen</p>
                        <p class="text-slate-900" id="det_regimen">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Uso de CFDI</p>
                        <p class="text-slate-900" id="det_uso_cfdi">-</p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="text-xs text-slate-500 uppercase">Constancia Fiscal</p>
                        <p class="text-slate-900" id="det_constancia_fiscal">-</p>
                    </div>
                </div>
            </div>

            <!-- Notificaciones -->
            <div class="border-l-4 border-amber-400 pl-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-3">Notificaciones por correo</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 cursor-pointer">
                        <input type="checkbox" id="det_notif_conf" class="w-4 h-4 accent-green-600">
                        <div>
                            <p class="text-sm font-medium text-slate-800">Confirmar pedido / pago</p>
                            <p class="text-xs text-slate-500">Correo al confirmar estado</p>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 cursor-pointer">
                        <input type="checkbox" id="det_notif_fact" class="w-4 h-4 accent-green-600">
                        <div>
                            <p class="text-sm font-medium text-slate-800">Factura electrónica</p>
                            <p class="text-xs text-slate-500">Correo al subir factura</p>
                        </div>
                    </label>
                </div>
                <button onclick="saveNotif()" class="mt-3 text-sm bg-amber-100 hover:bg-amber-200 text-amber-800 font-medium px-4 py-2 rounded-lg transition">Guardar preferencias</button>
            </div>

            <!-- Fecha Registro -->
            <div class="border-l-4 border-slate-300 pl-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-3">Registro</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Fecha de Registro</p>
                        <p class="text-slate-900" id="det_created_at">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Última Actualización</p>
                        <p class="text-slate-900" id="det_updated_at">-</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-6 border-t flex gap-3">
            <button onclick="cerrarModalDetalles()" 
                    class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300">
                Cerrar
            </button>
            <a id="btnEditarDatos" 
               href="#" 
               target="_blank"
               class="flex-1 btn-primary text-white py-3 rounded-xl font-medium text-center hover:opacity-90">
                Editar en Mis Datos
            </a>
        </div>
    </div>
</div>

<script src="<?= asset('js/paginator.js') ?>"></script>
<script>
let detClienteId = null;

function verDetalles(id) {
    fetch('clientes.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get&id=' + id
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const c = data.cliente;
            
            // Datos Básicos
            document.getElementById('det_telefono').textContent = c.telefono || '-';
            document.getElementById('det_nombre').textContent = c.nombre || '-';
            
            // Datos de Envío
            document.getElementById('det_calle').textContent = c.calle || '-';
            document.getElementById('det_numero').textContent = c.numero || '-';
            document.getElementById('det_colonia').textContent = c.colonia || '-';
            document.getElementById('det_cp').textContent = c.cp || '-';
            document.getElementById('det_estado').textContent = c.estado || '-';
            document.getElementById('det_quien_recibe').textContent = c.quien_recibe || '-';
            document.getElementById('det_referencias').textContent = c.referencias || '-';
            
            // Médico
            document.getElementById('det_nombre_medico').textContent = c.nombre_medico || '-';
            document.getElementById('det_nombre_representante').textContent = c.nombre_representante || '-';
            
            // Datos Fiscales
            document.getElementById('det_rfc').textContent = c.rfc || '-';
            document.getElementById('det_razon_social').textContent = c.razon_social || '-';
            document.getElementById('det_regimen').textContent = c.regimen || '-';
            document.getElementById('det_uso_cfdi').textContent = c.uso_cfdi || '-';
            
            // Constancia fiscal
            if (c.constancia_fiscal) {
                const filename = c.constancia_fiscal.split('/').pop();
                document.getElementById('det_constancia_fiscal').innerHTML = 
                    `<a href="../descargar-fiscal.php?file=${encodeURIComponent(filename)}" target="_blank" class="text-terracotta-500 hover:underline">${filename}</a>`;
            } else {
                document.getElementById('det_constancia_fiscal').textContent = '-';
            }
            
            // Fechas
            document.getElementById('det_created_at').textContent = 
                new Date(c.created_at).toLocaleString('es-MX') || '-';
            document.getElementById('det_updated_at').textContent = 
                new Date(c.updated_at).toLocaleString('es-MX') || '-';
            
            // Notificaciones
            detClienteId = c.id;
            document.getElementById('det_notif_conf').checked = parseInt(c.notif_confirmacion ?? 1) === 1;
            document.getElementById('det_notif_fact').checked = parseInt(c.notif_factura ?? 1) === 1;

            // Link a editar
            document.getElementById('btnEditarDatos').href = `../mis-datos.php?telefono=${c.telefono}`;
            
            // Mostrar modal
            document.getElementById('modalDetalles').classList.remove('hidden');
        } else {
            mostrarAlerta('Error al cargar los detalles', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        mostrarAlerta('Error al cargar los detalles', 'error');
    });
}

function cerrarModalDetalles() {
    document.getElementById('modalDetalles').classList.add('hidden');
}

function eliminarCliente(id) {
    if (confirm('¿Estás seguro de eliminar este cliente? Se eliminarán también sus pedidos.')) {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        fetch('clientes.php', {
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

const _clPag = new Paginator({
    rows:   () => document.querySelectorAll('.cliente-row'),
    bar:    '#cl-pag-bar',
    info:   '#cl-pag-info',
    ctrl:   '#cl-pag-ctrl',
    sizeEl: '#cl-pag-size',
    unit:   'cliente', units: 'clientes',
});

function filtrarClientes() {
    const search = document.getElementById('searchClientes').value.toLowerCase();
    _clPag.filter(row => {
        const id       = row.dataset.id.toLowerCase();
        const telefono = row.dataset.telefono.toLowerCase();
        const nombre   = row.dataset.nombre.toLowerCase();
        return id.includes(search) || telefono.includes(search) || nombre.includes(search);
    });
}

document.addEventListener('DOMContentLoaded', () => _clPag.apply(Array.from(document.querySelectorAll('.cliente-row'))));

function saveNotif() {
    if (!detClienteId) return;
    const body = new URLSearchParams({
        action: 'update_notif',
        id: detClienteId,
        notif_confirmacion: document.getElementById('det_notif_conf').checked ? 1 : 0,
        notif_factura:      document.getElementById('det_notif_fact').checked ? 1 : 0
    });
    fetch('clientes.php', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            mostrarAlerta(d.message, d.success ? 'success' : 'error');
            if (d.success) setTimeout(() => location.reload(), 800);
        })
        .catch(() => mostrarAlerta('Error de red', 'error'));
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalDetalles').addEventListener('click', cerrarModalDetalles);
</script>

</body>
</html>
