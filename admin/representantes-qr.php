<?php
session_start();

// Verificar autenticación
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Configuracion.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Obtener ID del usuario representante
$id = $_GET['id'] ?? 0;

if (!$id) {
    header('Location: representantes.php');
    exit;
}

$sql = "
    SELECT
        a.id as admin_id,
        a.nombre,
        a.usuario,
        rp.codigo,
        rp.telefono,
        rp.email
    FROM representante_perfiles rp
    INNER JOIN administradores a ON a.id = rp.admin_id
    WHERE a.id = ?
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([(int)$id]);
$representante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$representante) {
    header('Location: representantes.php');
    exit;
}

// Generar URL completa
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = str_replace('/admin', '', dirname($_SERVER['SCRIPT_NAME']));
$url_completa = $protocolo . '://' . $host . $base_url . '/r/' . $representante['codigo'];

// URL de API de QR Code
$qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($url_completa);

// Obtener estadísticas
$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT p.id) as total_pedidos,
        COUNT(DISTINCT p.cliente_id) as total_clientes,
        COALESCE(SUM(CASE WHEN p.estado IN ('confirmado','en_ruta','entregado') THEN p.total ELSE 0 END), 0) as total_ventas
    FROM pedidos p
    WHERE p.representante_admin_id = ?
");
$stmt->execute([(int)$representante['admin_id']]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Generador de QR - ' . $representante['nombre'];
?>

<?php include '../includes/header.php'; ?>

<div class="p-6 max-w-7xl mx-auto">
    
    <!-- Header -->
    <div class="mb-6">
        <a href="usuarios-sistema.php" class="inline-flex items-center gap-2 text-terracotta-600 hover:text-terracotta-700 mb-4 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Volver a Usuarios
        </a>
        <h1 class="text-3xl font-bold text-slate-900">Código QR</h1>
                <p class="text-slate-600 mt-1"><?= htmlspecialchars($representante['nombre']) ?></p>
                <p class="text-xs text-slate-500 mt-1">Usuario representante #<?= (int)$representante['admin_id'] ?></p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Columna Izquierda: QR Code -->
        <div class="card rounded-2xl shadow-lg p-8">
            <h2 class="text-xl font-bold text-slate-900 mb-6 text-center">Código QR</h2>
            
            <!-- QR Code -->
            <div class="flex justify-center mb-6">
                <div class="p-6 bg-white rounded-xl border-2 border-slate-200 inline-block">
                    <img src="<?= $qr_api_url ?>" 
                         alt="QR Code" 
                         id="qrImage"
                         class="max-w-full h-auto" 
                         style="width: 300px; height: 300px;">
                </div>
            </div>
            
            <!-- Información del Código -->
            <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4 mb-6 text-center">
                <div class="text-sm text-blue-900">
                    <strong>Código:</strong> <?= htmlspecialchars($representante['codigo']) ?><br>
                    <strong>Representante:</strong> <?= htmlspecialchars($representante['nombre']) ?>
                </div>
            </div>
            
            <!-- Botones de Acción -->
            <div class="space-y-3">
                <button onclick="descargarQR()" 
                        class="w-full btn-primary text-white py-3 px-6 rounded-xl font-semibold flex items-center justify-center gap-2 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Descargar QR
                </button>
                
                <button onclick="imprimirQR()" 
                        class="w-full bg-slate-500 hover:bg-slate-600 text-white py-3 px-6 rounded-xl font-semibold flex items-center justify-center gap-2 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Imprimir
                </button>
                
                <button onclick="compartirWhatsApp()" 
                        class="w-full bg-green-500 hover:bg-green-600 text-white py-3 px-6 rounded-xl font-semibold flex items-center justify-center gap-2 transition">
                    <span class="text-xl"></span>
                    Compartir por WhatsApp
                </button>
            </div>
        </div>
        
        <!-- Columna Derecha: Información y Opciones -->
        <div class="space-y-6">
            
            <!-- Enlace del Representante -->
            <div class="card rounded-2xl shadow-lg p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Enlace de Referido</h2>
                <p class="text-sm text-slate-600 mb-4">Comparte este enlace con tus clientes:</p>
                
                <div class="flex gap-2 mb-4">
                    <input type="text" 
                           id="urlReferido" 
                           value="<?= $url_completa ?>" 
                           readonly
                           class="flex-1 px-4 py-3 rounded-xl border-2 border-slate-200 bg-slate-50 text-sm font-mono">
                    <button onclick="copiarEnlace()" 
                            class="bg-slate-500 hover:bg-slate-600 text-white px-4 rounded-xl transition"
                            title="Copiar enlace">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                        </svg>
                    </button>
                </div>
                
                <button onclick="abrirEnlace()" 
                        class="w-full bg-terracotta-500 hover:bg-terracotta-600 text-white py-3 px-6 rounded-xl font-semibold transition">
                    Probar Enlace
                </button>
            </div>
            
            <!-- Estadísticas del Representante -->
            <div class="card rounded-2xl shadow-lg p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Estadísticas</h2>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-cream-100 rounded-xl p-4">
                        <div class="text-xs text-slate-600 mb-1">Total Pedidos</div>
                        <div class="text-2xl font-bold text-slate-900"><?= $stats['total_pedidos'] ?></div>
                    </div>
                    <div class="bg-cream-100 rounded-xl p-4">
                        <div class="text-xs text-slate-600 mb-1">Clientes</div>
                        <div class="text-2xl font-bold text-slate-900"><?= $stats['total_clientes'] ?></div>
                    </div>
                    <div class="col-span-2 bg-terracotta-100 rounded-xl p-4">
                        <div class="text-xs text-terracotta-700 mb-1">Total Ventas</div>
                        <div class="text-3xl font-bold text-terracotta-600">$<?= number_format($stats['total_ventas'], 2) ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Instrucciones de Uso -->
            <div class="card rounded-2xl shadow-lg p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Instrucciones</h2>
                
                <div class="space-y-3">
                    <details class="group">
                        <summary class="cursor-pointer p-3 bg-slate-50 hover:bg-slate-100 rounded-xl transition list-none">
                            <strong>1. ¿Cómo usar el código QR?</strong>
                        </summary>
                        <div class="p-3 text-sm text-slate-600">
                            Los clientes deben escanear el código QR con su celular. 
                            Esto los llevará directamente a tu enlace de referido y 
                            guardará tu código automáticamente en sus cookies.
                        </div>
                    </details>
                    
                    <details class="group">
                        <summary class="cursor-pointer p-3 bg-slate-50 hover:bg-slate-100 rounded-xl transition list-none">
                            <strong>2. ¿Cómo compartir el enlace?</strong>
                        </summary>
                        <div class="p-3 text-sm text-slate-600">
                            Puedes copiar el enlace y enviarlo por WhatsApp, email, 
                            redes sociales o cualquier otro medio. El enlace 
                            funcionará igual que el código QR.
                        </div>
                    </details>
                    
                    <details class="group">
                        <summary class="cursor-pointer p-3 bg-slate-50 hover:bg-slate-100 rounded-xl transition list-none">
                            <strong>3. ¿Cómo se registran las ventas?</strong>
                        </summary>
                        <div class="p-3 text-sm text-slate-600">
                            Cuando un cliente accede mediante tu código o enlace, 
                            todos los pedidos que haga se registrarán automáticamente 
                            bajo tu nombre. La cookie permanece activa indefinidamente.
                        </div>
                    </details>
                    
                    <details class="group">
                        <summary class="cursor-pointer p-3 bg-slate-50 hover:bg-slate-100 rounded-xl transition list-none">
                            <strong>4. ¿Cómo imprimir el QR?</strong>
                        </summary>
                        <div class="p-3 text-sm text-slate-600">
                            Haz clic en "Imprimir" para abrir la versión para impresión. 
                            Se recomienda imprimir en alta calidad para asegurar que 
                            el QR se escanee correctamente.
                        </div>
                    </details>
                </div>
            </div>
        </div>
        
    </div>
    
</div>

<script>
// Variables para JavaScript
const urlCompleta = "<?= $url_completa ?>";
const nombreRepresentante = "<?= htmlspecialchars($representante['nombre']) ?>";
const codigoRepresentante = "<?= htmlspecialchars($representante['codigo']) ?>";

// Copiar enlace al portapapeles
function copiarEnlace() {
    const texto = document.getElementById('urlReferido').value;

    function _mostrarOk() {
        showToast('Enlace copiado al portapapeles', 'success');
    }

    function _fallback() {
        const ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); _mostrarOk(); } catch(e) {}
        document.body.removeChild(ta);
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(texto).then(_mostrarOk).catch(_fallback);
    } else {
        _fallback();
    }
}

