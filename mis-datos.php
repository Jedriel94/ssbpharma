<?php
session_start();
require_once __DIR__ . '/models/Cliente.php';

$clienteModel = new Cliente();

// Procesar AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'verificar_password':
            $telefono = $_POST['telefono'] ?? '';
            $password = $_POST['password'] ?? '';
            
            $cliente = $clienteModel->getByTelefono($telefono);
            
            if ($cliente && password_verify($password, $cliente['password'])) {
                $_SESSION['cliente_verificado'] = $telefono;
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta']);
            }
            exit;
            
        case 'actualizar_datos':
            $telefono = $_POST['telefono'] ?? '';
            $nombre = trim($_POST['nombre'] ?? '');
            $calle = trim($_POST['calle'] ?? '');
            $numero = trim($_POST['numero'] ?? '');
            $colonia = trim($_POST['colonia'] ?? '');
            $cp = trim($_POST['cp'] ?? '');
            $estado = trim($_POST['estado'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $referencias = trim($_POST['referencias'] ?? '');
            $quien_recibe = trim($_POST['quien_recibe'] ?? '');
            $nombre_medico = trim($_POST['nombre_medico'] ?? '');
            $telefono_medico = trim($_POST['telefono_medico'] ?? '');
            $nombre_representante = trim($_POST['nombre_representante'] ?? '');
            $rfc = strtoupper(trim($_POST['rfc'] ?? ''));
            $razon_social = trim($_POST['razon_social'] ?? '');
            $email_factura = trim($_POST['email_factura'] ?? '');
            $codigo_postal = trim($_POST['codigo_postal'] ?? '');
            $regimen = trim($_POST['regimen'] ?? '');
            $uso_cfdi = trim($_POST['uso_cfdi'] ?? '');
            $regimen_fiscal = trim($_POST['regimen_fiscal'] ?? '');
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            
            if (empty($telefono) || strlen($telefono) != 10) {
                echo json_encode(['success' => false, 'message' => 'Teléfono no válido']);
                exit;
            }
            
            $password_hash = null;
            if (!empty($password)) {
                if ($password !== $password_confirm) {
                    echo json_encode(['success' => false, 'message' => 'Las contraseñas no coinciden']);
                    exit;
                }
                if (strlen($password) < 4) {
                    echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 4 caracteres']);
                    exit;
                }
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
            }
            
            $constancia_fiscal = null;
            if (isset($_FILES['constancia_fiscal']) && $_FILES['constancia_fiscal']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['constancia_fiscal'];
                $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
                $maxSize = 5 * 1024 * 1024;
                
                if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= $maxSize && in_array($file['type'], $allowedTypes)) {
                    $uploadDir = uploads_dir_privado('fiscales') . '/';
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $constancia_fiscal = 'constancia_' . $telefono . '_' . time() . '.' . $extension;
                    move_uploaded_file($file['tmp_name'], $uploadDir . $constancia_fiscal);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al subir archivo']);
                    exit;
                }
            } else {
                $cliente = $clienteModel->getByTelefono($telefono);
                $constancia_fiscal = $cliente['constancia_fiscal'] ?? null;
            }
            
            if ($clienteModel->updateDatos(
                $telefono, 
                $nombre, 
                // Datos de Envío
                $calle, $numero, $colonia, $cp, $estado, $ciudad, $referencias, $quien_recibe,
                // Datos Médicos
                $nombre_medico, $telefono_medico, $nombre_representante,
                // Datos Fiscales (en el orden correcto)
                $rfc, 
                $razon_social,
                $email_factura,
                $codigo_postal,
                null,  // empresa (eliminado, se usa razon_social)
                $regimen, 
                $uso_cfdi,
                $regimen_fiscal,
                // Archivos
                $constancia_fiscal,
                // Password
                $password_hash
            )) {
                echo json_encode(['success' => true, 'message' => 'Datos actualizados exitosamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar datos']);
            }
            exit;
    }
}

$telefono = $_GET['telefono'] ?? '';
$mostrarFormulario = false;
$requierePassword = false;
$cliente = null;

