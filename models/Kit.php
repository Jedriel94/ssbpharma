<?php
/**
 * Modelo para gestión de Kits
 * 
 * Un kit agrupa varios productos y se vende como unidad,
 * pero descuenta los productos individuales del inventario
 */

require_once __DIR__ . '/../config/database.php';

class Kit {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Obtener todos los kits activos disponibles
     * Si se pasan tags restrictivos, solo devuelve kits que contengan
     * al menos un producto con alguno de esos tags.
     */
    public function obtenerKitsDisponibles($tags_permitidos = null) {
        if (!empty($tags_permitidos) && $tags_permitidos !== '*') {
            $tags_array = array_map('trim', explode(',', $tags_permitidos));
            $conditions = [];
            foreach ($tags_array as $tag) {
                $conditions[] = "FIND_IN_SET(:tag_" . md5($tag) . ", REPLACE(p.tags, ' ', ''))";
            }
            $tagWhere = "AND p.tags IS NOT NULL AND (" . implode(' OR ', $conditions) . ")";
            $sql = "SELECT k.id, k.nombre, k.descripcion, k.imagen, k.precio_kit,
                           k.activo, k.orden,
                           MIN(FLOOR(p.existencia / kp.cantidad)) AS stock_disponible,
                           COALESCE((SELECT SUM(kv.cantidad) FROM kit_ventas kv WHERE kv.kit_id = k.id), 0) AS total_vendidos
                    FROM kits k
                    INNER JOIN kit_productos kp ON k.id = kp.kit_id
                    INNER JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
                    WHERE k.activo = 1
                    {$tagWhere}
                    GROUP BY k.id
                    ORDER BY k.orden, k.nombre";
            $stmt = $this->db->prepare($sql);
            foreach ($tags_array as $tag) {
                $stmt->bindValue(':tag_' . md5($tag), $tag);
            }
            $stmt->execute();
        } else {
            // Prioriza la vista por rendimiento, con fallback a tablas base si la vista falla (ej. definer invalido en produccion).
            try {
                $sql = "SELECT * FROM vw_kits_disponibles ORDER BY orden, nombre";
                $stmt = $this->db->query($sql);
            } catch (PDOException $e) {
                $fallbackSql = "SELECT k.id, k.nombre, k.descripcion, k.imagen, k.precio_kit,
                                       k.activo, k.orden,
                                       MIN(FLOOR(p.existencia / kp.cantidad)) AS stock_disponible,
                                       COALESCE((SELECT SUM(kv.cantidad) FROM kit_ventas kv WHERE kv.kit_id = k.id), 0) AS total_vendidos
                                FROM kits k
                                INNER JOIN kit_productos kp ON k.id = kp.kit_id
                                INNER JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
                                WHERE k.activo = 1
                                GROUP BY k.id
                                ORDER BY k.orden, k.nombre";
                $stmt = $this->db->prepare($fallbackSql);
                $stmt->execute();
            }
        }
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener detalle de un kit específico
     */
    public function obtenerKitPorId($kit_id) {
        $kit_id = (int)$kit_id;
        if ($kit_id <= 0) {
            return null;
        }
        
        $sql = "SELECT 
                    k.id,
                    k.nombre,
                    k.descripcion,
                    k.imagen,
                    k.precio_kit,
                    k.activo,
                    k.orden,
                    k.en_carrusel,
                    k.created_at,
                    k.updated_at,
                    MIN(FLOOR(p.existencia / kp.cantidad)) as stock_disponible
                FROM kits k
                LEFT JOIN kit_productos kp ON k.id = kp.kit_id
                LEFT JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
                WHERE k.id = ?
                GROUP BY k.id, k.nombre, k.descripcion, k.imagen, k.precio_kit, k.activo, k.orden, k.en_carrusel, k.created_at, k.updated_at";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$kit_id]);
        return $stmt->fetch();
    }
    
    /**
     * Obtener productos que componen un kit
     */
    public function obtenerProductosDeKit($kit_id) {
        $kit_id = (int)$kit_id;
        if ($kit_id <= 0) {
            return [];
        }
        $sql = "SELECT 
                    p.id,
                    p.id as producto_id,
                    p.producto as nombre,
                    p.imagen,
                    p.existencia,
                    kp.cantidad as cantidad_en_kit,
                    kp.precio_unitario,
                    FLOOR(p.existencia / kp.cantidad) as kits_posibles
                FROM kit_productos kp
                INNER JOIN productos p ON kp.producto_id = p.id
                WHERE kp.kit_id = ? AND p.activo = 1
                ORDER BY p.producto";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$kit_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * Verificar disponibilidad de un kit
     * Retorna cuántos kits se pueden armar con el inventario actual
     */
    public function verificarDisponibilidad($kit_id, $cantidad_solicitada = 1) {
        $kit_id = (int)$kit_id;
        $cantidad_solicitada = (int)$cantidad_solicitada;
        
        if ($kit_id <= 0 || $cantidad_solicitada <= 0) {
            return [
                'disponible' => false,
                'stock_disponible' => 0,
                'cantidad_solicitada' => $cantidad_solicitada
            ];
        }
        
        $sql = "SELECT MIN(FLOOR(p.existencia / kp.cantidad)) as stock_disponible
                FROM kit_productos kp
                INNER JOIN productos p ON kp.producto_id = p.id
                WHERE kp.kit_id = ? AND p.activo = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$kit_id]);
        $result = $stmt->fetch();
        
        $disponible = (int)($result['stock_disponible'] ?? 0);
        
        return [
            'disponible' => $disponible >= $cantidad_solicitada,
            'stock_disponible' => $disponible,
            'cantidad_solicitada' => $cantidad_solicitada
        ];
    }
    
