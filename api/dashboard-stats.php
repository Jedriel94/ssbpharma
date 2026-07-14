<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../models/Dashboard.php';
require_once '../models/Administrador.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticación
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$dashboardModel = new Dashboard();
$adminModel = new Administrador();

$action = $_GET['action'] ?? '';
$admin_id = $_SESSION['admin_id'];

// Obtener datos del admin actual
$admin = $adminModel->getById($admin_id);
$rol_codigo = $admin['rol_codigo'] ?? 'admin';
$representante_admin_id = ($rol_codigo === 'representante') ? (int)$admin['id'] : null;
$representantes_visibles = $dashboardModel->getRepresentantesVisibles($admin_id, $rol_codigo, $representante_admin_id);

// ── Filtro por Gerente de Distrito (solo Gerente Nacional = director_unidad) ──
// El Gerente Nacional puede acotar la vista a un gerente subordinado específico.
// $admin_id_scope se usa en lugar de $admin_id para los métodos getKPIsSubordinados / getRankingSubordinados.
$admin_id_scope = $admin_id;
if ($rol_codigo === 'director_unidad' && isset($_GET['gerente_id']) && $_GET['gerente_id'] !== '') {
    $gerente_id_req = (int)$_GET['gerente_id'];
    // Validar que el gerente solicitado es subordinado directo del usuario actual
    $conn = Database::getInstance()->getConnection();
    $chk = $conn->prepare("SELECT id FROM administradores WHERE id = ? AND superior_id = ? AND activo = 1 LIMIT 1");
    $chk->execute([$gerente_id_req, $admin_id]);
    if ($chk->fetchColumn()) {
        $admin_id_scope = $gerente_id_req;
        // Redirigir representantes_visibles al scope del gerente seleccionado
        $representantes_visibles = $dashboardModel->getRepresentantesVisibles($gerente_id_req, 'gerente', null);
    }
}

// Fechas (por defecto: mes actual)
// IMPORTANTE: fecha_fin se normaliza a 23:59:59 para incluir pedidos
// creados en cualquier hora del día final (DATETIME vs DATE en BETWEEN)
$fecha_inicio = ($_GET['fecha_inicio'] ?? date('Y-m-01')) . ' 00:00:00';
$fecha_fin    = ($_GET['fecha_fin']    ?? date('Y-m-d'))  . ' 23:59:59';

// Leer config de estado de ventas UNA sola vez — se aplica a toda la sesión
require_once '../models/Configuracion.php';
$estado_conf = Configuracion::get('dashboard_estado_ventas', 'entregado');
$sin_iva     = isset($_GET['sin_iva']) && $_GET['sin_iva'] === '1';

