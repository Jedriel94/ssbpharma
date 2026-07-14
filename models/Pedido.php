<?php
require_once __DIR__ . '/../config/database.php';

class Pedido {
    public $db; // Cambiado a public para acceso desde kanban.php
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // Crear nuevo pedido
    public function create($cliente_id, $total, $notas = null, $cupon_codigo = null, $cupon_descuento = 0) {
        try {
            $identidadRepresentante = $this->resolverRepresentanteDesdeCookie();
            $representante_admin_id = $identidadRepresentante['representante_admin_id'];
            
            $sql = "INSERT INTO pedidos (cliente_id, total, estado, notas, representante_admin_id, cupon_codigo, cupon_descuento)
                    VALUES (:cliente_id, :total, 'pendiente', :notas, :representante_admin_id, :cupon_codigo, :cupon_descuento)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':cliente_id', $cliente_id);
            $stmt->bindParam(':total', $total);
            $stmt->bindParam(':notas', $notas);
            $stmt->bindValue(':representante_admin_id', $representante_admin_id, $representante_admin_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindParam(':cupon_codigo', $cupon_codigo);
            $stmt->bindParam(':cupon_descuento', $cupon_descuento);
            
            if ($stmt->execute()) {
                return $this->db->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Error al crear pedido: " . $e->getMessage());
            throw new Exception("Error al crear pedido: " . $e->getMessage());
        }
    }
    
    // Agregar detalle al pedido
    // $impuesto: tasa decimal (0.16 = 16%). Si null, se lee de la tabla productos.
    public function addDetalle($pedido_id, $producto_id, $cantidad, $precio_unitario, $impuesto = null) {
        try {
            if ($impuesto === null) {
                $si = $this->db->prepare("SELECT impuesto FROM productos WHERE id = ?");
                $si->execute([$producto_id]);
                $ri = $si->fetch();
                $impuesto = $ri ? (float)$ri['impuesto'] : 0.16;
            }
            $subtotal = $cantidad * $precio_unitario;

            $sql = "INSERT INTO detalle_pedidos (pedido_id, producto_id, cantidad, precio_unitario, subtotal, impuesto)
                    VALUES (:pedido_id, :producto_id, :cantidad, :precio_unitario, :subtotal, :impuesto)";

            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':pedido_id', $pedido_id);
            $stmt->bindParam(':producto_id', $producto_id);
            $stmt->bindParam(':cantidad', $cantidad);
            $stmt->bindParam(':precio_unitario', $precio_unitario);
            $stmt->bindParam(':subtotal', $subtotal);
            $stmt->bindParam(':impuesto', $impuesto);

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error al agregar detalle: " . $e->getMessage());
            throw new Exception("Error al agregar producto al pedido: " . $e->getMessage());
        }
    }
    
