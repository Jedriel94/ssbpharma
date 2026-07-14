<?php
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../models/MetodoPago.php';

$metodoPagoModel = new MetodoPago();

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'actualizar':
            $id = $_POST['id'] ?? 0;
            
            $datos = [
                'nombre_display' => trim($_POST['nombre_display'] ?? ''),
                'descripcion' => trim($_POST['descripcion'] ?? ''),
                'instrucciones' => trim($_POST['instrucciones'] ?? ''),
                'banco' => trim($_POST['banco'] ?? ''),
                'titular' => trim($_POST['titular'] ?? ''),
                'cuenta' => trim($_POST['cuenta'] ?? ''),
                'clabe' => trim($_POST['clabe'] ?? ''),
                'numero_tarjeta' => trim($_POST['numero_tarjeta'] ?? ''),
                'beneficiario' => trim($_POST['beneficiario'] ?? ''),
                'rfc_empresa' => strtoupper(trim($_POST['rfc_empresa'] ?? '')),
                'referencia' => trim($_POST['referencia'] ?? ''),
                'monto_minimo' => floatval($_POST['monto_minimo'] ?? 0),
                'monto_maximo' => floatval($_POST['monto_maximo'] ?? 0),
                'comision_porcentaje' => floatval($_POST['comision_porcentaje'] ?? 0),
                'activo' => isset($_POST['activo']) ? 1 : 0,
                'orden' => intval($_POST['orden'] ?? 0),
                'imagen' => trim($_POST['imagen'] ?? ''),
                'paypal_client_id' => trim($_POST['paypal_client_id'] ?? ''),
                'paypal_secret' => trim($_POST['paypal_secret'] ?? ''),
                'paypal_mode' => trim($_POST['paypal_mode'] ?? 'sandbox'),
                'paypal_webhook_url' => trim($_POST['paypal_webhook_url'] ?? ''),
                'paypal_sin_cuenta' => isset($_POST['paypal_sin_cuenta']) ? 1 : 0,
                'mp_public_key' => trim($_POST['mp_public_key'] ?? ''),
                'mp_access_token' => trim($_POST['mp_access_token'] ?? ''),
                'mp_mode' => trim($_POST['mp_mode'] ?? 'production'),
                'mp_sin_cuenta' => isset($_POST['mp_sin_cuenta']) ? 1 : 0,
                'ecartpay_public_key' => trim($_POST['ecartpay_public_key'] ?? ''),
                'ecartpay_private_key' => trim($_POST['ecartpay_private_key'] ?? ''),
                'ecartpay_sandbox' => isset($_POST['ecartpay_sandbox']) ? 1 : 0,
                'openpay_merchant_id' => trim($_POST['openpay_merchant_id'] ?? ''),
                'openpay_private_key' => trim($_POST['openpay_private_key'] ?? ''),
                'openpay_public_key' => trim($_POST['openpay_public_key'] ?? ''),
                'openpay_sandbox' => isset($_POST['openpay_sandbox']) ? 1 : 0,
                'flujo_a' => isset($_POST['flujo_a']) ? 1 : 0,
                'flujo_b' => isset($_POST['flujo_b']) ? 1 : 0,
                'flujo_c' => isset($_POST['flujo_c']) ? 1 : 0,
                'flujo_d' => isset($_POST['flujo_d']) ? 1 : 0,
            ];
            
            if ($metodoPagoModel->update($id, $datos)) {
                echo json_encode(['success' => true, 'message' => 'Método de pago actualizado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar']);
            }
            exit;
            
        case 'toggle_activo':
            $id = $_POST['id'] ?? 0;
            if ($metodoPagoModel->toggleActivo($id)) {
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar estado']);
            }
            exit;
    }
}

// Obtener todos los métodos de pago
$metodos = $metodoPagoModel->getAll();

$totalMetodos = count($metodos);
$metodosActivos = count(array_filter($metodos, fn($m) => !empty($m['activo'])));
$metodosInactivos = $totalMetodos - $metodosActivos;
$pasarelasConfiguradas = count(array_filter($metodos, fn($m) => in_array($m['metodo'], ['paypal', 'mercado_pago', 'ecartpay'], true) && !empty($m['activo'])));
?>

<?php include '../includes/header.php'; ?>

