<?php
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../models/Pedido.php';
require_once __DIR__ . '/../models/Cliente.php';
require_once __DIR__ . '/../models/MensajePedido.php';

$pedidoModel = new Pedido();
$clienteModel = new Cliente();
$mensajeModel = new MensajePedido();

// Procesar AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'enviar_mensaje':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $mensaje = trim($_POST['mensaje'] ?? '');
            
            if (empty($mensaje)) {
                echo json_encode(['success' => false, 'message' => 'El mensaje no puede estar vacío']);
                exit;
            }
            
            $mensaje_id = $mensajeModel->create($pedido_id, 'admin', $mensaje);
            
            if ($mensaje_id) {
                echo json_encode(['success' => true, 'message' => 'Mensaje enviado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al enviar mensaje']);
            }
            exit;
            
        case 'obtener_mensajes':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $mensajes = $mensajeModel->getByPedido($pedido_id);
            echo json_encode(['success' => true, 'mensajes' => $mensajes]);
            exit;
    }
}

// Obtener datos
$pedido_id = $_GET['pedido_id'] ?? 0;
$return_to = $_GET['return'] ?? 'pedidos'; // Por defecto vuelve a pedidos.php

if (empty($pedido_id)) {
    header('Location: pedidos.php');
    exit;
}

$pedido = $pedidoModel->getById($pedido_id);

if (!$pedido) {
    header('Location: pedidos.php');
    exit;
}

// Determinar la URL de regreso
$return_url = 'pedidos.php';
$return_label = 'Pedidos';

if ($return_to === 'kanban') {
    $return_url = 'kanban.php';
    $return_label = 'Kanban';
} elseif ($return_to === 'historial') {
    $return_url = 'pedidos-historial.php';
    $return_label = 'Historial';
}

$cliente = $clienteModel->getByTelefono($pedido['telefono']);
$mensajes = $mensajeModel->getByPedido($pedido_id);

// MARCAR MENSAJES DEL CLIENTE COMO LEÍDOS al abrir el chat
$mensajeModel->marcarLeidosAdmin($pedido_id);
?>

<?php include '../includes/header.php'; ?>