// Abrir enlace en nueva pestaña
function abrirEnlace() {
    window.open(urlCompleta, '_blank');
}

// Descargar código QR
function descargarQR() {
    const qrImage = document.getElementById('qrImage');
    const link = document.createElement('a');
    link.href = qrImage.src;
    link.download = `QR_${codigoRepresentante}.png`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Imprimir código QR
function imprimirQR() {
    const ventanaImpresion = window.open('', '_blank');
    ventanaImpresion.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Imprimir QR - ${nombreRepresentante}</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 40px;
                }
                h1 {
                    color: #333;
                    margin-bottom: 30px;
                }
                img {
                    max-width: 400px;
                    margin: 30px auto;
                    display: block;
                }
                .info {
                    margin-top: 30px;
                    font-size: 16px;
                    color: #666;
                }
                @media print {
                    @page {
                        margin: 2cm;
                    }
                }
            </style>
        </head>
        <body>
            <h1><?= htmlspecialchars(Configuracion::get('nombre_tienda', 'Tienda')) ?> Pedidos</h1>
            <img src="${document.getElementById('qrImage').src}" alt="QR Code">
            <div class="info">
                <p><strong>Representante:</strong> ${nombreRepresentante}</p>
                <p><strong>Código:</strong> ${codigoRepresentante}</p>
                <p><strong>URL:</strong> ${urlCompleta}</p>
            </div>
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 1000);
                }
            <\/script>
        </body>
        </html>
    `);
}

// Compartir por WhatsApp
function compartirWhatsApp() {
    const mensaje = `¡Hola! \n\nTe comparto mi enlace de pedidos de <?= htmlspecialchars(Configuracion::get('nombre_tienda', 'Tienda'), ENT_QUOTES) ?>:\n\n${urlCompleta}\n\n¡Escanea el QR o haz clic en el enlace para hacer tu pedido!\n\nSaludos,\n${nombreRepresentante}`;
    
    const urlWhatsApp = `https://wa.me/?text=${encodeURIComponent(mensaje)}`;
    window.open(urlWhatsApp, '_blank');
}
</script>

<?php include '../includes/footer.php'; ?>