    // Obtener todos los pedidos
    public function getAll() {
        $sql = "SELECT p.*, c.telefono, c.nombre,
                       p.representante_admin_id,
                       ar.nombre as representante_nombre_real,
                       rp.codigo as representante_codigo
                FROM pedidos p 
                INNER JOIN clientes c ON p.cliente_id = c.id 
                LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
                LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
                ORDER BY p.created_at DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener pedidos por cliente (teléfono)
    public function getByTelefono($telefono) {
        $sql = "SELECT p.*, c.telefono, c.nombre,
                       p.representante_admin_id,
                       ar.nombre as representante_nombre_real,
                       rp.codigo as representante_codigo
                FROM pedidos p 
                INNER JOIN clientes c ON p.cliente_id = c.id 
                LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
                LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
                WHERE c.telefono = :telefono 
                ORDER BY p.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener pedido por ID
    public function getById($id) {
        $sql = "SELECT p.*, c.telefono, c.nombre,
                       p.representante_admin_id,
                       ar.nombre as representante_nombre_real,
                       rp.codigo as representante_codigo
                FROM pedidos p 
                INNER JOIN clientes c ON p.cliente_id = c.id 
                LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
                LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
                WHERE p.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Obtener detalle de un pedido
    public function getDetalle($pedido_id) {
        $sql = "SELECT d.*, pr.producto, pr.imagen 
                FROM detalle_pedidos d 
                INNER JOIN productos pr ON d.producto_id = pr.id 
                WHERE d.pedido_id = :pedido_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pedido_id', $pedido_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna un array estructurado con kits y productos sueltos del pedido.
     * [
     *   ['tipo'=>'kit',     'kit_id'=>X, 'nombre'=>..., 'imagen'=>..., 'cantidad'=>N, 'subtotal'=>..., 'productos'=>[...]],
     *   ['tipo'=>'producto', ...campos de detalle_pedidos + producto + imagen],
     * ]
     */
    public function getDetalleAgrupado($pedido_id) {
        $pedido_id = (int)$pedido_id;

        // Kits vendidos en este pedido
        $stmtKits = $this->db->prepare(
            "SELECT kv.kit_id, kv.cantidad, kv.subtotal,
                    k.nombre, k.imagen
             FROM kit_ventas kv
             INNER JOIN kits k ON k.id = kv.kit_id
             WHERE kv.pedido_id = ?"
        );
        $stmtKits->execute([$pedido_id]);
        $kitsVendidos = $stmtKits->fetchAll(PDO::FETCH_ASSOC);

        // Productos que pertenecen a algún kit en este pedido
        // (los identificamos cruzando kit_productos con detalle_pedidos)
        $productosDeKit = [];
        $grupos = [];
        foreach ($kitsVendidos as $kv) {
            $stmtKP = $this->db->prepare(
                "SELECT kp.producto_id, kp.cantidad * ? AS cantidad_en_kit
                 FROM kit_productos kp WHERE kp.kit_id = ?"
            );
            $stmtKP->execute([$kv['cantidad'], $kv['kit_id']]);
            $kpRows = $stmtKP->fetchAll(PDO::FETCH_ASSOC);

            $prods = [];
            foreach ($kpRows as $kp) {
                $productosDeKit[$kp['producto_id']] = true;
                // Obtener nombre e imagen del producto
                $stmtD = $this->db->prepare(
                    "SELECT pr.producto, pr.imagen,
                            COALESCE((SELECT precio_unitario FROM detalle_pedidos
                                      WHERE pedido_id = ? AND producto_id = ?
                                      ORDER BY id DESC LIMIT 1), 0) AS precio_unitario
                     FROM productos pr WHERE pr.id = ?"
                );
                $stmtD->execute([$pedido_id, $kp['producto_id'], $kp['producto_id']]);
                $dRow = $stmtD->fetch(PDO::FETCH_ASSOC);
                if ($dRow) {
                    $cant     = (int)$kp['cantidad_en_kit'];
                    $precio   = (float)$dRow['precio_unitario'];
                    $prods[] = [
                        'producto_id'    => $kp['producto_id'],
                        'producto'       => $dRow['producto'],
                        'imagen'         => $dRow['imagen'],
                        'cantidad'       => $cant,
                        'precio_unitario'=> $precio,
                        'subtotal'       => round($precio * $cant, 2),
                    ];
                }
            }

            $grupos[] = [
                'tipo'     => 'kit',
                'kit_id'   => $kv['kit_id'],
                'nombre'   => $kv['nombre'],
                'imagen'   => $kv['imagen'],
                'cantidad' => $kv['cantidad'],
                'subtotal' => $kv['subtotal'],
                'productos'=> $prods,
            ];
        }

        // Productos sueltos (no pertenecen a ningún kit)
        $stmtSueltos = $this->db->prepare(
            "SELECT d.*, pr.producto, pr.imagen
             FROM detalle_pedidos d
             INNER JOIN productos pr ON pr.id = d.producto_id
             WHERE d.pedido_id = ?"
        );
        $stmtSueltos->execute([$pedido_id]);
        foreach ($stmtSueltos->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($productosDeKit[$row['producto_id']])) {
                $grupos[] = ['tipo' => 'producto'] + $row;
            }
        }

        return $grupos;
    }
    
    // Actualizar estado del pedido
    public function updateEstado($id, $estado) {
        $sql = "UPDATE pedidos SET estado = :estado WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':estado', $estado);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    public function confirmarPago($id, $admin_id, $fecha_confirmacion_retroactiva = null) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM pedidos WHERE id = ? FOR UPDATE");
            $stmt->execute([(int)$id]);
            $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pedido) {
                throw new Exception('Pedido no encontrado');
            }

            if (!in_array($pedido['estado'], ['pendiente', 'por_verificar', 'confirmado'], true)) {
                throw new Exception('El pedido no esta en un estado válido para confirmar pago');
            }

            $facturaPendiente = (int)$pedido['requiere_factura'] === 1
                && empty($pedido['factura_pdf'])
                && empty($pedido['factura_xml']);

            $esEntregaDirecta = ($pedido['canal'] ?? '') === 'representante_directo'
                || (int)($pedido['entrega_directa'] ?? 0) === 1;

            $nuevoEstado = ($esEntregaDirecta && !$facturaPendiente) ? 'entregado' : 'confirmado';
            $nuevoEstadoLiquidacion = ($pedido['metodo_pago'] ?? '') === 'efectivo'
                ? 'liquidado'
                : ($pedido['estado_liquidacion'] ?? 'no_aplica');

            $sql = "UPDATE pedidos
                    SET estado = :estado,
                        confirmado_por_admin_id = :admin_id,
                        fecha_confirmacion_pago = :fecha_confirmacion,
                        estado_liquidacion = :estado_liquidacion,
                        fecha_entrega_directa = CASE
                            WHEN :entrega_directa = 1 AND :estado_case = 'entregado'
                            THEN COALESCE(fecha_entrega_directa, NOW())
                            ELSE fecha_entrega_directa
                        END
                    WHERE id = :id";

            $fechaConf = $fecha_confirmacion_retroactiva
                ?? ($pedido['fecha_confirmacion_pago'] ?? null)
                ?? date('Y-m-d H:i:s');

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':estado' => $nuevoEstado,
                ':estado_case' => $nuevoEstado,
                ':admin_id' => (int)$admin_id,
                ':fecha_confirmacion' => $fechaConf,
                ':estado_liquidacion' => $nuevoEstadoLiquidacion,
                ':entrega_directa' => $esEntregaDirecta ? 1 : 0,
                ':id' => (int)$id
            ]);

