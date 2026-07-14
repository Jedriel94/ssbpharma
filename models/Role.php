<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Clase Role
 * Gestiona los roles del sistema y sus permisos
 */
class Role {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    
    /**
     * Obtener todos los roles
     */
    public function getAll() {
        $stmt = $this->conn->prepare("
            SELECT id, nombre, codigo, nivel_jerarquico, descripcion, permisos, 
                   created_at, updated_at
            FROM roles 
            ORDER BY nivel_jerarquico ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener rol por ID
     */
    public function getById($id) {
        $stmt = $this->conn->prepare("
            SELECT id, nombre, codigo, nivel_jerarquico, descripcion, permisos, 
                   created_at, updated_at
            FROM roles 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener rol por código
     */
    public function getByCodigo($codigo) {
        $stmt = $this->conn->prepare("
            SELECT id, nombre, codigo, nivel_jerarquico, descripcion, permisos, 
                   created_at, updated_at
            FROM roles 
            WHERE codigo = ?
        ");
        $stmt->execute([$codigo]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar si un rol tiene un permiso específico
     */
    public function tienePermiso($rol_id, $permiso) {
        $rol = $this->getById($rol_id);
        if (!$rol || !$rol['permisos']) {
            return false;
        }
        
        $permisos = json_decode($rol['permisos'], true);
        
        // Admin tiene todos los permisos
        if (isset($permisos['acceso_total']) && $permisos['acceso_total']) {
            return true;
        }
        
        return isset($permisos[$permiso]) && $permisos[$permiso];
    }
    
    /**
     * Obtener permisos de un rol
     */
    public function getPermisos($rol_id) {
        $rol = $this->getById($rol_id);
        if (!$rol || !$rol['permisos']) {
            return [];
        }
        
        return json_decode($rol['permisos'], true);
    }
    
    /**
     * Verificar si un rol puede gestionar otro rol (basado en jerarquía)
     */
    public function puedeGestionar($rol_gestor_id, $rol_gestionado_id) {
        $rol_gestor = $this->getById($rol_gestor_id);
        $rol_gestionado = $this->getById($rol_gestionado_id);
        
        if (!$rol_gestor || !$rol_gestionado) {
            return false;
        }
        
        // Admin (nivel 0) puede gestionar todos
        if ($rol_gestor['nivel_jerarquico'] == 0) {
            return true;
        }
        
        // Solo puede gestionar roles de nivel inferior
        return $rol_gestor['nivel_jerarquico'] < $rol_gestionado['nivel_jerarquico'];
    }
    
    /**
     * Obtener roles subordinados (niveles jerárquicos inferiores)
     */
    public function getRolesSubordinados($nivel_jerarquico) {
        $stmt = $this->conn->prepare("
            SELECT id, nombre, codigo, nivel_jerarquico, descripcion
            FROM roles 
            WHERE nivel_jerarquico > ?
            ORDER BY nivel_jerarquico ASC
        ");
        $stmt->execute([$nivel_jerarquico]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