    /**
     * Vender un kit (descuenta productos individuales y registra venta)
     */
    public function venderKit($kit_id, $pedido_id, $cantidad = 1) {
        $kit_id    = (int)$kit_id;
        $pedido_id = (int)$pedido_id;
        $cantidad  = (int)$cantidad;

        if ($kit_id <= 0 || $pedido_id <= 0 || $cantidad <= 0) {
            return ['success' => false, 'mensaje' => 'Parámetros inválidos'];
        }

        try {
            // Obtener kit
            $kit = $this->db->prepare("SELECT precio_kit FROM kits WHERE id = ? AND activo = 1");
            $kit->execute([$kit_id]);
            $kitData = $kit->fetch(PDO::FETCH_ASSOC);
            if (!$kitData) {
                return ['success' => false, 'mensaje' => 'Kit no encontrado o inactivo'];
            }

            // Obtener productos del kit con precio_unitario
            $stmt = $this->db->prepare(
                "SELECT kp.producto_id, kp.cantidad * ? AS cantidad_necesaria,
                        COALESCE(kp.precio_unitario, 0.00) AS precio_unitario,
                        COALESCE((SELECT impuesto FROM productos WHERE id = kp.producto_id), 0.16) AS impuesto
                 FROM kit_productos kp
                 WHERE kp.kit_id = ?"
            );
            $stmt->execute([$cantidad, $kit_id]);
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($productos)) {
                return ['success' => false, 'mensaje' => 'El kit no tiene productos'];
            }

            // Verificar stock de cada producto
            foreach ($productos as $p) {
                $s = $this->db->prepare("SELECT existencia FROM productos WHERE id = ? AND activo = 1");
                $s->execute([$p['producto_id']]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['existencia'] < (int)$p['cantidad_necesaria']) {
                    return ['success' => false, 'mensaje' => 'Stock insuficiente para producto ID: ' . $p['producto_id']];
                }
            }

            $this->db->beginTransaction();

            foreach ($productos as $p) {
                // Descontar existencia
                $this->db->prepare(
                    "UPDATE productos SET existencia = existencia - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
                )->execute([(int)$p['cantidad_necesaria'], $p['producto_id']]);

                // Insertar detalle con precio_unitario real del kit
                $subtotal = round((float)$p['precio_unitario'] * (int)$p['cantidad_necesaria'], 2);
                $this->db->prepare(
                    "INSERT INTO detalle_pedidos (pedido_id, producto_id, cantidad, precio_unitario, subtotal, impuesto)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([
                    $pedido_id,
                    $p['producto_id'],
                    (int)$p['cantidad_necesaria'],
                    (float)$p['precio_unitario'],
                    $subtotal,
                    (float)$p['impuesto'],
                ]);
            }

