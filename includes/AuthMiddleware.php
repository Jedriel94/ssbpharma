<?php
/**
 * Clase AuthMiddleware
 * Middleware para autenticación y autorización de usuarios del sistema
 */
class AuthMiddleware {
    private $db;
    private $usuarioSistema;
    private $role;
    
    public function __construct($database) {
        $this->db = $database;
        require_once __DIR__ . '/../config/paths.php';
        require_once __DIR__ . '/../models/UsuarioSistema.php';
        require_once __DIR__ . '/../models/Role.php';
        $this->usuarioSistema = new UsuarioSistema($database);
        $this->role = new Role($database);
    }
    
    /**
     * Verificar si hay un usuario autenticado
     * Retorna los datos del usuario o false
     */
    public function verificarAutenticacion() {
        // Verificar si hay token en la sesión
        if (!isset($_SESSION['sistema_token'])) {
            return false;
        }
        
        $token = $_SESSION['sistema_token'];
        $sesion = $this->usuarioSistema->validarSesion($token);
        
        if (!$sesion) {
            // Token inválido o expirado
            unset($_SESSION['sistema_token']);
            return false;
        }
        
        return $sesion;
    }
    
    /**
     * Requerir autenticación - redirige al login si no está autenticado
     */
    public function requireAuth() {
        $usuario = $this->verificarAutenticacion();
        
        if (!$usuario) {
            header('Location: ' . url('login-sistema.php'));
            exit;
        }
        
        return $usuario;
    }
    
    /**
     * Verificar si el usuario tiene un permiso específico
     */
    public function tienePermiso($permiso) {
        $usuario = $this->verificarAutenticacion();
        
        if (!$usuario) {
            return false;
        }
        
        return $this->role->tienePermiso($usuario['rol_id'], $permiso);
    }
    
    /**
     * Requerir un permiso específico
     */
    public function requirePermiso($permiso, $mensaje = 'No tienes permisos para realizar esta acción') {
        if (!$this->tienePermiso($permiso)) {
            http_response_code(403);
            die(json_encode([
                'success' => false,
                'message' => $mensaje
            ]));
        }
        
        return true;
    }
    
    /**
     * Verificar si el usuario es admin
     */
    public function esAdmin() {
        $usuario = $this->verificarAutenticacion();
        
        if (!$usuario) {
            return false;
        }
        
        return $usuario['rol_codigo'] === 'admin' || $usuario['nivel_jerarquico'] == 0;
    }
    
    /**
     * Requerir rol de admin
     */
    public function requireAdmin($mensaje = 'Solo administradores pueden acceder') {
        if (!$this->esAdmin()) {
            http_response_code(403);
            die(json_encode([
                'success' => false,
                'message' => $mensaje
            ]));
        }
        
        return true;
    }
    
    /**
     * Verificar si el usuario tiene un rol específico
     */
    public function tieneRol($codigo_rol) {
        $usuario = $this->verificarAutenticacion();
        
        if (!$usuario) {
            return false;
        }
        
        return $usuario['rol_codigo'] === $codigo_rol;
    }
    
    /**
     * Verificar si el usuario puede ver datos de otro usuario
     */
    public function puedeVerUsuario($usuario_objetivo_id) {
        $usuario = $this->verificarAutenticacion();
        
        if (!$usuario) {
            return false;
        }
        
        return $this->usuarioSistema->puedeVerUsuario($usuario['usuario_id'], $usuario_objetivo_id);
    }
    
    /**
     * Obtener usuario actual
     */
    public function getUsuarioActual() {
        return $this->verificarAutenticacion();
    }
    
    /**
     * Registrar actividad del usuario
     */
    public function logActividad($accion, $modulo, $entidad_tipo = null, $entidad_id = null, $datos_anteriores = null, $datos_nuevos = null) {
        $usuario = $this->verificarAutenticacion();
        
        if (!$usuario) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO logs_actividad_sistema
            (usuario_sistema_id, accion, modulo, entidad_tipo, entidad_id, 
             datos_anteriores, datos_nuevos, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $usuario['usuario_id'],
            $accion,
            $modulo,
            $entidad_tipo,
            $entidad_id,
            $datos_anteriores ? json_encode($datos_anteriores) : null,
            $datos_nuevos ? json_encode($datos_nuevos) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    /**
     * Verificar si puede crear/editar/eliminar (solo admin)
     */
    public function puedeModificar() {
        return $this->tienePermiso('crear') || 
               $this->tienePermiso('actualizar') || 
               $this->tienePermiso('eliminar');
    }
    
    /**
     * Requerir permiso de modificación
     */
    public function requireModificar($mensaje = 'Solo administradores pueden modificar datos') {
        if (!$this->puedeModificar()) {
            http_response_code(403);
            die(json_encode([
                'success' => false,
                'message' => $mensaje
            ]));
        }
        
        return true;
    }
}