<style>
.mp-shell { max-width: 1280px; margin: 0 auto; }
.mp-tabs {
    display: flex;
    gap: .5rem;
    overflow-x: auto;
    padding: .75rem;
    background: var(--bg-card-hover);
    border-bottom: 1px solid var(--border-card);
    scrollbar-width: thin;
    scrollbar-color: var(--border-input) transparent;
}
.mp-tabs::-webkit-scrollbar { height: 4px; }
.mp-tabs::-webkit-scrollbar-track { background: transparent; }
.mp-tabs::-webkit-scrollbar-thumb {
    background: var(--border-input);
    border-radius: 4px;
}
.mp-tabs::-webkit-scrollbar-thumb:hover { background: var(--accent); }
.mp-tab {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: .55rem;
    padding: .75rem 1rem;
    border-radius: .75rem;
    border: 1px solid transparent;
    color: var(--text-secondary);
    background: transparent;
}
.mp-tab:hover { background: var(--bg-menu-item); color: var(--text-primary); }
.mp-tab-active {
    background: var(--bg-card);
    color: var(--text-primary);
    border-color: var(--border-card);
    box-shadow: 0 1px 3px rgba(15,23,42,.08);
}
.mp-content { padding: 1.5rem; }
.mp-method-head { border-bottom: 1px solid var(--border-card) !important; }
.mp-code-pill {
    background: var(--bg-card-hover);
    color: var(--text-secondary);
    border: 1px solid var(--border-card);
}
.tab-panel form > div[class*="bg-"][class*="-50"],
.tab-panel form > div[class*="bg-"][class*="-100"] {
    background: var(--bg-card-hover) !important;
    border: 1px solid var(--border-card) !important;
    border-left: 3px solid var(--accent) !important;
}
.tab-panel form > div[class*="bg-"][class*="-50"] h3,
.tab-panel form > div[class*="bg-"][class*="-100"] h3,
.tab-panel form > div[class*="bg-"][class*="-50"] label,
.tab-panel form > div[class*="bg-"][class*="-100"] label {
    color: var(--text-primary) !important;
}
.tab-panel .border-t-2 { border-top: 1px solid var(--border-card) !important; }
</style>

