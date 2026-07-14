// Sistema de Notificaciones Push del Navegador
class NotificationManager {
    constructor() {
        this.permission = Notification.permission;
        this.checkInterval = null;
        this.lastCheck = null;
        // Obtener BASE_PATH del window o usar fallback para localhost
        this.basePath = window.BASE_PATH || '/botikitpedidos/';
    }

    // Solicitar permiso para notificaciones
    async requestPermission() {
        if (!("Notification" in window)) {
            console.log("Este navegador no soporta notificaciones");
            return false;
        }

        if (this.permission === "granted") {
            return true;
        }

        if (this.permission !== "denied") {
            const permission = await Notification.requestPermission();
            this.permission = permission;
            return permission === "granted";
        }

        return false;
    }

    // Mostrar notificación
    show(title, options = {}) {
        if (this.permission !== "granted") {
            console.log("No hay permiso para mostrar notificaciones");
            return;
        }

        const defaultOptions = {
            icon: this.basePath + 'uploads/logo.png',
            badge: this.basePath + 'uploads/logo.png',
            vibrate: [200, 100, 200],
            requireInteraction: false,
            ...options
        };

        const notification = new Notification(title, defaultOptions);

        // Auto-cerrar después de 5 segundos
        setTimeout(() => notification.close(), 5000);

        // Manejar clic en la notificación
        notification.onclick = function(event) {
            event.preventDefault();
            window.focus();
            if (options.url) {
                window.location.href = options.url;
            }
            notification.close();
        };

        return notification;
    }

    // Iniciar verificación periódica de mensajes nuevos (para chat)
    startChatMonitoring(pedidoId, userType = 'cliente') {
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }

        this.lastCheck = new Date().toISOString();

        // Verificar cada 10 segundos
        this.checkInterval = setInterval(() => {
            this.checkNewMessages(pedidoId, userType);
        }, 10000);
    }

    // Verificar mensajes nuevos
    async checkNewMessages(pedidoId, userType) {
        try {
            const formData = new FormData();
            formData.append('action', 'check_new_messages');
            formData.append('pedido_id', pedidoId);
            formData.append('user_type', userType);
            formData.append('last_check', this.lastCheck);

            const response = await fetch(this.basePath + 'api/check-notifications.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.has_new_messages) {
                const messageText = data.count > 1 
                    ? `${data.count} mensajes nuevos` 
                    : '1 mensaje nuevo';

                this.show('💬 Nuevo mensaje en el chat', {
                    body: messageText,
                    tag: 'chat-' + pedidoId,
                    url: userType === 'cliente' 
                        ? this.basePath + `chat-pedido.php?id=${pedidoId}`
                        : this.basePath + `admin/chat-pedido.php?id=${pedidoId}`
                });

                // Reproducir sonido
                this.playNotificationSound();
            }

            this.lastCheck = new Date().toISOString();
        } catch (error) {
            console.error('Error checking messages:', error);
        }
    }

    // Iniciar verificación de cambios de estado (solo para clientes)
    startStatusMonitoring(pedidoId) {
        if (this.statusCheckInterval) {
            clearInterval(this.statusCheckInterval);
        }

        this.lastStatusCheck = new Date().toISOString();

        // Verificar cada 30 segundos
        this.statusCheckInterval = setInterval(() => {
            this.checkStatusChange(pedidoId);
        }, 30000);
    }

    // Verificar cambios de estado
    async checkStatusChange(pedidoId) {
        try {
            const formData = new FormData();
            formData.append('action', 'check_status_change');
            formData.append('pedido_id', pedidoId);
            formData.append('last_check', this.lastStatusCheck);

            const response = await fetch(this.basePath + 'api/check-notifications.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.status_changed) {
                const estadosTexto = {
                    'pendiente': 'Pendiente',
                    'por_verificar': 'Por Verificar',
                    'confirmado': 'Confirmado ✅',
                    'en_ruta': 'En Ruta 🚚',
                    'entregado': 'Entregado 📦',
                    'cancelado': 'Cancelado'
                };

                const estadoTexto = estadosTexto[data.new_status] || data.new_status;

                this.show('📦 Estado de pedido actualizado', {
                    body: `Tu pedido #${pedidoId} ahora está: ${estadoTexto}`,
                    tag: 'status-' + pedidoId,
                    url: this.basePath + 'seguimiento.php'
                });

                // Reproducir sonido
                this.playNotificationSound();
            }

            this.lastStatusCheck = new Date().toISOString();
        } catch (error) {
            console.error('Error checking status:', error);
        }
    }

    // Reproducir sonido de notificación
    playNotificationSound() {
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBji');
            audio.volume = 0.3;
            audio.play().catch(e => console.log('Error playing sound:', e));
        } catch (e) {
            console.log('Error playing notification sound:', e);
        }
    }

    // Detener monitoreo
    stopMonitoring() {
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
            this.checkInterval = null;
        }
        if (this.statusCheckInterval) {
            clearInterval(this.statusCheckInterval);
            this.statusCheckInterval = null;
        }
    }

    // Mostrar botón para solicitar permisos
    showPermissionButton() {
        if (!("Notification" in window)) {
            return;
        }

        if (Notification.permission === "granted") {
            return;
        }

        if (Notification.permission === "denied") {
            return;
        }

        // Crear botón flotante
        const button = document.createElement('button');
        button.id = 'notification-permission-btn';
        button.className = 'fixed bottom-4 right-4 bg-terracotta-500 text-white px-6 py-3 rounded-full shadow-lg hover:bg-terracotta-600 transition flex items-center gap-2 z-50 animate-bounce';
        button.innerHTML = `
            <span class="text-xl">🔔</span>
            <span class="font-semibold">Activar Notificaciones</span>
        `;

        button.onclick = async () => {
            const granted = await this.requestPermission();
            if (granted) {
                button.remove();
                this.show('✅ Notificaciones activadas', {
                    body: 'Recibirás notificaciones de mensajes y cambios de estado'
                });
            }
        };

        document.body.appendChild(button);
    }
}

// Crear instancia global
window.notificationManager = new NotificationManager();
