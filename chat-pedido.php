<?php
session_start();
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/models/Pedido.php';
require_once __DIR__ . '/models/Cliente.php';
require_once __DIR__ . '/models/MensajePedido.php';

$pedidoModel = new Pedido();
$clienteModel = new Cliente();
$mensajeModel = new MensajePedido();

// Función para convertir URLs en enlaces clicables
function convertirUrlsEnEnlaces($texto) {
    $patron = '/(https?:\/\/[^\s]+)/i';
    $reemplazo = '<a href="$1" target="_blank" class="text-purple-600 hover:text-purple-800 underline font-semibold break-all">🔗 $1</a>';
    return preg_replace($patron, $reemplazo, htmlspecialchars($texto));
}

// Procesar AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'enviar_mensaje':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $mensaje = trim($_POST['mensaje'] ?? '');
            $telefono = $_POST['telefono'] ?? '';
            
            if (empty($mensaje)) {
                echo json_encode(['success' => false, 'message' => 'El mensaje no puede estar vacío']);
                exit;
            }
            
            // Validar que el pedido pertenezca al cliente
            $pedido = $pedidoModel->getById($pedido_id);
            if (!$pedido || $pedido['telefono'] !== $telefono) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            
            $mensaje_id = $mensajeModel->create($pedido_id, 'cliente', $mensaje);
            
            if ($mensaje_id) {
                echo json_encode(['success' => true, 'message' => 'Mensaje enviado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al enviar mensaje']);
            }
            exit;
            
        case 'obtener_mensajes':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $telefono = $_POST['telefono'] ?? '';
            
            // Validar que el pedido pertenezca al cliente
            $pedido = $pedidoModel->getById($pedido_id);
            if (!$pedido || $pedido['telefono'] !== $telefono) {
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                exit;
            }
            
            $mensajes = $mensajeModel->getByPedido($pedido_id);
            echo json_encode(['success' => true, 'mensajes' => $mensajes]);
            exit;
    }
}

// Obtener datos
$pedido_id = $_GET['pedido_id'] ?? 0;
$telefono = $_GET['telefono'] ?? '';

if (empty($pedido_id) || empty($telefono)) {
    header('Location: index.php');
    exit;
}

$pedido = $pedidoModel->getById($pedido_id);
$cliente = $clienteModel->getByTelefono($telefono);

if (!$pedido || !$cliente || $pedido['telefono'] !== $telefono) {
    header('Location: index.php');
    exit;
}

// Marcar la última visita al chat para este pedido
$_SESSION['ultima_visita_chat_' . $pedido_id] = date('Y-m-d H:i:s');

// MARCAR MENSAJES DEL ADMIN COMO LEÍDOS al abrir el chat
$mensajeModel->marcarLeidosCliente($pedido_id);

$mensajes = $mensajeModel->getByPedido($pedido_id);
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= asset('css/cliente-mobile.css') ?>">
<script>document.body.classList.add('cliente-app', 'chat-native');</script>

<style>
/* === CHAT MÓVIL: layout full-screen para correcto manejo del teclado === */
@media (max-width: 639px) {
    html, body { height: 100%; overflow: hidden; }
    #chat-outer {
        height: 100dvh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        padding: 8px 8px 0;
        max-width: 100%;
        margin: 0 auto;
    }
    #chat-outer > .mb-2 { flex-shrink: 0; }
    #chat-card {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-height: 0;
    }
    #area-mensajes {
        flex: 1 !important;
        height: auto !important;
        min-height: 0;
    }
    #formMensaje {
        flex-shrink: 0;
        padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));
    }
    #chat-outer > p { display: none; }
}
</style>