if (!empty($telefono) && is_numeric($telefono) && strlen($telefono) == 10) {
    $cliente = $clienteModel->getOrCreate($telefono);
    $requierePassword = !empty($cliente['password']);
    
    if ($requierePassword) {
        if (isset($_SESSION['cliente_verificado']) && $_SESSION['cliente_verificado'] === $telefono) {
            $mostrarFormulario = true;
        } else {
            $mostrarFormulario = false;
        }
    } else {
        $mostrarFormulario = true;
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['cliente_verificado']);
    header('Location: mis-datos.php');
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<div class="w-full px-4 py-8 max-w-4xl mx-auto">
    
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-slate-900 mb-2">Mis Datos</h1>
        <p class="text-slate-600">Ingresa tu teléfono para ver o actualizar tu información</p>
    </div>

    <?php if (empty($telefono)): ?>
        <div class="card rounded-2xl shadow-lg p-8">
            <form method="GET" action="mis-datos.php">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Número de Teléfono <span class="text-red-500">*</span>
                    </label>
                    <input type="tel" name="telefono" required pattern="[0-9]{10}" maxlength="10"
                           class="input-field w-full px-4 py-3 rounded-xl" placeholder="Ejemplo: 5551234567"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <p class="text-xs text-slate-500 mt-1">Ingresa tu teléfono a 10 dígitos</p>
                </div>
                <button type="submit" class="btn-primary w-full text-white py-3 rounded-xl font-medium">
                    Continuar →
                </button>
            </form>
        </div>
        
    <?php elseif (!$mostrarFormulario && $requierePassword): ?>
        <div class="card rounded-2xl shadow-lg p-8">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-terracotta-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="text-3xl"></span>
                </div>
                <h2 class="text-2xl font-bold text-slate-900 mb-2">Verificación Requerida</h2>
                <p class="text-slate-600">Este teléfono está protegido con contraseña</p>
                <p class="text-sm text-terracotta-600 mt-2"><?= htmlspecialchars($telefono) ?></p>
            </div>
            <form id="formPassword" onsubmit="verificarPassword(event)">
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Contraseña</label>
                    <input type="password" id="input_password" required
                           class="input-field w-full px-4 py-3 rounded-xl" placeholder="Ingresa tu contraseña">
                </div>
                <div class="flex gap-3">
                    <a href="mis-datos.php" class="flex-1 text-center bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300">
                        ← Volver
                    </a>
                    <button type="submit" class="flex-1 btn-primary text-white py-3 rounded-xl font-medium">
                        Verificar
                    </button>
                </div>
            </form>
        </div>
        
    <?php elseif ($mostrarFormulario && $cliente): ?>
        <div class="card rounded-2xl shadow-lg p-8">
            <div class="flex justify-between items-center mb-6 pb-4 border-b">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Información del Cliente</h2>
                    <p class="text-sm text-terracotta-600"><?= htmlspecialchars($telefono) ?></p>
                </div>
                <?php if ($requierePassword): ?>
                    <a href="?logout=1" class="text-sm text-slate-500 hover:text-red-600">Cerrar Sesión</a>
                <?php endif; ?>
            </div>
            <form id="formDatos" onsubmit="actualizarDatos(event)" enctype="multipart/form-data">
                <input type="hidden" name="telefono" value="<?= htmlspecialchars($telefono) ?>">
                
                <!-- DATOS BÁSICOS -->
                <div class="mb-8 pb-8 border-b border-slate-200">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <span class="w-8 h-8 bg-terracotta-400 text-white rounded-full flex items-center justify-center mr-3 text-sm">1</span>
                        Datos Básicos
                    </h3>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-2">Teléfono</label>
                        <input type="tel" value="<?= htmlspecialchars($cliente['telefono']) ?>" disabled
                               class="input-field w-full px-4 py-3 rounded-xl bg-slate-100 text-slate-500">
                        <p class="text-xs text-slate-500 mt-1">El teléfono no se puede modificar</p>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-2">Nombre Completo</label>
                        <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($cliente['nombre'] ?? '') ?>"
                               class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre del cliente">
                    </div>
                </div>
                
                <!-- DATOS DE ENVÍO -->
                <div class="mb-8 pb-8 border-b border-slate-200">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <span class="w-8 h-8 bg-sage-400 text-white rounded-full flex items-center justify-center mr-3 text-sm">2</span>
                        Datos de Envío
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-2">Calle</label>
                            <input type="text" id="calle" name="calle" value="<?= htmlspecialchars($cliente['calle'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre de la calle">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Número</label>
                            <input type="text" id="numero" name="numero" value="<?= htmlspecialchars($cliente['numero'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Ej: 123 o 45-A">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Colonia</label>
                            <input type="text" id="colonia" name="colonia" value="<?= htmlspecialchars($cliente['colonia'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre de la colonia">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Código Postal</label>
                            <input type="text" id="cp" name="cp" value="<?= htmlspecialchars($cliente['cp'] ?? '') ?>"
                                   maxlength="5" class="input-field w-full px-4 py-3 rounded-xl" placeholder="12345"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Estado</label>
                            <select id="estado" name="estado" class="input-field w-full px-4 py-3 rounded-xl">
                                <option value="">Selecciona un estado</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Municipio / Alcaldía</label>
                            <select id="ciudad" name="ciudad" class="input-field w-full px-4 py-3 rounded-xl" disabled>
                                <option value="">— Primero selecciona un estado —</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Quien Recibe</label>
                            <input type="text" id="quien_recibe" name="quien_recibe" value="<?= htmlspecialchars($cliente['quien_recibe'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre de quien recibe">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-2">Referencias</label>
                            <textarea id="referencias" name="referencias" rows="2"
                                      class="input-field w-full px-4 py-3 rounded-xl"
                                      placeholder="Referencias para encontrar el domicilio..."><?= htmlspecialchars($cliente['referencias'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- MÉDICO -->
                <div class="mb-8 pb-8 border-b border-slate-200">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <span class="w-8 h-8 bg-blue-400 text-white rounded-full flex items-center justify-center mr-3 text-sm">3</span>
                        Información Médica
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Nombre del Médico</label>
                            <input type="text" id="nombre_medico" name="nombre_medico" value="<?= htmlspecialchars($cliente['nombre_medico'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Dr. Juan Pérez">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">� Teléfono del Médico</label>
                            <input type="tel" id="telefono_medico" name="telefono_medico" value="<?= htmlspecialchars($cliente['telefono_medico'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="5512345678"
                                   maxlength="10"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">�Nombre del Representante</label>
                            <input type="text" id="nombre_representante" name="nombre_representante" value="<?= htmlspecialchars($cliente['nombre_representante'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="María García">
                        </div>
                    </div>
                </div>
                
                <!-- DATOS FISCALES -->
                <div class="mb-8 pb-8 border-b border-slate-200">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <span class="w-8 h-8 bg-purple-400 text-white rounded-full flex items-center justify-center mr-3 text-sm">4</span>
                        Datos Fiscales
                    </h3>
                    
                    <!-- DEBUG: Mostrar valores actuales -->
                    <?php if (!empty($cliente['rfc']) || !empty($cliente['razon_social'])): ?>
                    <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs">
                        <strong>Valores fiscales guardados:</strong>
                        <ul class="mt-2 space-y-1">
                            <li>� RFC: <strong><?= htmlspecialchars($cliente['rfc'] ?? 'No definido') ?></strong></li>
                            <li>�Razón Social: <strong><?= htmlspecialchars($cliente['razon_social'] ?? 'No definido') ?></strong></li>
                            <li>Email Factura: <strong><?= htmlspecialchars($cliente['email_factura'] ?? 'No definido') ?></strong></li>
                            <li>CP Fiscal: <strong><?= htmlspecialchars($cliente['codigo_postal'] ?? 'No definido') ?></strong></li>
                            <li>Régimen: <strong><?= htmlspecialchars($cliente['regimen'] ?? 'No definido') ?></strong></li>
                            <li>Régimen Fiscal: <strong><?= htmlspecialchars($cliente['regimen_fiscal'] ?? 'No definido') ?></strong></li>
                            <li>Uso CFDI: <strong><?= htmlspecialchars($cliente['uso_cfdi'] ?? 'No definido') ?></strong></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">RFC</label>
                            <input type="text" id="rfc" name="rfc" value="<?= htmlspecialchars($cliente['rfc'] ?? '') ?>"
                                   maxlength="13" class="input-field w-full px-4 py-3 rounded-xl uppercase"
                                   placeholder="XAXX010101000" 
                                   oninput="this.value = this.value.toUpperCase(); actualizarRegimenesFiscales()">
                            <p class="text-xs text-slate-500 mt-1" id="rfc-hint">12 caracteres = Persona Moral, 13 = Persona Física</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Razón Social</label>
                            <input type="text" id="razon_social" name="razon_social" value="<?= htmlspecialchars($cliente['razon_social'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre completo o razón social">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Email para Factura</label>
                            <input type="email" id="email_factura" name="email_factura" value="<?= htmlspecialchars($cliente['email_factura'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="correo@ejemplo.com"
                                   autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Código Postal (Fiscal)</label>
                            <input type="text" id="codigo_postal" name="codigo_postal" value="<?= htmlspecialchars($cliente['codigo_postal'] ?? '') ?>"
                                   maxlength="5" class="input-field w-full px-4 py-3 rounded-xl" placeholder="12345"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            <p class="text-xs text-slate-500 mt-1">CP del domicilio fiscal (puede ser diferente al de envío)</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Régimen Fiscal</label>
                            <select id="regimen_fiscal" name="regimen_fiscal" class="input-field w-full px-4 py-3 rounded-xl" data-valor-guardado="<?= htmlspecialchars($cliente['regimen_fiscal'] ?? '') ?>">
                                <option value="">Primero ingresa tu RFC</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1" id="regimen-hint">El RFC determina los regímenes disponibles</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Uso de CFDI</label>
                            <select id="uso_cfdi" name="uso_cfdi" class="input-field w-full px-4 py-3 rounded-xl">
                                <option value="">Selecciona un uso</option>
                                <?php
                                $usos_cfdi = [
                                    'G01' => 'G01 - Adquisición de mercancías', 'G02' => 'G02 - Devoluciones, descuentos',
                                    'G03' => 'G03 - Gastos en general', 'I01' => 'I01 - Construcciones',
                                    'I02' => 'I02 - Mobiliario y equipo', 'I03' => 'I03 - Equipo de transporte',
                                    'I04' => 'I04 - Equipo de cómputo', 'D01' => 'D01 - Honorarios médicos',
                                    'D02' => 'D02 - Gastos médicos', 'D10' => 'D10 - Servicios educativos',
                                    'S01' => 'S01 - Sin efectos fiscales', 'CP01' => 'CP01 - Pagos'
                                ];
                                foreach ($usos_cfdi as $clave => $desc) {
                                    $selected = ($cliente['uso_cfdi'] ?? '') === $clave ? 'selected' : '';
                                    echo "<option value=\"$clave\" $selected>$desc</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-2">Constancia Fiscal</label>
                            <?php if (!empty($cliente['constancia_fiscal'])): ?>
                                <div class="mb-3 p-3 bg-green-50 border border-green-200 rounded-xl text-sm">
                                    <span class="text-green-600">Archivo actual: </span>
                                    <a href="descargar-fiscal.php?file=<?= urlencode($cliente['constancia_fiscal']) ?>" 
                                       target="_blank" class="text-terracotta-600 hover:underline">
                                        <?= htmlspecialchars($cliente['constancia_fiscal']) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="constancia_fiscal" accept=".pdf,.jpg,.jpeg,.png"
                                   class="input-field w-full px-4 py-3 rounded-xl">
                            <p class="text-xs text-slate-500 mt-1">
                                Formatos: PDF, JPG, PNG (Máx. 5MB)
                                <?= !empty($cliente['constancia_fiscal']) ? ' - Subir nuevo reemplazará el actual' : '' ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- SEGURIDAD -->
                <div class="mb-8">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <span class="w-8 h-8 bg-slate-400 text-white rounded-full flex items-center justify-center mr-3 text-sm"></span>
                        Seguridad
                    </h3>
                    
                    <?php if ($requierePassword): ?>
                        <!-- Ya tiene contraseña configurada -->
                        <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-4">
                            <p class="text-sm text-green-800">
                                <strong>Contraseña Configurada:</strong> Tu cuenta está protegida con contraseña.
                            </p>
                            <p class="text-xs text-green-700 mt-1">
                                Deja los campos vacíos si no deseas cambiarla.
                            </p>
                        </div>
                        
                        <div class="mb-4">
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <input type="checkbox" 
                                       id="checkCambiarPassword" 
                                       onchange="toggleCambiarPassword()"
                                       class="w-5 h-5 text-terracotta-500 rounded focus:ring-2 focus:ring-terracotta-500">
                                <span class="text-slate-900 font-semibold group-hover:text-terracotta-600 transition">
                                    Deseo cambiar mi contraseña
                                </span>
                            </label>
                        </div>
                        
                        <div id="camposPassword" class="hidden">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Nueva Contraseña</label>
                                    <input type="password" id="password" name="password" value=""
                                           class="input-field w-full px-4 py-3 rounded-xl"
                                           placeholder="Mínimo 4 caracteres"
                                           autocomplete="new-password">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Confirmar Contraseña</label>
                                    <input type="password" id="password_confirm" name="password_confirm" value=""
                                           class="input-field w-full px-4 py-3 rounded-xl" placeholder="Repite la contraseña"
                                           autocomplete="new-password">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- No tiene contraseña -->
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
                            <p class="text-sm text-amber-800">
                                <strong>Consejo:</strong> Configura una contraseña para proteger tus datos.
                            </p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Nueva Contraseña</label>
                                <input type="password" id="password" name="password" value=""
                                       class="input-field w-full px-4 py-3 rounded-xl"
                                       placeholder="Mínimo 4 caracteres"
                                       autocomplete="new-password">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Confirmar Contraseña</label>
                                <input type="password" id="password_confirm" name="password_confirm" value=""
                                       class="input-field w-full px-4 py-3 rounded-xl" placeholder="Repite la contraseña"
                                       autocomplete="new-password">
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex gap-3">
                    <a href="index.php" class="flex-1 text-center bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300">
                        ← Volver al Inicio
                    </a>
                    <button type="submit" class="flex-1 btn-primary text-white py-3 rounded-xl font-medium">
                        Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<script>
// Regímenes Fiscales por tipo de persona
const regimenesFiscales = {
    personaMoral: [
        { clave: '601', nombre: '601 - General de Ley Personas Morales' },
        { clave: '603', nombre: '603 - Personas Morales con Fines no Lucrativos' },
        { clave: '607', nombre: '607 - Régimen de Enajenación o Adquisición de Bienes' },
        { clave: '608', nombre: '608 - Demás ingresos' },
        { clave: '610', nombre: '610 - Residentes en el Extranjero sin Establecimiento Permanente en México' },
        { clave: '620', nombre: '620 - Sociedades Cooperativas de Producción que optan por diferir sus ingresos' },
        { clave: '622', nombre: '622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras' },
        { clave: '623', nombre: '623 - Opcional para Grupos de Sociedades' },
        { clave: '624', nombre: '624 - Coordinados' },
        { clave: '628', nombre: '628 - Hidrocarburos' },
        { clave: '607', nombre: '607 - Régimen de Enajenación o Adquisición de Bienes' }
    ],
    personaFisica: [
        { clave: '605', nombre: '605 - Sueldos y Salarios e Ingresos Asimilados a Salarios' },
        { clave: '606', nombre: '606 - Arrendamiento' },
        { clave: '608', nombre: '608 - Demás ingresos' },
        { clave: '609', nombre: '609 - Consolidación' },
        { clave: '610', nombre: '610 - Residentes en el Extranjero sin Establecimiento Permanente en México' },
        { clave: '611', nombre: '611 - Ingresos por Dividendos (socios y accionistas)' },
        { clave: '612', nombre: '612 - Personas Físicas con Actividades Empresariales y Profesionales' },
        { clave: '614', nombre: '614 - Ingresos por intereses' },
        { clave: '615', nombre: '615 - Régimen de los ingresos por obtención de premios' },
        { clave: '616', nombre: '616 - Sin obligaciones fiscales' },
        { clave: '621', nombre: '621 - Incorporación Fiscal' },
        { clave: '622', nombre: '622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras' },
        { clave: '623', nombre: '623 - Opcional para Grupos de Sociedades' },
        { clave: '624', nombre: '624 - Coordinados' },
        { clave: '625', nombre: '625 - Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas' },
        { clave: '626', nombre: '626 - Régimen Simplificado de Confianza' }
    ]
};

function actualizarRegimenesFiscales() {
    const rfcInput = document.getElementById('rfc');
    const regimenSelect = document.getElementById('regimen_fiscal');
    const rfcHint = document.getElementById('rfc-hint');
    const regimenHint = document.getElementById('regimen-hint');
    
    const rfc = rfcInput.value.trim();
    const rfcLength = rfc.length;
    
    // Guardar valor actual o el valor guardado del data-attribute
    const valorActual = regimenSelect.value || regimenSelect.dataset.valorGuardado;
    
    // Limpiar opciones
    regimenSelect.innerHTML = '<option value="">Selecciona un régimen</option>';
    
    if (rfcLength === 12) {
        // Persona Moral
        rfcHint.textContent = 'RFC de Persona Moral (12 caracteres)';
        rfcHint.className = 'text-xs text-green-600 mt-1';
        regimenHint.textContent = 'Regímenes para Persona Moral';
        regimenHint.className = 'text-xs text-green-600 mt-1';
        
        regimenesFiscales.personaMoral.forEach(reg => {
            const option = document.createElement('option');
            option.value = reg.clave;
            option.textContent = reg.nombre;
            if (valorActual === reg.clave) option.selected = true;
            regimenSelect.appendChild(option);
        });
    } else if (rfcLength === 13) {
        // Persona Física
        rfcHint.textContent = 'RFC de Persona Física (13 caracteres)';
        rfcHint.className = 'text-xs text-blue-600 mt-1';
        regimenHint.textContent = 'Regímenes para Persona Física';
        regimenHint.className = 'text-xs text-blue-600 mt-1';
        
        regimenesFiscales.personaFisica.forEach(reg => {
            const option = document.createElement('option');
            option.value = reg.clave;
            option.textContent = reg.nombre;
            if (valorActual === reg.clave) option.selected = true;
            regimenSelect.appendChild(option);
        });
    } else {
        // RFC incompleto o inválido
        rfcHint.textContent = '12 caracteres = Persona Moral, 13 = Persona Física';
        rfcHint.className = 'text-xs text-slate-500 mt-1';
        regimenHint.textContent = 'El RFC determina los regímenes disponibles';
        regimenHint.className = 'text-xs text-slate-500 mt-1';
    }
}

// Cargar regímenes al iniciar si ya hay RFC
document.addEventListener('DOMContentLoaded', function() {
    const rfcInput = document.getElementById('rfc');
    if (rfcInput && rfcInput.value.trim().length >= 12) {
        actualizarRegimenesFiscales();
    }
});

function toggleCambiarPassword() {
    const checkbox = document.getElementById('checkCambiarPassword');
    const camposPassword = document.getElementById('camposPassword');
    const inputPassword = document.getElementById('password');
    const inputConfirm = document.getElementById('password_confirm');
    
    if (checkbox.checked) {
        // Mostrar campos
        camposPassword.classList.remove('hidden');
    } else {
        // Ocultar campos y limpiar valores
        camposPassword.classList.add('hidden');
        inputPassword.value = '';
        inputConfirm.value = '';
    }
}

function verificarPassword(e) {
    e.preventDefault();
    const formData = new FormData();
    formData.append('action', 'verificar_password');
    formData.append('telefono', '<?= htmlspecialchars($telefono) ?>');
    formData.append('password', document.getElementById('input_password').value);
    
    fetch('mis-datos.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            mostrarAlerta(data.message, 'error');
        }
    });
}

function actualizarDatos(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'actualizar_datos');
    
    const btn = e.target.querySelector('button[type="submit"]');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Guardando...';
    
    fetch('mis-datos.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        mostrarAlerta(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.disabled = false;
            btn.innerHTML = btnText;
        }
    });
}
</script>
<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initUbicaciones({
        selectEstado:    '#estado',
        selectMunicipio: '#ciudad',
        valorEstado:     <?= json_encode($cliente['estado'] ?? '') ?>,
        valorMunicipio:  <?= json_encode($cliente['ciudad'] ?? '') ?>,
        basePath:        '<?= BASE_PATH ?>',
    });
});
</script>

</body>
</html>
