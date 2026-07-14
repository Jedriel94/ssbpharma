<?php
require_once __DIR__ . '/../config/database.php';

class Cupon {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    
    /**
     * Obtener todos los cupones
     */
    public function getAll() {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM cupones_uso WHERE cupon_id = c.id) as total_usos,
                  CASE 
                    WHEN c.activo = 0 THEN 'Inactivo'
                    WHEN NOW() < c.fecha_inicio THEN 'Programado'
                    WHEN NOW() > c.fecha_expiracion THEN 'Expirado'
                    WHEN c.usos_maximos IS NOT NULL AND c.usos_actuales >= c.usos_maximos THEN 'Agotado'
                    ELSE 'Activo'
                  END as estado
                  FROM cupones c 
                  ORDER BY c.activo DESC, c.fecha_expiracion DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener cupón por ID
     */
    public function getById($id) {
        $query = "SELECT * FROM cupones WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    /**
     * Obtener cupón por código
     */
    public function getByCodigo($codigo) {
        $query = "SELECT * FROM cupones WHERE codigo = :codigo";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':codigo', $codigo);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    /**
     * Crear nuevo cupón
     */
    public function create($datos) {
        $datos['aplicacion_admin_ids'] = $datos['aplicacion_admin_ids'] ?? null;
        $query = "INSERT INTO cupones 
                  (codigo, descripcion, tipo_descuento, valor_descuento, 
                   tipo_aplicacion, aplicacion_ids, aplicacion_admin_ids, aplicacion_tags, 
                   minimo_compra, fecha_inicio, fecha_expiracion, 
                   usos_maximos, activo) 
                  VALUES 
                  (:codigo, :descripcion, :tipo_descuento, :valor_descuento,
                   :tipo_aplicacion, :aplicacion_ids, :aplicacion_admin_ids, :aplicacion_tags,
                   :minimo_compra, :fecha_inicio, :fecha_expiracion,
                   :usos_maximos, :activo)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':codigo', $datos['codigo']);
        $stmt->bindParam(':descripcion', $datos['descripcion']);
        $stmt->bindParam(':tipo_descuento', $datos['tipo_descuento']);
        $stmt->bindParam(':valor_descuento', $datos['valor_descuento']);
        $stmt->bindParam(':tipo_aplicacion', $datos['tipo_aplicacion']);
        $stmt->bindParam(':aplicacion_ids', $datos['aplicacion_ids']);
        $stmt->bindParam(':aplicacion_admin_ids', $datos['aplicacion_admin_ids']);
        $stmt->bindParam(':aplicacion_tags', $datos['aplicacion_tags']);
        $stmt->bindParam(':minimo_compra', $datos['minimo_compra']);
        $stmt->bindParam(':fecha_inicio', $datos['fecha_inicio']);
        $stmt->bindParam(':fecha_expiracion', $datos['fecha_expiracion']);
        $stmt->bindParam(':usos_maximos', $datos['usos_maximos']);
        $stmt->bindParam(':activo', $datos['activo']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }
    
    /**
     * Actualizar cupón
     */
    public function update($id, $datos) {
        $datos['aplicacion_admin_ids'] = $datos['aplicacion_admin_ids'] ?? null;
        $query = "UPDATE cupones SET 
                  codigo = :codigo,
                  descripcion = :descripcion,
                  tipo_descuento = :tipo_descuento,
                  valor_descuento = :valor_descuento,
                  tipo_aplicacion = :tipo_aplicacion,
                  aplicacion_ids = :aplicacion_ids,
                  aplicacion_admin_ids = :aplicacion_admin_ids,
                  aplicacion_tags = :aplicacion_tags,
                  minimo_compra = :minimo_compra,
                  fecha_inicio = :fecha_inicio,
                  fecha_expiracion = :fecha_expiracion,
                  usos_maximos = :usos_maximos,
                  activo = :activo
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':codigo', $datos['codigo']);
        $stmt->bindParam(':descripcion', $datos['descripcion']);
        $stmt->bindParam(':tipo_descuento', $datos['tipo_descuento']);
        $stmt->bindParam(':valor_descuento', $datos['valor_descuento']);
        $stmt->bindParam(':tipo_aplicacion', $datos['tipo_aplicacion']);
        $stmt->bindParam(':aplicacion_ids', $datos['aplicacion_ids']);
        $stmt->bindParam(':aplicacion_admin_ids', $datos['aplicacion_admin_ids']);
        $stmt->bindParam(':aplicacion_tags', $datos['aplicacion_tags']);
        $stmt->bindParam(':minimo_compra', $datos['minimo_compra']);
        $stmt->bindParam(':fecha_inicio', $datos['fecha_inicio']);
        $stmt->bindParam(':fecha_expiracion', $datos['fecha_expiracion']);
        $stmt->bindParam(':usos_maximos', $datos['usos_maximos']);
        $stmt->bindParam(':activo', $datos['activo']);
        
        return $stmt->execute();
    }
    