<div id="chat-outer" class="w-full px-2 sm:px-4 py-2 sm:py-6 max-w-3xl mx-auto">
    
    <!-- Header -->
    <div class="mb-2 sm:mb-6">
        <a href="seguimiento.php?telefono=<?= htmlspecialchars($telefono) ?>" class="text-terracotta-600 hover:underline mb-2 inline-block">
            ← Volver al seguimiento
        </a>
        <div class="card rounded-2xl shadow-lg p-3 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 mb-1">💬 Chat del Pedido</h1>
                    <p class="text-slate-600">Pedido #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-slate-600">Total</p>
                    <p class="text-xl font-bold text-terracotta-600">$<?= number_format($pedido['total'], 2) ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Contenedor de Chat -->
    <div id="chat-card" class="card rounded-2xl shadow-lg overflow-hidden">
        
        <!-- Área de Mensajes -->
        <div id="area-mensajes" class="p-3 sm:p-6 space-y-4 overflow-y-auto bg-cream-50" style="height: clamp(260px, 48vh, 500px);">
            <?php if (empty($mensajes)): ?>
                <div class="text-center py-12">
                    <div class="text-6xl mb-4">💬</div>
                    <p class="text-slate-600">No hay mensajes aún</p>
                    <p class="text-sm text-slate-500">Inicia la conversación con el proveedor</p>
                </div>
            <?php else: ?>
                <?php foreach ($mensajes as $msg): ?>
                    <?php if ($msg['usuario_tipo'] === 'cliente'): ?>
                        <!-- Mensaje del Cliente (derecha) -->
                        <div class="flex justify-end">
                            <div class="bg-terracotta-500 text-white rounded-2xl rounded-tr-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                                <p class="text-sm"><?= nl2br(htmlspecialchars($msg['mensaje'])) ?></p>
                                <p class="text-xs opacity-75 mt-1">
                                    <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Mensaje del Admin (izquierda) -->
                        <div class="flex justify-start">
                            <div class="bg-white border border-sage-200 rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                                <p class="text-xs font-semibold text-sage-600 mb-1">📦 Proveedor</p>
                                <p class="text-sm text-slate-900"><?= nl2br(convertirUrlsEnEnlaces($msg['mensaje'])) ?></p>
                                <p class="text-xs text-slate-500 mt-1">
                                    <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Formulario de Mensaje -->
        <form id="formMensaje" onsubmit="enviarMensaje(event)" class="p-2 sm:p-4 bg-white border-t border-cream-200">
            <div class="flex gap-3">
                <div class="flex-1 relative">
                    <textarea id="mensaje" 
                              name="mensaje" 
                              rows="2" 
                              placeholder="Escribe tu mensaje..."
                              class="w-full input-field px-4 py-3 rounded-xl resize-none"
                              required></textarea>
                    
                    <!-- Botón de Emojis -->
                    <button type="button" 
                            onclick="toggleEmojis()" 
                            class="absolute bottom-3 right-3 text-2xl hover:scale-110 transition-transform"
                            title="Insertar emoji">
                        😊
                    </button>
                    
                    <!-- Panel de Emojis -->
                    <div id="emojiPicker" class="hidden absolute bottom-16 right-0 bg-white rounded-xl shadow-2xl border-2 border-purple-200 p-2 z-50 w-56 sm:w-80">
                        <div class="grid grid-cols-8 gap-2 max-h-64 overflow-y-auto">
                            <button type="button" onclick="insertEmoji('😊')" class="text-2xl hover:bg-purple-100 rounded p-1">😊</button>
                            <button type="button" onclick="insertEmoji('😃')" class="text-2xl hover:bg-purple-100 rounded p-1">😃</button>
                            <button type="button" onclick="insertEmoji('😄')" class="text-2xl hover:bg-purple-100 rounded p-1">😄</button>
                            <button type="button" onclick="insertEmoji('😁')" class="text-2xl hover:bg-purple-100 rounded p-1">😁</button>
                            <button type="button" onclick="insertEmoji('😅')" class="text-2xl hover:bg-purple-100 rounded p-1">😅</button>
                            <button type="button" onclick="insertEmoji('😂')" class="text-2xl hover:bg-purple-100 rounded p-1">😂</button>
                            <button type="button" onclick="insertEmoji('🤣')" class="text-2xl hover:bg-purple-100 rounded p-1">🤣</button>
                            <button type="button" onclick="insertEmoji('😉')" class="text-2xl hover:bg-purple-100 rounded p-1">😉</button>
                            <button type="button" onclick="insertEmoji('😍')" class="text-2xl hover:bg-purple-100 rounded p-1">😍</button>
                            <button type="button" onclick="insertEmoji('🥰')" class="text-2xl hover:bg-purple-100 rounded p-1">🥰</button>
                            <button type="button" onclick="insertEmoji('😘')" class="text-2xl hover:bg-purple-100 rounded p-1">😘</button>
                            <button type="button" onclick="insertEmoji('😗')" class="text-2xl hover:bg-purple-100 rounded p-1">😗</button>
                            <button type="button" onclick="insertEmoji('😙')" class="text-2xl hover:bg-purple-100 rounded p-1">😙</button>
                            <button type="button" onclick="insertEmoji('😚')" class="text-2xl hover:bg-purple-100 rounded p-1">😚</button>
                            <button type="button" onclick="insertEmoji('🙂')" class="text-2xl hover:bg-purple-100 rounded p-1">🙂</button>
                            <button type="button" onclick="insertEmoji('🤗')" class="text-2xl hover:bg-purple-100 rounded p-1">🤗</button>
                            <button type="button" onclick="insertEmoji('🤩')" class="text-2xl hover:bg-purple-100 rounded p-1">🤩</button>
                            <button type="button" onclick="insertEmoji('🤔')" class="text-2xl hover:bg-purple-100 rounded p-1">🤔</button>
                            <button type="button" onclick="insertEmoji('🤨')" class="text-2xl hover:bg-purple-100 rounded p-1">🤨</button>
                            <button type="button" onclick="insertEmoji('😐')" class="text-2xl hover:bg-purple-100 rounded p-1">😐</button>
                            <button type="button" onclick="insertEmoji('😑')" class="text-2xl hover:bg-purple-100 rounded p-1">😑</button>
                            <button type="button" onclick="insertEmoji('😶')" class="text-2xl hover:bg-purple-100 rounded p-1">😶</button>
                            <button type="button" onclick="insertEmoji('😏')" class="text-2xl hover:bg-purple-100 rounded p-1">😏</button>
                            <button type="button" onclick="insertEmoji('😒')" class="text-2xl hover:bg-purple-100 rounded p-1">😒</button>
                            <button type="button" onclick="insertEmoji('🙄')" class="text-2xl hover:bg-purple-100 rounded p-1">🙄</button>
                            <button type="button" onclick="insertEmoji('😬')" class="text-2xl hover:bg-purple-100 rounded p-1">😬</button>
                            <button type="button" onclick="insertEmoji('😮‍💨')" class="text-2xl hover:bg-purple-100 rounded p-1">😮‍💨</button>
                            <button type="button" onclick="insertEmoji('😌')" class="text-2xl hover:bg-purple-100 rounded p-1">😌</button>
                            <button type="button" onclick="insertEmoji('😔')" class="text-2xl hover:bg-purple-100 rounded p-1">😔</button>
                            <button type="button" onclick="insertEmoji('😪')" class="text-2xl hover:bg-purple-100 rounded p-1">😪</button>
                            <button type="button" onclick="insertEmoji('🤤')" class="text-2xl hover:bg-purple-100 rounded p-1">🤤</button>
                            <button type="button" onclick="insertEmoji('😴')" class="text-2xl hover:bg-purple-100 rounded p-1">😴</button>
                            <button type="button" onclick="insertEmoji('👍')" class="text-2xl hover:bg-purple-100 rounded p-1">👍</button>
                            <button type="button" onclick="insertEmoji('👎')" class="text-2xl hover:bg-purple-100 rounded p-1">👎</button>
                            <button type="button" onclick="insertEmoji('👌')" class="text-2xl hover:bg-purple-100 rounded p-1">👌</button>
                            <button type="button" onclick="insertEmoji('✌️')" class="text-2xl hover:bg-purple-100 rounded p-1">✌️</button>
                            <button type="button" onclick="insertEmoji('🤞')" class="text-2xl hover:bg-purple-100 rounded p-1">🤞</button>
                            <button type="button" onclick="insertEmoji('🤟')" class="text-2xl hover:bg-purple-100 rounded p-1">🤟</button>
                            <button type="button" onclick="insertEmoji('🤘')" class="text-2xl hover:bg-purple-100 rounded p-1">🤘</button>
                            <button type="button" onclick="insertEmoji('🤙')" class="text-2xl hover:bg-purple-100 rounded p-1">🤙</button>
                            <button type="button" onclick="insertEmoji('👏')" class="text-2xl hover:bg-purple-100 rounded p-1">👏</button>
                            <button type="button" onclick="insertEmoji('🙌')" class="text-2xl hover:bg-purple-100 rounded p-1">🙌</button>
                            <button type="button" onclick="insertEmoji('👐')" class="text-2xl hover:bg-purple-100 rounded p-1">👐</button>
                            <button type="button" onclick="insertEmoji('🤲')" class="text-2xl hover:bg-purple-100 rounded p-1">🤲</button>
                            <button type="button" onclick="insertEmoji('🤝')" class="text-2xl hover:bg-purple-100 rounded p-1">🤝</button>
                            <button type="button" onclick="insertEmoji('🙏')" class="text-2xl hover:bg-purple-100 rounded p-1">🙏</button>
                            <button type="button" onclick="insertEmoji('✨')" class="text-2xl hover:bg-purple-100 rounded p-1">✨</button>
                            <button type="button" onclick="insertEmoji('⭐')" class="text-2xl hover:bg-purple-100 rounded p-1">⭐</button>
                            <button type="button" onclick="insertEmoji('🌟')" class="text-2xl hover:bg-purple-100 rounded p-1">🌟</button>
                            <button type="button" onclick="insertEmoji('💫')" class="text-2xl hover:bg-purple-100 rounded p-1">💫</button>
                            <button type="button" onclick="insertEmoji('💥')" class="text-2xl hover:bg-purple-100 rounded p-1">💥</button>
                            <button type="button" onclick="insertEmoji('💯')" class="text-2xl hover:bg-purple-100 rounded p-1">💯</button>
                            <button type="button" onclick="insertEmoji('🔥')" class="text-2xl hover:bg-purple-100 rounded p-1">🔥</button>
                            <button type="button" onclick="insertEmoji('❤️')" class="text-2xl hover:bg-purple-100 rounded p-1">❤️</button>
                            <button type="button" onclick="insertEmoji('🧡')" class="text-2xl hover:bg-purple-100 rounded p-1">🧡</button>
                            <button type="button" onclick="insertEmoji('💛')" class="text-2xl hover:bg-purple-100 rounded p-1">💛</button>
                            <button type="button" onclick="insertEmoji('💚')" class="text-2xl hover:bg-purple-100 rounded p-1">💚</button>
                            <button type="button" onclick="insertEmoji('💙')" class="text-2xl hover:bg-purple-100 rounded p-1">💙</button>
                            <button type="button" onclick="insertEmoji('💜')" class="text-2xl hover:bg-purple-100 rounded p-1">💜</button>
                            <button type="button" onclick="insertEmoji('🤎')" class="text-2xl hover:bg-purple-100 rounded p-1">🤎</button>
                            <button type="button" onclick="insertEmoji('🖤')" class="text-2xl hover:bg-purple-100 rounded p-1">🖤</button>
                            <button type="button" onclick="insertEmoji('🤍')" class="text-2xl hover:bg-purple-100 rounded p-1">🤍</button>
                            <button type="button" onclick="insertEmoji('💝')" class="text-2xl hover:bg-purple-100 rounded p-1">💝</button>
                            <button type="button" onclick="insertEmoji('💖')" class="text-2xl hover:bg-purple-100 rounded p-1">💖</button>
                            <button type="button" onclick="insertEmoji('💗')" class="text-2xl hover:bg-purple-100 rounded p-1">💗</button>
                            <button type="button" onclick="insertEmoji('💓')" class="text-2xl hover:bg-purple-100 rounded p-1">💓</button>
                            <button type="button" onclick="insertEmoji('💞')" class="text-2xl hover:bg-purple-100 rounded p-1">💞</button>
                            <button type="button" onclick="insertEmoji('💕')" class="text-2xl hover:bg-purple-100 rounded p-1">💕</button>
                            <button type="button" onclick="insertEmoji('🎉')" class="text-2xl hover:bg-purple-100 rounded p-1">🎉</button>
                            <button type="button" onclick="insertEmoji('🎊')" class="text-2xl hover:bg-purple-100 rounded p-1">🎊</button>
                            <button type="button" onclick="insertEmoji('🎁')" class="text-2xl hover:bg-purple-100 rounded p-1">🎁</button>
                            <button type="button" onclick="insertEmoji('🎈')" class="text-2xl hover:bg-purple-100 rounded p-1">🎈</button>
                            <button type="button" onclick="insertEmoji('✅')" class="text-2xl hover:bg-purple-100 rounded p-1">✅</button>
                            <button type="button" onclick="insertEmoji('❌')" class="text-2xl hover:bg-purple-100 rounded p-1">❌</button>
                            <button type="button" onclick="insertEmoji('⚠️')" class="text-2xl hover:bg-purple-100 rounded p-1">⚠️</button>
                            <button type="button" onclick="insertEmoji('💡')" class="text-2xl hover:bg-purple-100 rounded p-1">💡</button>
                            <button type="button" onclick="insertEmoji('📦')" class="text-2xl hover:bg-purple-100 rounded p-1">📦</button>
                            <button type="button" onclick="insertEmoji('📱')" class="text-2xl hover:bg-purple-100 rounded p-1">📱</button>
                            <button type="button" onclick="insertEmoji('💰')" class="text-2xl hover:bg-purple-100 rounded p-1">💰</button>
                            <button type="button" onclick="insertEmoji('💳')" class="text-2xl hover:bg-purple-100 rounded p-1">💳</button>
                            <button type="button" onclick="insertEmoji('🚚')" class="text-2xl hover:bg-purple-100 rounded p-1">🚚</button>
                            <button type="button" onclick="insertEmoji('📍')" class="text-2xl hover:bg-purple-100 rounded p-1">📍</button>
                            <button type="button" onclick="insertEmoji('⏰')" class="text-2xl hover:bg-purple-100 rounded p-1">⏰</button>
                            <button type="button" onclick="insertEmoji('📝')" class="text-2xl hover:bg-purple-100 rounded p-1">📝</button>
                        </div>
                    </div>
                </div>
                
                <button type="submit" 
                        class="btn-primary text-white px-3 sm:px-6 py-3 rounded-xl font-medium flex items-center gap-2 self-end">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                    <span class="hidden sm:inline">Enviar</span>
                </button>
            </div>
        </form>
        
    </div>
    
    <p class="text-xs text-slate-500 text-center mt-4">
        💡 Los mensajes se actualizan automáticamente cada 5 segundos
    </p>
    
