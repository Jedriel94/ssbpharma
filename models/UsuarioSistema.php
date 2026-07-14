<?php
/**
 * Clase UsuarioSistema
 * Gestiona usuarios internos del sistema (staff/empleados)
 * NO confundir con clientes que compran en la tienda
 */
class UsuarioSistema {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Crear nuevo usuario del sistema
     */
    public function crear($datos) {
        $stmt = $this->db->prepare("
            INSERT INTO usuarios_sistema 
            (username, password, nombre, email, telefono, rol_id, superior_id, representante_id, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Hash del password
        $password_hash = password_hash($datos['password'], PASSWORD_DEFAULT);
        
        $stmt->execute([
            $datos['username'],
            $password_hash,
            $datos['nombre'],
            $datos['email'],
            $datos['telefono'] ?? null,
            $datos['rol_id'],
            $datos['superior_id'] ?? null,
            $datos['representante_id'] ?? null,
            $datos['activo'] ?? 1
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Actualizar usuario del sistema (solo admin puede hacer esto)
     */
    public function actualizar($id, $datos) {
        $campos = [];
        $valores = [];
        
        $campos_permitidos = ['username', 'nombre', 'email', 'telefono', 'rol_id', 'superior_id', 'representante_id', 'activo'];
        
        foreach ($campos_permitidos as $campo) {
            if (isset($datos[$campo])) {
                $campos[] = "$campo = ?";
                $valores[] = $datos[$campo];
            }
        }
        
        if (empty($campos)) {
            return false;
        }
        
        $valores[] = $id;
        
        $sql = "UPDATE usuarios_sistema SET " . implode(', ', $campos) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($valores);
    }
    
    /**
     * Cambiar contraseña
     */
    public function cambiarPassword($id, $nueva_password) {
        $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("
            UPDATE usuarios_sistema 
            SET password = ? 
            WHERE id = ?
        ");
        
        return $stmt->execute([$password_hash, $id]);
    }
    
    /**
     * Obtener usuario por ID con información de rol
     */
    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT 
                u.id, u.username, u.nombre, u.email, u.telefono,
                u.rol_id, u.superior_id, u.representante_id, u.activo,
                u.ultimo_acceso, u.created_at, u.updated_at,
                r.nombre as rol_nombre, r.codigo as rol_codigo, 
                r.nivel_jerarquico, r.permisos as rol_permisos,
                s.nombre as superior_nombre,
                rep.nombre as representante_nombre, rep.codigo as representante_codigo
            FROM usuarios_sistema u
            INNER JOIN roles r ON u.rol_id = r.id
            LEFT JOIN usuarios_sistema s ON u.superior_id = s.id
            LEFT JOIN representantes rep ON u.representante_id = rep.id
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener usuario por username
     */
    public function getByUsername($username) {
        $stmt = $this->db->prepare("
            SELECT 
                u.id, u.username, u.password, u.nombre, u.email, u.telefono,
                u.rol_id, u.superior_id, u.representante_id, u.activo,
                u.ultimo_acceso, u.created_at, u.updated_at,
                r.nombre as rol_nombre, r.codigo as rol_codigo, 
                r.nivel_jerarquico, r.permisos as rol_permisos,
                s.nombre as superior_nombre
            FROM usuarios_sistema u
            INNER JOIN roles r ON u.rol_id = r.id
            LEFT JOIN usuarios_sistema s ON u.superior_id = s.id
            WHERE u.username = ? AND u.activo = 1
        ");
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Login de usuario del sistema
     */
    public function login($username, $password) {
        $usuario = $this->getByUsername($username);
        
        if (!$usuario) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }
        
        if (!password_verify($password, $usuario['password'])) {
            return ['success' => false, 'message' => 'Contraseña incorrecta'];
        }
        
        // Actualizar último acceso
        $this->actualizarUltimoAcceso($usuario['id']);
        
        // Crear sesión
        $token = $this->crearSesion($usuario['id']);
        
        // No devolver el password
        unset($usuario['password']);
        
        return [
            'success' => true,
            'usuario' => $usuario,
            'token' => $token
        ];
    }
    
    /**
     * Crear sesión
     */
    private function crearSesion($usuario_id) {
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+8 hours'));
        
        $stmt = $this->db->prepare("
            INSERT INTO sesiones_sistema 
            (usuario_sistema_id, token, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $usuario_id,
            $token,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expires_at
        ]);
        
        return $token;
    }
    
    /**
     * Validar sesión por token
     */
    public function validarSesion($token) {
        $stmt = $this->db->prepare("
            SELECT 
                s.id, s.usuario_sistema_id, s.expires_at,
                u.id as usuario_id, u.username, u.nombre, u.email,
                u.rol_id, r.codigo as rol_codigo, r.permisos as rol_permisos,
                r.nivel_jerarquico
            FROM sesiones_sistema s
            INNER JOIN usuarios_sistema u ON s.usuario_sistema_id = u.id
            INNER JOIN roles r ON u.rol_id = r.id
            WHERE s.token = ? 
            AND s.expires_at > NOW()
            AND u.activo = 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Cerrar sesión
     */
    public function logout($token) {
        $stmt = $this->db->prepare("DELETE FROM sesiones_sistema WHERE token = ?");
        return $stmt->execute([$token]);
    }
    
    /**
     * Actualizar último acceso
     */
    private function actualizarUltimoAcceso($usuario_id) {
        $stmt = $this->db->prepare("
            UPDATE usuarios_sistema 
            SET ultimo_acceso = NOW() 
            WHERE id = ?
        ");
        return $stmt->execute([$usuario_id]);
    }
    
    /**
     * Obtener todos los usuarios del sistema
     */
    public function getAll($filtros = []) {
        $sql = "
            SELECT 
                u.id, u.username, u.nombre, u.email, u.telefono,
                u.activo, u.ultimo_acceso, u.created_at,
                r.nombre as rol_nombre, r.codigo as rol_codigo,
                r.nivel_jerarquico,
                s.nombre as superior_nombre,
                rep.nombre as representante_nombre
            FROM usuarios_sistema u
            INNER JOIN roles r ON u.rol_id = r.id
            LEFT JOIN usuarios_sistema s ON u.superior_id = s.id
            LEFT JOIN representantes rep ON u.representante_id = rep.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (isset($filtros['rol_id'])) {
            $sql .= " AND u.rol_id = ?";
            $params[] = $filtros['rol_id'];
        }
        
        if (isset($filtros['superior_id'])) {
            $sql .= " AND u.superior_id = ?";
            $params[] = $filtros['superior_id'];
        }
        
        if (isset($filtros['activo'])) {
            $sql .= " AND u.activo = ?";
            $params[] = $filtros['activo'];
        }
        
        $sql .= " ORDER BY r.nivel_jerarquico ASC, u.nombre ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener subordinados directos de un usuario
     */
    public function getSubordinados($usuario_id) {
        $stmt = $this->db->prepare("
            SELECT 
                u.id, u.username, u.nombre, u.email, u.activo,
                r.nombre as rol_nombre, r.codigo as rol_codigo
            FROM usuarios_sistema u
            INNER JOIN roles r ON u.rol_id = r.id
            WHERE u.superior_id = ?
            ORDER BY u.nombre ASC
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener toda la jerarquía bajo un usuario
     */
    public function getJerarquia($usuario_id) {
        // Obtener subordinados directos
        $subordinados = $this->getSubordinados($usuario_id);
        
        $jerarquia = [];
        foreach ($subordinados as $subordinado) {
            $jerarquia[] = [
                'usuario' => $subordinado,
                'subordinados' => $this->getJerarquia($subordinado['id']) // Recursivo
            ];
        }
        
        return $jerarquia;
    }
    
    /**
     * Verificar si un usuario puede ver datos de otro (basado en jerarquía)
     */
    public function puedeVerUsuario($usuario_actual_id, $usuario_objetivo_id) {
        $actual = $this->getById($usuario_actual_id);
        $objetivo = $this->getById($usuario_objetivo_id);
        
        if (!$actual || !$objetivo) {
            return false;
        }
        
        // Admin puede ver todo
        if ($actual['nivel_jerarquico'] == 0) {
            return true;
        }
        
        // Puede verse a sí mismo
        if ($usuario_actual_id == $usuario_objetivo_id) {
            return true;
        }
        
        // Verificar si está en su jerarquía
        return $this->estaEnJerarquia($usuario_actual_id, $usuario_objetivo_id);
    }
    
    /**
     * Verificar si un usuario está en la jerarquía de otro (es subordinado)
     */
    private function estaEnJerarquia($superior_id, $subordinado_id) {
        $subordinados = $this->getSubordinados($superior_id);
        
        foreach ($subordinados as $sub) {
            if ($sub['id'] == $subordinado_id) {
                return true;
            }
            // Buscar recursivamente
            if ($this->estaEnJerarquia($sub['id'], $subordinado_id)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Eliminar usuario (solo admin)
     */
    public function eliminar($id) {
        $stmt = $this->db->prepare("DELETE FROM usuarios_sistema WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Desactivar usuario (preferible a eliminar)
     */
    public function desactivar($id) {
        $stmt = $this->db->prepare("UPDATE usuarios_sistema SET activo = 0 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Activar usuario
     */
    public function activar($id) {
        $stmt = $this->db->prepare("UPDATE usuarios_sistema SET activo = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
