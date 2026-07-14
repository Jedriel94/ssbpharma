<?php
require_once __DIR__ . '/../config/database.php';

class Dashboard {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    
    /**
     * Convierte el umbral de estado en un string SQL IN (...)
     * 'confirmado' → 'confirmado','en_ruta','entregado'
     * 'en_ruta'    → 'en_ruta','entregado'
     * 'entregado'  → 'entregado'
     */
    public function getEstadoIn(string $estado = 'entregado'): string {
        return $this->buildEstadoIn($estado);
    }

    private function buildEstadoIn(string $estado = 'entregado'): string {
        $map = [
            'confirmado' => "'confirmado','en_ruta','entregado'",
            'en_ruta'    => "'en_ruta','entregado'",
            'entregado'  => "'entregado'",
        ];
        return $map[$estado] ?? "'entregado'";
    }

    /**
     * Cuando $sinIva=true retorna un LEFT JOIN a una subconsulta que agrega
     * SUM(subtotal/(1+impuesto)) por pedido, y la expresión de monto sin IVA.
     * Requiere que el alias _dp_iva no esté ya en uso en el query.
     *
     * @return array{join:string, total:string}
     */
    private function buildSinIvaExpr(bool $sinIva, string $pedidoAlias = 'p'): array {
        if (!$sinIva) {
            // Usar SUM(detalle_pedidos.subtotal) - cupon para excluir costo_envio
            return [
                'join'  => "LEFT JOIN (SELECT pedido_id, SUM(subtotal) AS subtotal_bruto FROM detalle_pedidos GROUP BY pedido_id) _dp_bruto ON _dp_bruto.pedido_id = {$pedidoAlias}.id",
                'total' => "GREATEST(COALESCE(_dp_bruto.subtotal_bruto, 0) - COALESCE({$pedidoAlias}.cupon_descuento, 0), 0)",
            ];
        }
        return [
            'join'  => "LEFT JOIN (SELECT pedido_id, SUM(subtotal / (1 + impuesto)) AS neto_iva FROM detalle_pedidos GROUP BY pedido_id) _dp_iva
                         ON _dp_iva.pedido_id = {$pedidoAlias}.id",
            'total' => 'COALESCE(_dp_iva.neto_iva, 0)',
        ];
    }