</div>

<script>
let ultimoMensajeId = 0;
let intervaloActualizacion = null;

// === TECLADO MÓVIL: reposicionar al cerrar ===
// 100dvh maneja el achique automático. Al cerrar el teclado forzamos scroll
// a 0 para que el browser recalcule el layout y el input baje al fondo.
if (window.visualViewport) {
    let alturaAnterior = window.visualViewport.height;
    window.visualViewport.addEventListener('resize', function() {
        const vhActual = window.visualViewport.height;
        const areaMensajes = document.getElementById('area-mensajes');
        if (vhActual > alturaAnterior + 100) {
            // Teclado cerrado: forzar reflow y scroll al fondo
            window.scrollTo({ top: 0, behavior: 'instant' });
            setTimeout(() => {
                window.scrollTo({ top: 0, behavior: 'instant' });
                if (areaMensajes) areaMensajes.scrollTop = areaMensajes.scrollHeight;
            }, 100);
        } else if (areaMensajes) {
            // Teclado abierto: scroll mensajes al fondo
            setTimeout(() => { areaMensajes.scrollTop = areaMensajes.scrollHeight; }, 50);
        }
        alturaAnterior = vhActual;
    });
}

// Toggle panel de emojis
function toggleEmojis() {
    const picker = document.getElementById('emojiPicker');
    picker.classList.toggle('hidden');
}