            $this->db->commit();

            // Hook: si el nuevo estado alcanza el umbral configurado, confirmar reserva de inventario
            $this->_confirmarReservaInventarioSiUmbral((int)$id, $nuevoEstado);

            return [
                'success' => true,
                'estado' => $nuevoEstado,
                'factura_pendiente' => $facturaPendiente,
                'entrega_directa' => $esEntregaDirecta
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Hook interno: confirmar reserva de inventario cuando se alcanza el umbral de estado
    private function _confirmarReservaInventarioSiUmbral(int $pedido_id, string $nuevoEstado): void {
        try {
            require_once __DIR__ . '/../models/Configuracion.php';
            require_once __DIR__ . '/RepresentanteVenta.php';
            $umbral = Configuracion::get('dashboard_estado_ventas', 'entregado');
            $umbralMap = [
                'confirmado' => ['confirmado', 'en_ruta', 'entregado'],
                'en_ruta'    => ['en_ruta', 'entregado'],
                'entregado'  => ['entregado'],
            ];
            $estadosUmbral = $umbralMap[$umbral] ?? ['entregado'];
            if (in_array($nuevoEstado, $estadosUmbral, true)) {
                (new RepresentanteVenta())->confirmarReserva($pedido_id);
            }
        } catch (\Throwable $e) {
            error_log("Pedido::_confirmarReservaInventarioSiUmbral pedido#{$pedido_id}: " . $e->getMessage());
        }
    }

    // Actualizar existencia de productos después de crear pedido
    public function actualizarExistencia($producto_id, $cantidad) {
        $sql = "UPDATE productos SET existencia = existencia - :cantidad WHERE id = :producto_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':cantidad', $cantidad);
        $stmt->bindParam(':producto_id', $producto_id);
        
        return $stmt->execute();
    }

    /**
     * Cancelar un pedido de tienda y devolver el stock al inventario general.
     * Reintegra las cantidades desde detalle_pedidos, por lo que cubre tanto
     * productos sueltos como productos vendidos dentro de kits.
     */
    public function cancelarPedidoTienda(int $pedido_id): array {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, estado, canal, entrega_directa, fecha_pago FROM pedidos WHERE id = ? FOR UPDATE");
            $stmt->execute([$pedido_id]);
            $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pedido) {
                throw new Exception('Pedido no encontrado');
            }

            $esEntregaDirecta = (($pedido['canal'] ?? '') === 'representante_directo') || ((int)($pedido['entrega_directa'] ?? 0) === 1);
            if ($esEntregaDirecta) {
                throw new Exception('Este pedido se cancela desde el módulo de representantes');
            }

            if ($pedido['estado'] !== 'pendiente' || !empty($pedido['fecha_pago'])) {
                throw new Exception('Solo se puede cancelar un pedido que aún no ha procesado el pago');
            }

            $stmtDetalle = $this->db->prepare("SELECT producto_id, SUM(cantidad) AS cantidad_total FROM detalle_pedidos WHERE pedido_id = ? GROUP BY producto_id");
            $stmtDetalle->execute([$pedido_id]);
            $lineas = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($lineas)) {
                $stmtUpd = $this->db->prepare("UPDATE productos SET existencia = existencia + :cantidad, updated_at = CURRENT_TIMESTAMP WHERE id = :producto_id");
                foreach ($lineas as $linea) {
                    $cantidad = (int)($linea['cantidad_total'] ?? 0);
                    $producto_id = (int)($linea['producto_id'] ?? 0);
                    if ($cantidad <= 0 || $producto_id <= 0) {
                        continue;
                    }
                    $stmtUpd->execute([
                        ':cantidad' => $cantidad,
                        ':producto_id' => $producto_id
                    ]);
                }
            }