try {
    switch ($action) {
        case 'kpis':
            // Obtener KPIs según el rol
            if ($rol_codigo === 'admin' || $rol_codigo === 'director_general' || $rol_codigo === 'viewer') {
                // Vista global
                $kpis = $dashboardModel->getKPIsGlobales($fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            } elseif ($rol_codigo === 'representante' && $representante_admin_id) {
                // Vista personal del representante
                $kpis = $dashboardModel->getKPIsRepresentante($representante_admin_id, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            } else {
                // Director de unidad o Gerente: vista de subordinados
                $kpis = $dashboardModel->getKPIsSubordinados($admin_id_scope, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            }
            
            echo json_encode(['success' => true, 'data' => $kpis]);
            break;
            
        case 'ranking':
            $limit = $_GET['limit'] ?? 10;
            
            if ($rol_codigo === 'admin' || $rol_codigo === 'director_general' || $rol_codigo === 'viewer') {
                // Ranking global de representantes
                $ranking = $dashboardModel->getRankingRepresentantes($limit, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            } elseif ($rol_codigo === 'representante') {
                // Ranking de todos para ver su posición
                $ranking = $dashboardModel->getRankingRepresentantes(100, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            } else {
                // Ranking de subordinados
                $ranking = $dashboardModel->getRankingSubordinados($admin_id_scope, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            }
            
            echo json_encode(['success' => true, 'data' => $ranking]);
            break;
            
        case 'top_productos':
            $limit        = (int)($_GET['limit'] ?? 50);
            $rep_id_fil   = isset($_GET['rep_id']) && $_GET['rep_id'] !== '' ? (int)$_GET['rep_id'] : null;
            $ruta_fil     = isset($_GET['ruta'])   && $_GET['ruta']   !== '' ? trim($_GET['ruta'])  : null;
            $mostrar_kits = isset($_GET['mostrar_kits']) && $_GET['mostrar_kits'] === '1';
            // Cuando "Mostrar Kits" está OFF: productos con totales completos (incluir_kits=true)
            // Cuando "Mostrar Kits" está ON:  productos solo directos + filas de kit (incluir_kits=false)
            $incluir_kits = !$mostrar_kits;

            // Scope de representantes: intersectar filtro con visibles
            $scope_ids = $representantes_visibles;
            if ($rep_id_fil !== null) {
                if ($scope_ids === null || in_array($rep_id_fil, $scope_ids)) {
                    $scope_ids = [$rep_id_fil];
                } else {
                    echo json_encode(['success' => false, 'message' => 'Sin acceso a ese representante']);
                    exit;
                }
            }

            $productos = $dashboardModel->getTopProductos(
                $limit, $fecha_inicio, $fecha_fin,
                $estado_conf, $scope_ids, $ruta_fil, $incluir_kits, $sin_iva
            );

            // Total de piezas para calcular % del total en el frontend
            $total_piezas = array_sum(array_column($productos, 'total_piezas'));

            echo json_encode([
                'success'      => true,
                'data'         => $productos,
                'total_piezas' => (int)$total_piezas,
            ]);
            break;

        case 'filtros_productos':
            $rutas = $dashboardModel->getRutasVisibles($representantes_visibles);
            $reps  = $dashboardModel->getRepresentantesLista($representantes_visibles);
            echo json_encode(['success' => true, 'data' => ['rutas' => $rutas, 'representantes' => $reps]]);
            break;

        case 'operaciones':
            $kpis = $dashboardModel->getKPIsOperativos($representantes_visibles, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            $representantes = $dashboardModel->getResumenOperativoRepresentantes($representantes_visibles, $fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            echo json_encode([
                'success' => true,
                'data' => [
                    'kpis' => $kpis,
                    'representantes' => $representantes
                ]
            ]);
            break;
            
        case 'tendencia':
            $periodo = $_GET['periodo'] ?? 'dia';
            $dias = $_GET['dias'] ?? 30;
            $tendencia = $dashboardModel->getTendenciaVentas($periodo, $dias, $representantes_visibles, $estado_conf, $sin_iva);
            echo json_encode(['success' => true, 'data' => $tendencia]);
            break;
            
        case 'comparativa':
            $comparativa = $dashboardModel->getComparativa($fecha_inicio, $fecha_fin, $estado_conf, $sin_iva);
            echo json_encode(['success' => true, 'data' => $comparativa]);
            break;
            
        case 'ultimos_pedidos':
            if ($rol_codigo === 'representante' && $representante_admin_id) {
                $limit = $_GET['limit'] ?? 10;
                $pedidos = $dashboardModel->getUltimosPedidosRepresentante($representante_admin_id, $limit);
                echo json_encode(['success' => true, 'data' => $pedidos]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No disponible para este rol']);
            }
            break;

        case 'rep_detalle':
            $rep_id = (int)($_GET['rep_id'] ?? 0);
            if (!$rep_id) {
                echo json_encode(['success' => false, 'message' => 'rep_id requerido']);
                break;
            }
            // Seguridad: verificar que el usuario tiene visibilidad sobre ese rep
            if ($rol_codigo !== 'admin' && $rol_codigo !== 'director_general' && $rol_codigo !== 'viewer') {
                if ($representantes_visibles !== null && !in_array($rep_id, array_map('intval', $representantes_visibles))) {
                    echo json_encode(['success' => false, 'message' => 'Sin acceso a este representante']);
                    break;
                }
            }
            $detalle = $dashboardModel->getRepDetalle($rep_id, $fecha_inicio, $fecha_fin);
            echo json_encode(['success' => true, 'data' => $detalle]);
            break;

        case 'rep_ventas_producto':
            $rep_id = (int)($_GET['rep_id'] ?? 0);
            if (!$rep_id) { echo json_encode(['success' => false, 'message' => 'rep_id requerido']); break; }
            if ($rol_codigo !== 'admin' && $rol_codigo !== 'director_general') {
                if ($representantes_visibles !== null && !in_array($rep_id, array_map('intval', $representantes_visibles))) {
                    echo json_encode(['success' => false, 'message' => 'Sin acceso']); break;
                }
            }
            $conn  = Database::getInstance()->getConnection();
            $estadoInRV = $dashboardModel->getEstadoIn($estado_conf);
            $montoRV = $sin_iva ? 'dp.subtotal / (1 + dp.impuesto)' : 'dp.subtotal';
            $stmtRV = $conn->prepare("
                SELECT pr.producto,
                       SUM(CASE WHEN p.canal = 'representante_directo' THEN dp.cantidad ELSE 0 END) AS directa_piezas,
                       SUM(CASE WHEN p.canal = 'representante_directo' THEN $montoRV    ELSE 0 END) AS directa_monto,
                       SUM(CASE WHEN p.canal != 'representante_directo' THEN dp.cantidad ELSE 0 END) AS tienda_piezas,
                       SUM(CASE WHEN p.canal != 'representante_directo' THEN $montoRV   ELSE 0 END) AS tienda_monto,
                       SUM(dp.cantidad) AS total_piezas,
                       SUM($montoRV)    AS total_monto
                FROM detalle_pedidos dp
                JOIN pedidos p   ON dp.pedido_id   = p.id
                JOIN productos pr ON dp.producto_id = pr.id
                WHERE p.representante_admin_id = :rep_id
                  AND p.estado IN ($estadoInRV)
                  AND p.created_at BETWEEN :fecha_inicio AND :fecha_fin
                GROUP BY dp.producto_id
                ORDER BY total_monto DESC
            ");
            $stmtRV->execute([':rep_id' => $rep_id, ':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin]);
            echo json_encode(['success' => true, 'data' => $stmtRV->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'pedido_detalle':
            $pedido_id = (int)($_GET['pedido_id'] ?? 0);
            if (!$pedido_id) { echo json_encode(['success' => false, 'message' => 'pedido_id requerido']); break; }
            $items = $dashboardModel->getPedidoItems($pedido_id);
            echo json_encode(['success' => true, 'data' => $items]);
            break;

        case 'solicitud_detalle':
            $solicitud_id = (int)($_GET['solicitud_id'] ?? 0);
            if (!$solicitud_id) { echo json_encode(['success' => false, 'message' => 'solicitud_id requerido']); break; }
            $items = $dashboardModel->getSolicitudItems($solicitud_id);
            echo json_encode(['success' => true, 'data' => $items]);
            break;

        case 'reporte_ventas':
            $dimension    = $_GET['dimension'] ?? 'producto';
            $allowed      = ['producto', 'cliente', 'estado', 'localidad', 'especialidad', 'cp', 'representante'];
            if (!in_array($dimension, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'Dimensión inválida']);
                break;
            }
            $mostrar_kits = isset($_GET['mostrar_kits']) && $_GET['mostrar_kits'] === '1';

            $conn = Database::getInstance()->getConnection();

            // WHERE base
            $where_parts = [
                "p.estado IN (" . $dashboardModel->getEstadoIn($estado_conf) . ")",
                "p.created_at BETWEEN :fecha_inicio AND :fecha_fin"
            ];
            $params = [':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin];

            // Scope de visibilidad del rol
            if ($representantes_visibles !== null) {
                if (empty($representantes_visibles)) {
                    echo json_encode(['success' => true, 'data' => [], 'total_monto' => 0]);
                    break;
                }
                $pids = implode(',', array_map('intval', $representantes_visibles));
                $where_parts[] = "p.representante_admin_id IN ($pids)";
            }

            // Filtro puntual por representante (desde el select del panel)
            $rv_rep_id = isset($_GET['rv_rep_id']) ? (int)$_GET['rv_rep_id'] : 0;
            if ($rv_rep_id > 0) {
                // Seguridad: solo si el rep está dentro del scope visible
                if ($representantes_visibles === null || in_array($rv_rep_id, array_map('intval', $representantes_visibles))) {
                    $where_parts[] = "p.representante_admin_id = :rv_rep_id";
                    $params[':rv_rep_id'] = $rv_rep_id;
                }
            }

            $where = 'WHERE ' . implode(' AND ', $where_parts);

            switch ($dimension) {
                case 'producto':
                    // Expresión de monto según sinIva
                    $montoDP = $sin_iva ? 'dp.subtotal / (1 + dp.impuesto)' : 'dp.subtotal';
                    if ($mostrar_kits) {
                        // Modo Mostrar Kits: ventas directas (sin pedidos de kits) + kits como filas separadas
                        $where2 = str_replace(
                            [':fecha_inicio', ':fecha_fin'],
                            [':fi2',          ':ff2'],
                            $where
                        );
                        $params[':fi2'] = $fecha_inicio;
                        $params[':ff2'] = $fecha_fin;
                        $sql = "SELECT pr.producto AS label,
                                       COALESCE(pr.marca, '') AS extra,
                                       SUM(dp.cantidad) AS piezas,
                                       SUM($montoDP) AS monto,
                                       COUNT(DISTINCT p.id) AS pedidos
                                FROM detalle_pedidos dp
                                JOIN pedidos p  ON dp.pedido_id  = p.id
                                JOIN productos pr ON dp.producto_id = pr.id
                                $where
                                AND dp.pedido_id NOT IN (SELECT DISTINCT pedido_id FROM kit_ventas)
                                GROUP BY dp.producto_id

                                UNION ALL

                                SELECT CONCAT('', k.nombre) AS label,
                                       '' AS extra,
                                       SUM(kv.cantidad) AS piezas,
                                       SUM(kv.subtotal) AS monto,
                                       COUNT(DISTINCT p.id) AS pedidos
                                FROM kits k
                                JOIN kit_ventas kv ON k.id = kv.kit_id
                                JOIN pedidos p ON kv.pedido_id = p.id
                                $where2
                                GROUP BY k.id

                                ORDER BY monto DESC";
                    } else {
                        // Modo normal: todos los productos agrupados
                        $sql = "SELECT pr.producto AS label,
                                       COALESCE(pr.marca, '') AS extra,
                                       SUM(dp.cantidad) AS piezas,
                                       SUM($montoDP) AS monto,
                                       COUNT(DISTINCT p.id) AS pedidos
                                FROM detalle_pedidos dp
                                JOIN pedidos p  ON dp.pedido_id  = p.id
                                JOIN productos pr ON dp.producto_id = pr.id
                                $where
                                GROUP BY dp.producto_id
                                ORDER BY monto DESC";
                    }
                    break;
                case 'cliente':
                    $ivaJoinCliente = $sin_iva
                        ? "LEFT JOIN (SELECT pedido_id, SUM(subtotal/(1+impuesto)) AS neto FROM detalle_pedidos GROUP BY pedido_id) _iva ON _iva.pedido_id = p.id"
                        : '';
                    $montoCliente = $sin_iva ? 'SUM(COALESCE(_iva.neto, 0))' : 'SUM(p.total)';
                    $sql = "SELECT COALESCE(c.nombre, 'Sin cliente') AS label,
                                   COALESCE(c.tipo_cliente, '') AS extra,
                                   NULL AS piezas,
                                   $montoCliente AS monto,
                                   COUNT(DISTINCT p.id) AS pedidos,
                                   DATE_FORMAT(MAX(p.created_at), '%d/%m/%Y') AS ultimo_pedido
                            FROM pedidos p
                            LEFT JOIN clientes c ON p.cliente_id = c.id
                            $ivaJoinCliente
                            $where
                            GROUP BY p.cliente_id
                            ORDER BY monto DESC";
                    break;
                case 'estado':
                    $ivaJoinEstado = $sin_iva
                        ? "LEFT JOIN (SELECT pedido_id, SUM(subtotal/(1+impuesto)) AS neto FROM detalle_pedidos GROUP BY pedido_id) _iva ON _iva.pedido_id = p.id"
                        : '';
                    $montoEstado = $sin_iva ? 'SUM(COALESCE(_iva.neto, 0))' : 'SUM(p.total)';
                    $sql = "SELECT COALESCE(NULLIF(p.estado_envio,''), c.estado, 'Sin estado') AS label,
                                   '' AS extra,
                                   NULL AS piezas,
                                   $montoEstado AS monto,
                                   COUNT(DISTINCT p.id) AS pedidos,
                                   COUNT(DISTINCT p.cliente_id) AS clientes
                            FROM pedidos p
                            LEFT JOIN clientes c ON p.cliente_id = c.id
                            $ivaJoinEstado
                            $where
                            GROUP BY label
                            ORDER BY monto DESC";
                    break;
                case 'localidad':
                    $ivaJoinLoc = $sin_iva
                        ? "LEFT JOIN (SELECT pedido_id, SUM(subtotal/(1+impuesto)) AS neto FROM detalle_pedidos GROUP BY pedido_id) _iva ON _iva.pedido_id = p.id"
                        : '';
                    $montoLoc = $sin_iva ? 'SUM(COALESCE(_iva.neto, 0))' : 'SUM(p.total)';
                    $sql = "SELECT COALESCE(NULLIF(p.ciudad,''), c.ciudad, 'Sin ciudad') AS label,
                                   COALESCE(NULLIF(p.estado_envio,''), c.estado, '') AS extra,
                                   NULL AS piezas,
                                   $montoLoc AS monto,
                                   COUNT(DISTINCT p.id) AS pedidos,
                                   COUNT(DISTINCT p.cliente_id) AS clientes
                            FROM pedidos p
                            LEFT JOIN clientes c ON p.cliente_id = c.id
                            $ivaJoinLoc
                            $where
                            GROUP BY label, extra
                            ORDER BY monto DESC";
                    break;
                case 'especialidad':
                    $ivaJoinEsp = $sin_iva
                        ? "LEFT JOIN (SELECT pedido_id, SUM(subtotal/(1+impuesto)) AS neto FROM detalle_pedidos GROUP BY pedido_id) _iva ON _iva.pedido_id = p.id"
                        : '';
                    $montoEsp = $sin_iva ? 'SUM(COALESCE(_iva.neto, 0))' : 'SUM(p.total)';
                    $sql = "SELECT COALESCE(NULLIF(c.especialidad,''), 'Sin especialidad') AS label,
                                   '' AS extra,
                                   NULL AS piezas,
                                   $montoEsp AS monto,
                                   COUNT(DISTINCT p.id) AS pedidos,
                                   COUNT(DISTINCT p.cliente_id) AS clientes
                            FROM pedidos p
                            LEFT JOIN clientes c ON p.cliente_id = c.id
                            $ivaJoinEsp
                            $where
                            GROUP BY c.especialidad
                            ORDER BY monto DESC";
                    break;
                case 'cp':
                    $ivaJoinCp = $sin_iva
                        ? "LEFT JOIN (SELECT pedido_id, SUM(subtotal/(1+impuesto)) AS neto FROM detalle_pedidos GROUP BY pedido_id) _iva ON _iva.pedido_id = p.id"
                        : '';
                    $montoCP = $sin_iva ? 'SUM(COALESCE(_iva.neto, 0))' : 'SUM(p.total)';
                    $sql = "SELECT COALESCE(NULLIF(p.cp_envio,''), c.cp, 'Sin CP') AS label,
                                   COALESCE(NULLIF(p.ciudad,''), c.ciudad, '') AS extra,
                                   NULL AS piezas,
                                   $montoCP AS monto,
                                   COUNT(DISTINCT p.id) AS pedidos,
                                   COUNT(DISTINCT p.cliente_id) AS clientes
                            FROM pedidos p
                            LEFT JOIN clientes c ON p.cliente_id = c.id
                            $ivaJoinCp
                            $where
                            GROUP BY label
                            ORDER BY monto DESC";
                    break;
                case 'representante':
                    $ivaJoinRep = $sin_iva
                        ? "LEFT JOIN (SELECT pedido_id, SUM(subtotal/(1+impuesto)) AS neto FROM detalle_pedidos GROUP BY pedido_id) _iva ON _iva.pedido_id = p.id"
                        : '';
                    $montoRep = $sin_iva ? 'SUM(COALESCE(_iva.neto, 0))' : 'SUM(p.total)';
                    $sql = "SELECT COALESCE(a.nombre, 'Sin representante') AS label,
                                   COALESCE(rp.codigo, '') AS extra,
                                   NULL AS piezas,
                                   $montoRep AS monto,
                                   COUNT(DISTINCT p.id) AS pedidos,
                                   COUNT(DISTINCT p.cliente_id) AS clientes
                            FROM pedidos p
                            LEFT JOIN administradores a ON a.id = p.representante_admin_id
                            LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
                            $ivaJoinRep
                            $where
                            GROUP BY p.representante_admin_id
                            ORDER BY monto DESC";
                    break;
            }

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_monto = array_sum(array_column($data, 'monto'));

            echo json_encode(['success' => true, 'data' => $data, 'total_monto' => $total_monto]);
            break;

        case 'inventario_movimientos':
            $rep_id     = (int)($_GET['rep_id']      ?? 0);
            $producto_id = (int)($_GET['producto_id'] ?? 0);
            if (!$rep_id || !$producto_id) { echo json_encode(['success' => false, 'message' => 'rep_id y producto_id requeridos']); break; }
            if ($rol_codigo !== 'admin' && $rol_codigo !== 'director_general') {
                if ($representantes_visibles !== null && !in_array($rep_id, array_map('intval', $representantes_visibles))) {
                    echo json_encode(['success' => false, 'message' => 'Sin acceso']); break;
                }
            }
            $movs = $dashboardModel->getInventarioMovimientos($rep_id, $producto_id);
            echo json_encode(['success' => true, 'data' => $movs]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