            // Registrar kit_venta
            $precio_kit = (float)$kitData['precio_kit'];
            $subtotal_kit = round($precio_kit * $cantidad, 2);
            $this->db->prepare(
                "INSERT INTO kit_ventas (kit_id, pedido_id, cantidad, precio_unitario, subtotal)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$kit_id, $pedido_id, $cantidad, $precio_kit, $subtotal_kit]);

            $this->db->commit();

            return ['success' => true, 'mensaje' => 'Kit vendido exitosamente. Subtotal: $' . $subtotal_kit];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'mensaje' => 'Error al vender kit: ' . $e->getMessage()];
        }
    }
    
    /**
     * Crear un nuevo kit
     */
    public function crearKit($datos) {
        try {
            // Validar datos requeridos
            if (empty($datos['nombre']) || !isset($datos['precio_kit'])) {
                return [
                    'success' => false,
                    'mensaje' => 'Nombre y precio son campos requeridos'
                ];
            }
            
            // Validar precio
            $precio = floatval($datos['precio_kit']);
            if ($precio < 0) {
                return [
                    'success' => false,
                    'mensaje' => 'El precio debe ser mayor o igual a 0'
                ];
            }
            
            // Validar que haya productos
            if (empty($datos['productos']) || !is_array($datos['productos'])) {
                return [
                    'success' => false,
                    'mensaje' => 'Debe incluir al menos un producto en el kit'
                ];
            }
            
            $this->db->beginTransaction();
            
            // Debug log
            error_log("Insertando kit con imagen: " . ($datos['imagen'] ?? 'NULL'));
            
            // Insertar kit
            $sql = "INSERT INTO kits (nombre, descripcion, imagen, precio_kit, activo, orden, en_carrusel) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $resultado = $stmt->execute([
                trim($datos['nombre']),
                isset($datos['descripcion']) ? trim($datos['descripcion']) : null,
                $datos['imagen'] ?? null,
                $precio,
                isset($datos['activo']) ? (int)$datos['activo'] : 1,
                isset($datos['orden']) ? (int)$datos['orden'] : 0,
                isset($datos['en_carrusel']) ? (int)$datos['en_carrusel'] : 0
            ]);
            
            if (!$resultado) {
                error_log("Error al insertar kit: " . print_r($stmt->errorInfo(), true));
            }
            
            $kit_id = $this->db->lastInsertId();
            
            // Agregar productos al kit
            $sql_producto = "INSERT INTO kit_productos (kit_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)";
            $stmt_producto = $this->db->prepare($sql_producto);
            
            foreach ($datos['productos'] as $producto) {
                $producto_id = (int)($producto['producto_id'] ?? 0);
                $cantidad = (int)($producto['cantidad'] ?? 0);
                $precio_comp = round(floatval($producto['precio_unitario'] ?? 0), 2);
                
                if ($producto_id <= 0 || $cantidad <= 0) {
                    throw new Exception('ID de producto o cantidad inválidos');
                }
                
                $stmt_producto->execute([
                    $kit_id,
                    $producto_id,
                    $cantidad,
                    $precio_comp
                ]);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'kit_id' => $kit_id,
                'mensaje' => 'Kit creado exitosamente'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'mensaje' => 'Error al crear kit: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Actualizar un kit
     */
    public function actualizarKit($kit_id, $datos) {
        try {
            // Validar kit_id
            $kit_id = (int)$kit_id;
            if ($kit_id <= 0) {
                return [
                    'success' => false,
                    'mensaje' => 'ID de kit inválido'
                ];
            }
            
            // Validar datos requeridos
            if (empty($datos['nombre']) || !isset($datos['precio_kit'])) {
                return [
                    'success' => false,
                    'mensaje' => 'Nombre y precio son campos requeridos'
                ];
            }
            
            // Validar precio
            $precio = floatval($datos['precio_kit']);
            if ($precio < 0) {
                return [
                    'success' => false,
                    'mensaje' => 'El precio debe ser mayor o igual a 0'
                ];
            }
            
            $this->db->beginTransaction();
            
            // Obtener imagen actual si no se proporciona una nueva
            $imagenActualizar = $datos['imagen'] ?? null;
            if (empty($imagenActualizar)) {
                // Obtener la imagen actual del kit
                $kitActual = $this->db->prepare("SELECT imagen FROM kits WHERE id = ?");
                $kitActual->execute([$kit_id]);
                $kitData = $kitActual->fetch();
                if ($kitData) {
                    $imagenActualizar = $kitData['imagen'];
                }
            }
            
            // Actualizar kit
            $sql = "UPDATE kits 
                    SET nombre = ?, descripcion = ?, imagen = ?, precio_kit = ?, 
                        activo = ?, orden = ?, en_carrusel = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                trim($datos['nombre']),
                isset($datos['descripcion']) ? trim($datos['descripcion']) : null,
                $imagenActualizar,
                $precio,
                isset($datos['activo']) ? (int)$datos['activo'] : 1,
                isset($datos['orden']) ? (int)$datos['orden'] : 0,
                isset($datos['en_carrusel']) ? (int)$datos['en_carrusel'] : 0,
                $kit_id
            ]);
            
            // Si se proporcionan productos, actualizar composición
            if (isset($datos['productos'])) {
                // Validar que sea array
                if (!is_array($datos['productos'])) {
                    throw new Exception('Los productos deben ser un array');
                }
                
                // Eliminar productos actuales
                $this->db->prepare("DELETE FROM kit_productos WHERE kit_id = ?")->execute([$kit_id]);
                
                // Agregar nuevos productos
                if (!empty($datos['productos'])) {
                    $sql_producto = "INSERT INTO kit_productos (kit_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)";
                    $stmt_producto = $this->db->prepare($sql_producto);
                    
                    foreach ($datos['productos'] as $producto) {
                        $producto_id = (int)($producto['producto_id'] ?? 0);
                        $cantidad = (int)($producto['cantidad'] ?? 0);
                        $precio_comp = round(floatval($producto['precio_unitario'] ?? 0), 2);
                        
                        if ($producto_id <= 0 || $cantidad <= 0) {
                            throw new Exception('ID de producto o cantidad inválidos');
                        }
                        
                        $stmt_producto->execute([
                            $kit_id,
                            $producto_id,
                            $cantidad,
                            $precio_comp
                        ]);
                    }
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'mensaje' => 'Kit actualizado exitosamente'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'mensaje' => 'Error al actualizar kit: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtener kits activos marcados para carrusel
     */
    public function getCarrusel($tags_permitidos = null) {
        // Los kits no tienen tags propios; si el rep tiene tags restrictivos
        // solo se muestran kits cuyos productos pertenezcan a esos tags.
        if (!empty($tags_permitidos) && $tags_permitidos !== '*') {
            $tags_array = array_map('trim', explode(',', $tags_permitidos));
            $conditions = [];
            foreach ($tags_array as $tag) {
                $conditions[] = "FIND_IN_SET(:tag_" . md5($tag) . ", REPLACE(p.tags, ' ', ''))";
            }
            $tagWhere = "AND p.tags IS NOT NULL AND (" . implode(' OR ', $conditions) . ")";
            $sql = "SELECT k.*,
                           MIN(FLOOR(p.existencia / kp.cantidad)) as stock_disponible
                    FROM kits k
                    INNER JOIN kit_productos kp ON k.id = kp.kit_id
                    INNER JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
                    WHERE k.activo = 1 AND k.en_carrusel = 1
                    {$tagWhere}
                    GROUP BY k.id
                    ORDER BY k.orden ASC, k.nombre ASC";
            $stmt = $this->db->prepare($sql);
            foreach ($tags_array as $tag) {
                $stmt->bindValue(':tag_' . md5($tag), $tag);
            }
            $stmt->execute();
        } else {
            $sql = "SELECT k.*,
                           MIN(FLOOR(p.existencia / kp.cantidad)) as stock_disponible
                    FROM kits k
                    LEFT JOIN kit_productos kp ON k.id = kp.kit_id
                    LEFT JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
                    WHERE k.activo = 1 AND k.en_carrusel = 1
                    GROUP BY k.id
                    ORDER BY k.orden ASC, k.nombre ASC";
            $stmt = $this->db->query($sql);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Eliminar un kit (soft delete - desactivar)
     */
    public function eliminarKit($kit_id) {
        $sql = "UPDATE kits SET activo = 0 WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        if ($stmt->execute([$kit_id])) {
            return ['success' => true, 'mensaje' => 'Kit desactivado'];
        }
        
        return ['success' => false, 'mensaje' => 'Error al desactivar kit'];
    }
    
    /**
     * Obtener estadísticas de ventas de kits
     */
    public function obtenerEstadisticasVentas($kit_id = null, $fecha_inicio = null, $fecha_fin = null) {
        $sql = "SELECT 
                    k.id,
                    k.nombre,
                    COUNT(DISTINCT kv.pedido_id) as total_pedidos,
                    SUM(kv.cantidad) as total_kits_vendidos,
                    SUM(kv.subtotal) as ventas_totales,
                    AVG(kv.precio_unitario) as precio_promedio,
                    MIN(kv.created_at) as primera_venta,
                    MAX(kv.created_at) as ultima_venta
                FROM kits k
                LEFT JOIN kit_ventas kv ON k.id = kv.kit_id";
        
        $condiciones = [];
        $params = [];
        
        if ($kit_id) {
            $condiciones[] = "k.id = ?";
            $params[] = $kit_id;
        }
        
        if ($fecha_inicio) {
            $condiciones[] = "kv.created_at >= ?";
            $params[] = $fecha_inicio;
        }
        
        if ($fecha_fin) {
            $condiciones[] = "kv.created_at <= ?";
            $params[] = $fecha_fin;
        }
        
        if (!empty($condiciones)) {
            $sql .= " WHERE " . implode(" AND ", $condiciones);
        }
        
        $sql .= " GROUP BY k.id, k.nombre ORDER BY total_kits_vendidos DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener todos los kits (incluyendo inactivos) para administración
     */
    public function obtenerTodosLosKits() {
        $sql = "SELECT 
                    k.*,
                    COUNT(DISTINCT kp.producto_id) as total_productos,
                    MIN(FLOOR(p.existencia / kp.cantidad)) as stock_disponible,
                    COALESCE(SUM(kv.cantidad), 0) as total_vendidos
                FROM kits k
                LEFT JOIN kit_productos kp ON k.id = kp.kit_id
                LEFT JOIN productos p ON kp.producto_id = p.id AND p.activo = 1
                LEFT JOIN kit_ventas kv ON k.id = kv.kit_id
                GROUP BY k.id
                ORDER BY k.activo DESC, k.orden, k.nombre";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
}