    /**
     * Eliminar cupón
     */
    public function delete($id) {
        $query = "DELETE FROM cupones WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    /**
     * Validar cupón completo
     * @param string $codigo Código del cupón
     * @param float $subtotal Subtotal del pedido
     * @param array $productos Array de productos del carrito con id, cantidad, tags, es_kit
     * @param int|null $representante_id Parametro legacy conservado por compatibilidad; ya no se resuelve
     * @param int|null $representante_admin_id ID del usuario representante (opcional)
     * @return array ['valido' => bool, 'mensaje' => string, 'cupon' => array|null, 'descuento' => float]
     */
    public function validar($codigo, $subtotal, $productos = [], $representante_id = null, $representante_admin_id = null) {
        // Obtener cupón
        $cupon = $this->getByCodigo(strtoupper(trim($codigo)));
        
        if (!$cupon) {
            return [
                'valido' => false,
                'mensaje' => 'El cupón no existe',
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        // Validar estado activo
        if ($cupon['activo'] != 1) {
            return [
                'valido' => false,
                'mensaje' => 'El cupón no está activo',
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        // Validar fechas
        $ahora = date('Y-m-d H:i:s');
        if ($ahora < $cupon['fecha_inicio']) {
            return [
                'valido' => false,
                'mensaje' => 'El cupón aún no es válido. Válido desde: ' . date('d/m/Y', strtotime($cupon['fecha_inicio'])),
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        if ($ahora > $cupon['fecha_expiracion']) {
            return [
                'valido' => false,
                'mensaje' => 'El cupón ha expirado. Expiró el: ' . date('d/m/Y', strtotime($cupon['fecha_expiracion'])),
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        // Validar límite de usos
        if ($cupon['usos_maximos'] !== null && $cupon['usos_actuales'] >= $cupon['usos_maximos']) {
            return [
                'valido' => false,
                'mensaje' => 'El cupón ha alcanzado su límite de usos',
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        // Validar mínimo de compra
        if ($subtotal < $cupon['minimo_compra']) {
            return [
                'valido' => false,
                'mensaje' => 'El monto mínimo de compra para este cupón es $' . number_format($cupon['minimo_compra'], 2),
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        // Validar tipo de aplicación
        $representante_admin_id = $this->resolverRepresentanteAdminId($representante_admin_id);
        $aplicable = $this->validarAplicacion($cupon, $productos, $representante_admin_id);
        if (!$aplicable['valido']) {
            return [
                'valido' => false,
                'mensaje' => $aplicable['mensaje'],
                'cupon' => null,
                'descuento' => 0
            ];
        }
        
        // Calcular subtotal aplicable (solo productos que cumplen)
        $subtotal_aplicable = $this->calcularSubtotalAplicable($cupon, $productos, $representante_admin_id);
        
        // Calcular descuento sobre el subtotal aplicable
        $descuento = $this->calcularDescuento($cupon, $subtotal_aplicable);
        
        return [
            'valido' => true,
            'mensaje' => 'Cupón válido',
            'cupon' => $cupon,
            'descuento' => $descuento,
            'subtotal_aplicable' => $subtotal_aplicable
        ];
    }
    
    /**
     * Validar si el cupón aplica según su tipo de aplicación
     */
    private function validarAplicacion($cupon, $productos, $representante_admin_id = null) {
        switch ($cupon['tipo_aplicacion']) {
            case 'general':
                // Siempre válido
                return ['valido' => true, 'mensaje' => ''];
                
            case 'productos':
                if (empty($cupon['aplicacion_ids'])) {
                    return ['valido' => false, 'mensaje' => 'Cupón mal configurado'];
                }
                $ids_permitidos = explode(',', $cupon['aplicacion_ids']);
                $tiene_producto_valido = false;
                foreach ($productos as $producto) {
                    if (!empty($producto['es_kit']) && $producto['es_kit']) {
                        continue; // Los kits no cuentan para cupones de productos
                    }
                    if (in_array($producto['id'], $ids_permitidos)) {
                        $tiene_producto_valido = true;
                        break;
                    }
                }
                if (!$tiene_producto_valido) {
                    return ['valido' => false, 'mensaje' => 'El cupón no aplica a los productos en tu carrito'];
                }
                return ['valido' => true, 'mensaje' => ''];
                
            case 'tags':
                if (empty($cupon['aplicacion_tags'])) {
                    return ['valido' => false, 'mensaje' => 'Cupón mal configurado'];
                }
                $tags_permitidos = array_map('trim', explode(',', $cupon['aplicacion_tags']));
                $tiene_tag_valido = false;
                foreach ($productos as $producto) {
                    if (!empty($producto['es_kit']) && $producto['es_kit']) {
                        continue; // Los kits no cuentan para cupones de tags
                    }
                    if (!empty($producto['tags'])) {
                        $producto_tags = array_map('trim', explode(',', $producto['tags']));
                        foreach ($producto_tags as $tag) {
                            if (in_array($tag, $tags_permitidos)) {
                                $tiene_tag_valido = true;
                                break 2;
                            }
                        }
                    }
                }
                if (!$tiene_tag_valido) {
                    return ['valido' => false, 'mensaje' => 'El cupón no aplica a las categorías de productos en tu carrito'];
                }
                return ['valido' => true, 'mensaje' => ''];
                
            case 'kits':
                if (empty($cupon['aplicacion_ids'])) {
                    return ['valido' => false, 'mensaje' => 'Cupón mal configurado'];
                }
                $ids_permitidos = explode(',', $cupon['aplicacion_ids']);
                $tiene_kit_valido = false;
                foreach ($productos as $producto) {
                    if (!empty($producto['es_kit']) && $producto['es_kit']) {
                        if (in_array($producto['id'], $ids_permitidos)) {
                            $tiene_kit_valido = true;
                            break;
                        }
                    }
                }
                if (!$tiene_kit_valido) {
                    return ['valido' => false, 'mensaje' => 'El cupón no aplica a los kits en tu carrito'];
                }
                return ['valido' => true, 'mensaje' => ''];
                
            case 'representantes':
                $admin_ids_permitidos = $this->parseIds($cupon['aplicacion_admin_ids'] ?? '');

                if (empty($admin_ids_permitidos)) {
                    return ['valido' => false, 'mensaje' => 'Cupón mal configurado'];
                }
                if (empty($representante_admin_id)) {
                    return ['valido' => false, 'mensaje' => 'Este cupón solo es válido para compras a través de representante'];
                }

                if (!in_array((int)$representante_admin_id, $admin_ids_permitidos, true)) {
                    return ['valido' => false, 'mensaje' => 'Este cupón no es válido para tu representante'];
                }
                return ['valido' => true, 'mensaje' => ''];
                
            default:
                return ['valido' => false, 'mensaje' => 'Tipo de cupón no reconocido'];
        }
    }
    
    /**
     * Calcular subtotal aplicable según tipo de cupón
     */
    private function calcularSubtotalAplicable($cupon, $productos, $representante_admin_id = null) {
        $subtotal_aplicable = 0;
        
        switch ($cupon['tipo_aplicacion']) {
            case 'general':
                // Aplica a todo
                foreach ($productos as $producto) {
                    $subtotal_aplicable += $producto['precio'] * $producto['cantidad'];
                }
                break;
                
            case 'productos':
                // Solo productos específicos
                $ids_permitidos = explode(',', $cupon['aplicacion_ids']);
                foreach ($productos as $producto) {
                    if (empty($producto['es_kit']) && in_array($producto['id'], $ids_permitidos)) {
                        $subtotal_aplicable += $producto['precio'] * $producto['cantidad'];
                    }
                }
                break;
                
            case 'tags':
                // Solo productos con tags específicos
                $tags_permitidos = array_map('trim', explode(',', $cupon['aplicacion_tags']));
                foreach ($productos as $producto) {
                    if (empty($producto['es_kit']) && !empty($producto['tags'])) {
                        $producto_tags = array_map('trim', explode(',', $producto['tags']));
                        foreach ($producto_tags as $tag) {
                            if (in_array($tag, $tags_permitidos)) {
                                $subtotal_aplicable += $producto['precio'] * $producto['cantidad'];
                                break;
                            }
                        }
                    }
                }
                break;
                
            case 'kits':
                // Solo kits específicos
                $ids_permitidos = explode(',', $cupon['aplicacion_ids']);
                foreach ($productos as $producto) {
                    if (!empty($producto['es_kit']) && in_array($producto['id'], $ids_permitidos)) {
                        $subtotal_aplicable += $producto['precio'] * $producto['cantidad'];
                    }
                }
                break;
                
            case 'representantes':
                // Aplica a todo si el representante es válido
                $admin_ids_permitidos = $this->parseIds($cupon['aplicacion_admin_ids'] ?? '');
                $aplica = in_array((int)$representante_admin_id, $admin_ids_permitidos, true);

                if ($aplica) {
                    foreach ($productos as $producto) {
                        $subtotal_aplicable += $producto['precio'] * $producto['cantidad'];
                    }
                }
                break;
        }
        
        return $subtotal_aplicable;
    }
    
    /**
     * Calcular descuento según tipo
     */
    private function calcularDescuento($cupon, $subtotal) {
        if ($cupon['tipo_descuento'] === 'porcentaje') {
            $descuento = ($subtotal * $cupon['valor_descuento']) / 100;
        } else {
            $descuento = $cupon['valor_descuento'];
        }
        
        // El descuento nunca puede ser mayor al subtotal
        return min($descuento, $subtotal);
    }
    
    /**
     * Registrar uso de cupón
     */
    public function registrarUso($cupon_id, $pedido_id, $monto_descuento, $subtotal_pedido, $cliente_id = null, $representante_id = null, $representante_admin_id = null) {
        $representante_admin_id = $this->resolverRepresentanteAdminId($representante_admin_id);

        // Incrementar contador de usos
        $query = "UPDATE cupones SET usos_actuales = usos_actuales + 1 WHERE id = :cupon_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cupon_id', $cupon_id);
        $stmt->execute();
        
        // Registrar uso
        $query = "INSERT INTO cupones_uso 
                  (cupon_id, pedido_id, cliente_id, representante_admin_id, monto_descuento, subtotal_pedido)
                  VALUES 
                  (:cupon_id, :pedido_id, :cliente_id, :representante_admin_id, :monto_descuento, :subtotal_pedido)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cupon_id', $cupon_id);
        $stmt->bindParam(':pedido_id', $pedido_id);
        $stmt->bindParam(':cliente_id', $cliente_id);
        $stmt->bindParam(':representante_admin_id', $representante_admin_id);
        $stmt->bindParam(':monto_descuento', $monto_descuento);
        $stmt->bindParam(':subtotal_pedido', $subtotal_pedido);
        
        return $stmt->execute();
    }
    
    /**
     * Obtener historial de uso de un cupón
     */
    public function getHistorialUso($cupon_id) {
        $query = "SELECT u.*, 
                  p.id as pedido_folio,
                  c.nombre as cliente_nombre,
                  c.telefono as cliente_telefono,
                  a.nombre as representante_nombre,
                  rp.codigo as representante_codigo
                  FROM cupones_uso u
                  LEFT JOIN pedidos p ON u.pedido_id = p.id
                  LEFT JOIN clientes c ON u.cliente_id = c.id
                  LEFT JOIN representante_perfiles rp ON rp.admin_id = u.representante_admin_id
                  LEFT JOIN administradores a ON a.id = u.representante_admin_id
                  WHERE u.cupon_id = :cupon_id
                  ORDER BY u.fecha_uso DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cupon_id', $cupon_id);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener estadísticas de cupones
     */
    public function getEstadisticas() {
        $query = "SELECT 
                  COUNT(*) as total_cupones,
                  SUM(CASE WHEN activo = 1 AND NOW() BETWEEN fecha_inicio AND fecha_expiracion THEN 1 ELSE 0 END) as activos,
                  SUM(CASE WHEN NOW() > fecha_expiracion THEN 1 ELSE 0 END) as expirados,
                  (SELECT COUNT(*) FROM cupones_uso) as total_usos,
                  (SELECT COALESCE(SUM(monto_descuento), 0) FROM cupones_uso) as total_descontado
                  FROM cupones";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }

    private function parseIds($ids) {
        if (empty($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            'intval',
            array_map('trim', explode(',', (string)$ids))
        )));
    }

    private function resolverRepresentanteAdminId($representante_admin_id = null) {
        if (!empty($representante_admin_id)) {
            return (int)$representante_admin_id;
        }

        return null;
    }
}
