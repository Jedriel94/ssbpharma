<?php
require_once __DIR__ . '/../config/database.php';

class Administrador {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    
    // Autenticar administrador por EMAIL
    public function autenticar($email, $password) {
        $query = "SELECT * FROM administradores WHERE email = :email AND activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify($password, $admin['password'])) {
            return $admin;
        }
        
        return false;
    }

    // Obtener administrador por email
    public function getByEmail($email) {
        $query = "SELECT * FROM administradores WHERE email = :email AND activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Obtener administrador por token de reset
    public function getByResetToken($token) {
        $query = "SELECT * FROM administradores WHERE reset_token = :token AND reset_token_expires > NOW() AND activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Guardar token de reset (expira en 1 hora)
    public function setResetToken($id, $token) {
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $query = "UPDATE administradores SET reset_token = :token, reset_token_expires = :expires WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':expires', $expires);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // Limpiar token de reset tras uso
    public function clearResetToken($id) {
        $query = "UPDATE administradores SET reset_token = NULL, reset_token_expires = NULL WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
    
    // Obtener todos los administradores CON ROLES
    public function getAll() {
        $query = "SELECT 
            a.id, a.usuario, a.email, a.nombre, a.activo, a.created_at,
            a.rol_id, a.superior_id, a.ruta, a.desc_ruta, a.celular,
            r.nombre as rol_nombre, r.codigo as rol_codigo, r.nivel_jerarquico,
            s.nombre as superior_nombre,
            rp.codigo as representante_codigo,
            CASE WHEN rp.id IS NOT NULL THEN a.nombre ELSE NULL END as representante_nombre,
            rp.telefono as representante_telefono,
            rp.email as representante_email,
            rp.tags_permitidos as representante_tags_permitidos
        FROM administradores a
        LEFT JOIN roles r ON a.rol_id = r.id
        LEFT JOIN administradores s ON a.superior_id = s.id
        LEFT JOIN representante_perfiles rp ON rp.admin_id = a.id
        ORDER BY r.nivel_jerarquico ASC, a.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener administrador por ID CON ROLES
    public function getById($id) {
        $query = "SELECT 
            a.id, a.usuario, a.email, a.nombre, a.activo, a.created_at,
            a.rol_id, a.superior_id, a.ruta, a.desc_ruta, a.celular,
            r.nombre as rol_nombre, r.codigo as rol_codigo, r.nivel_jerarquico, r.permisos,
            s.nombre as superior_nombre,
            rp.codigo as representante_codigo,
            CASE WHEN rp.id IS NOT NULL THEN a.nombre ELSE NULL END as representante_nombre,
            rp.telefono as representante_telefono,
            rp.email as representante_email,
            rp.tags_permitidos as representante_tags_permitidos
        FROM administradores a
        LEFT JOIN roles r ON a.rol_id = r.id
        LEFT JOIN administradores s ON a.superior_id = s.id
        LEFT JOIN representante_perfiles rp ON rp.admin_id = a.id
        WHERE a.id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Crear nuevo administrador CON ROL (soporta array o parámetros tradicionales)
    public function create($datos) {
        // Si el primer argumento es array, usar nueva sintaxis
        if (is_array($datos)) {
            $usuario = $datos['usuario'];
            $nombre = $datos['nombre'];
            $password = $datos['password'];
            $rol_id = $datos['rol_id'] ?? 1;
            $superior_id = $datos['superior_id'] ?? null;
            $email     = isset($datos['email'])     && $datos['email']     !== '' ? $datos['email']     : null;
            $ruta      = isset($datos['ruta'])      && $datos['ruta']      !== '' ? $datos['ruta']      : null;
            $desc_ruta = isset($datos['desc_ruta']) && $datos['desc_ruta'] !== '' ? $datos['desc_ruta'] : null;
            $celular   = isset($datos['celular'])   && $datos['celular']   !== '' ? $datos['celular']   : null;
        } else {
            // Mantener compatibilidad con sintaxis antigua
            $usuario = $datos;
            $nombre = func_get_arg(1);
            $password = func_get_arg(2);
            $rol_id = func_num_args() > 3 ? func_get_arg(3) : 1;
            $superior_id = func_num_args() > 4 ? func_get_arg(4) : null;
            $email = $ruta = $desc_ruta = $celular = null;
        }
        
        // Verificar si el usuario ya existe
        $query = "SELECT COUNT(*) FROM administradores WHERE usuario = :usuario";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':usuario', $usuario);
        $stmt->execute();
        
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('El usuario ya existe');
        }

        // Verificar si el email ya existe
        if ($email !== null) {
            $qe = "SELECT COUNT(*) FROM administradores WHERE email = :email";
            $se = $this->conn->prepare($qe);
            $se->bindParam(':email', $email);
            $se->execute();
            if ($se->fetchColumn() > 0) {
                throw new Exception('El correo ya está registrado');
            }
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO administradores (usuario, email, nombre, password, rol_id, superior_id, ruta, desc_ruta, celular) 
                  VALUES (:usuario, :email, :nombre, :password, :rol_id, :superior_id, :ruta, :desc_ruta, :celular)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':usuario', $usuario);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':password', $password_hash);
        $stmt->bindParam(':rol_id', $rol_id);
        $stmt->bindParam(':superior_id', $superior_id);
        $stmt->bindParam(':ruta', $ruta);
        $stmt->bindParam(':desc_ruta', $desc_ruta);
        $stmt->bindParam(':celular', $celular);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }
    
    // Actualizar administrador CON ROL (soporta array o parámetros tradicionales)
    public function update($id, $datos) {
        // Si el segundo argumento es array, usar nueva sintaxis
        if (is_array($datos)) {
            $campos = [];
            $valores = [':id' => $id];
            
            if (isset($datos['usuario'])) {
                $campos[] = "usuario = :usuario";
                $valores[':usuario'] = $datos['usuario'];
            }
            if (isset($datos['nombre'])) {
                $campos[] = "nombre = :nombre";
                $valores[':nombre'] = $datos['nombre'];
            }
            if (isset($datos['password'])) {
                $campos[] = "password = :password";
                $valores[':password'] = password_hash($datos['password'], PASSWORD_DEFAULT);
            }
            if (isset($datos['rol_id'])) {
                $campos[] = "rol_id = :rol_id";
                $valores[':rol_id'] = $datos['rol_id'];
            }
            if (isset($datos['superior_id'])) {
                $campos[] = "superior_id = :superior_id";
                $valores[':superior_id'] = $datos['superior_id'];
            }
            if (isset($datos['activo'])) {
                $campos[] = "activo = :activo";
                $valores[':activo'] = $datos['activo'];
            }
            foreach (['email', 'ruta', 'desc_ruta', 'celular'] as $_f) {
                if (array_key_exists($_f, $datos)) {
                    $campos[] = "`$_f` = :$_f";
                    $valores[":$_f"] = ($datos[$_f] !== '' ? $datos[$_f] : null);
                }
            }
            
            if (empty($campos)) {
                return true; // Nada que actualizar
            }
            
            $query = "UPDATE administradores SET " . implode(', ', $campos) . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            foreach ($valores as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            return $stmt->execute();
        } else {
            // Mantener compatibilidad con sintaxis antigua
            $usuario = $datos;
            $nombre = func_get_arg(2);
            $rol_id = func_num_args() > 3 ? func_get_arg(3) : null;
            $superior_id = func_num_args() > 4 ? func_get_arg(4) : null;
            
            $query = "UPDATE administradores 
                      SET usuario = :usuario, nombre = :nombre, rol_id = :rol_id, superior_id = :superior_id 
                      WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':usuario', $usuario);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':rol_id', $rol_id);
            $stmt->bindParam(':superior_id', $superior_id);
            return $stmt->execute();
        }
    }
    
    // Cambiar contraseña
    public function cambiarPassword($id, $password) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "UPDATE administradores SET password = :password WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':password', $password_hash);
        return $stmt->execute();
    }
    
    // Activar/Desactivar administrador
    public function toggleActivo($id) {
        $query = "UPDATE administradores SET activo = NOT activo WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    // Eliminar administrador
    public function delete($id) {
        $query = "DELETE FROM administradores WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    // ========================================
    // MÉTODOS DE ROLES Y JERARQUÍA
    // ========================================
    
    // Verificar si tiene permiso
    public function tienePermiso($admin_id, $permiso) {
        $admin = $this->getById($admin_id);
        if (!$admin || !$admin['permisos']) {
            return false;
        }
        
        $permisos = json_decode($admin['permisos'], true);
        
        // Admin tiene todos los permisos
        if (isset($permisos['acceso_total']) && $permisos['acceso_total']) {
            return true;
        }
        
        return isset($permisos[$permiso]) && $permisos[$permiso];
    }
    
    // Obtener subordinados directos
    public function getSubordinados($admin_id) {
        $query = "SELECT 
            a.id, a.usuario, a.nombre, a.activo,
            r.nombre as rol_nombre, r.codigo as rol_codigo
        FROM administradores a
        INNER JOIN roles r ON a.rol_id = r.id
        WHERE a.superior_id = :admin_id
        ORDER BY a.nombre ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
