<?php

class Representante {
    private $pdo;
    
    public function __construct($pdo = null) {
        if ($pdo) {
            $this->pdo = $pdo;
        } else {
            $db = Database::getInstance();
            $this->pdo = $db->getConnection();
        }
    }
    
    /**
     * Obtener representante por código único
     */
    public function getByCodigo($codigo) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM representantes 
            WHERE codigo = ? 
            LIMIT 1
        ");
        $stmt->execute([$codigo]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener representante por ID
     */
    public function getById($id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM representantes 
            WHERE id = ? 
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener todos los representantes
     */
    public function getAll($soloActivos = false) {
        $sql = "SELECT * FROM representantes";
        
        if ($soloActivos) {
            $sql .= " WHERE activo = 1";
        }
        
        $sql .= " ORDER BY nombre ASC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Crear nuevo representante
     */
    public function create($codigo, $nombre, $telefono = null, $email = null, $tags_permitidos = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO representantes 
                (codigo, nombre, telefono, email, tags_permitidos, activo) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            $result = $stmt->execute([
                strtoupper(trim($codigo)),
                trim($nombre),
                $telefono ? trim($telefono) : null,
                $email ? trim($email) : null,
                $tags_permitidos ? trim($tags_permitidos) : null
            ]);
            
            if ($result) {
                return $this->pdo->lastInsertId();
            }
            
            return false;
        } catch (PDOException $e) {
            // Error por código duplicado
            if ($e->getCode() == 23000) {
                throw new Exception("El código '{$codigo}' ya está en uso.");
            }
            throw $e;
        }
    }
    
    /**
     * Actualizar representante
     */
    public function update($id, $nombre, $telefono = null, $email = null, $activo = 1, $tags_permitidos = null) {
        $stmt = $this->pdo->prepare("
            UPDATE representantes 
            SET nombre = ?, 
                telefono = ?, 
                email = ?, 
                activo = ?,
                tags_permitidos = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            trim($nombre),
            $telefono ? trim($telefono) : null,
            $email ? trim($email) : null,
            $activo ? 1 : 0,
            $tags_permitidos ? trim($tags_permitidos) : null,
            $id
        ]);
    }
    
    /**
     * Desactivar representante (no se elimina, solo se marca como inactivo)
     */
    public function desactivar($id) {
        $stmt = $this->pdo->prepare("
            UPDATE representantes 
            SET activo = 0 
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }
    
    /**
     * Activar representante
     */
    public function activar($id) {
        $stmt = $this->pdo->prepare("
            UPDATE representantes 
            SET activo = 1 
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }
    
    /**
     * Eliminar representante (solo si no tiene pedidos asociados)
     */
    public function eliminar($id) {
        $stmt = $this->pdo->prepare("DELETE FROM representantes WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Obtener estadísticas de un representante
     */
    public function getEstadisticas($id) {
        return [
            'total_pedidos' => 0,
            'total_ventas' => 0,
            'promedio_venta' => 0,
            'total_clientes' => 0,
            'ventas_completadas' => 0,
            'ventas_entregadas' => 0
        ];
    }
    
    /**
     * Obtener pedidos de un representante
     */
    public function getPedidos($id, $limit = 100) {
        return [];
    }
    
    /**
     * Generar código único automático (REP001, REP002, etc)
     */
    public function generarCodigoUnico() {
        // Obtener el último código
        $stmt = $this->pdo->query("
            SELECT codigo 
            FROM representantes 
            WHERE codigo LIKE 'REP%' 
            ORDER BY codigo DESC 
            LIMIT 1
        ");
        $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ultimo) {
            // Extraer número del código (REP001 -> 001)
            $numero = intval(substr($ultimo['codigo'], 3));
            $siguiente = $numero + 1;
        } else {
            $siguiente = 1;
        }
        
        // Formatear con ceros a la izquierda (REP001, REP002, ...)
        return 'REP' . str_pad($siguiente, 3, '0', STR_PAD_LEFT);
    }
    
    /**
     * Obtener ranking de representantes por ventas
     */
    public function getRanking($limite = 10, $mes = null, $anio = null) {
        $where = "";
        $params = [];
        
        if ($mes && $anio) {
            $where = "WHERE MONTH(p.created_at) = ? AND YEAR(p.created_at) = ?";
            $params = [$mes, $anio];
        } elseif ($anio) {
            $where = "WHERE YEAR(p.created_at) = ?";
            $params = [$anio];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                r.id,
                r.codigo,
                r.nombre,
                r.telefono,
                0 as total_pedidos,
                0 as total_ventas,
                0 as clientes_referidos
            FROM representantes r
            GROUP BY r.id
            ORDER BY r.nombre ASC
            LIMIT ?
        ");
        
        $stmt->execute([(int)$limite]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
