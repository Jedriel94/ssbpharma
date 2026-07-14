<?php
require_once __DIR__ . '/../config/database.php';

class MensajePedido {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // Crear nuevo mensaje
    public function create($pedido_id, $usuario_tipo, $mensaje) {
        try {
            // TODOS los mensajes nuevos empiezan como NO LEÍDOS (leido = 0)
            // Se marcarán como leídos cuando el receptor abra el chat:
            // - Mensajes del cliente: marcarLeidosAdmin() cuando admin abre chat
            // - Mensajes del admin: marcarLeidosCliente() cuando cliente abre chat
            $leido = 0;
            
            $sql = "INSERT INTO mensajes_pedido (pedido_id, usuario_tipo, mensaje, leido) 
                    VALUES (:pedido_id, :usuario_tipo, :mensaje, :leido)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':pedido_id', $pedido_id);
            $stmt->bindParam(':usuario_tipo', $usuario_tipo);
            $stmt->bindParam(':mensaje', $mensaje);
            $stmt->bindParam(':leido', $leido);
            
            if ($stmt->execute()) {
                $mensaje_id = $this->db->lastInsertId();
                
                // NUEVA FUNCIONALIDAD: Detectar URLs en mensajes del admin
                if ($usuario_tipo === 'admin') {
                    $this->detectarYGuardarLigaPago($pedido_id, $mensaje);
                }
                
                return $mensaje_id;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Error al crear mensaje: " . $e->getMessage());
            throw new Exception("Error al crear mensaje: " . $e->getMessage());
        }
    }
    
    // Detectar URLs en el mensaje y guardar como liga de pago
    private function detectarYGuardarLigaPago($pedido_id, $mensaje) {
        // Buscar URLs en el mensaje (patrón regex)
        $patron = '/(https?:\/\/[^\s]+)/i';
        if (preg_match($patron, $mensaje, $matches)) {
            $url = $matches[1];
            
            // Guardar la URL en el campo liga_pago del pedido
            try {
                $sql = "UPDATE pedidos SET liga_pago = :liga_pago WHERE id = :pedido_id";
                $stmt = $this->db->prepare($sql);
                $stmt->bindParam(':liga_pago', $url);
                $stmt->bindParam(':pedido_id', $pedido_id);
                $stmt->execute();
                
                error_log("Liga de pago guardada automáticamente: $url para pedido #$pedido_id");
            } catch (PDOException $e) {
                error_log("Error al guardar liga de pago: " . $e->getMessage());
            }
        }
    }
    
    // Obtener todos los mensajes de un pedido
    public function getByPedido($pedido_id) {
        $sql = "SELECT * FROM mensajes_pedido 
                WHERE pedido_id = :pedido_id 
                ORDER BY created_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Contar mensajes NO LEÍDOS del admin (para el cliente)
    public function contarNoLeidosCliente($pedido_id) {
        $sql = "SELECT COUNT(*) as total FROM mensajes_pedido 
                WHERE pedido_id = :pedido_id 
                AND usuario_tipo = 'admin' 
                AND leido = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'];
    }
    
    // Contar mensajes NO LEÍDOS del cliente (para el admin)
    public function contarNoLeidosAdmin($pedido_id) {
        $sql = "SELECT COUNT(*) as total FROM mensajes_pedido 
                WHERE pedido_id = :pedido_id 
                AND usuario_tipo = 'cliente' 
                AND leido = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'];
    }
    
    // Marcar todos los mensajes del cliente como leídos (cuando admin abre el chat)
    public function marcarLeidosAdmin($pedido_id) {
        $sql = "UPDATE mensajes_pedido 
                SET leido = 1 
                WHERE pedido_id = :pedido_id 
                AND usuario_tipo = 'cliente' 
                AND leido = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        return $stmt->execute();
    }
    
    // Marcar todos los mensajes del admin como leídos (cuando cliente abre el chat)
    public function marcarLeidosCliente($pedido_id) {
        $sql = "UPDATE mensajes_pedido 
                SET leido = 1 
                WHERE pedido_id = :pedido_id 
                AND usuario_tipo = 'admin' 
                AND leido = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        return $stmt->execute();
    }
    
    // Eliminar mensajes de un pedido
    public function deleteByPedido($pedido_id) {
        $sql = "DELETE FROM mensajes_pedido WHERE pedido_id = :pedido_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        return $stmt->execute();
    }
}