<div class="mp-shell px-4 py-8">
    
    <!-- Header con Estadísticas -->
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-800 mb-2">💳 Gestión de Métodos de Pago</h1>
                <p class="text-slate-600">Configura transferencias, pagos en tienda y pasarelas disponibles para tus clientes.</p>
            </div>
        </div>

        <!-- Estadísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Total Métodos</div>
                <div class="text-2xl font-bold text-slate-800"><?= $totalMetodos ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Activos</div>
                <div class="text-2xl font-bold text-green-600"><?= $metodosActivos ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Inactivos</div>
                <div class="text-2xl font-bold text-red-600"><?= $metodosInactivos ?></div>
            </div>
            <div class="card rounded-xl p-4 shadow">
                <div class="text-slate-600 text-sm mb-1">Pasarelas Activas</div>
                <div class="text-2xl font-bold text-blue-600"><?= $pasarelasConfiguradas ?></div>
            </div>
        </div>
    </div>

    <!-- Tabs de Métodos de Pago -->
    <div class="card rounded-xl shadow-lg overflow-hidden">
        <!-- Navegación de Tabs -->
        <div class="mp-tabs">
            <?php 
            $iconos_tabs = [
                'transferencia' => '🏦',
                'oxxo' => '🏪',
                'tienda' => '🏪',
                'paypal' => '💵',
                'liga_pago' => '🔗',
                'mercado_pago' => '💳',
                'ecartpay' => '🛒'
            ];
            foreach ($metodos as $index => $metodo): 
                $icono = $iconos_tabs[$metodo['metodo']] ?? '💳';
                $isFirst = $index === 0;
            ?>
                <button type="button" 
                        onclick="cambiarTab('<?= $metodo['metodo'] ?>')"
                        id="tab-<?= $metodo['metodo'] ?>"
                        class="tab-btn mp-tab font-semibold transition <?= $isFirst ? 'mp-tab-active' : '' ?>">
                    <span class="text-xl"><?= $icono ?></span>
                    <span class="hidden sm:inline"><?= htmlspecialchars($metodo['nombre_display']) ?></span>
                    <span class="text-xs px-2 py-0.5 rounded-full <?= $metodo['activo'] ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600' ?>">
                        <?= $metodo['activo'] ? 'Activo' : 'Inactivo' ?>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Contenido de Tabs -->
        <div class="mp-content">
            <?php foreach ($metodos as $index => $metodo): 
                $isFirst = $index === 0;
            ?>
            <div id="panel-<?= $metodo['metodo'] ?>" class="tab-panel <?= $isFirst ? '' : 'hidden' ?>">
                <form onsubmit="actualizarMetodo(event, <?= $metodo['id'] ?>)" class="space-y-6">
                    
                    <!-- Header del Método -->
                    <div class="mp-method-head flex flex-col md:flex-row md:justify-between md:items-start gap-4 pb-4">
                        <div>
                            <h2 class="text-2xl font-bold text-slate-900 mb-1">
                                <?= htmlspecialchars($metodo['nombre_display']) ?>
                            </h2>
                            <p class="text-sm text-slate-600">
                                Tipo: <span class="mp-code-pill font-mono px-2 py-1 rounded"><?= htmlspecialchars($metodo['metodo']) ?></span>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" 
                                       name="activo" 
                                       <?= $metodo['activo'] ? 'checked' : '' ?>
                                       class="w-5 h-5 text-terracotta-500 rounded focus:ring-2 focus:ring-terracotta-500">
                                <span class="font-semibold <?= $metodo['activo'] ? 'text-green-600' : 'text-slate-400' ?>">
                                    <?= $metodo['activo'] ? '✅ Activo' : '❌ Inactivo' ?>
                                </span>
                            </label>
                        </div>
                    </div>
                    <!-- Flujos de venta donde aplica -->
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                        <p class="text-sm font-semibold text-slate-700 mb-3">&#x1F500; Aplica en los flujos de venta</p>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" name="flujo_a" <?= !empty($metodo['flujo_a']) ? 'checked' : '' ?> class="w-4 h-4 rounded accent-terracotta-500">
                                <span class="text-sm font-semibold text-slate-700">A &mdash; Venta directa rep</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" name="flujo_b" <?= !empty($metodo['flujo_b']) ? 'checked' : '' ?> class="w-4 h-4 rounded accent-terracotta-500">
                                <span class="text-sm font-semibold text-slate-700">B &mdash; Rep opera la tienda</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" name="flujo_c" <?= !empty($metodo['flujo_c']) ? 'checked' : '' ?> class="w-4 h-4 rounded accent-terracotta-500">
                                <span class="text-sm font-semibold text-slate-700">C &mdash; Cliente vía QR</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" name="flujo_d" <?= !empty($metodo['flujo_d']) ? 'checked' : '' ?> class="w-4 h-4 rounded accent-terracotta-500">
                                <span class="text-sm font-semibold text-slate-700">D &mdash; Cliente sin QR</span>
                            </label>
                        </div>
                    </div>
                    <!-- Información General -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">📋 Nombre para Mostrar</label>
                            <input type="text" 
                                   name="nombre_display" 
                                   value="<?= htmlspecialchars($metodo['nombre_display']) ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl"
                                   required>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">📝 Descripción</label>
                            <textarea name="descripcion" 
                                      rows="2"
                                      class="input-field w-full px-4 py-3 rounded-xl"><?= htmlspecialchars($metodo['descripcion'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">📖 Instrucciones para el Cliente</label>
                            <textarea name="instrucciones" 
                                      rows="3"
                                      class="input-field w-full px-4 py-3 rounded-xl"
                                      placeholder="Pasos que debe seguir el cliente para completar el pago"><?= htmlspecialchars($metodo['instrucciones'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <?php if ($metodo['metodo'] === 'transferencia'): ?>
                        <!-- Datos de Transferencia Bancaria -->
                        <div class="bg-blue-50 p-6 rounded-xl border-2 border-blue-200">
                            <h3 class="text-lg font-bold text-blue-900 mb-4 flex items-center gap-2">
                                🏦 Datos Bancarios
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Banco</label>
                                    <input type="text" 
                                           name="banco" 
                                           value="<?= htmlspecialchars($metodo['banco'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl"
                                           placeholder="Ej: BBVA, Santander, Banorte">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Titular de la Cuenta</label>
                                    <input type="text" 
                                           name="titular" 
                                           value="<?= htmlspecialchars($metodo['titular'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl"
                                           placeholder="Nombre completo del titular">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Número de Cuenta</label>
                                    <input type="text" 
                                           name="cuenta" 
                                           value="<?= htmlspecialchars($metodo['cuenta'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono"
                                           placeholder="1234567890">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">CLABE Interbancaria</label>
                                    <input type="text" 
                                           name="clabe" 
                                           value="<?= htmlspecialchars($metodo['clabe'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono"
                                           placeholder="123456789012345678"
                                           maxlength="18">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">RFC de la Empresa</label>
                                    <input type="text" 
                                           name="rfc_empresa" 
                                           value="<?= htmlspecialchars($metodo['rfc_empresa'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono"
                                           placeholder="BTK123456ABC"
                                           maxlength="13">
                                </div>
                            </div>
                        </div>
                        <!-- Campos ocultos para transferencia -->
                        <input type="hidden" name="numero_tarjeta" value="">
                        <input type="hidden" name="beneficiario" value="">
                        <input type="hidden" name="referencia" value="">
                        <input type="hidden" name="paypal_client_id" value="">
                        <input type="hidden" name="paypal_secret" value="">
                        <input type="hidden" name="paypal_mode" value="sandbox">
                        <input type="hidden" name="paypal_webhook_url" value="">
                        <input type="hidden" name="paypal_sin_cuenta" value="0">
                        <input type="hidden" name="mp_public_key" value="">
                        <input type="hidden" name="mp_access_token" value="">
                        <input type="hidden" name="mp_mode" value="production">
                        <input type="hidden" name="mp_sin_cuenta" value="0">
                        <input type="hidden" name="ecartpay_public_key" value="">
                        <input type="hidden" name="ecartpay_private_key" value="">
                        <input type="hidden" name="ecartpay_sandbox" value="0">
                    <?php endif; ?>

                    <?php if ($metodo['metodo'] === 'oxxo' || $metodo['metodo'] === 'tienda'): ?>
                        <!-- Datos de OXXO/Tienda de Conveniencia -->
                        <div class="bg-amber-50 p-6 rounded-xl border-2 border-amber-200">
                            <h3 class="text-lg font-bold text-amber-900 mb-4 flex items-center gap-2">
                                🏪 Datos de Tienda de Conveniencia
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-amber-900 mb-2">Banco</label>
                                    <input type="text" 
                                           name="banco" 
                                           value="<?= htmlspecialchars($metodo['banco'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl"
                                           placeholder="Ej: BBVA">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-amber-900 mb-2">Número de Tarjeta</label>
                                    <input type="text" 
                                           name="numero_tarjeta" 
                                           value="<?= htmlspecialchars($metodo['numero_tarjeta'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono"
                                           placeholder="4152 3137 0123 4567"
                                           maxlength="19">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-amber-900 mb-2">Referencia/Convenio</label>
                                    <input type="text" 
                                           name="referencia" 
                                           value="<?= htmlspecialchars($metodo['referencia'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono"
                                           placeholder="BOTIKIT o número de convenio">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-amber-900 mb-2">Beneficiario</label>
                                    <input type="text" 
                                           name="beneficiario" 
                                           value="<?= htmlspecialchars($metodo['beneficiario'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl"
                                           placeholder="BOTIKIT SA DE CV">
                                </div>
                            </div>
                        </div>
                        <!-- Campos ocultos para tienda/oxxo -->
                        <input type="hidden" name="titular" value="">
                        <input type="hidden" name="cuenta" value="">
                        <input type="hidden" name="clabe" value="">
                        <input type="hidden" name="rfc_empresa" value="">
                        <input type="hidden" name="monto_minimo" value="0">
                        <input type="hidden" name="monto_maximo" value="0">
                        <input type="hidden" name="comision_porcentaje" value="0">
                        <input type="hidden" name="paypal_client_id" value="">
                        <input type="hidden" name="paypal_secret" value="">
                        <input type="hidden" name="paypal_mode" value="sandbox">
                        <input type="hidden" name="paypal_webhook_url" value="">
                        <input type="hidden" name="paypal_sin_cuenta" value="0">
                        <input type="hidden" name="mp_public_key" value="">
                        <input type="hidden" name="mp_access_token" value="">
                        <input type="hidden" name="mp_mode" value="production">
                        <input type="hidden" name="mp_sin_cuenta" value="0">
                        <input type="hidden" name="ecartpay_public_key" value="">
                        <input type="hidden" name="ecartpay_private_key" value="">
                        <input type="hidden" name="ecartpay_sandbox" value="0">                        <input type="hidden" name="openpay_merchant_id" value="">
                        <input type="hidden" name="openpay_private_key" value="">
                        <input type="hidden" name="openpay_public_key" value="">
                        <input type="hidden" name="openpay_sandbox" value="1">                    <?php endif; ?>

                    <?php if ($metodo['metodo'] === 'liga_pago'): ?>
                        <!-- Liga de Pago -->
                        <div class="bg-violet-50 p-6 rounded-xl border-2 border-violet-200">
                            <h3 class="text-lg font-bold text-violet-900 mb-3 flex items-center gap-2">
                                🔗 Liga de Pago vía EcartPay
                            </h3>
                            <p class="text-sm text-violet-700 mb-4">
                                Pega tu enlace personal de EcartPay (<code>https://ecartpay.com/me/...</code>). El representante generará la liga con el monto del pedido y la compartirá con el cliente.
                            </p>
                            <div>
                                <label class="block text-sm font-semibold text-violet-800 mb-1">URL base de EcartPay (/me/)</label>
                                <input type="url" name="banco"
                                       value="<?= htmlspecialchars($metodo['banco'] ?? '') ?>"
                                       placeholder="https://ecartpay.com/me/xxxxxxxxxxxxxxxxxxxxxxxx"
                                       class="w-full px-4 py-3 border-2 border-violet-300 rounded-xl focus:border-violet-500 outline-none text-sm">
                                <p class="text-xs text-violet-600 mt-1">Cópiala desde tu panel de EcartPay → Cobrar → Mi liga de pago</p>
                            </div>
                        </div>
                        <input type="hidden" name="titular" value="">
                        <input type="hidden" name="cuenta" value="">
                        <input type="hidden" name="clabe" value="">
                        <input type="hidden" name="numero_tarjeta" value="">
                        <input type="hidden" name="beneficiario" value="">
                        <input type="hidden" name="rfc_empresa" value="">
                        <input type="hidden" name="referencia" value="">
                        <input type="hidden" name="monto_minimo" value="0">
                        <input type="hidden" name="monto_maximo" value="0">
                        <input type="hidden" name="comision_porcentaje" value="0">
                        <input type="hidden" name="paypal_client_id" value="">
                        <input type="hidden" name="paypal_secret" value="">
                        <input type="hidden" name="paypal_mode" value="sandbox">
                        <input type="hidden" name="paypal_webhook_url" value="">
                        <input type="hidden" name="paypal_sin_cuenta" value="0">
                        <input type="hidden" name="mp_public_key" value="">
                        <input type="hidden" name="mp_access_token" value="">
                        <input type="hidden" name="mp_mode" value="production">
                        <input type="hidden" name="mp_sin_cuenta" value="0">
                        <input type="hidden" name="ecartpay_public_key" value="">
                        <input type="hidden" name="ecartpay_private_key" value="">
                        <input type="hidden" name="ecartpay_sandbox" value="0">
                    <?php endif; ?>

                    <?php if ($metodo['metodo'] === 'paypal'): ?>
                        <!-- Datos de PayPal -->
                        <div class="bg-blue-50 p-6 rounded-xl border-2 border-blue-200">
                            <h3 class="text-lg font-bold text-blue-900 mb-4 flex items-center gap-2">
                                💳 Configuración de PayPal
                            </h3>
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Client ID de PayPal <span class="text-red-500">*</span></label>
                                    <input type="text" 
                                           name="paypal_client_id" 
                                           value="<?= htmlspecialchars($metodo['paypal_client_id'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="AeA1QIZXiflr1_JOELf...">
                                    <p class="text-xs text-slate-500 mt-1">Obtenlo desde tu Dashboard de PayPal</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Secret Key de PayPal <span class="text-red-500">*</span></label>
                                    <input type="password" 
                                           name="paypal_secret" 
                                           value="<?= htmlspecialchars($metodo['paypal_secret'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="EJRldII9VzF05...">
                                    <p class="text-xs text-slate-500 mt-1">⚠️ Mantén esta clave segura</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Modo de Operación</label>
                                    <select name="paypal_mode" 
                                            class="input-field w-full px-4 py-3 rounded-xl">
                                        <option value="sandbox" <?= ($metodo['paypal_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>
                                            🧪 Sandbox (Pruebas)
                                        </option>
                                        <option value="production" <?= ($metodo['paypal_mode'] ?? '') === 'production' ? 'selected' : '' ?>>
                                            🚀 Production (En vivo)
                                        </option>
                                    </select>
                                    <p class="text-xs text-slate-500 mt-1">Inicia en Sandbox para pruebas</p>
                                </div>
                                
                                <div class="bg-white border-2 border-blue-200 rounded-xl p-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox"
                                               name="paypal_sin_cuenta"
                                               <?= !empty($metodo['paypal_sin_cuenta']) ? 'checked' : '' ?>
                                               class="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                                        <div>
                                            <span class="font-semibold text-blue-900">💳 Permitir pago sin cuenta PayPal</span>
                                            <p class="text-xs text-slate-500 mt-0.5">El cliente podrá pagar con tarjeta de crédito/débito directamente, sin necesitar una cuenta PayPal</p>
                                        </div>
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-blue-900 mb-2">Webhook URL (Opcional)</label>
                                    <input type="text" 
                                           name="paypal_webhook_url" 
                                           value="<?= htmlspecialchars($metodo['paypal_webhook_url'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="https://tudominio.com/api/paypal-webhook.php">
                                    <p class="text-xs text-slate-500 mt-1">Para recibir notificaciones de PayPal</p>
                                </div>
                            </div>
                        </div>
                        <!-- Campos ocultos para PayPal -->
                        <input type="hidden" name="banco" value="">
                        <input type="hidden" name="titular" value="">
                        <input type="hidden" name="cuenta" value="">
                        <input type="hidden" name="clabe" value="">
                        <input type="hidden" name="numero_tarjeta" value="">
                        <input type="hidden" name="beneficiario" value="">
                        <input type="hidden" name="rfc_empresa" value="">
                        <input type="hidden" name="referencia" value="">
                        <input type="hidden" name="monto_minimo" value="0">
                        <input type="hidden" name="monto_maximo" value="0">
                        <input type="hidden" name="comision_porcentaje" value="0">
                        <input type="hidden" name="mp_public_key" value="">
                        <input type="hidden" name="mp_access_token" value="">
                        <input type="hidden" name="mp_mode" value="production">
                        <input type="hidden" name="mp_sin_cuenta" value="0">
                        <input type="hidden" name="ecartpay_public_key" value="">
                        <input type="hidden" name="ecartpay_private_key" value="">
                        <input type="hidden" name="ecartpay_sandbox" value="0">
                        <input type="hidden" name="openpay_merchant_id" value="">
                        <input type="hidden" name="openpay_private_key" value="">
                        <input type="hidden" name="openpay_public_key" value="">
                        <input type="hidden" name="openpay_sandbox" value="1">
                    <?php endif; ?>

                    <?php if ($metodo['metodo'] === 'ecartpay'): ?>
                        <!-- Datos de EcartPay -->
                        <div class="bg-green-50 p-6 rounded-xl border-2 border-green-200">
                            <h3 class="text-lg font-bold text-green-900 mb-4 flex items-center gap-2">
                                🛒 Configuración de EcartPay
                            </h3>
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-green-900 mb-2">Public Key <span class="text-red-500">*</span></label>
                                    <input type="text"
                                           name="ecartpay_public_key"
                                           value="<?= htmlspecialchars($metodo['ecartpay_public_key'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="pk_live_...">
                                    <p class="text-xs text-slate-500 mt-1">Obtenla en tu cuenta de EcartPay → Credenciales</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-green-900 mb-2">Private Key <span class="text-red-500">*</span></label>
                                    <input type="password"
                                           name="ecartpay_private_key"
                                           value="<?= htmlspecialchars($metodo['ecartpay_private_key'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="sk_live_...">
                                    <p class="text-xs text-slate-500 mt-1">⚠️ Mantén esta clave segura. Se usa en el servidor.</p>
                                </div>

                                <div class="bg-white border-2 border-green-200 rounded-xl p-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox"
                                               name="ecartpay_sandbox"
                                               <?= !empty($metodo['ecartpay_sandbox']) ? 'checked' : '' ?>
                                               class="w-5 h-5 text-green-600 rounded focus:ring-2 focus:ring-green-500">
                                        <div>
                                            <span class="font-semibold text-green-900">🧪 Modo Sandbox (Pruebas)</span>
                                            <p class="text-xs text-slate-500 mt-0.5">Activa para usar el entorno de pruebas de EcartPay. Desactiva para producción.</p>
                                        </div>
                                    </label>
                                </div>

                                <div class="bg-green-100 border border-green-300 rounded-xl p-4">
                                    <p class="text-xs text-green-800">
                                        ℹ️ Cuando el cliente selecciona este método, se redirige al checkout de EcartPay donde paga con tarjeta de crédito o débito. El pedido se confirma automáticamente vía webhook tras el pago exitoso.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <!-- Campos ocultos para EcartPay -->
                        <input type="hidden" name="banco" value="">
                        <input type="hidden" name="titular" value="">
                        <input type="hidden" name="cuenta" value="">
                        <input type="hidden" name="clabe" value="">
                        <input type="hidden" name="numero_tarjeta" value="">
                        <input type="hidden" name="beneficiario" value="">
                        <input type="hidden" name="rfc_empresa" value="">
                        <input type="hidden" name="referencia" value="">
                        <input type="hidden" name="monto_minimo" value="0">
                        <input type="hidden" name="monto_maximo" value="0">
                        <input type="hidden" name="comision_porcentaje" value="0">
                        <input type="hidden" name="paypal_client_id" value="">
                        <input type="hidden" name="paypal_secret" value="">
                        <input type="hidden" name="paypal_mode" value="sandbox">
                        <input type="hidden" name="paypal_webhook_url" value="">
                        <input type="hidden" name="paypal_sin_cuenta" value="0">
                        <input type="hidden" name="mp_public_key" value="">
                        <input type="hidden" name="mp_access_token" value="">
                        <input type="hidden" name="mp_mode" value="production">
                        <input type="hidden" name="mp_sin_cuenta" value="0">
                        <input type="hidden" name="openpay_merchant_id" value="">
                        <input type="hidden" name="openpay_private_key" value="">
                        <input type="hidden" name="openpay_public_key" value="">
                        <input type="hidden" name="openpay_sandbox" value="1">
                    <?php endif; ?>

                    <?php if ($metodo['metodo'] === 'mercado_pago'): ?>
                        <!-- Datos de Mercado Pago -->
                        <div class="bg-sky-50 p-6 rounded-xl border-2 border-sky-200">
                            <h3 class="text-lg font-bold text-sky-900 mb-4 flex items-center gap-2">
                                💳 Configuración de Mercado Pago
                            </h3>
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-sky-900 mb-2">Public Key <span class="text-red-500">*</span></label>
                                    <input type="text"
                                           name="mp_public_key"
                                           value="<?= htmlspecialchars($metodo['mp_public_key'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="APP_USR-xxxxxxxx-...">
                                    <p class="text-xs text-slate-500 mt-1">Obtenla en tu cuenta de Mercado Pago → Credenciales</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-sky-900 mb-2">Access Token <span class="text-red-500">*</span></label>
                                    <input type="password"
                                           name="mp_access_token"
                                           value="<?= htmlspecialchars($metodo['mp_access_token'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="APP_USR-...">
                                    <p class="text-xs text-slate-500 mt-1">⚠️ Mantén este token seguro. Se usa en el servidor.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-sky-900 mb-2">Modo de Operación</label>
                                    <select name="mp_mode" class="input-field w-full px-4 py-3 rounded-xl">
                                        <option value="sandbox" <?= ($metodo['mp_mode'] ?? 'production') === 'sandbox' ? 'selected' : '' ?>>
                                            🧪 Sandbox (Pruebas)
                                        </option>
                                        <option value="production" <?= ($metodo['mp_mode'] ?? 'production') === 'production' ? 'selected' : '' ?>>
                                            🚀 Production (En vivo)
                                        </option>
                                    </select>
                                    <p class="text-xs text-slate-500 mt-1">Usa Sandbox para pruebas antes de salir en vivo</p>
                                </div>

                                <div class="bg-white border-2 border-sky-200 rounded-xl p-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox"
                                               name="mp_sin_cuenta"
                                               <?= !empty($metodo['mp_sin_cuenta']) ? 'checked' : '' ?>
                                               class="w-5 h-5 text-sky-600 rounded focus:ring-2 focus:ring-sky-500">
                                        <div>
                                            <span class="font-semibold text-sky-900">💳 Permitir pago sin cuenta Mercado Pago</span>
                                            <p class="text-xs text-slate-500 mt-0.5">El cliente podrá pagar con tarjeta de crédito/débito directamente en el Checkout Pro, sin necesitar una cuenta de Mercado Pago</p>
                                        </div>
                                    </label>
                                </div>

                                <div class="bg-sky-100 border border-sky-300 rounded-xl p-4">
                                    <p class="text-xs text-sky-800">
                                        ℹ️ Cuando el cliente selecciona este método, se abre el checkout de Mercado Pago donde puede pagar con tarjeta de crédito, débito o cuenta MP. El pedido se confirma automáticamente tras el pago.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <!-- Campos ocultos para Mercado Pago -->
                        <input type="hidden" name="banco" value="">
                        <input type="hidden" name="titular" value="">
                        <input type="hidden" name="cuenta" value="">
                        <input type="hidden" name="clabe" value="">
                        <input type="hidden" name="numero_tarjeta" value="">
                        <input type="hidden" name="beneficiario" value="">
                        <input type="hidden" name="rfc_empresa" value="">
                        <input type="hidden" name="referencia" value="">
                        <input type="hidden" name="monto_minimo" value="0">
                        <input type="hidden" name="monto_maximo" value="0">
                        <input type="hidden" name="comision_porcentaje" value="0">
                        <input type="hidden" name="paypal_client_id" value="">
                        <input type="hidden" name="paypal_secret" value="">
                        <input type="hidden" name="paypal_mode" value="sandbox">
                        <input type="hidden" name="paypal_webhook_url" value="">
                        <input type="hidden" name="paypal_sin_cuenta" value="0">
                        <input type="hidden" name="ecartpay_public_key" value="">
                        <input type="hidden" name="ecartpay_private_key" value="">
                        <input type="hidden" name="ecartpay_sandbox" value="0">
                        <input type="hidden" name="openpay_merchant_id" value="">
                        <input type="hidden" name="openpay_private_key" value="">
                        <input type="hidden" name="openpay_public_key" value="">
                        <input type="hidden" name="openpay_sandbox" value="1">
                    <?php endif; ?>

                    <?php if ($metodo['metodo'] === 'openpay'): ?>
                        <!-- Datos de OpenPay -->
                        <div class="bg-indigo-50 p-6 rounded-xl border-2 border-indigo-200">
                            <h3 class="text-lg font-bold text-indigo-900 mb-4 flex items-center gap-2">
                                💳 Configuración de OpenPay (BBVA)
                            </h3>
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-indigo-900 mb-2">Merchant ID <span class="text-red-500">*</span></label>
                                    <input type="text"
                                           name="openpay_merchant_id"
                                           value="<?= htmlspecialchars($metodo['openpay_merchant_id'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="mzdtln0bmf2nhnjfo3...">
                                    <p class="text-xs text-slate-500 mt-1">Encuéntralo en tu dashboard de OpenPay → API Keys</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-indigo-900 mb-2">Private Key (Llave Privada) <span class="text-red-500">*</span></label>
                                    <input type="password"
                                           name="openpay_private_key"
                                           value="<?= htmlspecialchars($metodo['openpay_private_key'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="sk_...">
                                    <p class="text-xs text-slate-500 mt-1">⚠️ Mantén esta clave segura. Nunca la expongas al cliente.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-indigo-900 mb-2">Public Key (Llave Pública) <span class="text-red-500">*</span></label>
                                    <input type="text"
                                           name="openpay_public_key"
                                           value="<?= htmlspecialchars($metodo['openpay_public_key'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl font-mono text-sm"
                                           placeholder="pk_...">
                                    <p class="text-xs text-slate-500 mt-1">Se usa en el JS del cliente para tokenizar la tarjeta.</p>
                                </div>

                                <div class="bg-white border-2 border-indigo-200 rounded-xl p-4">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox"
                                               name="openpay_sandbox"
                                               <?= !empty($metodo['openpay_sandbox']) ? 'checked' : '' ?>
                                               class="w-5 h-5 text-indigo-600 rounded focus:ring-2 focus:ring-indigo-500">
                                        <div>
                                            <span class="font-semibold text-indigo-900">🧪 Modo Sandbox (Pruebas)</span>
                                            <p class="text-xs text-slate-500 mt-0.5">Activa para usar el entorno de pruebas. Desactiva para producción después de la certificación.</p>
                                        </div>
                                    </label>
                                </div>

                                <div class="bg-indigo-100 border border-indigo-300 rounded-xl p-4">
                                    <p class="text-xs text-indigo-800">
                                        ℹ️ El cliente ingresa los datos de su tarjeta directamente en tu página. OpenPay los tokeniza con JS y tu servidor crea el cargo. Tarjeta de prueba sandbox: <strong>4111 1111 1111 1111</strong>, cualquier fecha futura, CVV cualquiera.<br><br>
                                        🔗 Webhook: <code class="bg-white px-1 rounded">/api/openpay.php?action=webhook</code>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <!-- Campos ocultos para OpenPay -->
                        <input type="hidden" name="banco" value="">
                        <input type="hidden" name="titular" value="">
                        <input type="hidden" name="cuenta" value="">
                        <input type="hidden" name="clabe" value="">
                        <input type="hidden" name="numero_tarjeta" value="">
                        <input type="hidden" name="beneficiario" value="">
                        <input type="hidden" name="rfc_empresa" value="">
                        <input type="hidden" name="referencia" value="">
                        <input type="hidden" name="monto_minimo" value="0">
                        <input type="hidden" name="monto_maximo" value="0">
                        <input type="hidden" name="comision_porcentaje" value="0">
                        <input type="hidden" name="paypal_client_id" value="">
                        <input type="hidden" name="paypal_secret" value="">
                        <input type="hidden" name="paypal_mode" value="sandbox">
                        <input type="hidden" name="paypal_webhook_url" value="">
                        <input type="hidden" name="paypal_sin_cuenta" value="0">
                        <input type="hidden" name="mp_public_key" value="">
                        <input type="hidden" name="mp_access_token" value="">
                        <input type="hidden" name="mp_mode" value="production">
                        <input type="hidden" name="mp_sin_cuenta" value="0">
                        <input type="hidden" name="ecartpay_public_key" value="">
                        <input type="hidden" name="ecartpay_private_key" value="">
                        <input type="hidden" name="ecartpay_sandbox" value="0">
                    <?php endif; ?>

                    <!-- Configuración Adicional -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">🖼️ Imagen/Logo (URL)</label>
                            <input type="text" 
                                   name="imagen" 
                                   value="<?= htmlspecialchars($metodo['imagen'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl"
                                   placeholder="URL de la imagen">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">📊 Orden de Visualización</label>
                            <input type="number" 
                                   name="orden" 
                                   value="<?= $metodo['orden'] ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl"
                                   placeholder="0">
                            <p class="text-xs text-slate-500 mt-1">Menor número = aparece primero</p>
                        </div>
                    </div>

                    <!-- Botón Guardar -->
                    <div class="flex justify-end pt-4 border-t-2 border-slate-200">
                        <button type="submit" 
                                class="btn-primary text-white px-8 py-3 rounded-xl font-semibold flex items-center gap-2">
                            💾 Guardar Cambios
                        </button>
                    </div>

                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<script>
// Función para cambiar entre tabs
function cambiarTab(metodo) {
    // Ocultar todos los paneles
    document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.classList.add('hidden');
    });
    
    // Desactivar todos los botones
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('mp-tab-active');
    });
    
    // Mostrar panel activo
    const panel = document.getElementById('panel-' + metodo);
    if (panel) panel.classList.remove('hidden');
    
    // Activar botón correspondiente
    const btn = document.getElementById('tab-' + metodo);
    if (btn) {
        btn.classList.add('mp-tab-active');
    }
}

function actualizarMetodo(e, id) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', 'actualizar');
    formData.append('id', id);
    
    const btn = e.target.querySelector('button[type="submit"]');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Guardando...';
    
    // Usar la misma URL de la página actual para evitar problemas de rutas
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        mostrarAlerta(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.disabled = false;
            btn.innerHTML = btnText;
        }
    })
    .catch(error => {
        mostrarAlerta('Error de conexión', 'error');
        btn.disabled = false;
        btn.innerHTML = btnText;
    });
}
</script>

<?php include '../includes/footer.php'; ?>