            $stmtCancel = $this->db->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?");
            $stmtCancel->execute([$pedido_id]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Pedido cancelado y stock devuelto correctamente'
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Actualizar comprobante de pago y datos del pedido
    public function actualizarComprobante($pedido_id, $comprobante, $metodo_pago, $requiere_factura = 0, $datos_envio = [], $datos_fiscales = []) {
        try {
            // Iniciar transacción
            $this->db->beginTransaction();
            
            // Actualizar comprobante y estado base
            $sql = "UPDATE pedidos 
                    SET comprobante_pago = :comprobante, 
                        metodo_pago = :metodo_pago,
                        requiere_factura = :requiere_factura,
                        estado = 'por_verificar',
                        fecha_por_verificar = COALESCE(fecha_por_verificar, NOW())
                    WHERE id = :pedido_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':comprobante', $comprobante);
            $stmt->bindParam(':metodo_pago', $metodo_pago);
            $stmt->bindParam(':requiere_factura', $requiere_factura, PDO::PARAM_INT);
            $stmt->bindParam(':pedido_id', $pedido_id);
            $stmt->execute();
            
            // Actualizar datos de envío si se proporcionaron
            if (!empty($datos_envio)) {
                $this->actualizarDatosEnvio($pedido_id, $datos_envio);
            }
            
            // Actualizar datos fiscales si se proporcionaron
            if ($requiere_factura && !empty($datos_fiscales)) {
                $this->actualizarDatosFiscales($pedido_id, $datos_fiscales);
            }
            
            $this->db->commit();
            return true;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Error al actualizar comprobante: " . $e->getMessage());
            throw new Exception("Error al actualizar comprobante: " . $e->getMessage());
        }
    }
    
    // Actualizar datos de envío del pedido
    public function actualizarDatosEnvio($pedido_id, $datos, $num_factura = null) {
        $sql = "UPDATE pedidos 
                SET calle = :calle,
                    numero = :numero,
                    colonia = :colonia,
                    cp_envio = :cp_envio,
                    estado_envio = :estado_envio,
                    ciudad = :ciudad,
                    referencias = :referencias,
                    quien_recibe = :quien_recibe";

        if (func_num_args() >= 3) {
            $sql .= ", num_factura = :num_factura";
        }

        $sql .= " WHERE id = :pedido_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':calle', $datos['calle']);
        $stmt->bindParam(':numero', $datos['numero']);
        $stmt->bindParam(':colonia', $datos['colonia']);
        $stmt->bindParam(':cp_envio', $datos['cp']);
        $stmt->bindParam(':estado_envio', $datos['estado']);
        $stmt->bindParam(':ciudad', $datos['ciudad']);
        $stmt->bindParam(':referencias', $datos['referencias']);
        $stmt->bindParam(':quien_recibe', $datos['quien_recibe']);
        if (func_num_args() >= 3) {
            $stmt->bindParam(':num_factura', $num_factura);
        }
        $stmt->bindParam(':pedido_id', $pedido_id);
        
        return $stmt->execute();
    }
    
    // Actualizar datos fiscales del pedido
    public function actualizarDatosFiscales($pedido_id, $datos) {
        $sql = "UPDATE pedidos 
                SET rfc = :rfc,
                    razon_social = :razon_social,
                    email_factura = :email_factura,
                    codigo_postal = :codigo_postal,
                    uso_cfdi = :uso_cfdi,
                    regimen_fiscal = :regimen_fiscal
                WHERE id = :pedido_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':rfc', $datos['rfc']);
        $stmt->bindParam(':razon_social', $datos['razon_social']);
        $stmt->bindParam(':email_factura', $datos['email_factura']);
        $stmt->bindParam(':codigo_postal', $datos['codigo_postal']);
        $stmt->bindParam(':uso_cfdi', $datos['uso_cfdi']);
        $stmt->bindParam(':regimen_fiscal', $datos['regimen_fiscal']);
        $stmt->bindParam(':pedido_id', $pedido_id);
        
        return $stmt->execute();
    }
    
    // Actualizar datos adicionales del pedido (médico y representante)
    public function actualizarDatosAdicionales($pedido_id, $nombre_medico, $telefono_medico, $nombre_representante) {
        $sql = "UPDATE pedidos 
                SET nombre_medico = :nombre_medico,
                    telefono_medico = :telefono_medico,
                    nombre_representante = :nombre_representante
                WHERE id = :pedido_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':nombre_medico', $nombre_medico);
        $stmt->bindParam(':telefono_medico', $telefono_medico);
        $stmt->bindParam(':nombre_representante', $nombre_representante);
        $stmt->bindParam(':pedido_id', $pedido_id);
        
        return $stmt->execute();
    }

    private function resolverRepresentanteDesdeCookie() {
        $representante_admin_id = isset($_COOKIE['botikit_rep_admin']) ? intval($_COOKIE['botikit_rep_admin']) : null;

        return [
            'representante_admin_id' => $representante_admin_id ?: null
        ];
    }
}
