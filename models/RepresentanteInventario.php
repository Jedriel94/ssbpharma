<?php
require_once __DIR__ . '/../config/database.php';

class RepresentanteInventario {
    private $db;

    public function __construct($pdo = null) {
        if ($pdo) {
            $this->db = $pdo;
        } else {
            $this->db = Database::getInstance()->getConnection();
        }
    }

    public function getInventario($representante_id, $soloDisponibles = false, $representante_admin_id = null) {
        $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
        $sql = "SELECT
                    ri.*,
                    p.producto,
                    p.imagen,
                    p.activo,
                    p.tags,
                    p.existencia as existencia_general,
                    (
                        SELECT rp.precio
                        FROM rangos_precios rp
                        WHERE rp.producto_id = p.id
                        ORDER BY rp.cantidad_min ASC
                        LIMIT 1
                    ) as precio_base
                FROM representante_inventario ri
                INNER JOIN productos p ON p.id = ri.producto_id
                WHERE {$identidad['where_ri']}";

        if ($soloDisponibles) {
            $sql .= " AND ri.cantidad_disponible > 0 AND p.activo = 1";
        }

        $sql .= " ORDER BY p.producto ASC";

        $stmt = $this->db->prepare($sql);
        $this->bindIdentidad($stmt, $identidad);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInventarioPorAdmin($representante_admin_id, $soloDisponibles = false) {
        return $this->getInventario(null, $soloDisponibles, $representante_admin_id);
    }

    public function getProducto($representante_id, $producto_id, $representante_admin_id = null) {
        $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
        $sql = "SELECT
                    ri.*,
                    p.producto,
                    p.imagen,
                    p.activo,
                    p.existencia as existencia_general
                FROM representante_inventario ri
                INNER JOIN productos p ON p.id = ri.producto_id
                WHERE {$identidad['where_ri']}
                  AND ri.producto_id = :producto_id
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $this->bindIdentidad($stmt, $identidad);
        $stmt->bindValue(':producto_id', (int)$producto_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getProductoPorAdmin($representante_admin_id, $producto_id) {
        return $this->getProducto(null, $producto_id, $representante_admin_id);
    }

    public function getResumen($representante_id, $representante_admin_id = null) {
        $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id, '');
        $sql = "SELECT
                    COUNT(*) as productos_asignados,
                    COALESCE(SUM(cantidad_disponible), 0) as unidades_disponibles,
                    COALESCE(SUM(cantidad_reservada), 0) as unidades_reservadas,
                    COALESCE(SUM(cantidad_vendida), 0) as unidades_vendidas,
                    COALESCE(SUM(cantidad_devuelta), 0) as unidades_devueltas
                FROM representante_inventario
                WHERE {$identidad['where']}";

        $stmt = $this->db->prepare($sql);
        $this->bindIdentidad($stmt, $identidad);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getResumenPorAdmin($representante_admin_id) {
        return $this->getResumen(null, $representante_admin_id);
    }

    public function getMovimientos($representante_id, $limit = 100, $representante_admin_id = null) {
        $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id, 'rim');
        $limit = max(1, min((int)$limit, 500));
        $sql = "SELECT
                    rim.*,
                    p.producto,
                    a.nombre as admin_nombre
                FROM representante_inventario_movimientos rim
                INNER JOIN productos p ON p.id = rim.producto_id
                LEFT JOIN administradores a ON a.id = rim.admin_id
                WHERE {$identidad['where']}
                ORDER BY rim.created_at DESC, rim.id DESC
                LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $this->bindIdentidad($stmt, $identidad);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMovimientosPorAdmin($representante_admin_id, $limit = 100) {
        return $this->getMovimientos(null, $limit, $representante_admin_id);
    }

    public function asignarConsignacion($representante_id, $producto_id, $cantidad, $admin_id = null, $solicitud_id = null, $notas = null, $descontarInventarioGeneral = true, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $producto_id = $this->validarId($producto_id, 'Producto invalido');
            $cantidad = $this->validarCantidad($cantidad);

            $this->db->beginTransaction();

            if ($descontarInventarioGeneral) {
                $producto = $this->bloquearProducto($producto_id);
                if (!$producto) {
                    throw new Exception('Producto no encontrado');
                }
                if ((int)$producto['existencia'] < $cantidad) {
                    throw new Exception('Inventario general insuficiente para asignar consignacion');
                }

                $stmt = $this->db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                $stmt->execute([$cantidad, $producto_id]);
            }

            $inventario = $this->asegurarInventario($producto_id, $representante_admin_id);
            $antes = (int)$inventario['cantidad_disponible'];
            $despues = $antes + $cantidad;

            $stmt = $this->db->prepare("
                UPDATE representante_inventario
                SET cantidad_disponible = cantidad_disponible + ?,
                    representante_admin_id = COALESCE(representante_admin_id, ?)
                WHERE representante_admin_id = ?
                  AND producto_id = ?
            ");
            $stmt->execute([$cantidad, $representante_admin_id, $representante_admin_id, $producto_id]);

            $this->registrarMovimiento(
                $representante_admin_id,
                $producto_id,
                'entrada_consignacion',
                $cantidad,
                $antes,
                $despues,
                null,
                $solicitud_id,
                $admin_id,
                $notas
            );

            $this->db->commit();
            return ['success' => true, 'cantidad_disponible' => $despues];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function asignarConsignacionPorAdmin($representante_admin_id, $producto_id, $cantidad, $admin_id = null, $solicitud_id = null, $notas = null, $descontarInventarioGeneral = true) {
        return $this->asignarConsignacion(null, $producto_id, $cantidad, $admin_id, $solicitud_id, $notas, $descontarInventarioGeneral, $representante_admin_id);
    }

    public function reservar($representante_id, $producto_id, $cantidad, $pedido_id = null, $notas = null, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $producto_id = $this->validarId($producto_id, 'Producto invalido');
            $cantidad = $this->validarCantidad($cantidad);

            $this->db->beginTransaction();

            $inventario = $this->bloquearInventario($producto_id, $representante_admin_id);
            if (!$inventario || (int)$inventario['cantidad_disponible'] < $cantidad) {
                throw new Exception('Inventario disponible insuficiente');
            }

            $antes = (int)$inventario['cantidad_disponible'];
            $despues = $antes - $cantidad;

            $stmt = $this->db->prepare("
                UPDATE representante_inventario
                SET cantidad_disponible = cantidad_disponible - ?,
                    cantidad_reservada = cantidad_reservada + ?
                WHERE representante_admin_id = ?
                  AND producto_id = ?
            ");
            $stmt->execute([$cantidad, $cantidad, $representante_admin_id, $producto_id]);

            $this->registrarMovimiento($representante_admin_id, $producto_id, 'reserva', $cantidad, $antes, $despues, $pedido_id, null, null, $notas);

            $this->db->commit();
            return ['success' => true, 'cantidad_disponible' => $despues];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function liberarReserva($representante_id, $producto_id, $cantidad, $pedido_id = null, $notas = null, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $producto_id = $this->validarId($producto_id, 'Producto invalido');
            $cantidad = $this->validarCantidad($cantidad);

            $this->db->beginTransaction();

            $inventario = $this->bloquearInventario($producto_id, $representante_admin_id);
            if (!$inventario || (int)$inventario['cantidad_reservada'] < $cantidad) {
                throw new Exception('Reserva insuficiente para liberar');
            }

            $antes = (int)$inventario['cantidad_disponible'];
            $despues = $antes + $cantidad;

            $stmt = $this->db->prepare("
                UPDATE representante_inventario
                SET cantidad_disponible = cantidad_disponible + ?,
                    cantidad_reservada = cantidad_reservada - ?
                WHERE representante_admin_id = ?
                  AND producto_id = ?
            ");
            $stmt->execute([$cantidad, $cantidad, $representante_admin_id, $producto_id]);

            $this->registrarMovimiento($representante_admin_id, $producto_id, 'liberacion_reserva', $cantidad, $antes, $despues, $pedido_id, null, null, $notas);

            $this->db->commit();
            return ['success' => true, 'cantidad_disponible' => $despues];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function registrarVenta($representante_id, $producto_id, $cantidad, $pedido_id = null, $desdeReserva = false, $notas = null, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $producto_id = $this->validarId($producto_id, 'Producto invalido');
            $cantidad = $this->validarCantidad($cantidad);

            $this->db->beginTransaction();

            $inventario = $this->bloquearInventario($producto_id, $representante_admin_id);
            if (!$inventario) {
                throw new Exception('El representante no tiene inventario asignado de este producto');
            }

            $campoOrigen = $desdeReserva ? 'cantidad_reservada' : 'cantidad_disponible';
            if ((int)$inventario[$campoOrigen] < $cantidad) {
                throw new Exception($desdeReserva ? 'Reserva insuficiente para registrar venta' : 'Inventario disponible insuficiente');
            }

            $antes = (int)$inventario['cantidad_disponible'];
            $despues = $desdeReserva ? $antes : $antes - $cantidad;

            $sql = $desdeReserva
                ? "UPDATE representante_inventario
                   SET cantidad_reservada = cantidad_reservada - ?,
                       cantidad_vendida = cantidad_vendida + ?
                   WHERE representante_admin_id = ? AND producto_id = ?"
                : "UPDATE representante_inventario
                   SET cantidad_disponible = cantidad_disponible - ?,
                       cantidad_vendida = cantidad_vendida + ?
                   WHERE representante_admin_id = ? AND producto_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cantidad, $cantidad, $representante_admin_id, $producto_id]);

            $this->registrarMovimiento($representante_admin_id, $producto_id, 'venta', $cantidad, $antes, $despues, $pedido_id, null, null, $notas);

            $this->db->commit();
            return ['success' => true, 'cantidad_disponible' => $despues];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function registrarVentaPorAdmin($representante_admin_id, $producto_id, $cantidad, $pedido_id = null, $desdeReserva = false, $notas = null) {
        return $this->registrarVenta(null, $producto_id, $cantidad, $pedido_id, $desdeReserva, $notas, $representante_admin_id);
    }

    public function devolver($representante_id, $producto_id, $cantidad, $admin_id = null, $notas = null, $regresarInventarioGeneral = true, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $producto_id = $this->validarId($producto_id, 'Producto invalido');
            $cantidad = $this->validarCantidad($cantidad);

            $this->db->beginTransaction();

            $inventario = $this->bloquearInventario($producto_id, $representante_admin_id);
            if (!$inventario || (int)$inventario['cantidad_disponible'] < $cantidad) {
                throw new Exception('Inventario disponible insuficiente para devolucion');
            }

            $antes = (int)$inventario['cantidad_disponible'];
            $despues = $antes - $cantidad;

            $stmt = $this->db->prepare("
                UPDATE representante_inventario
                SET cantidad_disponible = cantidad_disponible - ?,
                    cantidad_devuelta = cantidad_devuelta + ?
                WHERE representante_admin_id = ?
                  AND producto_id = ?
            ");
            $stmt->execute([$cantidad, $cantidad, $representante_admin_id, $producto_id]);

            if ($regresarInventarioGeneral) {
                $stmt = $this->db->prepare("UPDATE productos SET existencia = existencia + ? WHERE id = ?");
                $stmt->execute([$cantidad, $producto_id]);
            }

            $this->registrarMovimiento($representante_admin_id, $producto_id, 'devolucion', $cantidad, $antes, $despues, null, null, $admin_id, $notas);

            $this->db->commit();
            return ['success' => true, 'cantidad_disponible' => $despues];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function ajustarDisponible($representante_id, $producto_id, $nuevaCantidadDisponible, $admin_id = null, $notas = null, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $producto_id = $this->validarId($producto_id, 'Producto invalido');
            $nuevaCantidadDisponible = (int)$nuevaCantidadDisponible;

            if ($nuevaCantidadDisponible < 0) {
                throw new Exception('La nueva cantidad disponible no puede ser negativa');
            }

            $this->db->beginTransaction();

            $inventario = $this->asegurarInventario($producto_id, $representante_admin_id);
            $antes = (int)$inventario['cantidad_disponible'];
            $diferencia = $nuevaCantidadDisponible - $antes;

            $stmt = $this->db->prepare("
                UPDATE representante_inventario
                SET cantidad_disponible = ?
                WHERE representante_admin_id = ?
                  AND producto_id = ?
            ");
            $stmt->execute([$nuevaCantidadDisponible, $representante_admin_id, $producto_id]);

            $this->registrarMovimiento(
                $representante_admin_id,
                $producto_id,
                'ajuste',
                $diferencia,
                $antes,
                $nuevaCantidadDisponible,
                null,
                null,
                $admin_id,
                $notas
            );

            $this->db->commit();
            return ['success' => true, 'cantidad_disponible' => $nuevaCantidadDisponible];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    private function validarId($id, $mensaje) {
        $id = (int)$id;
        if ($id <= 0) {
            throw new Exception($mensaje);
        }
        return $id;
    }

    private function validarCantidad($cantidad) {
        $cantidad = (int)$cantidad;
        if ($cantidad <= 0) {
            throw new Exception('La cantidad debe ser mayor a cero');
        }
        return $cantidad;
    }

    private function bloquearProducto($producto_id) {
        $stmt = $this->db->prepare("SELECT * FROM productos WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$producto_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function bloquearInventario($producto_id, $representante_admin_id) {
        $identidad = $this->resolverIdentidad(null, $representante_admin_id, '');
        $stmt = $this->db->prepare("
            SELECT *
            FROM representante_inventario
            WHERE {$identidad['where']}
              AND producto_id = :producto_id
            FOR UPDATE
        ");
        $this->bindIdentidad($stmt, $identidad);
        $stmt->bindValue(':producto_id', (int)$producto_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function asegurarInventario($producto_id, $representante_admin_id) {
        $identidad = $this->resolverIdentidad(null, $representante_admin_id);
        $inventario = $this->bloquearInventario($producto_id, $identidad['representante_admin_id']);
        if ($inventario) {
            return $inventario;
        }

        $stmt = $this->db->prepare("
            INSERT INTO representante_inventario (representante_admin_id, producto_id)
            VALUES (?, ?)
        ");
        $stmt->execute([
            (int)$identidad['representante_admin_id'],
            (int)$producto_id
        ]);

        return $this->bloquearInventario($producto_id, $identidad['representante_admin_id']);
    }

    private function registrarMovimiento($representante_admin_id, $producto_id, $tipo, $cantidad, $cantidad_antes, $cantidad_despues, $pedido_id = null, $solicitud_id = null, $admin_id = null, $notas = null) {
        $identidad = $this->resolverIdentidad(null, $representante_admin_id);
        $stmt = $this->db->prepare("
            INSERT INTO representante_inventario_movimientos
                (representante_admin_id, producto_id, pedido_id, solicitud_consignacion_id, admin_id, tipo, cantidad, cantidad_antes, cantidad_despues, notas)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            (int)$identidad['representante_admin_id'],
            (int)$producto_id,
            $pedido_id ? (int)$pedido_id : null,
            $solicitud_id ? (int)$solicitud_id : null,
            $admin_id ? (int)$admin_id : null,
            $tipo,
            (int)$cantidad,
            (int)$cantidad_antes,
            (int)$cantidad_despues,
            $notas
        ]);
    }

    private function resolverIdentidad($representante_id = null, $representante_admin_id = null, $alias = 'ri') {
        $representante_admin_id = $representante_admin_id ? (int)$representante_admin_id : null;

        if (!$representante_admin_id) {
            throw new Exception('Representante invalido');
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        return [
            'representante_admin_id' => $representante_admin_id,
            'where' => $prefix . 'representante_admin_id = :representante_admin_id',
            'where_ri' => 'ri.representante_admin_id = :representante_admin_id'
        ];
    }

    private function bindIdentidad($stmt, $identidad) {
        $stmt->bindValue(':representante_admin_id', (int)$identidad['representante_admin_id'], PDO::PARAM_INT);
    }

    // ──────────────────────────────────────────────────────────────
    // TRASPASOS ENTRE REPRESENTANTES
    // ──────────────────────────────────────────────────────────────

    public function crearTraspaso(int $origen_admin_id, int $destino_admin_id, int $producto_id, int $cantidad, string $notas = ''): array {
        if ($origen_admin_id === $destino_admin_id) {
            return ['success' => false, 'mensaje' => 'No puedes traspasarte inventario a ti mismo'];
        }
        try {
            $this->db->beginTransaction();

            $inv = $this->bloquearInventario($producto_id, $origen_admin_id);
            if (!$inv || (int)$inv['cantidad_disponible'] < $cantidad) {
                throw new Exception('Inventario disponible insuficiente para el traspaso');
            }

            $stmt = $this->db->prepare(
                "INSERT INTO traspasos_inventario (origen_admin_id, destino_admin_id, producto_id, cantidad, estado, notas)
                 VALUES (?, ?, ?, ?, 'pendiente', ?)"
            );
            $stmt->execute([$origen_admin_id, $destino_admin_id, $producto_id, $cantidad, $notas ?: null]);
            $traspasoId = (int)$this->db->lastInsertId();

            $antes = (int)$inv['cantidad_disponible'];
            $this->db->prepare(
                "UPDATE representante_inventario
                 SET cantidad_disponible = cantidad_disponible - ?,
                     cantidad_reservada  = cantidad_reservada  + ?
                 WHERE representante_admin_id = ? AND producto_id = ?"
            )->execute([$cantidad, $cantidad, $origen_admin_id, $producto_id]);

            $this->registrarMovimiento($origen_admin_id, $producto_id, 'traspaso_salida', $cantidad, $antes, $antes - $cantidad, null, null, null,
                "Traspaso #{$traspasoId} pendiente de confirmación");

            $this->db->commit();
            return ['success' => true, 'traspaso_id' => $traspasoId];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function confirmarTraspaso(int $traspaso_id, int $destino_admin_id): array {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "SELECT * FROM traspasos_inventario
                 WHERE id = ? AND destino_admin_id = ? AND estado = 'pendiente' FOR UPDATE"
            );
            $stmt->execute([$traspaso_id, $destino_admin_id]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) throw new Exception('Traspaso no encontrado o ya procesado');

            $orig   = (int)$t['origen_admin_id'];
            $pid    = (int)$t['producto_id'];
            $qty    = (int)$t['cantidad'];

            // Descontar reserva del origen
            $this->db->prepare(
                "UPDATE representante_inventario
                 SET cantidad_reservada = GREATEST(cantidad_reservada - ?, 0)
                 WHERE representante_admin_id = ? AND producto_id = ?"
            )->execute([$qty, $orig, $pid]);

            // Aumentar disponible del destino (crear fila si no existe)
            $this->asegurarInventario($pid, $destino_admin_id);
            $invDest = $this->bloquearInventario($pid, $destino_admin_id);
            $antesDest = (int)$invDest['cantidad_disponible'];

            $this->db->prepare(
                "UPDATE representante_inventario
                 SET cantidad_disponible = cantidad_disponible + ?
                 WHERE representante_admin_id = ? AND producto_id = ?"
            )->execute([$qty, $destino_admin_id, $pid]);

            $this->registrarMovimiento($destino_admin_id, $pid, 'traspaso_entrada', $qty, $antesDest, $antesDest + $qty, null, null, null,
                "Traspaso #{$traspaso_id} recibido");

            $this->db->prepare(
                "UPDATE traspasos_inventario SET estado = 'confirmado', respondido_at = NOW() WHERE id = ?"
            )->execute([$traspaso_id]);

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function rechazarTraspaso(int $traspaso_id, int $destino_admin_id): array {
        return $this->_deshacerTraspaso($traspaso_id, null, $destino_admin_id, 'rechazado');
    }

    public function cancelarTraspaso(int $traspaso_id, int $origen_admin_id): array {
        return $this->_deshacerTraspaso($traspaso_id, $origen_admin_id, null, 'cancelado');
    }

    private function _deshacerTraspaso(int $traspaso_id, ?int $origen_check, ?int $destino_check, string $nuevoEstado): array {
        try {
            $this->db->beginTransaction();

            if ($origen_check !== null) {
                $stmt = $this->db->prepare(
                    "SELECT * FROM traspasos_inventario WHERE id = ? AND estado = 'pendiente' AND origen_admin_id = ? FOR UPDATE"
                );
                $stmt->execute([$traspaso_id, $origen_check]);
            } else {
                $stmt = $this->db->prepare(
                    "SELECT * FROM traspasos_inventario WHERE id = ? AND estado = 'pendiente' AND destino_admin_id = ? FOR UPDATE"
                );
                $stmt->execute([$traspaso_id, $destino_check]);
            }

            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) throw new Exception('Traspaso no encontrado o ya procesado');

            $orig = (int)$t['origen_admin_id'];
            $pid  = (int)$t['producto_id'];
            $qty  = (int)$t['cantidad'];

            $invOrig = $this->bloquearInventario($pid, $orig);
            $antes   = (int)$invOrig['cantidad_disponible'];

            $this->db->prepare(
                "UPDATE representante_inventario
                 SET cantidad_reservada  = GREATEST(cantidad_reservada - ?, 0),
                     cantidad_disponible = cantidad_disponible + ?
                 WHERE representante_admin_id = ? AND producto_id = ?"
            )->execute([$qty, $qty, $orig, $pid]);

            $this->registrarMovimiento($orig, $pid, 'liberacion_reserva', $qty, $antes, $antes + $qty, null, null, null,
                "Traspaso #{$traspaso_id} {$nuevoEstado}");

            $this->db->prepare(
                "UPDATE traspasos_inventario SET estado = ?, respondido_at = NOW() WHERE id = ?"
            )->execute([$nuevoEstado, $traspaso_id]);

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function getTraspasosPendientesRecibidos(int $representante_admin_id): array {
        $stmt = $this->db->prepare(
            "SELECT t.*, p.producto, a.nombre AS origen_nombre
             FROM traspasos_inventario t
             JOIN productos p ON p.id = t.producto_id
             JOIN administradores a ON a.id = t.origen_admin_id
             WHERE t.destino_admin_id = ? AND t.estado = 'pendiente'
             ORDER BY t.created_at DESC"
        );
        $stmt->execute([$representante_admin_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTraspasosPendientesEnviados(int $representante_admin_id): array {
        $stmt = $this->db->prepare(
            "SELECT t.*, p.producto, a.nombre AS destino_nombre
             FROM traspasos_inventario t
             JOIN productos p ON p.id = t.producto_id
             JOIN administradores a ON a.id = t.destino_admin_id
             WHERE t.origen_admin_id = ? AND t.estado = 'pendiente'
             ORDER BY t.created_at DESC"
        );
        $stmt->execute([$representante_admin_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOtrosRepresentantes(int $mi_admin_id): array {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.nombre, rp.codigo
             FROM administradores a
             JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
             JOIN representante_perfiles rp ON rp.admin_id = a.id AND rp.activo = 1
             WHERE a.activo = 1 AND a.id != ?
             ORDER BY a.nombre ASC"
        );
        $stmt->execute([$mi_admin_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