// Insertar emoji en el textarea
function insertEmoji(emoji) {
    const textarea = document.getElementById('mensaje');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    
    // Insertar emoji en la posición del cursor
    textarea.value = text.substring(0, start) + emoji + text.substring(end);
    
    // Mover cursor después del emoji
    textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
    textarea.focus();
    
    // Cerrar panel de emojis
    document.getElementById('emojiPicker').classList.add('hidden');
}

// Cerrar panel de emojis al hacer clic fuera
document.addEventListener('click', function(e) {
    const picker = document.getElementById('emojiPicker');
    const btn = e.target.closest('button[onclick="toggleEmojis()"]');
    const emojiBtn = e.target.closest('#emojiPicker button');
    
    if (!btn && !emojiBtn && !picker.contains(e.target)) {
        picker.classList.add('hidden');
    }
});

// Enviar mensaje
function enviarMensaje(e) {
    e.preventDefault();
    
    const mensaje = document.getElementById('mensaje').value.trim();
    if (!mensaje) return;
    
    const formData = new FormData();
    formData.append('action', 'enviar_mensaje');
    formData.append('pedido_id', <?= $pedido['id'] ?>);
    formData.append('telefono', '<?= $telefono ?>');
    formData.append('mensaje', mensaje);
    
    // Quitar foco para cerrar teclado móvil
    document.getElementById('mensaje').blur();

    fetch('chat-pedido.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('mensaje').value = '';
            cargarMensajes();
        } else {
            mostrarAlerta(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('Error al enviar mensaje', 'error');
    });
}