<div class="w-full px-2 sm:px-4 py-2 sm:py-6 max-w-4xl mx-auto">
    
    <!-- Header -->
    <div class="mb-2 sm:mb-6">
        <a href="<?= htmlspecialchars($return_url) ?>" class="text-terracotta-600 hover:underline mb-2 inline-block">
            ← Volver a <?= htmlspecialchars($return_label) ?>
        </a>
        <div class="card rounded-2xl shadow-lg p-3 sm:p-6">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 mb-1">💬 Chat - Pedido #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?></h1>
                    <p class="text-slate-600">
                        📱 <?= htmlspecialchars($pedido['telefono']) ?>
                        <?php if (!empty($cliente['nombre'])): ?>
                            - <?= htmlspecialchars($cliente['nombre']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-slate-600">Total del pedido</p>
                    <p class="text-xl font-bold text-terracotta-600">$<?= number_format($pedido['total'], 2) ?></p>
                    <p class="text-xs text-slate-500 mt-1">
                        Estado: <span class="font-semibold capitalize"><?= htmlspecialchars($pedido['estado']) ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Contenedor de Chat -->
    <div class="card rounded-2xl shadow-lg overflow-hidden">
        
        <!-- Área de Mensajes -->
        <div id="area-mensajes" class="p-3 sm:p-6 space-y-4 overflow-y-auto bg-cream-50" style="height: clamp(260px, 48vh, 500px);">
            <?php if (empty($mensajes)): ?>
                <div class="text-center py-12">
                    <div class="text-6xl mb-4">💬</div>
                    <p class="text-slate-600">No hay mensajes aún</p>
                    <p class="text-sm text-slate-500">Inicia la conversación con el cliente</p>
                </div>
            <?php else: ?>
                <?php foreach ($mensajes as $msg): ?>
                    <?php if ($msg['usuario_tipo'] === 'admin'): ?>
                        <!-- Mensaje del Admin (derecha) -->
                        <div class="flex justify-end">
                            <div class="bg-sage-600 text-white rounded-2xl rounded-tr-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                                <p class="text-xs opacity-75 mb-1">📦 Tú (Proveedor)</p>
                                <p class="text-sm"><?= nl2br(htmlspecialchars($msg['mensaje'])) ?></p>
                                <p class="text-xs opacity-75 mt-1">
                                    <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Mensaje del Cliente (izquierda) -->
                        <div class="flex justify-start">
                            <div class="bg-white border border-terracotta-200 rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                                <p class="text-xs font-semibold text-terracotta-600 mb-1">👤 Cliente</p>
                                <p class="text-sm text-slate-900"><?= nl2br(htmlspecialchars($msg['mensaje'])) ?></p>
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
                              placeholder="Escribe tu respuesta al cliente..."
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
    
    <!-- Respuestas Rápidas -->
    <div class="mt-6 card rounded-2xl shadow-lg p-4">
        <p class="text-sm font-semibold text-slate-700 mb-3">⚡ Respuestas Rápidas:</p>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
            <button onclick="usarRespuestaRapida('Gracias por tu pedido. Estamos procesando tu pago.')" 
                    class="text-sm bg-cream-100 hover:bg-cream-200 text-slate-700 px-3 py-2 rounded-lg transition">
                💳 Procesando pago
            </button>
            <button onclick="usarRespuestaRapida('Tu pedido ha sido confirmado y está siendo preparado.')" 
                    class="text-sm bg-cream-100 hover:bg-cream-200 text-slate-700 px-3 py-2 rounded-lg transition">
                📦 En preparación
            </button>
            <button onclick="usarRespuestaRapida('Tu pedido está en camino. ¡Pronto lo recibirás!')" 
                    class="text-sm bg-cream-100 hover:bg-cream-200 text-slate-700 px-3 py-2 rounded-lg transition">
                🚚 En camino
            </button>
            <button onclick="usarRespuestaRapida('¿Necesitas modificar algo de tu pedido? Dime en qué puedo ayudarte.')" 
                    class="text-sm bg-cream-100 hover:bg-cream-200 text-slate-700 px-3 py-2 rounded-lg transition">
                ❓ ¿Necesitas ayuda?
            </button>
            <button onclick="usarRespuestaRapida('Gracias por tu compra. ¿Todo llegó en buen estado?')" 
                    class="text-sm bg-cream-100 hover:bg-cream-200 text-slate-700 px-3 py-2 rounded-lg transition">
                ✅ Confirmación entrega
            </button>
            <button onclick="usarRespuestaRapida('Disculpa la demora. Te actualizaré pronto sobre tu pedido.')" 
                    class="text-sm bg-cream-100 hover:bg-cream-200 text-slate-700 px-3 py-2 rounded-lg transition">
                ⏰ Disculpa demora
            </button>
        </div>
    </div>
    
    <p class="text-xs text-slate-500 text-center mt-4">
        💡 Los mensajes se actualizan automáticamente cada 5 segundos
    </p>
    
</div>

<script>
let ultimoMensajeId = 0;
let intervaloActualizacion = null;

// === FIX TECLADO MÓVIL ===
(function() {
    const areaMensajes = document.getElementById('area-mensajes');
    const form = document.getElementById('formMensaje');
    let tecladoAbierto = false;
    let alturaInicial = null;

    function recalcularAltura() {
        if (!window.visualViewport || !areaMensajes) return;
        const vh = window.visualViewport.height;
        const formH = form ? form.offsetHeight : 80;
        const nuevaAltura = Math.max(180, vh - formH - 110);
        areaMensajes.style.height = nuevaAltura + 'px';
        setTimeout(() => { areaMensajes.scrollTop = areaMensajes.scrollHeight; }, 50);
    }

    function restaurarAltura() {
        if (!areaMensajes) return;
        areaMensajes.style.height = 'clamp(260px, 48vh, 500px)';
        areaMensajes.scrollTop = areaMensajes.scrollHeight;
        window.scrollTo({ top: 0, behavior: 'instant' });
    }

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function() {
            if (!alturaInicial) alturaInicial = window.innerHeight;
            const vh = window.visualViewport.height;
            const tecladoVisible = (alturaInicial - vh) > 150;

            if (tecladoVisible) {
                tecladoAbierto = true;
                recalcularAltura();
            } else if (tecladoAbierto) {
                tecladoAbierto = false;
                setTimeout(restaurarAltura, 80);
            }
        });
    }

    // Guardar altura al cargar
    window.addEventListener('load', function() {
        alturaInicial = window.innerHeight;
    });
})();

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
    formData.append('mensaje', mensaje);
    
    // Quitar foco para cerrar teclado móvil
    document.getElementById('mensaje').blur();

    fetch('chat-admin.php', {
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
    
    fetch('chat-admin.php', {
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
                <p class="text-sm text-slate-500">Inicia la conversación con el cliente</p>
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
        
        if (msg.usuario_tipo === 'admin') {
            return `
                <div class="flex justify-end">
                    <div class="bg-sage-600 text-white rounded-2xl rounded-tr-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                        <p class="text-xs opacity-75 mb-1">📦 Tú (Proveedor)</p>
                        <p class="text-sm">${msg.mensaje.replace(/\n/g, '<br>')}</p>
                        <p class="text-xs opacity-75 mt-1">${fecha}</p>
                    </div>
                </div>
            `;
        } else {
            return `
                <div class="flex justify-start">
                    <div class="bg-white border border-terracotta-200 rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%] sm:max-w-xs lg:max-w-md shadow">
                        <p class="text-xs font-semibold text-terracotta-600 mb-1">👤 Cliente</p>
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

// Usar respuesta rápida
function usarRespuestaRapida(texto) {
    document.getElementById('mensaje').value = texto;
    document.getElementById('mensaje').focus();
}

// Auto-actualizar mensajes cada 5 segundos
intervaloActualizacion = setInterval(cargarMensajes, 5000);

// Scroll inicial al final
document.addEventListener('DOMContentLoaded', function() {
    const areaMensajes = document.getElementById('area-mensajes');
    areaMensajes.scrollTop = areaMensajes.scrollHeight;
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

// Atajo de teclado: Ctrl+Enter para enviar
document.getElementById('mensaje').addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'Enter') {
        document.getElementById('formMensaje').dispatchEvent(new Event('submit'));
    }
});

// Inicializar notificaciones
document.addEventListener('DOMContentLoaded', function() {
    if (window.notificationManager) {
        // Mostrar botón para activar notificaciones
        notificationManager.showPermissionButton();
        
        // Si ya tiene permisos, iniciar monitoreo
        if (Notification.permission === 'granted') {
            // Monitorear mensajes nuevos del cliente
            notificationManager.startChatMonitoring(<?= $pedido['id'] ?>, 'admin');
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
