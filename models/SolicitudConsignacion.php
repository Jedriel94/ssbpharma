<?php
require_once __DIR__ . '/../config/database.php';

class SolicitudConsignacion {
    private $db;

    public function __construct($pdo = null) {
        if ($pdo) {
            $this->db = $pdo;
        } else {
            $this->db = Database::getInstance()->getConnection();
        }
    }

    public function crear($representante_id, $items, $notas_representante = null, $solicitado_por_admin_id = null, $representante_admin_id = null) {
        try {
            $identidad = $this->resolverIdentidad($representante_id, $representante_admin_id);
            $representante_admin_id = $identidad['representante_admin_id'];
            $items = $this->normalizarItems($items, 'cantidad_solicitada');

            if (empty($items)) {
                throw new Exception('La solicitud debe incluir al menos un producto');
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO solicitudes_consignacion
                    (representante_admin_id, solicitado_por_admin_id, estado, notas_representante)
                VALUES
                    (?, ?, 'solicitada', ?)
            ");
            $stmt->execute([
                $representante_admin_id,
                $solicitado_por_admin_id ? (int)$solicitado_por_admin_id : null,
                $notas_representante
            ]);

            $solicitud_id = (int)$this->db->lastInsertId();

            $stmtDetalle = $this->db->prepare("
                INSERT INTO solicitudes_consignacion_detalle
                    (solicitud_id, producto_id, cantidad_solicitada)
                VALUES
                    (?, ?, ?)
            ");

            foreach ($items as $item) {
                $stmtDetalle->execute([
                    $solicitud_id,
                    $item['producto_id'],
                    $item['cantidad_solicitada']
                ]);
            }

            $this->db->commit();

            return [
                'success' => true,
                'solicitud_id' => $solicitud_id,
                'mensaje' => 'Solicitud creada'
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return [
                'success' => false,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    public function crearPorAdmin($representante_admin_id, $items, $notas_representante = null, $solicitado_por_admin_id = null) {
        return $this->crear(null, $items, $notas_representante, $solicitado_por_admin_id, $representante_admin_id);
    }

    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT
                sc.*,
                rp.codigo as representante_codigo,
                ar.nombre as representante_nombre,
                rp.telefono as representante_telefono,
                rp.dir_calle   as rep_dir_calle,
                rp.dir_numero  as rep_dir_numero,
                rp.dir_colonia as rep_dir_colonia,
                rp.dir_ciudad  as rep_dir_ciudad,
                rp.dir_estado  as rep_dir_estado,
                rp.dir_cp      as rep_dir_cp,
                rp.dir_referencias  as rep_dir_referencias,
                rp.dir_quien_recibe as rep_dir_quien_recibe,
                a1.nombre as solicitado_por_nombre,
                a2.nombre as revisado_por_nombre
            FROM solicitudes_consignacion sc
            LEFT JOIN representante_perfiles rp ON rp.admin_id = sc.representante_admin_id
            LEFT JOIN administradores ar ON ar.id = sc.representante_admin_id
            LEFT JOIN administradores a1 ON a1.id = sc.solicitado_por_admin_id
            LEFT JOIN administradores a2 ON a2.id = sc.revisado_por_admin_id
            WHERE sc.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$id]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$solicitud) {
            return null;
        }

        $solicitud['detalle'] = $this->getDetalle($solicitud['id']);
        return $solicitud;
    }

    public function getDetalle($solicitud_id) {
        $stmt = $this->db->prepare("
            SELECT
                scd.*,
                p.producto,
                p.imagen,
                p.existencia as existencia_general,
                p.activo
            FROM solicitudes_consignacion_detalle scd
            INNER JOIN productos p ON p.id = scd.producto_id
            WHERE scd.solicitud_id = ?
            ORDER BY p.producto ASC
        ");
        $stmt->execute([(int)$solicitud_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll($filtros = []) {
        $sql = "SELECT
                    sc.*,
                    rp.codigo as representante_codigo,
                    ar.nombre as representante_nombre,
                    rp.dir_calle        as rep_dir_calle,
                    rp.dir_numero       as rep_dir_numero,
                    rp.dir_colonia      as rep_dir_colonia,
                    rp.dir_ciudad       as rep_dir_ciudad,
                    rp.dir_estado       as rep_dir_estado,
                    rp.dir_cp           as rep_dir_cp,
                    rp.dir_referencias  as rep_dir_referencias,
                    rp.dir_quien_recibe as rep_dir_quien_recibe,
                    COUNT(scd.id) as total_productos,
                    COALESCE(SUM(scd.cantidad_solicitada), 0) as total_solicitado,
                    COALESCE(SUM(scd.cantidad_aprobada), 0) as total_aprobado,
                    COALESCE(SUM(scd.cantidad_entregada), 0) as total_entregado
                FROM solicitudes_consignacion sc
                LEFT JOIN representante_perfiles rp ON rp.admin_id = sc.representante_admin_id
                LEFT JOIN administradores ar ON ar.id = sc.representante_admin_id
                LEFT JOIN solicitudes_consignacion_detalle scd ON scd.solicitud_id = sc.id
                WHERE 1=1";

        $params = [];

        if (!empty($filtros['estado'])) {
            $sql .= " AND sc.estado = ?";
            $params[] = $filtros['estado'];
        }

        if (!empty($filtros['representante_admin_id'])) {
            $sql .= " AND sc.representante_admin_id = ?";
            $params[] = (int)$filtros['representante_admin_id'];
        }

        if (!empty($filtros['fecha_inicio'])) {
            $sql .= " AND sc.fecha_solicitud >= ?";
            $params[] = $filtros['fecha_inicio'];
        }

        if (!empty($filtros['fecha_fin'])) {
            $sql .= " AND sc.fecha_solicitud <= ?";
            $params[] = $filtros['fecha_fin'];
        }

        // Excluir estados finales (entregada/rechazada/cancelada) con más de N días
        if (!empty($filtros['fecha_limite_finales'])) {
            $sql .= " AND (sc.estado NOT IN ('entregada','rechazada','cancelada') OR sc.updated_at >= ?)";
            $params[] = $filtros['fecha_limite_finales'];
        }

        $sql .= " GROUP BY sc.id ORDER BY sc.fecha_solicitud DESC, sc.id DESC";

        if (!empty($filtros['limit'])) {
            $limit = max(1, min((int)$filtros['limit'], 500));
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByRepresentante($representante_id, $limit = 100, $representante_admin_id = null) {
        $filtros = ['limit' => $limit];

        if ($representante_admin_id) {
            $filtros['representante_admin_id'] = (int)$representante_admin_id;
        } else {
            return [];
        }

        return $this->getAll($filtros);
    }

    public function getByRepresentanteAdmin($representante_admin_id, $limit = 100) {
        return $this->getAll([
            'representante_admin_id' => (int)$representante_admin_id,
            'limit' => $limit
        ]);
    }

    public function aprobar($solicitud_id, $cantidades_aprobadas, $admin_id, $notas_admin = null) {
        try {
            $solicitud_id = $this->validarId($solicitud_id, 'Solicitud invalida');
            $admin_id = $this->validarId($admin_id, 'Administrador invalido');
            $cantidades = $this->normalizarCantidadesPorProducto($cantidades_aprobadas);

            $this->db->beginTransaction();

            $solicitud = $this->bloquearSolicitud($solicitud_id);
            if (!$solicitud) {
                throw new Exception('Solicitud no encontrada');
            }
            if (!in_array($solicitud['estado'], ['solicitada', 'aprobada'], true)) {
                throw new Exception('La solicitud no se puede aprobar desde el estado actual');
            }

            $detalle = $this->getDetalle($solicitud_id);
            if (empty($detalle)) {
                throw new Exception('La solicitud no tiene productos');
            }

            $stmtDetalle = $this->db->prepare("
                UPDATE solicitudes_consignacion_detalle
                SET cantidad_aprobada = ?
                WHERE solicitud_id = ?
                  AND producto_id = ?
            ");

            $totalAprobado = 0;
            foreach ($detalle as $item) {
                $producto_id = (int)$item['producto_id'];
                $aprobada = array_key_exists($producto_id, $cantidades)
                    ? (int)$cantidades[$producto_id]
                    : (int)$item['cantidad_solicitada'];

                if ($aprobada < 0) {
                    throw new Exception('La cantidad aprobada no puede ser negativa');
                }
                if ($aprobada > (int)$item['cantidad_solicitada']) {
                    throw new Exception('No se puede aprobar mas de lo solicitado para ' . $item['producto']);
                }

                $stmtDetalle->execute([$aprobada, $solicitud_id, $producto_id]);
                $totalAprobado += $aprobada;
            }

            if ($totalAprobado <= 0) {
                throw new Exception('Debe aprobar al menos una unidad');
            }

            $stmt = $this->db->prepare("
                UPDATE solicitudes_consignacion
                SET estado = 'aprobada',
                    revisado_por_admin_id = ?,
                    notas_admin = ?,
                    fecha_revision = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $notas_admin, $solicitud_id]);

            $this->db->commit();

            return ['success' => true, 'mensaje' => 'Solicitud aprobada'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function rechazar($solicitud_id, $admin_id, $notas_admin = null) {
        return $this->actualizarEstadoRevision($solicitud_id, 'rechazada', $admin_id, $notas_admin);
    }

    public function cancelar($solicitud_id, $admin_id = null, $notas_admin = null) {
        try {
            $solicitud_id = $this->validarId($solicitud_id, 'Solicitud invalida');

            $this->db->beginTransaction();

            $solicitud = $this->bloquearSolicitud($solicitud_id);
            if (!$solicitud) {
                throw new Exception('Solicitud no encontrada');
            }
            if (in_array($solicitud['estado'], ['entregada', 'cancelada'], true)) {
                throw new Exception('La solicitud no se puede cancelar desde el estado actual');
            }

            $stmt = $this->db->prepare("
                UPDATE solicitudes_consignacion
                SET estado = 'cancelada',
                    revisado_por_admin_id = COALESCE(?, revisado_por_admin_id),
                    notas_admin = COALESCE(?, notas_admin),
                    fecha_revision = COALESCE(fecha_revision, NOW())
                WHERE id = ?
            ");
            $stmt->execute([
                $admin_id ? (int)$admin_id : null,
                $notas_admin,
                $solicitud_id
            ]);

            $this->db->commit();
            return ['success' => true, 'mensaje' => 'Solicitud cancelada'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function marcarPreparando($solicitud_id, $admin_id, $notas_admin = null) {
        return $this->actualizarEstadoRevision($solicitud_id, 'preparando', $admin_id, $notas_admin, ['aprobada']);
    }

    public function enviar($solicitud_id, $admin_id, $datosEnvio, $notas_admin = null) {
        try {
            $solicitud_id = $this->validarId($solicitud_id, 'Solicitud invalida');
            $admin_id = $this->validarId($admin_id, 'Administrador invalido');

            $paqueteria = trim((string)($datosEnvio['paqueteria'] ?? ''));
            $numeroGuia = trim((string)($datosEnvio['numero_guia'] ?? ''));
            $urlRastreo = trim((string)($datosEnvio['url_rastreo'] ?? ''));
            $guiaArchivo = trim((string)($datosEnvio['guia_archivo'] ?? ''));

            if ($paqueteria === '' || $numeroGuia === '') {
                throw new Exception('La paqueteria y el numero de guia son requeridos');
            }

            $this->db->beginTransaction();

            $solicitud = $this->bloquearSolicitud($solicitud_id);
            if (!$solicitud) {
                throw new Exception('Solicitud no encontrada');
            }
            if (!in_array($solicitud['estado'], ['aprobada', 'preparando'], true)) {
                throw new Exception('La solicitud debe estar aprobada o en preparacion para enviarse');
            }

            $stmt = $this->db->prepare("
                UPDATE solicitudes_consignacion
                SET estado = 'en_transito',
                    revisado_por_admin_id = ?,
                    notas_admin = COALESCE(?, notas_admin),
                    paqueteria = ?,
                    numero_guia = ?,
                    url_rastreo = ?,
                    guia_archivo = COALESCE(?, guia_archivo),
                    fecha_revision = COALESCE(fecha_revision, NOW()),
                    fecha_envio = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $admin_id,
                $notas_admin,
                $paqueteria,
                $numeroGuia,
                $urlRastreo !== '' ? $urlRastreo : null,
                $guiaArchivo !== '' ? $guiaArchivo : null,
                $solicitud_id
            ]);

            $this->db->commit();

            return ['success' => true, 'mensaje' => 'Solicitud enviada con guia'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function actualizarGuia($solicitud_id, $admin_id, array $datos) {
        try {
            $solicitud_id = $this->validarId($solicitud_id, 'Solicitud invalida');
            $admin_id     = $this->validarId($admin_id,     'Administrador invalido');

            $paqueteria  = trim((string)($datos['paqueteria']  ?? ''));
            $numeroGuia  = trim((string)($datos['numero_guia'] ?? ''));
            $urlRastreo  = trim((string)($datos['url_rastreo'] ?? ''));
            $guiaArchivo = trim((string)($datos['guia_archivo'] ?? ''));

            if ($paqueteria === '' || $numeroGuia === '') {
                throw new Exception('La paqueteria y el numero de guia son requeridos');
            }

            $this->db->beginTransaction();
            $solicitud = $this->bloquearSolicitud($solicitud_id);
            if (!$solicitud) throw new Exception('Solicitud no encontrada');
            if ($solicitud['estado'] !== 'en_transito') {
                throw new Exception('Solo se puede corregir la guia cuando la solicitud esta en transito');
            }

            $stmt = $this->db->prepare("
                UPDATE solicitudes_consignacion
                SET paqueteria  = ?,
                    numero_guia = ?,
                    url_rastreo = ?,
                    guia_archivo = COALESCE(NULLIF(?, ''), guia_archivo)
                WHERE id = ?
            ");
            $stmt->execute([$paqueteria, $numeroGuia, $urlRastreo !== '' ? $urlRastreo : null, $guiaArchivo, $solicitud_id]);
            $this->db->commit();
            return ['success' => true, 'mensaje' => 'Guia actualizada correctamente'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function entregar($solicitud_id, $admin_id, $cantidades_entregadas = null, $notas_admin = null) {
        try {
            $solicitud_id = $this->validarId($solicitud_id, 'Solicitud invalida');
            $admin_id = $this->validarId($admin_id, 'Administrador invalido');
            $cantidades = $cantidades_entregadas === null ? null : $this->normalizarCantidadesPorProducto($cantidades_entregadas);

            $this->db->beginTransaction();

            $solicitud = $this->bloquearSolicitud($solicitud_id);
            if (!$solicitud) {
                throw new Exception('Solicitud no encontrada');
            }
            if ($solicitud['estado'] !== 'en_transito') {
                throw new Exception('La solicitud debe estar en transito para entregarse');
            }

            $detalle = $this->getDetalle($solicitud_id);
            if (empty($detalle)) {
                throw new Exception('La solicitud no tiene productos');
            }

            $stmtDetalle = $this->db->prepare("
                UPDATE solicitudes_consignacion_detalle
                SET cantidad_entregada = ?
                WHERE solicitud_id = ?
                  AND producto_id = ?
            ");

            foreach ($detalle as $item) {
                $producto_id = (int)$item['producto_id'];
                $aprobada = (int)$item['cantidad_aprobada'];
                $entregada = $cantidades === null
                    ? $aprobada
                    : (int)($cantidades[$producto_id] ?? 0);

                if ($entregada < 0) {
                    throw new Exception('La cantidad entregada no puede ser negativa');
                }
                if ($entregada > $aprobada) {
                    throw new Exception('No se puede entregar mas de lo aprobado para ' . $item['producto']);
                }
                if ($entregada <= 0) {
                    continue;
                }

                $this->descontarInventarioGeneral($producto_id, $entregada, $item['producto']);
                $inventario = $this->asegurarInventarioRepresentante($producto_id, $solicitud['representante_admin_id'] ?? null);
                $antes = (int)$inventario['cantidad_disponible'];
                $despues = $antes + $entregada;

                $stmtInv = $this->db->prepare("
                    UPDATE representante_inventario
                    SET cantidad_disponible = cantidad_disponible + ?,
                        representante_admin_id = COALESCE(representante_admin_id, ?)
                    WHERE representante_admin_id = ?
                      AND producto_id = ?
                ");
                $stmtInv->execute([$entregada, $solicitud['representante_admin_id'] ?? null, $solicitud['representante_admin_id'], $producto_id]);

                $this->registrarMovimientoInventario(
                    $solicitud['representante_admin_id'],
                    $producto_id,
                    'entrada_consignacion',
                    $entregada,
                    $antes,
                    $despues,
                    $solicitud_id,
                    $admin_id,
                    $notas_admin
                );

                $stmtDetalle->execute([$entregada, $solicitud_id, $producto_id]);
            }

            $stmt = $this->db->prepare("
                UPDATE solicitudes_consignacion
                SET estado = 'entregada',
                    revisado_por_admin_id = ?,
                    notas_admin = COALESCE(?, notas_admin),
                    fecha_entrega = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $notas_admin, $solicitud_id]);

            $this->db->commit();

            return ['success' => true, 'mensaje' => 'Inventario entregado al representante'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'mensaje' => $e->getMessage()];
        }
    }

    private function actualizarEstadoRevision($solicitud_id, $estado, $admin_id, $notas_admin = null, $estadosPermitidos = ['solicitada', 'aprobada', 'preparando', 'en_transito']) {
        try {
            $solicitud_id = $this->validarId($solicitud_id, 'Solicitud invalida');
            $admin_id = $this->validarId($admin_id, 'Administrador invalido');

            $this->db->beginTransaction();

            $solicitud = $this->bloquearSolicitud($solicitud_id);
            if (!$solicitud) {
                throw new Exception('Solicitud no encontrada');
            }
            if (!in_array($solicitud['estado'], $estadosPermitidos, true)) {
                throw new Exception('La solicitud no se puede cambiar desde el estado actual');
            }

            $stmt = $this->db->prepare("
                UPDATE solicitudes_consignacion
                SET estado = ?,
                    revisado_por_admin_id = ?,
                    notas_admin = COALESCE(?, notas_admin),
                    fecha_revision = COALESCE(fecha_revision, NOW())
                WHERE id = ?
            ");
            $stmt->execute([$estado, $admin_id, $notas_admin, $solicitud_id]);

            $this->db->commit();

            return ['success' => true, 'mensaje' => 'Solicitud actualizada'];
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

    private function normalizarItems($items, $campoCantidad) {
        if (!is_array($items)) {
            throw new Exception('Los productos deben enviarse como arreglo');
        }

        $normalizados = [];
        foreach ($items as $item) {
            $producto_id = (int)($item['producto_id'] ?? 0);
            $cantidad = (int)($item[$campoCantidad] ?? $item['cantidad'] ?? 0);

            if ($producto_id <= 0 || $cantidad <= 0) {
                continue;
            }

            if (!isset($normalizados[$producto_id])) {
                $normalizados[$producto_id] = [
                    'producto_id' => $producto_id,
                    $campoCantidad => 0
                ];
            }
            $normalizados[$producto_id][$campoCantidad] += $cantidad;
        }

        return array_values($normalizados);
    }

    private function normalizarCantidadesPorProducto($cantidades) {
        if (!is_array($cantidades)) {
            throw new Exception('Las cantidades deben enviarse como arreglo');
        }

        $normalizadas = [];
        foreach ($cantidades as $key => $value) {
            if (is_array($value)) {
                $producto_id = (int)($value['producto_id'] ?? 0);
                $cantidad = (int)($value['cantidad'] ?? $value['cantidad_aprobada'] ?? $value['cantidad_entregada'] ?? 0);
            } else {
                $producto_id = (int)$key;
                $cantidad = (int)$value;
            }

            if ($producto_id > 0) {
                $normalizadas[$producto_id] = $cantidad;
            }
        }

        return $normalizadas;
    }

    private function bloquearSolicitud($solicitud_id) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM solicitudes_consignacion
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([(int)$solicitud_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function descontarInventarioGeneral($producto_id, $cantidad, $productoNombre) {
        $stmt = $this->db->prepare("SELECT existencia FROM productos WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$producto_id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new Exception('Producto no encontrado');
        }
        if ((int)$producto['existencia'] < (int)$cantidad) {
            throw new Exception('Inventario general insuficiente para ' . $productoNombre);
        }

        $stmt = $this->db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
        $stmt->execute([(int)$cantidad, (int)$producto_id]);
    }

    private function asegurarInventarioRepresentante($producto_id, $representante_admin_id) {
        $identidad = $this->resolverIdentidad(null, $representante_admin_id);
        $stmt = $this->db->prepare("
            SELECT *
            FROM representante_inventario
            WHERE representante_admin_id = ?
              AND producto_id = ?
            FOR UPDATE
        ");
        $stmt->execute([(int)$identidad['representante_admin_id'], (int)$producto_id]);
        $inventario = $stmt->fetch(PDO::FETCH_ASSOC);

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

        $stmt = $this->db->prepare("
            SELECT *
            FROM representante_inventario
            WHERE representante_admin_id = ?
              AND producto_id = ?
            FOR UPDATE
        ");
        $stmt->execute([(int)$identidad['representante_admin_id'], (int)$producto_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function registrarMovimientoInventario($representante_admin_id, $producto_id, $tipo, $cantidad, $antes, $despues, $solicitud_id, $admin_id, $notas = null) {
        $identidad = $this->resolverIdentidad(null, $representante_admin_id);
        $stmt = $this->db->prepare("
            INSERT INTO representante_inventario_movimientos
                (representante_admin_id, producto_id, solicitud_consignacion_id, admin_id, tipo, cantidad, cantidad_antes, cantidad_despues, notas)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            (int)$identidad['representante_admin_id'],
            (int)$producto_id,
            (int)$solicitud_id,
            (int)$admin_id,
            $tipo,
            (int)$cantidad,
            (int)$antes,
            (int)$despues,
            $notas
        ]);
    }

    private function resolverIdentidad($representante_id = null, $representante_admin_id = null) {
        $representante_admin_id = $representante_admin_id ? (int)$representante_admin_id : null;

        if (!$representante_admin_id) {
            throw new Exception('Representante invalido');
        }

        return [
            'representante_admin_id' => $representante_admin_id
        ];
    }
}