// Cargar mensajes
function cargarMensajes() {
    const formData = new FormData();
    formData.append('action', 'obtener_mensajes');
    formData.append('pedido_id', <?= $pedido['id'] ?>);
    formData.append('telefono', '<?= $telefono ?>');
    
    fetch('chat-pedido.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderizarMensajes(data.mensajes);
        }
    })
    .catch(error => {
        console.error('Error al cargar mensajes:', error);
    });
}

// Renderizar mensajes
function renderizarMensajes(mensajes) {
    const areaMensajes = document.getElementById('area-mensajes');
    
    if (mensajes.length === 0) {
        areaMensajes.innerHTML = `
            <div class="text-center py-12">
                <div class="text-6xl mb-4">💬</div>
                <p class="text-slate-600">No hay mensajes aún</p>
                <p class="text-sm text-slate-500">Inicia la conversación con el proveedor</p>
            </div>
        `;
        return;
    }
    
    const html = mensajes.map(msg => {
        const fecha = new Date(msg.created_at).toLocaleString('es-MX', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        if (msg.usuario_tipo === 'cliente') {
            return `
                <div class="flex justify-end">
                    <div class="bg-terracotta-500 text-white rounded-2xl rounded-tr-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                        <p class="text-sm">${msg.mensaje.replace(/\n/g, '<br>')}</p>
                        <p class="text-xs opacity-75 mt-1">${fecha}</p>
                    </div>
                </div>
            `;
        } else {
            return `
                <div class="flex justify-start">
                    <div class="bg-white border border-sage-200 rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                        <p class="text-xs font-semibold text-sage-600 mb-1">📦 Proveedor</p>
                        <p class="text-sm text-slate-900">${msg.mensaje.replace(/\n/g, '<br>')}</p>
                        <p class="text-xs text-slate-500 mt-1">${fecha}</p>
                    </div>
                </div>
            `;
        }
    }).join('');
    
    areaMensajes.innerHTML = html;
    
    // Scroll al final
    areaMensajes.scrollTop = areaMensajes.scrollHeight;
}

// Auto-actualizar mensajes cada 5 segundos
intervaloActualizacion = setInterval(cargarMensajes, 5000);

// Scroll inicial al final
document.addEventListener('DOMContentLoaded', function() {
    const areaMensajes = document.getElementById('area-mensajes');
    areaMensajes.scrollTop = areaMensajes.scrollHeight;

    const textarea = document.getElementById('mensaje');
    if (textarea) {
        const resizeTextarea = () => {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        };
        textarea.addEventListener('input', resizeTextarea);
        resizeTextarea();
    }
});

// Limpiar intervalo al salir
window.addEventListener('beforeunload', function() {
    if (intervaloActualizacion) {
        clearInterval(intervaloActualizacion);
    }
    if (window.notificationManager) {
        window.notificationManager.stopMonitoring();
    }
});

// Inicializar notificaciones
document.addEventListener('DOMContentLoaded', function() {
    if (window.notificationManager) {
        // Mostrar botón para activar notificaciones
        notificationManager.showPermissionButton();
        
        // Si ya tiene permisos, iniciar monitoreo
        if (Notification.permission === 'granted') {
            // Monitorear mensajes nuevos del admin
            notificationManager.startChatMonitoring(<?= $pedido['id'] ?>, 'cliente');
            
            // Monitorear cambios de estado
            notificationManager.startStatusMonitoring(<?= $pedido['id'] ?>);
        }
    }
});
</script>

<!-- Configuración de rutas para JavaScript -->
<script>
    window.BASE_PATH = '<?= BASE_PATH ?>';
</script>

<!-- Notificaciones Push -->
<script src="<?= asset('js/notifications.js') ?>"></script>

</body>
</html>