    /**
     * Obtener KPIs generales (Admin y Director General)
     */
    public function getKPIsGlobales($fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn = $this->buildEstadoIn($estado);
        $iva      = $this->buildSinIvaExpr($sinIva);
        $where  = "WHERE p.estado IN ($estadoIn)";
        $where2 = "WHERE p2.estado IN ($estadoIn)";

        if ($fecha_inicio && $fecha_fin) {
            $where  .= " AND p.created_at  BETWEEN :fecha_inicio AND :fecha_fin";
            $where2 .= " AND p2.created_at BETWEEN :fecha_inicio2 AND :fecha_fin2";
        }

        $totalExpr = $iva['total'];
        $query = "SELECT
            COUNT(p.id) as total_pedidos,
            COALESCE(SUM({$totalExpr}), 0) as total_ventas,
            COALESCE(SUM({$totalExpr}), 0) / NULLIF(COUNT(p.id), 0) as ticket_promedio,
            COALESCE((
                SELECT SUM(dp.cantidad)
                FROM detalle_pedidos dp
                INNER JOIN pedidos p2 ON p2.id = dp.pedido_id
                $where2
            ), 0) as total_piezas
        FROM pedidos p
        {$iva['join']}
        $where";

        $stmt = $this->conn->prepare($query);

        if ($fecha_inicio && $fecha_fin) {
            $stmt->bindParam(':fecha_inicio',  $fecha_inicio);
            $stmt->bindParam(':fecha_fin',     $fecha_fin);
            $stmt->bindParam(':fecha_inicio2', $fecha_inicio);
            $stmt->bindParam(':fecha_fin2',    $fecha_fin);
        }

        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener KPIs por representante específico
     */
    public function getKPIsRepresentante($representante_admin_id, $fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn = $this->buildEstadoIn($estado);
        $iva      = $this->buildSinIvaExpr($sinIva);
        $where = "WHERE p.representante_admin_id = :representante_admin_id AND p.estado IN ($estadoIn)";
        
        if ($fecha_inicio && $fecha_fin) {
            $where .= " AND p.created_at BETWEEN :fecha_inicio AND :fecha_fin";
        }
        
        $totalExpr = $iva['total'];
        $query = "SELECT 
            COUNT(DISTINCT p.id) as total_pedidos,
            COALESCE(SUM({$totalExpr}), 0) as total_ventas,
            COALESCE(SUM({$totalExpr}), 0) / NULLIF(COUNT(DISTINCT p.id), 0) as ticket_promedio,
            COALESCE((SELECT SUM(dp.cantidad) FROM detalle_pedidos dp WHERE dp.pedido_id IN (
                SELECT id FROM pedidos p2
                WHERE p2.representante_admin_id = :representante_admin_id2
                AND p2.estado IN ($estadoIn)
            )), 0) as total_piezas
        FROM pedidos p
        {$iva['join']}
        $where";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':representante_admin_id',  $representante_admin_id);
        $stmt->bindParam(':representante_admin_id2', $representante_admin_id);
        
        if ($fecha_inicio && $fecha_fin) {
            $stmt->bindParam(':fecha_inicio', $fecha_inicio);
            $stmt->bindParam(':fecha_fin', $fecha_fin);
        }
        
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener KPIs de subordinados (para Gerentes y Directores)
     */
    public function getKPIsSubordinados($admin_id, $fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn  = $this->buildEstadoIn($estado);
        $iva       = $this->buildSinIvaExpr($sinIva);
        $dateCond  = '';
        $dateCond3 = '';
        if ($fecha_inicio && $fecha_fin) {
            $dateCond  = " AND p.created_at  BETWEEN :fecha_inicio  AND :fecha_fin";
            $dateCond3 = " AND p3.created_at BETWEEN :fecha_inicio3 AND :fecha_fin3";
        }

        $scopeCond = "a.superior_id IN (
            SELECT id FROM administradores WHERE superior_id = :admin_id
            UNION SELECT :admin_id
        ) AND p.estado IN ($estadoIn) {$dateCond}";

        $totalExpr = $iva['total'];
        $query = "SELECT
            COUNT(DISTINCT p.id) as total_pedidos,
            COALESCE(SUM({$totalExpr}), 0) as total_ventas,
            COALESCE(SUM({$totalExpr}), 0) / NULLIF(COUNT(DISTINCT p.id), 0) as ticket_promedio,
            COALESCE((SELECT SUM(dp.cantidad)
                FROM detalle_pedidos dp
                INNER JOIN pedidos p3 ON p3.id = dp.pedido_id
                INNER JOIN administradores a3 ON a3.id = p3.representante_admin_id
                WHERE a3.superior_id IN (
                    SELECT id FROM administradores WHERE superior_id = :admin_id2
                    UNION SELECT :admin_id2
                ) AND p3.estado IN ($estadoIn)
                {$dateCond3}
            ), 0) as total_piezas
        FROM pedidos p
        INNER JOIN administradores a ON a.id = p.representante_admin_id
        {$iva['join']}
        WHERE {$scopeCond}";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':admin_id',  $admin_id);
        $stmt->bindParam(':admin_id2', $admin_id);

        if ($fecha_inicio && $fecha_fin) {
            $stmt->bindParam(':fecha_inicio',  $fecha_inicio);
            $stmt->bindParam(':fecha_fin',     $fecha_fin);
            $stmt->bindParam(':fecha_inicio3', $fecha_inicio);
            $stmt->bindParam(':fecha_fin3',    $fecha_fin);
        }

        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ranking de representantes (Global o filtrado)
     */
    public function getRankingRepresentantes($limit = 10, $fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn  = $this->buildEstadoIn($estado);
        $ventasExpr = $sinIva ? 'SUM(dp.subtotal / (1 + dp.impuesto))' : 'SUM(dp.subtotal)';
        $where = "WHERE p.estado IN ($estadoIn)";
        
        if ($fecha_inicio && $fecha_fin) {
            $where .= " AND p.created_at BETWEEN :fecha_inicio AND :fecha_fin";
        }
        
        $query = "SELECT 
            a.id,
            a.nombre,
            rp.codigo,
            COUNT(DISTINCT p.id) as total_pedidos,
            COALESCE(SUM(dp.cantidad), 0) as total_piezas,
            COALESCE({$ventasExpr}, 0) as total_ventas,
            MAX(p.created_at) as ultima_venta
        FROM administradores a
        INNER JOIN roles rol ON rol.id = a.rol_id AND rol.codigo = 'representante'
        INNER JOIN representante_perfiles rp ON rp.admin_id = a.id
        LEFT JOIN pedidos p ON a.id = p.representante_admin_id AND p.estado IN ($estadoIn)";
        
        if ($fecha_inicio && $fecha_fin) {
            $query .= " AND p.created_at BETWEEN :fecha_inicio AND :fecha_fin";
        }
        
        $query .= "
        LEFT JOIN detalle_pedidos dp ON p.id = dp.pedido_id
        WHERE a.activo = 1 AND rp.activo = 1
        GROUP BY a.id
        ORDER BY total_ventas DESC
        LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        
        if ($fecha_inicio && $fecha_fin) {
            $stmt->bindParam(':fecha_inicio', $fecha_inicio);
            $stmt->bindParam(':fecha_fin', $fecha_fin);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ranking de subordinados directos (Gerentes)
     */
    public function getRankingSubordinados($admin_id, $fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn  = $this->buildEstadoIn($estado);
        $iva       = $this->buildSinIvaExpr($sinIva);
        $where  = "";
        $where2 = "";
        if ($fecha_inicio && $fecha_fin) {
            $where  = "AND p.created_at  BETWEEN :fecha_inicio  AND :fecha_fin";
            $where2 = "AND p2.created_at BETWEEN :fecha_inicio2 AND :fecha_fin2";
        }

        $totalExpr = $iva['total'];
        $query = "SELECT
            a.id,
            a.nombre,
            a.usuario,
            a.nombre as representante_nombre,
            rp.codigo as representante_codigo,
            COUNT(DISTINCT p.id) as total_pedidos,
            COALESCE((
                SELECT SUM(dp.cantidad)
                FROM detalle_pedidos dp
                INNER JOIN pedidos p2 ON p2.id = dp.pedido_id
                WHERE p2.representante_admin_id = a.id
                  AND p2.estado IN ($estadoIn)
                  $where2
            ), 0) as total_piezas,
            COALESCE(SUM({$totalExpr}), 0) as total_ventas,
            MAX(p.created_at) as ultima_venta
        FROM administradores a
        LEFT JOIN representante_perfiles rp ON rp.admin_id = a.id
        LEFT JOIN pedidos p ON a.id = p.representante_admin_id
                           AND p.estado IN ($estadoIn)
                           $where
        {$iva['join']}
        WHERE a.superior_id = :admin_id AND a.activo = 1
        GROUP BY a.id
        ORDER BY total_ventas DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':admin_id', $admin_id);

        if ($fecha_inicio && $fecha_fin) {
            $stmt->bindParam(':fecha_inicio',  $fecha_inicio);
            $stmt->bindParam(':fecha_fin',     $fecha_fin);
            $stmt->bindParam(':fecha_inicio2', $fecha_inicio);
            $stmt->bindParam(':fecha_fin2',    $fecha_fin);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Top productos más vendidos (con soporte de filtros y kits)
     *
     * @param int         $limit           Máx filas a retornar
     * @param string|null $fecha_inicio    DATETIME 'Y-m-d 00:00:00'
     * @param string|null $fecha_fin       DATETIME 'Y-m-d 23:59:59'
     * @param string      $estado          Umbral de estado: 'entregado' | 'en_ruta' | 'confirmado'
     * @param array|null  $representante_ids  null = todos, [] = ninguno, [1,2,...] = scope
     * @param string|null $ruta            Filtrar por campo ruta de administradores
     * @param bool        $incluir_kits    false = kits como filas separadas; true = expandir en componentes
     */
    public function getTopProductos(
        int    $limit            = 25,
        ?string $fecha_inicio    = null,
        ?string $fecha_fin       = null,
        string  $estado          = 'entregado',
        ?array  $representante_ids = null,
        ?string $ruta            = null,
        bool    $incluir_kits    = false,
        bool    $sinIva          = false
    ): array {
        // Estado umbral → IN list
        $threshold = [
            'confirmado' => "'confirmado','en_ruta','entregado'",
            'en_ruta'    => "'en_ruta','entregado'",
            'entregado'  => "'entregado'",
        ];
        $estado_in = $threshold[$estado] ?? "'entregado'";

        // Condiciones y parámetros base (compartidos por ambas partes del UNION/subqueries)
        $conds  = ["p.estado IN ($estado_in)"];
        $params = [];

        if ($fecha_inicio && $fecha_fin) {
            $conds[]  = "p.created_at BETWEEN ? AND ?";
            $params[] = $fecha_inicio;
            $params[] = $fecha_fin;
        }

        if ($representante_ids !== null) {
            if (empty($representante_ids)) {
                return []; // scope vacío → sin datos
            }
            $phs      = implode(',', array_fill(0, count($representante_ids), '?'));
            $conds[]  = "p.representante_admin_id IN ($phs)";
            $params   = array_merge($params, array_map('intval', $representante_ids));
        }

        $repJoin = '';
        if ($ruta !== null && $ruta !== '') {
            $repJoin  = "INNER JOIN administradores adm ON adm.id = p.representante_admin_id";
            $conds[]  = "adm.ruta = ?";
            $params[] = $ruta;
        }

        $where = "WHERE " . implode(" AND ", $conds);
        $montoExpr = $sinIva ? "SUM(dp.subtotal / (1 + dp.impuesto))" : "SUM(dp.subtotal)";

        if ($incluir_kits) {
            // Modo ON: agrupar por producto usando detalle_pedidos únicamente.
            $sql = "
                SELECT pr.id,
                       pr.producto               AS nombre,
                       COALESCE(d.piezas, 0)     AS total_piezas,
                       COALESCE(d.monto, 0)      AS total_monto,
                       COALESCE(d.pedidos, 0)    AS veces_vendido,
                       0                         AS es_kit
                FROM productos pr
                INNER JOIN (
                    SELECT dp.producto_id,
                           SUM(dp.cantidad)      AS piezas,
                           {$montoExpr}          AS monto,
                           COUNT(DISTINCT p.id)  AS pedidos
                    FROM detalle_pedidos dp
                    INNER JOIN pedidos p ON dp.pedido_id = p.id
                    $repJoin
                    $where
                    GROUP BY dp.producto_id
                ) d ON pr.id = d.producto_id
                ORDER BY total_piezas DESC
                LIMIT ?
            ";
            $finalParams = array_merge($params, [$limit]);
        } else {
            // Modo OFF: ventas directas (excluyendo componentes de kit) + kits como filas separadas
            $sql = "
                SELECT pr.id                     AS id,
                       pr.producto               AS nombre,
                       SUM(dp.cantidad)          AS total_piezas,
                       {$montoExpr}              AS total_monto,
                       COUNT(DISTINCT p.id)      AS veces_vendido,
                       0                        AS es_kit
                FROM productos pr
                INNER JOIN detalle_pedidos dp ON pr.id = dp.producto_id
                INNER JOIN pedidos p ON dp.pedido_id = p.id
                $repJoin
                $where
                AND dp.pedido_id NOT IN (SELECT DISTINCT pedido_id FROM kit_ventas)
                GROUP BY pr.id

                UNION ALL

                SELECT k.id                     AS id,
                       CONCAT('📦 ', k.nombre)  AS nombre,
                       SUM(kv.cantidad)         AS total_piezas,
                       SUM(kv.subtotal)         AS total_monto,
                       COUNT(DISTINCT p.id)     AS veces_vendido,
                       1                        AS es_kit
                FROM kits k
                INNER JOIN kit_ventas kv ON k.id = kv.kit_id
                INNER JOIN pedidos p ON kv.pedido_id = p.id
                $repJoin
                $where
                GROUP BY k.id

                ORDER BY total_piezas DESC
                LIMIT ?
            ";
            // params se usa dos veces (parte producto + parte kit del UNION)
            $finalParams = array_merge($params, $params, [$limit]);
        }

        $stmt = $this->conn->prepare($sql);
        foreach ($finalParams as $i => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($i + 1, $val, $type);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rutas disponibles dentro del scope de representantes visibles
     */
    public function getRutasVisibles(?array $representante_ids): array {
        $sql    = "SELECT DISTINCT ruta, desc_ruta FROM administradores WHERE ruta IS NOT NULL AND ruta != '' AND activo = 1";
        $params = [];
        if ($representante_ids !== null) {
            if (empty($representante_ids)) return [];
            $phs    = implode(',', array_fill(0, count($representante_ids), '?'));
            $sql   .= " AND id IN ($phs)";
            $params = array_map('intval', $representante_ids);
        }
        $sql .= " ORDER BY ruta";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista de representantes dentro del scope visible
     */
    public function getRepresentantesLista(?array $representante_ids): array {
        $sql    = "SELECT a.id, a.nombre, a.ruta FROM administradores a
                   INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
                   WHERE a.activo = 1";
        $params = [];
        if ($representante_ids !== null) {
            if (empty($representante_ids)) return [];
            $phs    = implode(',', array_fill(0, count($representante_ids), '?'));
            $sql   .= " AND a.id IN ($phs)";
            $params = array_map('intval', $representante_ids);
        }
        $sql .= " ORDER BY a.nombre";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRepresentantesVisibles($admin_id, $rol_codigo, $representante_admin_id = null) {
        if ($rol_codigo === 'admin' || $rol_codigo === 'director_general' || $rol_codigo === 'viewer') {
            return null; // null = sin filtro de scope = ve todos
        }

        if ($rol_codigo === 'representante' && $representante_admin_id) {
            return [(int)$representante_admin_id];
        }

        $stmt = $this->conn->prepare("
            WITH RECURSIVE jerarquia AS (
                SELECT id
                FROM administradores
                WHERE id = ?

                UNION ALL

                SELECT a.id
                FROM administradores a
                INNER JOIN jerarquia j ON a.superior_id = j.id
                WHERE a.activo = 1
            )
            SELECT DISTINCT a.id
            FROM jerarquia j
            INNER JOIN administradores a ON a.id = j.id
            INNER JOIN roles r ON r.id = a.rol_id AND r.codigo = 'representante'
            INNER JOIN representante_perfiles rp ON rp.admin_id = a.id
            WHERE a.activo = 1
        ");
        $stmt->execute([(int)$admin_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getKPIsOperativos($representante_admin_ids = null, $fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn  = $this->buildEstadoIn($estado);
        $iva       = $this->buildSinIvaExpr($sinIva);
        $scope = $this->buildRepresentanteScope($representante_admin_ids, 'p');
        $dateWhere = '';
        $params = $scope['params'];

        if ($fecha_inicio && $fecha_fin) {
            $dateWhere = " AND p.created_at BETWEEN ? AND ?";
            $params[] = $fecha_inicio;
            $params[] = $fecha_fin;
        }

        $totalExpr = $iva['total'];
        $sql = "SELECT
                    COUNT(CASE WHEN p.canal = 'representante_directo' AND p.estado IN ($estadoIn) THEN 1 END) as ventas_directas,
                    COALESCE(SUM(CASE WHEN p.canal = 'representante_directo' AND p.estado IN ($estadoIn) THEN {$totalExpr} ELSE 0 END), 0) as monto_directo,
                    COUNT(CASE WHEN p.canal = 'cliente_directo' AND p.estado IN ($estadoIn) THEN 1 END) as ventas_tienda,
                    COALESCE(SUM(CASE WHEN p.canal = 'cliente_directo' AND p.estado IN ($estadoIn) THEN {$totalExpr} ELSE 0 END), 0) as monto_tienda,
                    COUNT(CASE WHEN p.canal = 'representante_qr' AND p.estado IN ($estadoIn) THEN 1 END) as ventas_qr,
                    COUNT(CASE WHEN p.estado IN ('pendiente','por_verificar') THEN 1 END) as pagos_por_validar,
                    COUNT(CASE WHEN p.requiere_factura = 1 AND p.factura_pdf IS NULL AND p.factura_xml IS NULL THEN 1 END) as cfdi_pendientes
                FROM pedidos p
                {$iva['join']}
                WHERE 1=1 {$scope['where']} {$dateWhere}";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $kpis = $stmt->fetch(PDO::FETCH_ASSOC);

        // efectivo_pendiente: consulta separada SIN filtro de fechas (son obligaciones activas)
        $efScope = $this->buildRepresentanteScope($representante_admin_ids, 'p');
        $stmtEf = $this->conn->prepare("
            SELECT
                COUNT(CASE WHEN p.metodo_pago = 'efectivo' AND p.estado_liquidacion = 'pendiente' THEN 1 END) as efectivo_pendiente,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'efectivo' AND p.estado_liquidacion = 'pendiente' THEN {$totalExpr} ELSE 0 END), 0) as monto_efectivo_pendiente
            FROM pedidos p
            {$iva['join']}
            WHERE 1=1 {$efScope['where']}
        ");
        $stmtEf->execute($efScope['params']);
        $efectivoKpi = $stmtEf->fetch(PDO::FETCH_ASSOC);

        $scopeSol = $this->buildRepresentanteScope($representante_admin_ids, 'sc');
        $stmt = $this->conn->prepare("
            SELECT
                COUNT(CASE WHEN sc.estado NOT IN ('rechazada','cancelada') THEN 1 END) as solicitudes_abiertas,
                COUNT(CASE WHEN sc.estado = 'entregada' THEN 1 END) as solicitudes_entregadas
            FROM solicitudes_consignacion sc
            WHERE 1=1 {$scopeSol['where']}
        ");
        $stmt->execute($scopeSol['params']);
        $solicitudes = $stmt->fetch(PDO::FETCH_ASSOC);

        $scopeInv = $this->buildRepresentanteScope($representante_admin_ids, 'ri');
        $stmt = $this->conn->prepare("
            SELECT
                COALESCE(SUM(ri.cantidad_disponible), 0) as inventario_disponible,
                COALESCE(SUM(ri.cantidad_reservada), 0) as inventario_reservado,
                COALESCE(SUM(ri.cantidad_vendida), 0) as inventario_vendido
            FROM representante_inventario ri
            WHERE 1=1 {$scopeInv['where']}
        ");
        $stmt->execute($scopeInv['params']);
        $inventario = $stmt->fetch(PDO::FETCH_ASSOC);

        return array_merge($kpis ?: [], $efectivoKpi ?: [], $solicitudes ?: [], $inventario ?: []);
    }

    public function getResumenOperativoRepresentantes($representante_admin_ids = null, $fecha_inicio = null, $fecha_fin = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn  = $this->buildEstadoIn($estado);
        $iva       = $this->buildSinIvaExpr($sinIva);
        $scope = $this->buildRepresentanteScope($representante_admin_ids, 'a', 'id');
        $dateJoin = '';
        $params = [];

        if ($fecha_inicio && $fecha_fin) {
            $dateJoin = " AND p.created_at BETWEEN ? AND ?";
            $params[] = $fecha_inicio;
            $params[] = $fecha_fin;
        }
        $params = array_merge($params, $scope['params']);

        $totalExpr = $iva['total'];
        $sql = "SELECT
                    a.id,
                    rp.codigo,
                    a.nombre,
                    COUNT(DISTINCT CASE WHEN p.canal = 'representante_directo' AND p.estado IN ($estadoIn) THEN p.id END) as ventas_directas,
                    COALESCE(SUM(CASE WHEN p.canal = 'representante_directo' AND p.estado IN ($estadoIn) THEN {$totalExpr} ELSE 0 END), 0) as monto_directo,
                    COUNT(DISTINCT CASE WHEN p.canal = 'cliente_directo' AND p.estado IN ($estadoIn) THEN p.id END) as ventas_tienda,
                    COALESCE(SUM(CASE WHEN p.canal = 'cliente_directo' AND p.estado IN ($estadoIn) THEN {$totalExpr} ELSE 0 END), 0) as monto_tienda,
                    COALESCE(ef.efectivo_pendiente, 0) as efectivo_pendiente,
                    COUNT(DISTINCT CASE WHEN p.requiere_factura = 1 AND p.factura_pdf IS NULL AND p.factura_xml IS NULL THEN p.id END) as cfdi_pendientes,
                    COALESCE(inv.inventario_disponible, 0) as inventario_disponible,
                    COALESCE(sol.solicitudes_abiertas, 0) as solicitudes_abiertas
                FROM administradores a
                INNER JOIN roles rol ON rol.id = a.rol_id AND rol.codigo = 'representante'
                INNER JOIN representante_perfiles rp ON rp.admin_id = a.id
                LEFT JOIN pedidos p ON p.representante_admin_id = a.id {$dateJoin}
                {$iva['join']}
                LEFT JOIN (
                    SELECT representante_admin_id, SUM(cantidad_disponible) as inventario_disponible
                    FROM representante_inventario
                    GROUP BY representante_admin_id
                ) inv ON inv.representante_admin_id = a.id
                LEFT JOIN (
                    SELECT representante_admin_id, COUNT(*) as solicitudes_abiertas
                    FROM solicitudes_consignacion
                    WHERE estado NOT IN ('rechazada','cancelada')
                    GROUP BY representante_admin_id
                ) sol ON sol.representante_admin_id = a.id
                LEFT JOIN (
                    SELECT representante_admin_id, COUNT(*) as efectivo_pendiente
                    FROM pedidos
                    WHERE metodo_pago = 'efectivo' AND estado_liquidacion = 'pendiente'
                    GROUP BY representante_admin_id
                ) ef ON ef.representante_admin_id = a.id
                WHERE a.activo = 1 AND rp.activo = 1 {$scope['where']}
                GROUP BY a.id, rp.codigo, a.nombre, inv.inventario_disponible, sol.solicitudes_abiertas, ef.efectivo_pendiente
                ORDER BY monto_directo DESC, ventas_directas DESC, a.nombre ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildRepresentanteScope($representante_admin_ids, $alias, $field = 'representante_admin_id') {
        if ($representante_admin_ids === null) {
            return ['where' => '', 'params' => []];
        }

        if (empty($representante_admin_ids)) {
            return ['where' => ' AND 1=0', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($representante_admin_ids), '?'));
        return [
            'where' => " AND {$alias}.{$field} IN ({$placeholders})",
            'params' => array_map('intval', $representante_admin_ids)
        ];
    }
    
    /**
     * Tendencia de ventas (agrupado por día/semana/mes)
     */
    public function getTendenciaVentas($periodo = 'dia', $dias = 30, $representante_admin_ids = null, string $estado = 'entregado', bool $sinIva = false) {
        $estadoIn     = $this->buildEstadoIn($estado);
        $fecha_inicio = date('Y-m-d', strtotime("-$dias days"));
        $ventasExpr   = $sinIva ? 'SUM(dp.subtotal / (1 + dp.impuesto))' : 'SUM(p.total)';
        
        $group_by = match($periodo) {
            'dia' => "DATE(p.created_at)",
            'semana' => "YEARWEEK(p.created_at, 1)",
            'mes' => "DATE_FORMAT(p.created_at, '%Y-%m')",
            default => "DATE(p.created_at)"
        };
        
        $scope = $this->buildRepresentanteScope($representante_admin_ids, 'p');
        $params = $scope['params'];
        $params[] = $fecha_inicio; // para el bind positional

        $query = "SELECT 
            $group_by as periodo,
            COUNT(DISTINCT p.id) as total_pedidos,
            COALESCE(SUM(dp.cantidad), 0) as total_piezas,
            COALESCE({$ventasExpr}, 0) as total_ventas
        FROM pedidos p
        LEFT JOIN detalle_pedidos dp ON p.id = dp.pedido_id
        WHERE p.estado IN ($estadoIn) AND p.created_at >= ?
        {$scope['where']}
        GROUP BY periodo
        ORDER BY periodo ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Comparativa con periodo anterior
     */
    public function getComparativa($fecha_inicio, $fecha_fin, string $estado = 'entregado', bool $sinIva = false) {
        $dias_diferencia = (strtotime($fecha_fin) - strtotime($fecha_inicio)) / 86400;
        $fecha_inicio_anterior = date('Y-m-d', strtotime("-$dias_diferencia days", strtotime($fecha_inicio)));
        $fecha_fin_anterior = $fecha_inicio;
        
        // Periodo actual
        $actual = $this->getKPIsGlobales($fecha_inicio, $fecha_fin, $estado, $sinIva);
        
        // Periodo anterior
        $anterior = $this->getKPIsGlobales($fecha_inicio_anterior, $fecha_fin_anterior, $estado, $sinIva);
        
        return [
            'actual' => $actual,
            'anterior' => $anterior,
            'cambios' => [
                'ventas' => $this->calcularCambio($anterior['total_ventas'], $actual['total_ventas']),
                'piezas' => $this->calcularCambio($anterior['total_piezas'], $actual['total_piezas']),
                'pedidos' => $this->calcularCambio($anterior['total_pedidos'], $actual['total_pedidos']),
                'ticket' => $this->calcularCambio($anterior['ticket_promedio'], $actual['ticket_promedio'])
            ]
        ];
    }
    
    /**
     * Calcular porcentaje de cambio
     */
    private function calcularCambio($anterior, $actual) {
        if ($anterior == 0) {
            return $actual > 0 ? 100 : 0;
        }
        return round((($actual - $anterior) / $anterior) * 100, 2);
    }
    
    /**
     * Últimos pedidos del representante
     */
    public function getUltimosPedidosRepresentante($representante_admin_id, $limit = 10) {
        $query = "SELECT 
            p.id,
            p.total,
            p.estado,
            p.created_at,
            c.nombre as cliente_nombre,
            c.telefono as cliente_telefono,
            SUM(dp.cantidad) as total_piezas
        FROM pedidos p
        INNER JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN detalle_pedidos dp ON p.id = dp.pedido_id
        WHERE p.representante_admin_id = :representante_admin_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':representante_admin_id', $representante_admin_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Detalle completo de un representante (panel lateral)
     */
    public function getRepDetalle(int $rep_id, string $fecha_inicio, string $fecha_fin): array {
        // Información básica
        $stmt = $this->conn->prepare(
            "SELECT a.id, a.nombre, a.usuario, a.activo,
                    rp.codigo, rp.telefono, rp.email
             FROM administradores a
             LEFT JOIN representante_perfiles rp ON rp.admin_id = a.id
             WHERE a.id = ?"
        );
        $stmt->execute([$rep_id]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // KPIs del período
        $stmt = $this->conn->prepare(
            "SELECT
                COUNT(*) as total_pedidos,
                COUNT(CASE WHEN estado = 'entregado' THEN 1 END) as ventas_entregadas,
                COALESCE(SUM(CASE WHEN estado = 'entregado' THEN total END), 0) as monto_entregado,
                COUNT(CASE WHEN estado IN ('pendiente','por_verificar') THEN 1 END) as por_validar,
                COUNT(CASE WHEN estado_liquidacion = 'pendiente' AND estado NOT IN ('pendiente','por_verificar') THEN 1 END) as efectivo_pendiente,
                COALESCE(SUM(CASE WHEN estado_liquidacion = 'pendiente' AND estado NOT IN ('pendiente','por_verificar') THEN total END), 0) as monto_efectivo
             FROM pedidos
             WHERE representante_admin_id = ? AND created_at BETWEEN ? AND ?"
        );
        $stmt->execute([$rep_id, $fecha_inicio, $fecha_fin]);
        $kpis = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Pedidos del período
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.estado, p.canal, p.total, p.created_at,
                    p.estado_liquidacion, p.requiere_factura,
                    (p.factura_pdf IS NOT NULL) as tiene_factura,
                    c.nombre as cliente_nombre, c.telefono as cliente_tel
             FROM pedidos p
             LEFT JOIN clientes c ON c.id = p.cliente_id
             WHERE p.representante_admin_id = ? AND p.created_at BETWEEN ? AND ?
             ORDER BY p.id DESC
             LIMIT 100"
        );
        $stmt->execute([$rep_id, $fecha_inicio, $fecha_fin]);
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Solicitudes de consignación filtradas por el período
        $stmt = $this->conn->prepare(
            "SELECT id, estado, fecha_solicitud, fecha_entrega, paqueteria, numero_guia
             FROM solicitudes_consignacion
             WHERE representante_admin_id = ?
               AND fecha_solicitud BETWEEN ? AND ?
             ORDER BY id DESC
             LIMIT 50"
        );
        $stmt->execute([$rep_id, $fecha_inicio, $fecha_fin]);
        $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Inventario actual
        $stmt = $this->conn->prepare(
            "SELECT ri.producto_id, pr.producto as producto_nombre,
                    ri.cantidad_disponible, ri.cantidad_reservada,
                    ri.cantidad_vendida, ri.cantidad_devuelta
             FROM representante_inventario ri
             INNER JOIN productos pr ON pr.id = ri.producto_id
             WHERE ri.representante_admin_id = ?
             ORDER BY ri.cantidad_disponible DESC"
        );
        $stmt->execute([$rep_id]);
        $inventario = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return compact('info', 'kpis', 'pedidos', 'solicitudes', 'inventario');
    }

    /** Items de un pedido (para drill-down desde el drawer) */
    public function getPedidoItems(int $pedido_id): array {
        $stmt = $this->conn->prepare(
            "SELECT dp.cantidad, dp.precio_unitario, dp.subtotal,
                    pr.producto as nombre
             FROM detalle_pedidos dp
             INNER JOIN productos pr ON pr.id = dp.producto_id
             WHERE dp.pedido_id = ?
             ORDER BY dp.id ASC"
        );
        $stmt->execute([$pedido_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Items de una solicitud de consignación */
    public function getSolicitudItems(int $solicitud_id): array {
        $stmt = $this->conn->prepare(
            "SELECT scd.cantidad_solicitada, scd.cantidad_aprobada, scd.cantidad_entregada,
                    pr.producto as nombre
             FROM solicitudes_consignacion_detalle scd
             INNER JOIN productos pr ON pr.id = scd.producto_id
             WHERE scd.solicitud_id = ?
             ORDER BY scd.id ASC"
        );
        $stmt->execute([$solicitud_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Movimientos de inventario de un producto para un representante */
    public function getInventarioMovimientos(int $rep_id, int $producto_id): array {
        $stmt = $this->conn->prepare(
            "SELECT rim.tipo, rim.cantidad, rim.cantidad_antes, rim.cantidad_despues,
                    rim.notas, rim.pedido_id, rim.solicitud_consignacion_id, rim.created_at,
                    a.nombre as admin_nombre
             FROM representante_inventario_movimientos rim
             LEFT JOIN administradores a ON a.id = rim.admin_id
             WHERE rim.representante_admin_id = ? AND rim.producto_id = ?
             ORDER BY rim.id DESC
             LIMIT 100"
        );
        $stmt->execute([$rep_id, $producto_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
