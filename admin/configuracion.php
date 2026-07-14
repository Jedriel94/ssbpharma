<?php
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Configuracion.php';

// Claves que son booleanas (toggle on/off)
$CLAVES_BOOLEANAS = ['mostrar_stock'];

// Claves que son texto libre
$CLAVES_TEXTO = ['nombre_tienda', 'terminos_condiciones_url', 'aviso_privacidad_url'];

// Claves tipo selector (dropdown con opciones fijas)
$CLAVES_SELECTOR = [
    'dashboard_estado_ventas' => [
        'opciones'    => ['entregado', 'en_ruta', 'confirmado'],
        'etiquetas'   => [
            'entregado'  => 'Solo Entregado',
            'en_ruta'    => 'En Ruta o Entregado',
            'confirmado' => 'Confirmado, En Ruta o Entregado',
        ],
        'icono'       => '',
        'descripcion' => 'Estado mínimo de pedido para considerar una venta en el dashboard',
    ],
];

// Claves de configuración email (gestionadas por separado)
$CLAVES_EMAIL = [
    'email_smtp_host', 'email_smtp_port', 'email_smtp_user', 'email_smtp_pass',
    'email_from_nombre', 'email_from_email',
    'email_confirmacion_asunto', 'email_confirmacion_cuerpo',
    'email_solicitud_inventario_asunto', 'email_solicitud_inventario_cuerpo',
    'activar_envio_correo', 'correo_prueba',
    'email_solicitudes_activo', 'email_prueba_destinatario',
    'correo_copia_solicitudes', 'correo_notif_por_verificar',
];

// Procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // ── Especialidades CRUD ───────────────────────────────────────────────
    $action_esp = $_POST['action'] ?? '';

    if ($action_esp === 'esp_crear') {
        $nombre = trim(strip_tags($_POST['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            echo json_encode(['success' => false, 'message' => 'Nombre inválido']); exit;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $existe = $db->prepare("SELECT id FROM especialidades WHERE nombre = ? LIMIT 1");
            $existe->execute([$nombre]);
            if ($existe->fetch()) { echo json_encode(['success' => false, 'message' => 'Ya existe esa especialidad']); exit; }
            $max = $db->query("SELECT COALESCE(MAX(orden),0)+1 FROM especialidades")->fetchColumn();
            $db->prepare("INSERT INTO especialidades (nombre, orden) VALUES (?,?)")->execute([$nombre, $max]);
            echo json_encode(['success' => true, 'message' => 'Especialidad creada', 'id' => $db->lastInsertId(), 'nombre' => $nombre, 'orden' => $max]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    if ($action_esp === 'esp_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); exit; }
        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare("UPDATE especialidades SET activo = 1 - activo WHERE id = ?")->execute([$id]);
            $activo = $db->prepare("SELECT activo FROM especialidades WHERE id = ?");
            $activo->execute([$id]);
            echo json_encode(['success' => true, 'activo' => (int)$activo->fetchColumn()]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    if ($action_esp === 'esp_promover') {
        $nombre = trim(strip_tags($_POST['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2) { echo json_encode(['success' => false, 'message' => 'Nombre inválido']); exit; }
        try {
            $db = Database::getInstance()->getConnection();
            $existe = $db->prepare("SELECT id FROM especialidades WHERE nombre = ? LIMIT 1");
            $existe->execute([$nombre]);
            if ($existe->fetch()) { echo json_encode(['success' => false, 'message' => 'Ya existe']); exit; }
            $max = $db->query("SELECT COALESCE(MAX(orden),0)+1 FROM especialidades")->fetchColumn();
            $db->prepare("INSERT INTO especialidades (nombre, orden) VALUES (?,?)")->execute([$nombre, $max]);
            echo json_encode(['success' => true, 'message' => "\"$nombre\" agregada como especialidad oficial"]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // Acción especial: guardar config de email
    if (($_POST['action'] ?? '') === 'email_config') {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE configuracion SET valor = :valor WHERE clave = :clave");
            // Correo de prueba y switch de envío
            $correo_prueba_val = trim($_POST['correo_prueba'] ?? '');
            if (!empty($correo_prueba_val) && !filter_var($correo_prueba_val, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El correo de prueba no es válido');
            }
            $correo_copia_val = trim($_POST['correo_copia_solicitudes'] ?? '');
            if (!empty($correo_copia_val) && !filter_var($correo_copia_val, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El correo de copia de solicitudes no es válido');
            }
            $correo_pv_val = trim($_POST['correo_notif_por_verificar'] ?? '');
            if (!empty($correo_pv_val) && !filter_var($correo_pv_val, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El correo de notificación por verificar no es válido');
            }
            $campos = [
                'activar_envio_correo'      => isset($_POST['activar_envio_correo']) ? 1 : 0,
                'email_solicitudes_activo'  => isset($_POST['email_solicitudes_activo']) ? 1 : 0,
                'correo_prueba'             => $correo_prueba_val,
                'correo_copia_solicitudes'  => $correo_copia_val,
                'correo_notif_por_verificar' => $correo_pv_val,
                'email_smtp_host'           => trim($_POST['email_smtp_host'] ?? ''),
                'email_smtp_port'           => intval($_POST['email_smtp_port'] ?? 587),
                'email_smtp_user'           => trim($_POST['email_smtp_user'] ?? ''),
                'email_smtp_pass'           => $_POST['email_smtp_pass'] ?? '',
                'email_from_nombre'         => trim(strip_tags($_POST['email_from_nombre'] ?? '')),
                'email_from_email'          => trim($_POST['email_from_email'] ?? ''),
                'email_confirmacion_asunto' => trim(strip_tags($_POST['email_confirmacion_asunto'] ?? '')),
                'email_confirmacion_cuerpo' => trim($_POST['email_confirmacion_cuerpo'] ?? ''),
                'email_solicitud_inventario_asunto' => trim(strip_tags($_POST['email_solicitud_inventario_asunto'] ?? '')),
                'email_solicitud_inventario_cuerpo' => trim($_POST['email_solicitud_inventario_cuerpo'] ?? ''),
            ];
            // Validar email from
            if (!empty($campos['email_from_email']) && !filter_var($campos['email_from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El correo remitente no es válido');
            }
            foreach ($campos as $clave => $valor) {
                $stmt->execute([':clave' => $clave, ':valor' => $valor]);
            }
            Configuracion::clearCache();
            echo json_encode(['success' => true, 'message' => 'Configuración de correo guardada']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    try {
        $clave = $_POST['clave'] ?? '';
        
        if (empty($clave)) {
            throw new Exception('Clave no válida');
        }

        if (in_array($clave, $CLAVES_TEXTO)) {
            $valor = trim(strip_tags($_POST['valor'] ?? ''));
            if (empty($valor)) {
                throw new Exception('El valor no puede estar vacío');
            }
            if ($clave === 'nombre_tienda' && mb_strlen($valor) > 100) {
                throw new Exception('El nombre no puede exceder 100 caracteres');
            }
            if (in_array($clave, ['terminos_condiciones_url', 'aviso_privacidad_url'], true)) {
                if (mb_strlen($valor) > 255) {
                    throw new Exception('La URL no puede exceder 255 caracteres');
                }
                if (!filter_var($valor, FILTER_VALIDATE_URL)) {
                    throw new Exception('La URL no es válida');
                }
            }
        } elseif (array_key_exists($clave, $CLAVES_SELECTOR)) {
            $valor = trim($_POST['valor'] ?? '');
            if (!in_array($valor, $CLAVES_SELECTOR[$clave]['opciones'], true)) {
                throw new Exception('Opción no válida');
            }
        } else {
            $valor = floatval($_POST['valor'] ?? 0);
            if ($valor < 0) {
                throw new Exception('El valor debe ser mayor o igual a 0');
            }
        }
        
        $success = Configuracion::set($clave, $valor);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Configuración actualizada correctamente'
            ]);
        } else {
            throw new Exception('No se pudo actualizar la configuración');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Asegurar que existan las claves selector en la BD (INSERT IGNORE)
$_db_cfg = Database::getInstance()->getConnection();
foreach ($CLAVES_SELECTOR as $clave_sel => $meta_sel) {
    $_db_cfg->prepare(
        "INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES (?, ?, ?)"
    )->execute([$clave_sel, $meta_sel['opciones'][0], $meta_sel['descripcion']]);
}
// Asegurar claves de control de correo
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('activar_envio_correo', '1', 'Activar envío de correos automáticos')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('correo_prueba', '', 'Correo de prueba (redirige todos los envíos a esta dirección)')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('email_solicitudes_activo', '1', 'Enviar correo al representante cuando crea una solicitud de inventario')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('correo_copia_solicitudes', '', 'Copia de solicitudes de inventario (CC al admin cuando un representante solicita)')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('correo_notif_por_verificar', '', 'Notificación interna cuando un pedido entra a Por Verificar')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('email_prueba_destinatario', '', 'Destinatario de prueba (alias de correo_prueba)')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('terminos_condiciones_url', '', 'URL pública de Términos y Condiciones')") ->execute();
$_db_cfg->prepare("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES ('aviso_privacidad_url', '', 'URL pública de Aviso de Privacidad')") ->execute();

// Obtener configuraciones (excluir claves de email y selector, gestionadas por separado)
$configuraciones = array_filter(Configuracion::getAll(), function($c) use ($CLAVES_EMAIL, $CLAVES_SELECTOR) {
    return !in_array($c['clave'], $CLAVES_EMAIL) && !array_key_exists($c['clave'], $CLAVES_SELECTOR);
});

// Cargar especialidades para la sección de admin
$_db_cfg = Database::getInstance()->getConnection();
$especialidades_admin = $_db_cfg->query("SELECT id, nombre, activo, orden FROM especialidades ORDER BY orden, nombre")->fetchAll(PDO::FETCH_ASSOC);
// "Otros" no promovidos: valores libres en clientes que no coinciden con ninguna especialidad registrada
$nombres_esp = array_column($especialidades_admin, 'nombre');
$placeholders = implode(',', array_fill(0, count($nombres_esp) ?: 1, '?'));
$stmt_otros = $_db_cfg->prepare("
    SELECT especialidad, COUNT(*) as total
    FROM clientes
    WHERE especialidad IS NOT NULL AND especialidad != ''
      AND especialidad NOT IN ($placeholders)
    GROUP BY especialidad
    ORDER BY total DESC, especialidad
");
$stmt_otros->execute($nombres_esp ?: ['__none__']);
$esp_otros = $stmt_otros->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include '../includes/header.php'; ?>

<div class="max-w-5xl mx-auto px-4 py-8">

    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-slate-800 mb-1">Configuración del Sistema</h1>
        <p class="text-sm" style="color:var(--text-secondary)">Parámetros generales, correo electrónico y especialidades médicas.</p>
    </div>
        
        <!-- Alert Container -->
        <div id="alertContainer" class="mb-6"></div>
        
        <!-- Configuraciones selector (Dashboard, etc.) -->
        <?php foreach ($CLAVES_SELECTOR as $clave_sel => $meta_sel):
            $valor_actual = Configuracion::get($clave_sel, $meta_sel['opciones'][0]);
        ?>
        <div class="card rounded-2xl shadow-lg p-6 mb-6">
            <div class="flex items-start justify-between mb-4">
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-slate-900 mb-1"><?= htmlspecialchars($meta_sel['descripcion']) ?></h3>
                    <p class="text-xs text-slate-500 font-mono"><?= htmlspecialchars($clave_sel) ?></p>
                </div>
                <span class="text-2xl"><?= $meta_sel['icono'] ?></span>
            </div>
            <form onsubmit="actualizarConfigSelector(event, '<?= htmlspecialchars($clave_sel) ?>')" class="flex items-center gap-3">
                <select name="valor" class="flex-1 px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition text-base font-semibold input-field">
                    <?php foreach ($meta_sel['etiquetas'] as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $valor_actual === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary px-6 py-3 rounded-xl font-semibold transition">
                    Guardar
                </button>
            </form>
            <p class="text-xs text-slate-400 mt-3">
                Define desde qué estado de pedido se contabilizan las ventas en el dashboard y reportes.
                "Confirmado" incluye más pedidos; "Entregado" solo los completamente cerrados.
            </p>
        </div>
        <?php endforeach; ?>

        <!-- Configuraciones -->
        <div class="grid gap-6 md:grid-cols-2">
            
            <?php foreach ($configuraciones as $config): ?>
            <?php
                $es_booleana = in_array($config['clave'], $CLAVES_BOOLEANAS);
                $es_texto    = in_array($config['clave'], $CLAVES_TEXTO);
            ?>
            <div class="card rounded-2xl shadow-lg p-6 <?= $es_texto ? 'md:col-span-2' : '' ?>">  
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-slate-900 mb-1">
                            <?php echo htmlspecialchars($config['descripcion']); ?>
                        </h3>
                        <p class="text-xs text-slate-500 font-mono">
                            <?php echo htmlspecialchars($config['clave']); ?>
                        </p>
                    </div>
                    <span class="text-2xl"><?php
                        if ($es_booleana) echo '';
                        elseif (in_array($config['clave'], ['terminos_condiciones_url', 'aviso_privacidad_url'], true)) echo '';
                        elseif ($es_texto) echo '';
                        elseif (in_array($config['clave'], ['carrusel_intervalo'])) echo '';
                        else echo '';
                    ?></span>
                </div>
                
                <?php if ($es_texto): ?>
                <!-- Texto libre -->
                <form onsubmit="actualizarConfigTexto(event, '<?php echo htmlspecialchars($config['clave']); ?>')" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Valor actual</label>
                        <input
                            type="text"
                            name="valor"
                            maxlength="100"
                            value="<?php echo htmlspecialchars($config['valor']); ?>"
                            class="w-full input-field px-4 py-3 rounded-xl text-lg font-semibold"
                            required
                        >
                    </div>
                    <button
                        type="submit"
                        class="w-full btn-primary py-3 rounded-xl font-semibold transition"
                    >
                        Guardar Cambios
                    </button>
                </form>
                <?php elseif ($es_booleana): ?>
                <!-- Toggle para configuración booleana -->
                <div class="flex items-center justify-between py-3 px-4 bg-slate-50 rounded-xl">
                    <span class="text-sm font-semibold text-slate-700">
                        <?php echo intval($config['valor']) ? 'Activado' : 'Desactivado'; ?>
                    </span>
                    <button
                        type="button"
                        onclick="toggleConfig('<?php echo htmlspecialchars($config['clave']); ?>', <?php echo intval($config['valor']); ?>)"
                        class="relative inline-flex h-8 w-16 items-center rounded-full transition-colors focus:outline-none <?php echo intval($config['valor']) ? 'bg-green-500' : 'bg-slate-300'; ?>"
                        id="toggle-<?php echo htmlspecialchars($config['clave']); ?>">
                        <span class="inline-block h-6 w-6 transform rounded-full bg-white shadow-md transition-transform <?php echo intval($config['valor']) ? 'translate-x-9' : 'translate-x-1'; ?>"></span>
                    </button>
                </div>
                <?php else: ?>
                <form onsubmit="actualizarConfig(event, '<?php echo htmlspecialchars($config['clave']); ?>')" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Valor actual</label>
                        <?php
                        $es_segundos = in_array($config['clave'], ['carrusel_intervalo']);
                        $es_numerico = is_numeric($config['valor']);
                        $prefijo = ($es_numerico && !$es_segundos) ? '$' : '';
                        $sufijo  = $es_segundos ? ' seg' : '';
                        $step    = $es_segundos ? '1' : '0.01';
                        $display = $es_numerico ? ($es_segundos ? number_format((float)$config['valor'], 0) : number_format((float)$config['valor'], 2, '.', '')) : $config['valor'];
                        ?>
                        <div class="relative">
                            <?php if ($prefijo): ?>
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold"><?php echo $prefijo; ?></span>
                            <?php endif; ?>
                            <input 
                                type="number" 
                                name="valor"
                                step="<?php echo $step; ?>"
                                min="<?php echo $es_segundos ? '1' : '0'; ?>"
                                value="<?php echo $display; ?>"
                                class="w-full input-field <?php echo $prefijo ? 'pl-8' : 'pl-4'; ?> <?php echo $sufijo ? 'pr-14' : 'pr-4'; ?> py-3 rounded-xl text-lg font-semibold"
                                required
                            >
                            <?php if ($sufijo): ?>
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"><?php echo $sufijo; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <button 
                        type="submit"
                        class="w-full btn-primary py-3 rounded-xl font-semibold transition"
                    >
                        Guardar Cambios
                    </button>
                </form>
                <?php endif; ?>
                
                <div class="mt-4 pt-4 border-t border-slate-200">
                    <p class="text-xs text-slate-500">
                        Última actualización: 
                        <span class="font-semibold">
                            <?php echo date('d/m/Y H:i', strtotime($config['fecha_actualizacion'])); ?>
                        </span>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
            
        </div>
        
        <!-- ================================================================
             SECCIÓN: CONFIGURACIÓN DE CORREO ELECTRÓNICO
        ================================================================ -->
        <div class="mt-10">
            <h2 class="text-2xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                Configuración de Correo Electrónico
            </h2>
            <p class="text-sm mb-6" style="color:var(--text-secondary)">
                Configura el servidor SMTP para enviar correos automáticos al confirmar pedidos y al subir facturas.
            </p>

            <?php
            $ec = [];
            foreach ($CLAVES_EMAIL as $k) $ec[$k] = Configuracion::get($k, '');
            ?>

            <form id="formEmailConfig" onsubmit="guardarEmailConfig(event)" class="space-y-6">

                <!-- Control de envío -->
                <div class="card rounded-2xl shadow-lg p-6">
                    <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">Control de envío</h3>
                    <div class="space-y-4">
                        <!-- Toggle activar envío -->
                        <div class="flex items-center justify-between py-3 px-4 rounded-xl" style="background:var(--bg-menu-item)">
                            <div>
                                <p class="text-sm font-semibold" style="color:var(--text-primary)">Activar envío de correos</p>
                                <p class="text-xs mt-0.5" style="color:var(--text-muted)">Si está desactivado, ningún correo automático se enviará</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="activar_envio_correo" id="chk_activar_correo"
                                       class="sr-only peer"
                                       <?= intval(Configuracion::get('activar_envio_correo', '1')) ? 'checked' : '' ?>>
                                <div class="w-14 h-7 rounded-full peer-checked:bg-green-500 bg-slate-300 transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:after:translate-x-7"></div>
                            </label>
                        </div>
                        <!-- Toggle enviar correo en solicitud de inventario -->
                        <div class="flex items-center justify-between py-3 px-4 rounded-xl" style="background:var(--bg-menu-item)">
                            <div>
                                <p class="text-sm font-semibold" style="color:var(--text-primary)">Enviar correo al representante cuando crea una solicitud de inventario</p>
                                <p class="text-xs mt-0.5" style="color:var(--text-muted)">Notifica al representante por correo al enviar una nueva solicitud</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="email_solicitudes_activo" id="chk_email_solicitudes"
                                       class="sr-only peer"
                                       <?= intval(Configuracion::get('email_solicitudes_activo', '1')) ? 'checked' : '' ?>>
                                <div class="w-14 h-7 rounded-full peer-checked:bg-green-500 bg-slate-300 transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:after:translate-x-7"></div>
                            </label>
                        </div>
                        <!-- Correo de prueba -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">
                                Correo de prueba
                                <span class="ml-2 text-xs font-normal text-slate-400">(opcional)</span>
                            </label>
                            <input type="email" name="correo_prueba"
                                   value="<?= htmlspecialchars(Configuracion::get('correo_prueba', '')) ?>"
                                   placeholder="Vacío = enviar al destinatario real"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                            <p class="text-xs text-slate-400 mt-1">
                                Si está configurado, todos los correos se redirigen a este destinatario de prueba
                            </p>
                        </div>
                        <!-- Correo copia solicitudes de inventario -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">
                                Correo copia — Solicitudes de inventario
                                <span class="ml-2 text-xs font-normal text-slate-400">(opcional)</span>
                            </label>
                            <input type="email" name="correo_copia_solicitudes"
                                   value="<?= htmlspecialchars(Configuracion::get('correo_copia_solicitudes', '')) ?>"
                                   placeholder="admin@empresa.com"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                            <p class="text-xs text-slate-400 mt-1">
                                Recibirá una copia (CC) cada vez que un representante envíe una solicitud de inventario
                            </p>
                        </div>
                        <!-- Notificación pedido por verificar -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">
                                Correo notificación — Pedido por verificar
                                <span class="ml-2 text-xs font-normal text-slate-400">(opcional)</span>
                            </label>
                            <input type="email" name="correo_notif_por_verificar"
                                   value="<?= htmlspecialchars(Configuracion::get('correo_notif_por_verificar', '')) ?>"
                                   placeholder="admin@empresa.com"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                            <p class="text-xs text-slate-400 mt-1">
                                Recibirá un aviso cada vez que un pedido entre al estado <strong>Por Verificar</strong>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- SMTP -->
                <div class="card rounded-2xl shadow-lg p-6">
                    <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">Servidor SMTP</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Host SMTP</label>
                            <input type="text" name="email_smtp_host"
                                   value="<?= htmlspecialchars($ec['email_smtp_host']) ?>"
                                   placeholder="mail.hostinger.com"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Puerto</label>
                            <input type="number" name="email_smtp_port"
                                   value="<?= htmlspecialchars($ec['email_smtp_port'] ?: '587') ?>"
                                   placeholder="587"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                            <p class="text-xs text-slate-400 mt-1">587 = TLS&nbsp;&nbsp;|&nbsp;&nbsp;465 = SSL</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Usuario SMTP</label>
                            <input type="email" name="email_smtp_user"
                                   value="<?= htmlspecialchars($ec['email_smtp_user']) ?>"
                                   placeholder="correo@tudominio.com"
                                   autocomplete="username"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Contraseña SMTP</label>
                            <div class="relative">
                                <input type="password" name="email_smtp_pass" id="email_smtp_pass"
                                       value="<?= htmlspecialchars($ec['email_smtp_pass']) ?>"
                                       autocomplete="current-password"
                                       class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition pr-12">
                                <button type="button" onclick="togglePass()" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 text-lg"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Remitente -->
                <div class="card rounded-2xl shadow-lg p-6">
                    <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">Datos del Remitente</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Nombre remitente</label>
                            <input type="text" name="email_from_nombre"
                                   value="<?= htmlspecialchars($ec['email_from_nombre'] ?: 'Solumedic Shop') ?>"
                                   placeholder="Solumedic Shop"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Correo remitente (From)</label>
                            <input type="email" name="email_from_email"
                                   value="<?= htmlspecialchars($ec['email_from_email']) ?>"
                                   placeholder="ventas@tudominio.com"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                        </div>
                    </div>
                </div>

                <!-- Plantilla confirmación -->
                <div class="card rounded-2xl shadow-lg p-6">
                    <h3 class="text-base font-bold text-slate-800 mb-1 flex items-center gap-2">Correo de Confirmación de Pedido</h3>
                    <p class="text-xs text-slate-500 mb-4">Se envía cuando el admin confirma el pedido en el Kanban. Variables disponibles: <code class="bg-slate-100 px-1 rounded">{nombre}</code>, <code class="bg-slate-100 px-1 rounded">{id}</code>, <code class="bg-slate-100 px-1 rounded">{total}</code>, <code class="bg-slate-100 px-1 rounded">{items}</code>, <code class="bg-slate-100 px-1 rounded">{tienda}</code></p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Asunto</label>
                            <input type="text" name="email_confirmacion_asunto"
                                   value="<?= htmlspecialchars($ec['email_confirmacion_asunto'] ?: 'Tu pedido #{id} fue confirmado') ?>"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Cuerpo del mensaje</label>
                            <textarea name="email_confirmacion_cuerpo" rows="5"
                                      class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition font-mono text-sm resize-y"><?= htmlspecialchars($ec['email_confirmacion_cuerpo']) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Plantilla solicitud inventario rep -->
                <div class="card rounded-2xl shadow-lg p-6">
                    <h3 class="text-base font-bold text-slate-800 mb-1 flex items-center gap-2">Correo de Solicitud de Inventario (Representante)</h3>
                    <p class="text-xs text-slate-500 mb-4">Se envía al representante cuando crea una nueva solicitud de inventario. Variables: <code class="bg-slate-100 px-1 rounded">{nombre}</code>, <code class="bg-slate-100 px-1 rounded">{id}</code>, <code class="bg-slate-100 px-1 rounded">{productos}</code>, <code class="bg-slate-100 px-1 rounded">{fecha}</code>, <code class="bg-slate-100 px-1 rounded">{tienda}</code></p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Asunto</label>
                            <input type="text" name="email_solicitud_inventario_asunto"
                                   value="<?= htmlspecialchars($ec['email_solicitud_inventario_asunto'] ?: 'Recibimos tu solicitud de inventario #{id}') ?>"
                                   class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Cuerpo del mensaje <span class="text-slate-400 font-normal">(dejar vacío usa la plantilla por defecto)</span></label>
                            <textarea name="email_solicitud_inventario_cuerpo" rows="5"
                                      class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 outline-none transition font-mono text-sm resize-y"><?= htmlspecialchars($ec['email_solicitud_inventario_cuerpo'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit"
                        class="w-full btn-primary py-3.5 rounded-xl font-semibold transition">
                    Guardar Configuración de Correo
                </button>
            </form>
        </div>

        <!-- Info Box -->
        <div class="mt-8 card border-l-4 border-blue-400 rounded-xl p-6">
            <div class="flex items-start gap-3">
                <span class="text-2xl">ℹ</span>
                <div>
                    <h4 class="font-bold text-blue-900 mb-2">Información importante</h4>
                    <ul class="text-sm text-blue-800 space-y-1">
                        <li>• <strong>Monto mínimo para envío gratis:</strong> Los pedidos que superen este monto no tendrán cargo por envío.</li>
                        <li>• <strong>Costo de envío:</strong> Se aplicará automáticamente a pedidos menores al monto mínimo.</li>                                <li>• <strong>Intervalo del carrusel:</strong> Segundos entre cada avance automático del carrusel en crear pedido.</li>
                    <li>• <strong>Mostrar stock:</strong> Activa o desactiva la línea "Stock: X" visible en la página de crear pedido.</li>
                    <li>• <strong>Nombre de la tienda:</strong> Nombre que se muestra a los clientes en el sitio, títulos y mensajes.</li>
                    <li>• <strong>Términos y Aviso:</strong> URLs que se muestran como enlaces en el checkbox de aceptación del pago.</li>
                    <li>• Los cambios se aplican inmediatamente en el sistema.</li>
                    </ul>
                </div>
            </div>
        </div>

</div><!-- /.max-w-5xl -->

<script>
    function toggleConfig(clave, valorActual) {
        const nuevoValor = valorActual ? 0 : 1;
        const formData = new FormData();
        formData.append('clave', clave);
        formData.append('valor', nuevoValor);

        fetch('configuracion.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarAlerta('' + data.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                mostrarAlerta('' + data.message, 'error');
            }
        })
        .catch(() => mostrarAlerta('Error al actualizar la configuración', 'error'));
    }

    function actualizarConfigSelector(event, clave) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('clave', clave);
        const btn = form.querySelector('button[type="submit"]');
        const btnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Guardando...';
        fetch('configuracion.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    mostrarAlerta('' + data.message, 'success');
                } else {
                    mostrarAlerta('' + (data.message || 'Error'), 'error');
                }
                btn.disabled = false;
                btn.innerHTML = btnText;
            })
            .catch(() => {
                mostrarAlerta('Error al guardar', 'error');
                btn.disabled = false;
                btn.innerHTML = btnText;
            });
    }

    function actualizarConfig(event, clave) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        formData.append('clave', clave);
        
        // Deshabilitar botón
        const btn = form.querySelector('button[type="submit"]');
        const btnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Guardando...';
        
        fetch('configuracion.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarAlerta('' + data.message, 'success');
                // Recargar página después de 1 segundo
                setTimeout(() => location.reload(), 1000);
            } else {
                mostrarAlerta('' + data.message, 'error');
                btn.disabled = false;
                btn.innerHTML = btnText;
            }
        })
        .catch(error => {
            mostrarAlerta('Error al actualizar la configuración', 'error');
            btn.disabled = false;
            btn.innerHTML = btnText;
        });
    }

    function actualizarConfigTexto(event, clave) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('clave', clave);

        const btn = form.querySelector('button[type="submit"]');
        const btnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Guardando...';

        fetch('configuracion.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarAlerta('' + data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                mostrarAlerta('' + data.message, 'error');
                btn.disabled = false;
                btn.innerHTML = btnText;
            }
        })
        .catch(() => {
            mostrarAlerta('Error al actualizar la configuración', 'error');
            btn.disabled = false;
            btn.innerHTML = btnText;
        });
    }
    
    function guardarEmailConfig(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('action', 'email_config');

        const btn = form.querySelector('button[type="submit"]');
        const btnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Guardando...';

        fetch('configuracion.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarAlerta('' + data.message, 'success');
            } else {
                mostrarAlerta('' + data.message, 'error');
            }
            btn.disabled = false;
            btn.innerHTML = btnText;
        })
        .catch(() => {
            mostrarAlerta('Error al guardar la configuración', 'error');
            btn.disabled = false;
            btn.innerHTML = btnText;
        });
    }

    function togglePass() {
        const input = document.getElementById('email_smtp_pass');
        input.type = input.type === 'password' ? 'text' : 'password';
    }

    function mostrarAlerta(mensaje, tipo) {
        const container = document.getElementById('alertContainer');
        const bgColor = tipo === 'success' ? 'bg-green-50 border-green-500 text-green-800' : 'bg-red-50 border-red-500 text-red-800';
        
        container.innerHTML = `
            <div class="${bgColor} border-l-4 rounded-xl p-4 shadow-lg animate-slide-down">
                <p class="font-semibold">${mensaje}</p>
            </div>
        `;
        
        // Auto-ocultar después de 5 segundos
        setTimeout(() => {
            container.innerHTML = '';
        }, 5000);
    }
    </script>
    
    <style>
    @keyframes slide-down {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .animate-slide-down {
        animation: slide-down 0.3s ease;
    }

    /* Email + especialidades inputs: heredan variables del tema */
    #formEmailConfig input[type="text"],
    #formEmailConfig input[type="email"],
    #formEmailConfig input[type="number"],
    #formEmailConfig input[type="password"],
    #formEmailConfig textarea,
    #esp-nueva-nombre {
        background: var(--bg-input);
        border: 1.5px solid var(--border-input);
        color: var(--text-primary);
        transition: border-color .2s ease;
    }
    #formEmailConfig input:focus,
    #formEmailConfig textarea:focus,
    #esp-nueva-nombre:focus {
        border-color: var(--border-focus);
        box-shadow: 0 0 0 3px var(--focus-ring);
        outline: none;
    }
    </style>
    <!-- ═══ Sección Especialidades ═══════════════════════════════════════ -->
    <section class="max-w-5xl mx-auto px-4 pb-12 mt-2">
        <h2 class="text-2xl font-bold text-slate-800 mb-6">Especialidades Médicas</h2>

        <div class="grid gap-6 md:grid-cols-2">

            <!-- Lista de especialidades -->
            <div class="card rounded-2xl shadow-lg p-6">
                <h3 class="font-bold text-slate-800 mb-4">Especialidades registradas</h3>
                <ul id="esp-lista" class="space-y-2 mb-4">
                    <?php foreach ($especialidades_admin as $esp): ?>
                    <li class="flex items-center justify-between gap-3 px-3 py-2 rounded-xl border <?= $esp['activo'] ? 'border-slate-200 bg-slate-50' : 'border-red-100 bg-red-50 opacity-60' ?>"
                        id="esp-row-<?= $esp['id'] ?>">
                        <span class="font-medium text-slate-800 text-sm"><?= htmlspecialchars($esp['nombre']) ?></span>
                        <button type="button"
                                onclick="toggleEsp(<?= $esp['id'] ?>, this)"
                                class="text-xs px-3 py-1 rounded-lg font-semibold transition <?= $esp['activo'] ? 'bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700' : 'bg-red-100 text-red-700 hover:bg-green-100 hover:text-green-700' ?>">
                            <?= $esp['activo'] ? 'Activa' : 'Inactiva' ?>
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Agregar nueva -->
                <form id="form-esp-nueva" onsubmit="crearEsp(event)" class="flex gap-2 mt-4">
                    <input type="text" id="esp-nueva-nombre" placeholder="Nueva especialidad..." maxlength="100"
                           class="flex-1 px-3 py-2 border-2 border-slate-200 rounded-xl text-sm focus:border-blue-400 outline-none">
                    <button type="submit" class="btn-primary px-4 py-2 rounded-xl text-sm font-semibold transition">
                        + Agregar
                    </button>
                </form>
            </div>

            <!-- Valores "Otro" capturados -->
            <div class="card rounded-2xl shadow-lg p-6">
                <h3 class="font-bold text-slate-800 mb-1">Especialidades capturadas como "Otro"</h3>
                <p class="text-xs text-slate-500 mb-4">Valores escritos libremente por representantes que aún no son especialidades oficiales.</p>
                <?php if (empty($esp_otros)): ?>
                    <p class="text-sm text-slate-400 italic">Sin valores pendientes.</p>
                <?php else: ?>
                    <ul id="esp-otros-lista" class="space-y-2">
                        <?php foreach ($esp_otros as $o): ?>
                        <li class="flex items-center justify-between gap-3 px-3 py-2 rounded-xl border border-amber-200 bg-amber-50" id="otro-row-<?= htmlspecialchars(base64_encode($o['especialidad'])) ?>">
                            <span class="text-sm font-medium text-slate-800 flex-1">
                                <?= htmlspecialchars($o['especialidad']) ?>
                                <span class="ml-2 text-xs text-slate-400">(<?= (int)$o['total'] ?> <?= $o['total'] == 1 ? 'vez' : 'veces' ?>)</span>
                            </span>
                            <button type="button"
                                    onclick="promoverEsp(<?= htmlspecialchars(json_encode($o['especialidad'])) ?>, this)"
                                    class="text-xs px-3 py-1 rounded-lg font-semibold bg-amber-400 hover:bg-green-500 hover:text-white text-amber-900 transition">
                                Promover
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </div>
    </section>

    <script>
    // ── Especialidades JS ────────────────────────────────────────────────
    async function toggleEsp(id, btn) {
        const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'esp_toggle', id }) });
        const data = await res.json();
        if (!data.success) { showToast(data.message, 'error'); return; }
        const row = document.getElementById('esp-row-' + id);
        if (data.activo) {
            row.className = 'flex items-center justify-between gap-3 px-3 py-2 rounded-xl border border-slate-200 bg-slate-50';
            btn.className = 'text-xs px-3 py-1 rounded-lg font-semibold transition bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700';
            btn.textContent = 'Activa';
        } else {
            row.className = 'flex items-center justify-between gap-3 px-3 py-2 rounded-xl border border-red-100 bg-red-50 opacity-60';
            btn.className = 'text-xs px-3 py-1 rounded-lg font-semibold transition bg-red-100 text-red-700 hover:bg-green-100 hover:text-green-700';
            btn.textContent = 'Inactiva';
        }
    }

    async function crearEsp(e) {
        e.preventDefault();
        const input = document.getElementById('esp-nueva-nombre');
        const nombre = input.value.trim();
        if (!nombre) return;
        const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'esp_crear', nombre }) });
        const data = await res.json();
        if (!data.success) { showToast(data.message, 'error'); return; }
        // Add to list
        const li = document.createElement('li');
        li.id = 'esp-row-' + data.id;
        li.className = 'flex items-center justify-between gap-3 px-3 py-2 rounded-xl border border-slate-200 bg-slate-50';
        li.innerHTML = `<span class="font-medium text-slate-800 text-sm">${data.nombre}</span>
            <button type="button" onclick="toggleEsp(${data.id}, this)"
                class="text-xs px-3 py-1 rounded-lg font-semibold transition bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700">Activa</button>`;
        document.getElementById('esp-lista').appendChild(li);
        input.value = '';
    }

    async function promoverEsp(nombre, btn) {
        if (!confirm(`¿Agregar "${nombre}" como especialidad oficial?`)) return;
        const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'esp_promover', nombre }) });
        const data = await res.json();
        if (!data.success) { showToast(data.message, 'error'); return; }
        // Remove from "otros" list
        btn.closest('li').remove();
        // Show feedback
        const feedback = document.createElement('div');
        feedback.className = 'text-sm text-green-700 font-semibold mt-2';
        feedback.textContent = data.message;
        document.getElementById('esp-otros-lista')?.prepend(feedback);
        setTimeout(() => feedback.remove(), 3000);
    }
    </script>

</body>
</html>
