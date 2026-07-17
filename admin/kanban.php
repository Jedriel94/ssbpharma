<?php
require_once __DIR__ . '/../includes/auth_admin.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../models/Pedido.php';
require_once __DIR__ . '/../models/Cliente.php';
require_once __DIR__ . '/../models/MensajePedido.php';
require_once __DIR__ . '/../utils/Mailer.php';

$pedidoModel = new Pedido();
$clienteModel = new Cliente();
$mensajeModel = new MensajePedido();
$rolCodigo = $_SESSION['admin_rol_codigo'] ?? ($authAdminActual['rol_codigo'] ?? '');
$puedeEditarDireccionPedido = ($rolCodigo === 'admin');

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'obtener_conteo_mensajes':
            // Decodificar el JSON que viene del JavaScript
            $pedidos_ids = isset($_POST['pedidos_ids']) ? json_decode($_POST['pedidos_ids'], true) : [];
            
            if (empty($pedidos_ids) || !is_array($pedidos_ids)) {
                echo json_encode(['success' => false, 'conteos' => [], 'error' => 'IDs vacíos o inválidos']);
                exit;
            }
            
            $conteos = [];
            $estados = [];
            
            foreach ($pedidos_ids as $pedido_id) {
                // Contar solo mensajes NO LEÍDOS del cliente
                $conteos[$pedido_id] = $mensajeModel->contarNoLeidosAdmin($pedido_id);
                
                // Obtener el estado actual del pedido
                $pedido = $pedidoModel->getById($pedido_id);
                if ($pedido) {
                    $estados[$pedido_id] = $pedido['estado'];
                }
            }
            
            echo json_encode([
                'success' => true, 
                'conteos' => $conteos,
                'estados' => $estados
            ]);
            exit;
            
        case 'verificar_nuevos_pedidos':
            $ultimo_timestamp = $_POST['ultimo_timestamp'] ?? date('Y-m-d H:i:s', strtotime('-1 day'));
            
            // Obtener pedidos creados después del último timestamp
            $sql = "SELECT p.*,
                    c.telefono,
                    c.nombre,
                    ar.nombre as representante_nombre_real,
                    rp.codigo as representante_codigo,
                    (SELECT COUNT(*) FROM mensajes_pedido mp 
                     WHERE mp.pedido_id = p.id AND mp.usuario_tipo = 'cliente' AND mp.leido = 0) as mensajes_no_leidos
                    FROM pedidos p
                    INNER JOIN clientes c ON p.cliente_id = c.id
                    LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
                    LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
                    WHERE p.created_at > :ultimo_timestamp 
                    AND p.estado IN ('pendiente', 'por_verificar', 'confirmado', 'en_ruta')
                    ORDER BY p.created_at DESC";
            
            $stmt = $pedidoModel->db->prepare($sql);
            $stmt->bindParam(':ultimo_timestamp', $ultimo_timestamp);
            $stmt->execute();
            $nuevos_pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($nuevos_pedidos)) {
                // Obtener detalles de cada pedido nuevo
                $pedidos_con_detalle = [];
                foreach ($nuevos_pedidos as $pedido) {
                    $detalle = $pedidoModel->getDetalle($pedido['id']);
                    $pedidos_con_detalle[] = [
                        'pedido' => $pedido,
                        'detalle' => $detalle,
                        'mensajes_no_leidos' => $pedido['mensajes_no_leidos']
                    ];
                }
                
                echo json_encode([
                    'success' => true, 
                    'hay_nuevos' => true,
                    'cantidad' => count($nuevos_pedidos),
                    'pedidos' => $pedidos_con_detalle,
                    'ultimo_timestamp' => $nuevos_pedidos[0]['created_at']
                ]);
            } else {
                echo json_encode([
                    'success' => true, 
                    'hay_nuevos' => false,
                    'cantidad' => 0
                ]);
            }
            exit;
            
        case 'obtener_html_tarjeta':
            $pedido_id = intval($_POST['pedido_id'] ?? 0);
            if (!$pedido_id) {
                echo json_encode(['success' => false, 'message' => 'ID inválido']);
                exit;
            }
            // Cargar el pedido con sus datos de cliente
            $sql_card = "SELECT p.*, c.telefono, c.nombre,
                                ar.nombre as representante_nombre_real,
                                rp.codigo as representante_codigo
                         FROM pedidos p 
                         INNER JOIN clientes c ON p.cliente_id = c.id 
                         LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
                         LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
                         WHERE p.id = :id";
            $stmt_card = $pedidoModel->db->prepare($sql_card);
            $stmt_card->bindParam(':id', $pedido_id);
            $stmt_card->execute();
            $pedido = $stmt_card->fetch(PDO::FETCH_ASSOC);
            if (!$pedido) {
                echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
                exit;
            }
            ob_start();
            include __DIR__ . '/kanban-card.php';
            $html = ob_get_clean();
            echo json_encode(['success' => true, 'html' => $html, 'estado' => $pedido['estado']]);
            exit;

        case 'cambiar_estado':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $nuevo_estado = $_POST['estado'] ?? '';
            
            $estados_validos = ['pendiente', 'por_verificar', 'confirmado', 'en_ruta', 'entregado', 'cancelado'];
            
            if (!in_array($nuevo_estado, $estados_validos)) {
                echo json_encode(['success' => false, 'message' => 'Estado no válido']);
                exit;
            }
            
            if ($pedidoModel->updateEstado($pedido_id, $nuevo_estado)) {
                // Hook inventario representante según el nuevo estado
                try {
                    require_once __DIR__ . '/../models/RepresentanteVenta.php';
                    require_once __DIR__ . '/../models/Configuracion.php';
                    $ventaModel = new RepresentanteVenta();
                    if ($nuevo_estado === 'cancelado') {
                        $ventaModel->liberarReserva((int)$pedido_id);
                    } else {
                        $umbral = Configuracion::get('dashboard_estado_ventas', 'entregado');
                        $umbralMap = [
                            'confirmado' => ['confirmado', 'en_ruta', 'entregado'],
                            'en_ruta'    => ['en_ruta', 'entregado'],
                            'entregado'  => ['entregado'],
                        ];
                        $estadosUmbral = $umbralMap[$umbral] ?? ['entregado'];
                        if (in_array($nuevo_estado, $estadosUmbral, true)) {
                            $ventaModel->confirmarReserva((int)$pedido_id);
                        }
                    }
                } catch (Exception $e) {
                    error_log("kanban cambiar_estado inventario pedido#{$pedido_id}: " . $e->getMessage());
                }
                // Enviar email de confirmación cuando el pedido pasa a "confirmado"
                if ($nuevo_estado === 'confirmado') {
                    try {
                        $pedido_conf = $pedidoModel->getById($pedido_id);
                        if ($pedido_conf) {
                            $email_conf = $pedido_conf['email_factura'] ?? '';
                            $cliente_conf = null;
                            if (empty($email_conf)) {
                                $cliente_conf = $clienteModel->getByTelefono($pedido_conf['telefono'] ?? '');
                                $email_conf = $cliente_conf['email_factura'] ?? '';
                            }
                            if (!empty($email_conf)) {
                                if ($cliente_conf === null) $cliente_conf = $clienteModel->getByTelefono($pedido_conf['telefono'] ?? '');
                                $quiere_notif = (int)($cliente_conf['notif_confirmacion'] ?? 1);
                                if ($quiere_notif) {
                                    $detalle_conf = $pedidoModel->getDetalle($pedido_id);
                                    Mailer::sendConfirmacion($email_conf, $pedido_conf, $detalle_conf);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error al enviar email confirmación pedido #{$pedido_id}: " . $e->getMessage());
                    }
                }
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar']);
            }
            exit;

        case 'confirmar_pago':
            $pedido_id = intval($_POST['pedido_id'] ?? 0);
            if (!$pedido_id) {
                echo json_encode(['success' => false, 'message' => 'Pedido inválido']);
                exit;
            }

            // Fecha retroactiva opcional (solo si el admin la aprobó en el modal)
            $fecha_retro = null;
            if (!empty($_POST['fecha_confirmacion_retroactiva'])) {
                $fr = DateTime::createFromFormat('Y-m-d H:i:s', $_POST['fecha_confirmacion_retroactiva']);
                if (!$fr) $fr = DateTime::createFromFormat('Y-m-d', $_POST['fecha_confirmacion_retroactiva']);
                if ($fr) $fecha_retro = $fr->format('Y-m-d H:i:s');
            }

            $resultado = $pedidoModel->confirmarPago($pedido_id, $_SESSION['admin_id'], $fecha_retro);
            if (!$resultado['success']) {
                echo json_encode(['success' => false, 'message' => $resultado['message'] ?? 'Error al confirmar pago']);
                exit;
            }

            try {
                $pedido_conf = $pedidoModel->getById($pedido_id);
                if ($pedido_conf) {
                    $email_conf = $pedido_conf['email_factura'] ?? '';
                    $cliente_conf = null;
                    if (empty($email_conf)) {
                        $cliente_conf = $clienteModel->getByTelefono($pedido_conf['telefono'] ?? '');
                        $email_conf = $cliente_conf['email_factura'] ?? '';
                    }
                    if (!empty($email_conf)) {
                        if ($cliente_conf === null) $cliente_conf = $clienteModel->getByTelefono($pedido_conf['telefono'] ?? '');
                        $quiere_notif = (int)($cliente_conf['notif_confirmacion'] ?? 1);
                        if ($quiere_notif) {
                            $detalle_conf = $pedidoModel->getDetalle($pedido_id);
                            Mailer::sendConfirmacion($email_conf, $pedido_conf, $detalle_conf);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error al enviar email confirmación pedido #{$pedido_id}: " . $e->getMessage());
            }

            $mensaje = 'Pago confirmado';
            if ($resultado['entrega_directa'] && $resultado['estado'] === 'entregado') {
                $mensaje .= '. Pedido marcado como entregado por entrega directa';
            } elseif ($resultado['factura_pendiente']) {
                $mensaje .= '. Factura pendiente antes de cerrar entrega directa';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'estado' => $resultado['estado']
            ]);
            exit;
            
        case 'subir_comprobante_envio':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            
            if (!isset($_FILES['comprobante_envio']) || $_FILES['comprobante_envio']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No se recibió el comprobante']);
                exit;
            }
            
            $file = $_FILES['comprobante_envio'];
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'El archivo no debe superar 5MB']);
                exit;
            }
            
            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => 'Solo se permiten PDF o imágenes']);
                exit;
            }
            
            $uploadDir = uploads_dir('comprobantes_envio') . '/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'envio_' . $pedido_id . '_' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Actualizar comprobante de envío y cambiar estado a "en_ruta"
                $sql = "UPDATE pedidos SET comprobante_envio = :comprobante, estado = 'en_ruta' WHERE id = :id";
                $stmt = $pedidoModel->db->prepare($sql);
                $stmt->bindParam(':comprobante', $filename);
                $stmt->bindParam(':id', $pedido_id);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Comprobante subido y pedido movido a En Ruta']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar pedido']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al guardar el comprobante']);
            }
            exit;
            
        case 'guardar_num_factura':
            $pedido_id = intval($_POST['pedido_id'] ?? 0);
            $num_factura = trim($_POST['num_factura'] ?? '');

            if (!$pedido_id) {
                echo json_encode(['success' => false, 'message' => 'Pedido inválido']);
                exit;
            }
            if ($num_factura === '') {
                echo json_encode(['success' => false, 'message' => 'El número de factura no puede estar vacío']);
                exit;
            }
            if (strlen($num_factura) > 60) {
                echo json_encode(['success' => false, 'message' => 'El número de factura es demasiado largo (máx. 60 caracteres)']);
                exit;
            }

            $stmt = $pedidoModel->db->prepare("UPDATE pedidos SET num_factura = :nf WHERE id = :id");
            $stmt->bindValue(':nf', $num_factura);
            $stmt->bindValue(':id', $pedido_id);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Número de factura guardado', 'num_factura' => $num_factura]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al guardar en la base de datos']);
            }
            exit;

        case 'guardar_direccion_pedido':
            if (!$puedeEditarDireccionPedido) {
                echo json_encode(['success' => false, 'message' => 'No tienes permiso para editar la direccion del pedido']);
                exit;
            }

            $pedido_id = intval($_POST['pedido_id'] ?? 0);
            if (!$pedido_id || !$pedidoModel->getById($pedido_id)) {
                echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
                exit;
            }

            $datos_envio = [
                'calle' => trim($_POST['calle'] ?? ''),
                'numero' => trim($_POST['numero'] ?? ''),
                'colonia' => trim($_POST['colonia'] ?? ''),
                'cp' => trim($_POST['cp'] ?? ''),
                'estado' => trim($_POST['estado'] ?? ''),
                'ciudad' => trim($_POST['ciudad'] ?? ''),
                'referencias' => trim($_POST['referencias'] ?? ''),
                'quien_recibe' => trim($_POST['quien_recibe'] ?? '')
            ];
            $num_factura = trim($_POST['num_factura'] ?? '');

            $longitudes = [
                'calle' => 160,
                'numero' => 60,
                'colonia' => 120,
                'cp' => 12,
                'estado' => 100,
                'ciudad' => 120,
                'referencias' => 500,
                'quien_recibe' => 160,
                'num_factura' => 60
            ];
            foreach ($longitudes as $campo => $max) {
                $valorCampo = $campo === 'num_factura' ? $num_factura : $datos_envio[$campo];
                if (mb_strlen($valorCampo) > $max) {
                    echo json_encode(['success' => false, 'message' => "El campo {$campo} es demasiado largo"]);
                    exit;
                }
            }

            try {
                $ok = $pedidoModel->actualizarDatosEnvio($pedido_id, $datos_envio, $num_factura);
                if ($ok) {
                    error_log("Pedido #{$pedido_id} datos de entrega/factura actualizados por admin #{$_SESSION['admin_id']}");
                    echo json_encode(['success' => true, 'message' => 'Datos del pedido actualizados']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'No se pudieron actualizar los datos del pedido']);
                }
            } catch (Exception $e) {
                error_log("Error al actualizar datos pedido #{$pedido_id}: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error al actualizar los datos del pedido']);
            }
            exit;

        case 'subir_factura':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            
            // Validar que el pedido requiere factura
            $pedido = $pedidoModel->getById($pedido_id);
            if (!$pedido || !$pedido['requiere_factura']) {
                echo json_encode(['success' => false, 'message' => 'Este pedido no requiere factura']);
                exit;
            }
            
            // Validar archivos
            $errores = [];
            $factura_pdf = null;
            $factura_xml = null;
            
            // Validar PDF
            if (isset($_FILES['factura_pdf']) && $_FILES['factura_pdf']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['factura_pdf'];
                
                if ($file['type'] !== 'application/pdf') {
                    $errores[] = 'El archivo PDF debe ser de tipo PDF';
                }
                
                if ($file['size'] > 5 * 1024 * 1024) {
                    $errores[] = 'El PDF no debe superar 5MB';
                }
                
                if (empty($errores)) {
                    $uploadDir = uploads_dir('facturas') . '/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $filename = 'factura_pdf_' . $pedido_id . '_' . time() . '.pdf';
                    $uploadPath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        $factura_pdf = $filename;
                    } else {
                        $errores[] = 'Error al guardar el PDF';
                    }
                }
            }
            
            // Validar XML
            if (isset($_FILES['factura_xml']) && $_FILES['factura_xml']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['factura_xml'];
                
                // Permitir tanto application/xml como text/xml
                if (!in_array($file['type'], ['application/xml', 'text/xml', 'application/octet-stream'])) {
                    $errores[] = 'El archivo XML debe ser de tipo XML';
                }
                
                if ($file['size'] > 5 * 1024 * 1024) {
                    $errores[] = 'El XML no debe superar 5MB';
                }
                
                if (empty($errores)) {
                    $uploadDir = uploads_dir('facturas') . '/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $filename = 'factura_xml_' . $pedido_id . '_' . time() . '.xml';
                    $uploadPath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        $factura_xml = $filename;
                    } else {
                        $errores[] = 'Error al guardar el XML';
                    }
                }
            }
            
            if (!empty($errores)) {
                echo json_encode(['success' => false, 'message' => implode('. ', $errores)]);
                exit;
            }
            
            if (!$factura_pdf && !$factura_xml) {
                echo json_encode(['success' => false, 'message' => 'Debe subir al menos un archivo (PDF o XML)']);
                exit;
            }
            
            // Actualizar base de datos
            $updates = [];
            $params = [':id' => $pedido_id];
            
            if ($factura_pdf) {
                $updates[] = 'factura_pdf = :factura_pdf';
                $params[':factura_pdf'] = $factura_pdf;
            }
            
            if ($factura_xml) {
                $updates[] = 'factura_xml = :factura_xml';
                $params[':factura_xml'] = $factura_xml;
            }
            
            $sql = "UPDATE pedidos SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $pedidoModel->db->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            if ($stmt->execute()) {
                $pedido_actualizado = $pedidoModel->getById($pedido_id);
                if ($pedido_actualizado
                    && (int)($pedido_actualizado['entrega_directa'] ?? 0) === 1
                    && !empty($pedido_actualizado['fecha_confirmacion_pago'])
                    && (int)$pedido_actualizado['requiere_factura'] === 1
                    && (!empty($pedido_actualizado['factura_pdf']) || !empty($pedido_actualizado['factura_xml']))
                    && $pedido_actualizado['estado'] === 'confirmado') {
                    $pedidoModel->updateEstado($pedido_id, 'entregado');
                }

                // Enviar email con factura adjunta si el pedido tiene email
                try {
                    $email_fact = $pedido['email_factura'] ?? '';
                    $cliente_fact = null;
                    if (empty($email_fact)) {
                        $cliente_fact = $clienteModel->getByTelefono($pedido['telefono'] ?? '');
                        $email_fact = $cliente_fact['email_factura'] ?? '';
                    }
                    if (!empty($email_fact)) {
                        if ($cliente_fact === null) $cliente_fact = $clienteModel->getByTelefono($pedido['telefono'] ?? '');
                        $quiere_notif = (int)($cliente_fact['notif_factura'] ?? 1);
                        if ($quiere_notif) {
                            $pdf_abs = $factura_pdf ? uploads_dir('facturas') . '/' . $factura_pdf : null;
                            $xml_abs = $factura_xml ? uploads_dir('facturas') . '/' . $factura_xml : null;
                            Mailer::sendFactura($email_fact, $pedido, $pdf_abs, $xml_abs);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error al enviar email factura pedido #{$pedido_id}: " . $e->getMessage());
                }

                $mensaje = 'Factura subida correctamente. ';
                if ($factura_pdf && $factura_xml) {
                    $mensaje .= 'PDF y XML guardados.';
                } elseif ($factura_pdf) {
                    $mensaje .= 'Solo PDF guardado.';
                } else {
                    $mensaje .= 'Solo XML guardado.';
                }
                echo json_encode(['success' => true, 'message' => $mensaje]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar la base de datos']);
            }
            exit;
    }
}

// ── Vista activa ──────────────────────────────────────────────────────────
$vista = in_array($_GET['vista'] ?? '', ['tablero', 'lista']) ? $_GET['vista'] : 'tablero';

// Obtener todos los pedidos activos (no entregados de hace más de 7 días)
$fecha_limite = date('Y-m-d', strtotime('-7 days'));

if ($vista === 'tablero') {
    $sql = "SELECT p.*, c.telefono, c.nombre,
                   ar.nombre as representante_nombre_real,
                   rp.codigo as representante_codigo
            FROM pedidos p
            INNER JOIN clientes c ON p.cliente_id = c.id
            LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
            LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
            WHERE p.estado != 'cancelado'
            AND (p.estado != 'entregado' OR p.updated_at >= :fecha_limite)
            ORDER BY
                CASE p.estado
                    WHEN 'por_verificar' THEN 1
                    WHEN 'pendiente' THEN 2
                    WHEN 'confirmado' THEN 3
                    WHEN 'en_ruta' THEN 4
                    WHEN 'entregado' THEN 5
                END,
                p.created_at DESC";
} else {
    // Lista: todos los pedidos sin restricción de fechas
    $sql = "SELECT p.*, c.telefono, c.nombre,
                   ar.nombre as representante_nombre_real,
                   rp.codigo as representante_codigo
            FROM pedidos p
            INNER JOIN clientes c ON p.cliente_id = c.id
            LEFT JOIN representante_perfiles rp ON rp.admin_id = p.representante_admin_id
            LEFT JOIN administradores ar ON ar.id = p.representante_admin_id
            ORDER BY p.created_at DESC";
}

$stmt = $pedidoModel->db->prepare($sql);
if ($vista === 'tablero') {
    $stmt->bindParam(':fecha_limite', $fecha_limite);
}
$stmt->execute();
$todos_pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar pedidos por estado
$pedidos_por_estado = [
    'pendiente' => [],
    'por_verificar' => [],
    'confirmado' => [],
    'en_ruta' => [],
    'entregado' => []
];

foreach ($todos_pedidos as $pedido) {
    if (isset($pedidos_por_estado[$pedido['estado']])) {
        $pedidos_por_estado[$pedido['estado']][] = $pedido;
    }
}

// Estadísticas
$total_pedidos = count($todos_pedidos);
$pendientes = count($pedidos_por_estado['pendiente']);
$por_verificar = count($pedidos_por_estado['por_verificar']);
$confirmados = count($pedidos_por_estado['confirmado']);
$en_ruta = count($pedidos_por_estado['en_ruta']);
$entregados = count($pedidos_por_estado['entregado']);
$web_directos  = count(array_filter($todos_pedidos, fn($p) => ($p['canal'] ?? 'cliente_directo') === 'cliente_directo' && empty($p['representante_admin_id'])));
$tienda_rep    = count(array_filter($todos_pedidos, fn($p) => ($p['canal'] ?? 'cliente_directo') === 'cliente_directo' && !empty($p['representante_admin_id'])));
$rep_qr = count(array_filter($todos_pedidos, fn($p) => ($p['canal'] ?? '') === 'representante_qr'));
$rep_directos = count(array_filter($todos_pedidos, fn($p) => (($p['canal'] ?? '') === 'representante_directo') || ((int)($p['entrega_directa'] ?? 0) === 1)));
$cfdi_pendientes = count(array_filter($todos_pedidos, fn($p) => !empty($p['requiere_factura']) && empty($p['factura_pdf']) && empty($p['factura_xml'])));
$efectivo_pendiente = count(array_filter($todos_pedidos, fn($p) => ($p['estado_liquidacion'] ?? '') === 'pendiente'));
$pago_validar = count($pedidos_por_estado['por_verificar']);

// Obtener el timestamp del pedido más reciente para verificar nuevos pedidos
$ultimo_timestamp = date('Y-m-d H:i:s');
if (!empty($todos_pedidos)) {
    $ultimo_timestamp = $todos_pedidos[0]['created_at']; // Ya está ordenado por created_at DESC
}
?>

<?php include '../includes/header.php'; ?>

<style>
.kanban-column {
    min-height: 500px;
    max-height: calc(100vh - 300px);
}

.kanban-lane {
    --lane-accent: var(--accent);
    background:
        linear-gradient(
            180deg,
            color-mix(in srgb, var(--bg-secondary) 72%, var(--text-primary) 28%) 0%,
            color-mix(in srgb, var(--bg-secondary) 80%, var(--text-primary) 20%) 100%
        );
    border: 1px solid color-mix(in srgb, var(--border-color) 72%, var(--text-primary) 28%);
    border-top: 3px solid var(--lane-accent);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.18),
        inset 0 12px 24px rgba(15, 23, 42, 0.04),
        var(--shadow-sm, 0 1px 3px rgba(15, 23, 42, 0.08));
}

.kanban-lane[data-estado="pendiente"] { --lane-accent: #d97706; }
.kanban-lane[data-estado="por_verificar"] { --lane-accent: #ea580c; }
.kanban-lane[data-estado="confirmado"] { --lane-accent: #2563eb; }
.kanban-lane[data-estado="en_ruta"] { --lane-accent: #7c3aed; }
.kanban-lane[data-estado="entregado"] { --lane-accent: #059669; }

.kanban-status-dot {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 999px;
    background: var(--lane-accent);
    flex: 0 0 auto;
}

.kanban-count {
    background: var(--bg-secondary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.kanban-card {
    cursor: move;
    background: var(--bg-card) !important;
    border: 1px solid color-mix(in srgb, var(--border-color) 78%, var(--text-muted));
    border-left: 3px solid color-mix(in srgb, var(--accent) 58%, var(--border-color));
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.10);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.kanban-card:hover {
    transform: translateY(-2px);
    border-color: color-mix(in srgb, var(--accent) 42%, var(--border-color));
    border-left-color: var(--accent);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
}

.kb-stat {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(15, 23, 42, 0.08));
}

.kb-filter-chip {
    background: var(--bg-secondary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.kb-filter-chip:hover {
    color: var(--text-primary);
    border-color: color-mix(in srgb, var(--accent) 30%, var(--border-color));
}

.kb-filter-chip.is-active {
    background: var(--text-primary);
    color: var(--bg-card);
    border-color: var(--text-primary);
}

.kb-filter-chip[data-priority="high"] {
    border-color: color-mix(in srgb, #ea580c 35%, var(--border-color));
}

.kb-empty {
    border: 1px dashed var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-muted);
}

.kanban-scroll {
    overflow-y: auto;
    overflow-x: hidden;
}

.kanban-scroll::-webkit-scrollbar {
    width: 6px;
}

.kanban-scroll::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 10px;
}

.kanban-scroll::-webkit-scrollbar-thumb {
    background: color-mix(in srgb, var(--accent) 45%, var(--border-color));
    border-radius: 10px;
}

.kanban-scroll::-webkit-scrollbar-thumb:hover {
    background: var(--accent);
}
.vista-tab {
    display: inline-block;
    padding: 7px 16px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 700;
    border: 1px solid transparent;
    text-decoration: none;
    transition: background .15s, color .15s;
    color: var(--text-primary, #0f172a);
    background: transparent;
}
.vista-tab.active {
    background: linear-gradient(to right, var(--tw-neu-800, #1e293b), var(--tw-neu-900, #0f172a));
    color: #fff;
}
body.theme-dark .vista-tab.active {
    background: linear-gradient(to right, var(--tw-neu-100, #21262D), var(--tw-neu-50, #1C2330));
    color: var(--text-primary);
}

/* ── Vista Lista ─────────────────────────────────────────────────────────── */
.s-pendiente     { --sc:#b45309; --sb:#fef3c7; --sd:#d97706; }
.s-por_verificar { --sc:#c2410c; --sb:#ffedd5; --sd:#ea580c; }
.s-confirmado    { --sc:#1d4ed8; --sb:#dbeafe; --sd:#2563eb; }
.s-en_ruta       { --sc:#6d28d9; --sb:#ede9fe; --sd:#7c3aed; }
.s-entregado     { --sc:#15803d; --sb:#dcfce7; --sd:#16a34a; }
.s-cancelado     { --sc:#b91c1c; --sb:#fee2e2; --sd:#dc2626; }

/* dark-mode: state select stays readable */
body.theme-dark .s-pendiente     { --sc:#fbbf24; --sb:#292116; --sd:#d97706; }
body.theme-dark .s-por_verificar { --sc:#fb923c; --sb:#271910; --sd:#ea580c; }
body.theme-dark .s-confirmado    { --sc:#60a5fa; --sb:#101d38; --sd:#3b82f6; }
body.theme-dark .s-en_ruta       { --sc:#a78bfa; --sb:#1a1238; --sd:#7c3aed; }
body.theme-dark .s-entregado     { --sc:#4ade80; --sb:#0d2318; --sd:#16a34a; }
body.theme-dark .s-cancelado     { --sc:#f87171; --sb:#2a1010; --sd:#dc2626; }

.pdx { width: 100%; padding: 0; }
.pdx-stats {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 12px; margin-bottom: 20px;
}
@media (max-width: 900px) { .pdx-stats { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 520px)  { .pdx-stats { grid-template-columns: repeat(2, 1fr); } }
.pdx-stat {
  background: var(--bg-card); border: 1px solid var(--border-card);
  border-radius: 12px; padding: 14px;
  box-shadow: 0 1px 3px rgba(15,23,42,.08);
}
.pdx-stat-n {
  font-size: 1.5rem; font-weight: 700; line-height: 1;
  color: var(--sd, var(--text-primary)); letter-spacing: -.03em;
}
.pdx-stat-l { font-size: .8rem; font-weight: 500; color: var(--text-secondary); margin-top: 5px; }

.pdx-filters {
  background: var(--bg-card); border: 1px solid var(--border-card);
  border-radius: 12px; padding: 14px; margin-bottom: 20px;
}
.pdx-frow { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.pdx-frow + .pdx-frow { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--bg-input); }
.pdx-flabel { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); min-width: 46px; flex-shrink: 0; }
.pdx-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 11px; border-radius: 16px; font-size: 12px; font-weight: 600;
  cursor: pointer; border: 1px solid var(--border-card);
  background: var(--bg-input); color: var(--text-secondary);
  transition: all .12s; white-space: nowrap; user-select: none;
}
.pdx-pill:hover         { filter: brightness(.92); }
.pdx-pill.p-active      { background: var(--text-primary); color: #fff; border-color: var(--text-primary); }
.pdx-pill.p-active-teal { background: var(--accent); color: #fff; border-color: var(--accent); }

.pdx-list-wrap { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 12px; overflow: hidden; }
.pdx-list-head {
  display: grid;
  grid-template-columns: 20px 88px 120px minmax(150px,1fr) 220px 120px 200px 160px 90px;
  gap: 8px; align-items: center; padding: 10px 14px;
  background: linear-gradient(to right, var(--tw-neu-800,#334155), var(--tw-neu-900,#1e293b));
  color: #fff; font-size: 11px; font-weight: 700;
}
@media (max-width: 980px) { .pdx-list-head { display: none; } }
@media (max-width: 1100px) { .pdx-list-head span:nth-child(6) { display: none; } }
body.theme-dark .pdx-list-head {
  background: linear-gradient(to right, var(--tw-neu-100, #21262D), var(--tw-neu-50, #1C2330));
  color: var(--text-primary);
}
.pdx-list { display: flex; flex-direction: column; gap: 0; }
.pdx-row {
  background: var(--bg-card); border-top: 1px solid var(--border-card);
  border-left: 3px solid var(--sd, var(--border-card));
  overflow: hidden; transition: background .15s;
}
.pdx-row:first-child { border-top: none; }
.pdx-row:hover   { background: var(--bg-card-hover, var(--bg-card)); }
.pdx-row.is-open { background: var(--bg-card-hover, var(--bg-card)); }
.pdx-sum {
  display: grid;
  grid-template-columns: 20px 88px 120px minmax(150px,1fr) 220px 120px 200px 160px 90px;
  gap: 8px; align-items: center;
  padding: 10px 14px; cursor: pointer; user-select: none;
}
.pdx-sum-factura { min-width: 0; overflow: hidden; font-size: 12px; font-weight: 600; color: var(--text-secondary); line-height: 1.4; }
.pdx-sum-factura small { display: block; font-size: 10px; font-weight: 400; color: var(--text-muted); }
.pdx-sum-metodo { overflow: hidden; }
@media (max-width: 1100px) { .pdx-sum-metodo { display: none; } }
.pdx-sum-chevron {
  color: var(--text-muted); flex-shrink: 0; transition: transform .22s; width: 16px; height: 16px;
}
.pdx-row.is-open .pdx-sum-chevron { transform: rotate(180deg); color: var(--text-secondary); }
.pdx-sum-id { font-size: 12px; font-weight: 600; color: var(--text-secondary); line-height: 1.4; overflow: hidden; }
.pdx-sum-id small { display: block; font-size: 10px; font-weight: 400; color: var(--text-muted); }
.pdx-sum-client { min-width: 0; overflow: hidden; }
.pdx-sum-client-name { font-size: 13px; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pdx-sum-client-phone { font-size: 11px; color: var(--text-muted); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pdx-sum-tags { display: flex; gap: 3px; flex-wrap: nowrap; overflow: hidden; align-items: center; }
@media (max-width: 980px) { .pdx-sum-tags { display: none; } }
.pdx-tag { display: inline-flex; align-items: center; font-size: 10px; font-weight: 700; letter-spacing: .02em; padding: 2px 7px; border-radius: 4px; white-space: nowrap; }
.pdx-sum-meta { text-align: right; overflow: hidden; padding-right: 14px; border-right: 1px solid var(--border-card); }
.pdx-sum-total { font-size: 13px; font-weight: 600; color: var(--text-primary); line-height: 1; }
.pdx-sum-total .cur { font-size: 10px; font-weight: 500; color: var(--text-secondary); margin-right: 1px; }
.pdx-sum-prods { font-size: 10px; color: var(--text-muted); margin-top: 2px; font-weight: 500; }
.pdx-status-sel {
  appearance: none; width: 100%;
  border: 1px solid var(--border-card); background: var(--sb, var(--bg-input));
  color: var(--sc, var(--text-secondary)); border-radius: 7px; padding: 6px 24px 6px 9px;
  font-size: 11.5px; font-weight: 700; cursor: pointer; padding-left: 6px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23aaa' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 7px center;
}
@media (max-width: 700px) { .pdx-status-sel { display: none; } }
.pdx-actions { display: flex; gap: 5px; align-items: center; flex-shrink: 0; }
.pdx-act {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 6px 10px; border-radius: 7px; font-size: 12px; font-weight: 600;
  border: none; cursor: pointer; text-decoration: none; transition: background .12s;
  position: relative; white-space: nowrap;
}
.pdx-act-chat       { background: var(--bg-input); color: var(--accent); border: 1px solid var(--border-card); }
.pdx-act-chat:hover { filter: brightness(.93); }
.pdx-act-approve    { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.pdx-act-approve:hover { background: #dcfce7; }
body.theme-dark .pdx-act-approve       { background: #0d2318; color: #4ade80; border-color: #1a4028; }
body.theme-dark .pdx-act-approve:hover { background: #122b1f; }
.pdx-badge {
  position: absolute; top: -6px; right: -6px;
  background: #dc2626; color: #fff; font-size: 9px; font-weight: 800;
  border-radius: 50%; min-width: 15px; height: 15px;
  display: flex; align-items: center; justify-content: center; border: 1.5px solid var(--bg-card);
}
.pdx-detail { display: none; border-top: 1px solid var(--border-card); background: var(--bg-input); padding: 18px 16px 20px 18px; }
.pdx-row.is-open .pdx-detail { display: block; animation: pdxIn .18s ease; }
@keyframes pdxIn { from { opacity:0; transform: translateY(-3px); } to { opacity:1; transform:none; } }
.pdx-prod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; margin-bottom: 14px; }
.pdx-prod-grid .hidden { display: none !important; }
.pdx-prod { display: flex; align-items: center; gap: 10px; background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 8px; padding: 9px 11px; }
.pdx-prod-img { width: 44px; height: 44px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--border-card); }
.pdx-prod-ph  { width: 44px; height: 44px; border-radius: 6px; background: var(--border-card); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pdx-prod-name { font-size: 12.5px; font-weight: 600; color: var(--text-primary); line-height: 1.3; }
.pdx-prod-qty  { font-size: 11px; color: var(--text-secondary); }
.pdx-prod-sub  { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-top: 2px; }
.pdx-extras { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px; }
.pdx-info { flex: 1; min-width: 172px; background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 8px; padding: 11px 13px; }
.pdx-info-ttl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 5px; }
.pdx-empty { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 12px; padding: 56px 28px; text-align: center; }
.pdx-empty-icon  { font-size: 40px; margin-bottom: 12px; }
.pdx-empty-title { font-size: 17px; font-weight: 700; color: var(--text-primary); }
.pdx-empty-sub   { color: var(--text-secondary); font-size: 13px; margin-top: 4px; }
</style>

<div class="w-full px-4 py-8">
    
    <!-- Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center flex-wrap gap-4" style="padding-right:3.5rem">
            <div>
                <h1 class="text-3xl font-bold mb-2" style="color:var(--text-primary)">Pedidos</h1>
                <p style="color:var(--text-secondary)"><?= $vista === 'lista' ? 'Vista Lista · todos los pedidos' : 'Vista Tablero · estados finales últimos 7 días' ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex gap-1 p-1 rounded-xl" style="background:var(--bg-input);border:1px solid var(--border-card)">
                    <a href="?vista=tablero" class="vista-tab <?= $vista === 'tablero' ? 'active' : '' ?>">⊞ Tablero</a>
                    <a href="?vista=lista"   class="vista-tab <?= $vista === 'lista'   ? 'active' : '' ?>">Lista</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($vista === 'tablero'): ?>
    <!-- Estadísticas Rápidas -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="kb-stat rounded-xl p-3 text-center">
            <p class="text-2xl font-bold text-yellow-700"><?= $pendientes ?></p>
            <p class="text-xs text-slate-600">Pendientes</p>
        </div>
        <div class="kb-stat rounded-xl p-3 text-center">
            <p class="text-2xl font-bold text-orange-700"><?= $por_verificar ?></p>
            <p class="text-xs text-slate-600">Por Verificar</p>
        </div>
        <div class="kb-stat rounded-xl p-3 text-center">
            <p class="text-2xl font-bold text-blue-700"><?= $confirmados ?></p>
            <p class="text-xs text-slate-600">Confirmados</p>
        </div>
        <div class="kb-stat rounded-xl p-3 text-center">
            <p class="text-2xl font-bold text-purple-700"><?= $en_ruta ?></p>
            <p class="text-xs text-slate-600">En Ruta</p>
        </div>
        <div class="kb-stat rounded-xl p-3 text-center">
            <p class="text-2xl font-bold text-green-700"><?= $entregados ?></p>
            <p class="text-xs text-slate-600">Entregados (7d)</p>
        </div>
    </div>

    <!-- Filtros Operativos -->
    <div class="card rounded-xl p-4 mb-6">
        <div class="mb-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Filtros operativos</h2>
                <p class="text-xs text-slate-500">
                    <span id="kanbanFiltroConteo"><?= $total_pedidos ?></span> pedidos visibles
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <button data-filtro-operacion="todos" onclick="filtrarKanbanOperacion('todos')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Todos (<?= $total_pedidos ?>)
            </button>
            <button data-filtro-operacion="cliente_directo" onclick="filtrarKanbanOperacion('cliente_directo')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Web (<?= $web_directos ?>)
            </button>
            <button data-filtro-operacion="tienda_rep" onclick="filtrarKanbanOperacion('tienda_rep')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Tienda rep (<?= $tienda_rep ?>)
            </button>
            <button data-filtro-operacion="representante_qr" onclick="filtrarKanbanOperacion('representante_qr')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                QR rep (<?= $rep_qr ?>)
            </button>
            <button data-filtro-operacion="representante_directo" onclick="filtrarKanbanOperacion('representante_directo')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Entrega directa (<?= $rep_directos ?>)
            </button>
            <button data-filtro-operacion="pago_validar" data-priority="high" onclick="filtrarKanbanOperacion('pago_validar')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Pago por verificar (<?= $pago_validar ?>)
            </button>
            <button data-filtro-operacion="efectivo_pendiente" data-priority="high" onclick="filtrarKanbanOperacion('efectivo_pendiente')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Efectivo pendiente (<?= $efectivo_pendiente ?>)
            </button>
            <button data-filtro-operacion="cfdi_pendiente" data-priority="high" onclick="filtrarKanbanOperacion('cfdi_pendiente')" class="filtro-operacion kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                CFDI pendiente (<?= $cfdi_pendientes ?>)
            </button>
            <button onclick="filtrarKanbanOperacion('todos')" class="kb-filter-chip px-3 py-2 rounded-lg text-xs font-bold">
                Limpiar
            </button>
        </div>
    </div>

    <!-- Tablero Kanban -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        
        <!-- Columna: Pendiente -->
        <div class="kanban-lane rounded-xl p-4" data-estado="pendiente">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <span class="kanban-status-dot"></span>
                    Pendiente
                </h2>
                <span class="kanban-count text-sm font-semibold px-2 py-1 rounded-full"><?= $pendientes ?></span>
            </div>
            <div class="kanban-scroll kanban-column kanban-cards space-y-3" data-estado="pendiente">
                <?php foreach ($pedidos_por_estado['pendiente'] as $pedido): ?>
                    <?php include 'kanban-card.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($pedidos_por_estado['pendiente'])): ?>
                    <p class="kb-empty text-center text-sm py-8 rounded-xl">Sin pedidos</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna: Por Verificar -->
        <div class="kanban-lane rounded-xl p-4" data-estado="por_verificar">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <span class="kanban-status-dot"></span>
                    Por Verificar
                </h2>
                <span class="kanban-count text-sm font-semibold px-2 py-1 rounded-full"><?= $por_verificar ?></span>
            </div>
            <div class="kanban-scroll kanban-column kanban-cards space-y-3" data-estado="por_verificar">
                <?php foreach ($pedidos_por_estado['por_verificar'] as $pedido): ?>
                    <?php include 'kanban-card.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($pedidos_por_estado['por_verificar'])): ?>
                    <p class="kb-empty text-center text-sm py-8 rounded-xl">Sin pedidos</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna: Confirmado -->
        <div class="kanban-lane rounded-xl p-4" data-estado="confirmado">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <span class="kanban-status-dot"></span>
                    Confirmado
                </h2>
                <span class="kanban-count text-sm font-semibold px-2 py-1 rounded-full"><?= $confirmados ?></span>
            </div>
            <div class="kanban-scroll kanban-column kanban-cards space-y-3" data-estado="confirmado">
                <?php foreach ($pedidos_por_estado['confirmado'] as $pedido): ?>
                    <?php include 'kanban-card.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($pedidos_por_estado['confirmado'])): ?>
                    <p class="kb-empty text-center text-sm py-8 rounded-xl">Sin pedidos</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna: En Ruta -->
        <div class="kanban-lane rounded-xl p-4" data-estado="en_ruta">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <span class="kanban-status-dot"></span>
                    En Ruta
                </h2>
                <span class="kanban-count text-sm font-semibold px-2 py-1 rounded-full"><?= $en_ruta ?></span>
            </div>
            <div class="kanban-scroll kanban-column kanban-cards space-y-3" data-estado="en_ruta">
                <?php foreach ($pedidos_por_estado['en_ruta'] as $pedido): ?>
                    <?php include 'kanban-card.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($pedidos_por_estado['en_ruta'])): ?>
                    <p class="kb-empty text-center text-sm py-8 rounded-xl">Sin pedidos</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna: Entregado -->
        <div class="kanban-lane rounded-xl p-4" data-estado="entregado">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <span class="kanban-status-dot"></span>
                    Entregado
                </h2>
                <span class="kanban-count text-sm font-semibold px-2 py-1 rounded-full"><?= $entregados ?></span>
            </div>
            <div class="kanban-scroll kanban-column kanban-cards space-y-3" data-estado="entregado">
                <?php foreach ($pedidos_por_estado['entregado'] as $pedido): ?>
                    <?php include 'kanban-card.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($pedidos_por_estado['entregado'])): ?>
                    <p class="kb-empty text-center text-sm py-8 rounded-xl">Sin pedidos</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <p class="text-xs text-slate-500 text-center mt-6">
        Los pedidos entregados se muestran solo los últimos 7 días.
    </p>
    <?php endif; /* tablero */ ?>

    <?php if ($vista === 'lista'): ?>
    <?php
        // Contadores para la vista lista
        $pdx_estados_def = [
            'pendiente'   => ['emoji'=>'','nombre'=>'Pendiente'],
            'por_verificar'=>['emoji'=>'','nombre'=>'Por Verificar'],
            'confirmado'  => ['emoji'=>'','nombre'=>'Confirmado'],
            'en_ruta'     => ['emoji'=>'','nombre'=>'En Ruta'],
            'entregado'   => ['emoji'=>'','nombre'=>'Entregado'],
            'cancelado'   => ['emoji'=>'','nombre'=>'Cancelado'],
        ];
        $pdx_total    = count($todos_pedidos);
        $pdx_pend     = count(array_filter($todos_pedidos, fn($p)=>$p['estado']==='pendiente'));
        $pdx_verif    = count(array_filter($todos_pedidos, fn($p)=>$p['estado']==='por_verificar'));
        $pdx_conf     = count(array_filter($todos_pedidos, fn($p)=>$p['estado']==='confirmado'));
        $pdx_ruta     = count(array_filter($todos_pedidos, fn($p)=>$p['estado']==='en_ruta'));
        $pdx_entregado= count(array_filter($todos_pedidos, fn($p)=>$p['estado']==='entregado'));
        $pdx_contadores = [];
        foreach ($todos_pedidos as $p) $pdx_contadores[$p['estado']] = ($pdx_contadores[$p['estado']] ?? 0) + 1;
        $pdx_web      = count(array_filter($todos_pedidos, fn($p)=>($p['canal']??'cliente_directo')==='cliente_directo' && empty($p['representante_admin_id'])));
        $pdx_qr       = count(array_filter($todos_pedidos, fn($p)=>($p['canal']??'')==='representante_qr'));
        $pdx_directa  = count(array_filter($todos_pedidos, fn($p)=>(($p['canal']??'')==='representante_directo')||((int)($p['entrega_directa']??0)===1)));
        $pdx_cfdi     = count(array_filter($todos_pedidos, fn($p)=>!empty($p['requiere_factura'])&&empty($p['factura_pdf'])&&empty($p['factura_xml'])));
        $pdx_efectivo = count(array_filter($todos_pedidos, fn($p)=>($p['estado_liquidacion']??'')==='pendiente'));
    ?>
    <div class="pdx">
        <!-- Stats -->
        <div class="pdx-stats">
            <div class="pdx-stat" style="--sd:var(--brand)"><div class="pdx-stat-n"><?= $pdx_total ?></div><div class="pdx-stat-l">Total</div></div>
            <div class="pdx-stat s-pendiente"><div class="pdx-stat-n"><?= $pdx_pend ?></div><div class="pdx-stat-l">Pendientes</div></div>
            <div class="pdx-stat s-por_verificar"><div class="pdx-stat-n"><?= $pdx_verif ?></div><div class="pdx-stat-l">Por Verificar</div></div>
            <div class="pdx-stat s-confirmado"><div class="pdx-stat-n"><?= $pdx_conf ?></div><div class="pdx-stat-l">Confirmados</div></div>
            <div class="pdx-stat s-en_ruta"><div class="pdx-stat-n"><?= $pdx_ruta ?></div><div class="pdx-stat-l">En Ruta</div></div>
            <div class="pdx-stat s-entregado"><div class="pdx-stat-n"><?= $pdx_entregado ?></div><div class="pdx-stat-l">Entregados</div></div>
        </div>

        <?php
        // Lista única de representantes en los pedidos mostrados
        $pdx_reps = [];
        foreach ($todos_pedidos as $_p) {
            $rid  = $_p['representante_admin_id'] ?? null;
            $rnom = $_p['representante_nombre_real'] ?? null;
            if ($rid && $rnom && !isset($pdx_reps[$rid])) $pdx_reps[$rid] = $rnom;
        }
        ?>
        <!-- Filtros -->
        <div class="pdx-filters">
            <div class="pdx-frow" style="gap:8px">
                <span class="pdx-flabel">Buscar</span>
                <input type="search" id="pdxBuscar" placeholder="# pedido o nombre cliente…"
                       oninput="pdxApply()"
                       style="flex:1;max-width:280px;padding:6px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;outline:none">
                <?php if (!empty($pdx_reps)): ?>
                <select id="pdxRepFiltro" onchange="pdxApply()"
                        style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;color:#374151;background:#fff">
                    <option value="">Todos los representantes</option>
                    <?php foreach ($pdx_reps as $rid => $rnom): ?>
                    <option value="<?= $rid ?>"><?= htmlspecialchars($rnom) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="pdx-frow">
                <span class="pdx-flabel">Canal</span>
                <button data-op="todos"                 onclick="pdxOp('todos')"                 class="pdx-pill p-active">Todos (<?= $pdx_total ?>)</button>
                <button data-op="cliente_directo"       onclick="pdxOp('cliente_directo')"       class="pdx-pill">Web (<?= $pdx_web ?>)</button>
                <button data-op="representante_qr"      onclick="pdxOp('representante_qr')"      class="pdx-pill">QR Rep (<?= $pdx_qr ?>)</button>
                <button data-op="representante_directo" onclick="pdxOp('representante_directo')" class="pdx-pill" style="border-color:#a7f3d0;background:#f0fdf4;color:#15803d">Directa (<?= $pdx_directa ?>)</button>
                <button data-op="efectivo_pendiente"    onclick="pdxOp('efectivo_pendiente')"    class="pdx-pill" style="border-color:#fde68a;background:#fffbeb;color:#92400e">Efectivo (<?= $pdx_efectivo ?>)</button>
                <button data-op="cfdi_pendiente"        onclick="pdxOp('cfdi_pendiente')"        class="pdx-pill" style="border-color:#c4b5fd;background:#f5f3ff;color:#5b21b6">CFDI (<?= $pdx_cfdi ?>)</button>
            </div>
            <div class="pdx-frow">
                <span class="pdx-flabel">Estado</span>
                <button data-st="todos" onclick="pdxSt('todos')" class="pdx-pill p-active-teal">Todos (<?= $pdx_total ?>)</button>
                <?php foreach ($pdx_estados_def as $sk => $sv): ?>
                    <?php if (!empty($pdx_contadores[$sk])): ?>
                    <button data-st="<?= $sk ?>" onclick="pdxSt('<?= $sk ?>')" class="pdx-pill s-<?= $sk ?>" style="border-color:var(--sc);background:var(--sb);color:var(--sc)">
                        <?= $sv['emoji'] ?> <?= $sv['nombre'] ?> (<?= $pdx_contadores[$sk] ?>)
                    </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Lista -->
        <?php if (empty($todos_pedidos)): ?>
        <div class="pdx-empty"><div class="pdx-empty-icon"></div><div class="pdx-empty-title">Sin pedidos</div></div>
        <?php else: ?>
        <div class="pdx-list-wrap">
            <div class="pdx-list-head">
                <span></span><span>Pedido</span><span>No. Factura</span><span>Cliente</span><span>Canal</span>
                <span>Método pago</span>
                <span style="text-align:right;padding-right:14px;border-right:1px solid rgba(255,255,255,.18)">Total</span><span style="padding-left:6px">Estado</span><span style="text-align:center">Acciones</span>
            </div>
            <div class="pdx-list" id="pdxList">
            <?php foreach ($todos_pedidos as $pedido):
                $detalle_pdx   = $pedidoModel->getDetalleAgrupado($pedido['id']);
                $es_directa    = (($pedido['canal']??'')==='representante_directo') || ((int)($pedido['entrega_directa']??0)===1);
                $fac_pend      = !empty($pedido['requiere_factura']) && empty($pedido['factura_pdf']) && empty($pedido['factura_xml']);
                $msgs_noleidos = $mensajeModel->contarNoLeidosAdmin($pedido['id']);
                $n_prods       = count(array_filter($detalle_pdx, fn($i) => $i['tipo'] === 'producto'))
                               + count(array_filter($detalle_pdx, fn($i) => $i['tipo'] === 'kit'));
                $direccionPedidoJson = json_encode([
                    'pedido_id' => (int)$pedido['id'],
                    'calle' => $pedido['calle'] ?? '',
                    'numero' => $pedido['numero'] ?? '',
                    'colonia' => $pedido['colonia'] ?? '',
                    'ciudad' => $pedido['ciudad'] ?? '',
                    'estado' => $pedido['estado_envio'] ?? '',
                    'cp' => $pedido['cp_envio'] ?? '',
                    'referencias' => $pedido['referencias'] ?? '',
                    'quien_recibe' => $pedido['quien_recibe'] ?? '',
                    'num_factura' => $pedido['num_factura'] ?? ''
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            ?>
            <div class="pdx-row s-<?= $pedido['estado'] ?>"
                 data-estado="<?= $pedido['estado'] ?>"
                 data-pedido-id="<?= $pedido['id'] ?>"
                 data-canal="<?= htmlspecialchars($pedido['canal']??'cliente_directo') ?>"
                 data-entrega-directa="<?= $es_directa?'1':'0' ?>"
                 data-cfdi-pendiente="<?= $fac_pend?'1':'0' ?>"
                 data-liquidacion="<?= htmlspecialchars($pedido['estado_liquidacion']??'no_aplica') ?>"
                 data-rep-id="<?= $pedido['representante_admin_id'] ?? '' ?>"
                 data-search-text="<?= htmlspecialchars(strtolower($pedido['id'].' '.$pedido['nombre'].' '.$pedido['telefono'])) ?>">

                <div class="pdx-sum" onclick="pdxToggle(this.closest('.pdx-row'))">
                    <svg class="pdx-sum-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    <div class="pdx-sum-id">
                        #<?= str_pad($pedido['id'],4,'0',STR_PAD_LEFT) ?>
                        <small><?= date('d/m H:i', strtotime($pedido['created_at'])) ?></small>
                    </div>
                    <div class="pdx-sum-factura">
                        <?= !empty($pedido['num_factura']) ? htmlspecialchars($pedido['num_factura']) : '—' ?>
                        <small>No. Factura</small>
                    </div>
                    <div class="pdx-sum-client">
                        <div class="pdx-sum-client-name"><?= htmlspecialchars($pedido['nombre']?:'—') ?></div>
                        <div class="pdx-sum-client-phone"><?= htmlspecialchars($pedido['telefono']) ?></div>
                    </div>
                    <div class="pdx-sum-tags">
                        <?php if ($es_directa): ?>
                            <span class="pdx-tag" style="background:#f0fdf4;color:#15803d">↦ Directa</span>
                        <?php elseif (($pedido['canal']??'')==='representante_qr'): ?>
                            <span class="pdx-tag" style="background:#f1f5f9;color:#475569">QR</span>
                        <?php elseif (($pedido['canal']??'cliente_directo')==='cliente_directo' && !empty($pedido['representante_admin_id'])): ?>
                            <span class="pdx-tag" style="background:#e0f2fe;color:#0369a1">Tienda</span>
                        <?php else: ?>
                            <span class="pdx-tag" style="background:#eff6ff;color:#1d4ed8">Web</span>
                        <?php endif; ?>
                        <?php if (!empty($pedido['representante_nombre_real'])): ?>
                            <span class="pdx-tag" style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0"><?= htmlspecialchars(mb_substr($pedido['representante_nombre_real'],0,13)) ?></span>
                        <?php endif; ?>

                        <?php if ($fac_pend): ?>
                            <span class="pdx-tag" style="background:#f5f3ff;color:#5b21b6">CFDI</span>
                        <?php endif; ?>
                    </div>
                    <div class="pdx-sum-metodo">
                        <?php
                        $mp = $pedido['metodo_pago'] ?? '';
                        [$mp_label,$mp_bg,$mp_color] = match($mp) {
                            'transferencia' => ['Transferencia','#eff6ff','#1d4ed8'],
                            'tienda'        => ['Tienda','#f0fdf4','#15803d'],
                            'tarjeta'       => ['Tarjeta','#f0fdf4','#15803d'],
                            'liga_pago'     => ['Liga pago','#faf5ff','#7c3aed'],
                            'paypal'        => ['PayPal','#eff6ff','#1d4ed8'],
                            'oxxo'          => ['OXXO','#fef9c3','#a16207'],
                            'mercado_pago'  => ['Mercado Pago','#e0f2fe','#0369a1'],
                            'ecartpay'      => ['EcartPay','#f0fdf4','#15803d'],
                            'efectivo'      => ['Efectivo','#fffbeb','#92400e'],
                            default         => ['—','#f8fafc','#94a3b8'],
                        };
                        ?>
                        <span class="pdx-tag" style="background:<?= $mp_bg ?>;color:<?= $mp_color ?>"><?= $mp_label ?></span>
                    </div>
                    <div class="pdx-sum-meta">
                        <div class="pdx-sum-total"><span class="cur">$</span><?= number_format($pedido['total'],2) ?></div>
                        <div class="pdx-sum-prods"><?= $n_prods ?> prod<?= $n_prods!=1?'s':'' ?></div>
                    </div>
                    <div onclick="event.stopPropagation()">
                        <select class="pdx-status-sel s-<?= $pedido['estado'] ?>" onchange="pdxCambiarEstado(<?= $pedido['id'] ?>, this.value)">
                            <?php foreach ($pdx_estados_def as $key=>$eo): ?>
                                <option value="<?= $key ?>" <?= $pedido['estado']===$key?'selected':'' ?>><?= $eo['emoji'] ?> <?= $eo['nombre'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pdx-actions" onclick="event.stopPropagation()">
                        <a href="chat-admin.php?pedido_id=<?= $pedido['id'] ?>&return=kanban?vista=lista" class="pdx-act pdx-act-chat" title="Chat">
                            <?php if ($msgs_noleidos>0): ?><span class="pdx-badge"><?= $msgs_noleidos ?></span><?php endif; ?>
                        </a>
                        <?php if ($pedido['estado']==='por_verificar' && !empty($pedido['comprobante_pago'])): ?>
                            <button onclick="pdxAprobar(<?= $pedido['id'] ?>)" class="pdx-act pdx-act-approve" title="Aprobar pago"></button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pdx-detail">
                    <div class="pdx-prod-grid">
                        <?php foreach ($detalle_pdx as $item): ?>
                        <?php if ($item['tipo'] === 'kit'): ?>
                        <!-- Kit colapsable -->
                        <div class="pdx-prod pdx-kit-row" style="grid-column:1/-1;cursor:pointer;background:#f8faff;border:1px solid #dde3f8;border-radius:10px;padding:10px 12px"
                             onclick="this.nextElementSibling.classList.toggle('hidden')">
                            <?php if ($item['imagen']): ?>
                                <img src="<?= uploads_url('kits') ?>/<?= htmlspecialchars($item['imagen']) ?>" alt="" class="pdx-prod-img" style="border-radius:8px">
                            <?php else: ?>
                                <div class="pdx-prod-ph" style="background:#e0e7ff"><span style="font-size:18px"></span></div>
                            <?php endif; ?>
                            <div style="min-width:0;flex:1">
                                <div class="pdx-prod-name" style="color:#3730a3;font-weight:700">
                                    Kit: <?= htmlspecialchars($item['nombre']) ?>
                                </div>
                                <div class="pdx-prod-qty"><?= $item['cantidad'] ?> kit<?= $item['cantidad']!=1?'s':'' ?> &nbsp;·&nbsp;
                                    <?php
                                        $resumen_uds = [];
                                        foreach ($item['productos'] as $p) {
                                            $resumen_uds[] = $p['cantidad'] . ' ' . htmlspecialchars($p['producto']);
                                        }
                                        echo implode(', ', $resumen_uds);
                                    ?>
                                </div>
                                <div class="pdx-prod-sub" style="color:#3730a3">$<?= number_format($item['subtotal'],2) ?></div>
                            </div>
                            <span style="color:#6366f1;font-size:12px;white-space:nowrap">▼ ver</span>
                        </div>
                        <!-- Productos del kit (colapsados por defecto) -->
                        <div class="hidden" style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;padding:4px 8px 8px 28px">
                            <?php foreach ($item['productos'] as $prod): ?>
                            <div class="pdx-prod" style="background:#fff;border:1px solid #e2e8f0">
                                <?php if ($prod['imagen']): ?>
                                    <img src="<?= uploads_url('productos') ?>/<?= htmlspecialchars($prod['imagen']) ?>" alt="" class="pdx-prod-img">
                                <?php else: ?>
                                    <div class="pdx-prod-ph"><svg width="18" height="18" fill="none" stroke="#c4c4c4" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
                                <?php endif; ?>
                                <div style="min-width:0">
                                    <div class="pdx-prod-name"><?= htmlspecialchars($prod['producto']) ?></div>
                                    <div class="pdx-prod-qty"><?= $prod['cantidad'] ?> × $<?= number_format($prod['precio_unitario'],2) ?></div>
                                    <div class="pdx-prod-sub">$<?= number_format($prod['subtotal'],2) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <!-- Producto suelto -->
                        <div class="pdx-prod">
                            <?php if ($item['imagen']): ?>
                                <img src="<?= uploads_url('productos') ?>/<?= htmlspecialchars($item['imagen']) ?>" alt="" class="pdx-prod-img">
                            <?php else: ?>
                                <div class="pdx-prod-ph"><svg width="18" height="18" fill="none" stroke="#c4c4c4" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
                            <?php endif; ?>
                            <div style="min-width:0">
                                <div class="pdx-prod-name"><?= htmlspecialchars($item['producto']) ?></div>
                                <div class="pdx-prod-qty"><?= $item['cantidad'] ?> × $<?= number_format($item['precio_unitario'],2) ?></div>
                                <div class="pdx-prod-sub">$<?= number_format($item['subtotal'],2) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="pdx-extras">
                        <?php if ($puedeEditarDireccionPedido || !empty($pedido['calle']) || !empty($pedido['numero']) || !empty($pedido['colonia']) || !empty($pedido['ciudad']) || !empty($pedido['estado_envio']) || !empty($pedido['cp_envio']) || !empty($pedido['referencias']) || !empty($pedido['quien_recibe'])): ?>
                        <div class="pdx-info" style="border-left:2px solid #38bdf8">
                            <div class="pdx-info-ttl" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                                <span>Domicilio</span>
                                <?php if ($puedeEditarDireccionPedido): ?>
                                    <button type="button"
                                            onclick='event.stopPropagation(); abrirModalDireccionPedido(<?= $direccionPedidoJson ?>)'
                                            style="border:1px solid #bae6fd;background:#f0f9ff;color:#0369a1;border-radius:6px;padding:3px 7px;font-size:10px;font-weight:800;line-height:1;cursor:pointer">
                                        Editar
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($pedido['calle']) || !empty($pedido['numero'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Direccion:</strong> <?= htmlspecialchars(trim(($pedido['calle'] ?? '') . ' ' . ($pedido['numero'] ?? ''))) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['colonia'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Colonia:</strong> <?= htmlspecialchars($pedido['colonia']) ?></div>
                            <?php endif; ?>
                            <?php
                                $ubicacion_envio = array_filter([
                                    $pedido['ciudad'] ?? '',
                                    $pedido['estado_envio'] ?? '',
                                    !empty($pedido['cp_envio']) ? 'CP: ' . $pedido['cp_envio'] : ''
                                ]);
                            ?>
                            <?php if (!empty($ubicacion_envio)): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Ciudad/Estado/CP:</strong> <?= htmlspecialchars(implode(', ', $ubicacion_envio)) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['referencias'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Referencias:</strong> <?= htmlspecialchars($pedido['referencias']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['quien_recibe'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Recibe:</strong> <?= htmlspecialchars($pedido['quien_recibe']) ?></div>
                            <?php endif; ?>
                            <?php if (empty($pedido['calle']) && empty($pedido['numero']) && empty($pedido['colonia']) && empty($pedido['ciudad']) && empty($pedido['estado_envio']) && empty($pedido['cp_envio']) && empty($pedido['referencias']) && empty($pedido['quien_recibe'])): ?>
                                <div style="font-size:13px;color:var(--faint);line-height:1.5">Sin domicilio capturado.</div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($pedido['requiere_factura'])): ?>
                        <div class="pdx-info" style="border-left:2px solid #c4b5fd">
                            <div class="pdx-info-ttl">Datos Fiscales</div>
                            <?php if (!empty($pedido['rfc'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>RFC:</strong> <?= htmlspecialchars($pedido['rfc']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['razon_social'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Razón Social:</strong> <?= htmlspecialchars($pedido['razon_social']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['email_factura'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Email:</strong> <?= htmlspecialchars($pedido['email_factura']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['codigo_postal'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>CP Fiscal:</strong> <?= htmlspecialchars($pedido['codigo_postal']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['uso_cfdi'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Uso CFDI:</strong> <?= htmlspecialchars($pedido['uso_cfdi']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pedido['regimen_fiscal'])): ?>
                                <div style="font-size:13px;color:var(--sub);line-height:1.5"><strong>Régimen:</strong> <?= htmlspecialchars($pedido['regimen_fiscal']) ?></div>
                            <?php endif; ?>
                            <?php if (empty($pedido['rfc']) && empty($pedido['razon_social']) && empty($pedido['email_factura'])): ?>
                                <div style="font-size:13px;color:var(--faint);line-height:1.5">Sin datos fiscales capturados.</div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($pedido['notas'])): ?>
                        <div class="pdx-info" style="border-left:2px solid #fbbf24">
                            <div class="pdx-info-ttl">Notas</div>
                            <div style="font-size:13px;color:var(--sub);line-height:1.5"><?= nl2br(htmlspecialchars($pedido['notas'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($pedido['metodo_pago'])): ?>
                        <div class="pdx-info" style="border-left:2px solid #93c5fd">
                            <div class="pdx-info-ttl">Pago</div>
                            <div style="font-size:13px;font-weight:600;color:var(--sub);text-transform:capitalize"><?= htmlspecialchars($pedido['metodo_pago']) ?></div>
                            <?php if (!empty($pedido['comprobante_pago'])): ?>
                                <a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$pedido['id'] . '&tipo=comprobante') ?>" target="_blank" style="display:inline-flex;align-items:center;gap:4px;margin-top:7px;font-size:12px;font-weight:700;color:var(--brand);text-decoration:none">Ver comprobante ↗</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($pedido['factura_pdf'])||!empty($pedido['factura_xml'])): ?>
                        <div class="pdx-info" style="border-left:2px solid #c4b5fd">
                            <div class="pdx-info-ttl">Factura<?php if (!empty($pedido['num_factura'])): ?> <span style="font-weight:400;font-size:11px;color:var(--faint)">#<?= htmlspecialchars($pedido['num_factura']) ?></span><?php endif; ?></div>
                            <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap">
                                <?php if (!empty($pedido['factura_pdf'])): ?><a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$pedido['id'] . '&tipo=factura_pdf') ?>" target="_blank" style="font-size:12px;font-weight:700;color:#7c3aed;text-decoration:none">PDF ↗</a><?php endif; ?>
                                <?php if (!empty($pedido['factura_xml'])): ?><a href="<?= url('descargar-pedido-archivo.php?pedido=' . (int)$pedido['id'] . '&tipo=factura_xml') ?>" target="_blank" style="font-size:12px;font-weight:700;color:#7c3aed;text-decoration:none">XML ↗</a><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="pdx-info" style="border-left:2px solid #a78bfa">
                            <div class="pdx-info-ttl">Resumen</div>
                            <div style="font-size:11px;color:var(--faint);font-weight:500"><?= date('d/m/Y · H:i', strtotime($pedido['created_at'])) ?></div>
                            <?php
                                $sub_prods = 0;
                                foreach ($detalle_pdx as $_di) {
                                    $sub_prods += $_di['tipo'] === 'kit'
                                        ? (float)$_di['subtotal']
                                        : (float)$_di['subtotal'];
                                }
                                $desc      = (float)($pedido['cupon_descuento']??0);
                                $cenvio    = round((float)$pedido['total'] - $sub_prods + $desc, 2);
                            ?>
                            <table style="width:100%;margin-top:6px;font-size:12px;border-collapse:collapse">
                                <tr><td style="color:var(--sub);padding:2px 0">Subtotal</td><td style="text-align:right;font-family:monospace">$<?= number_format($sub_prods,2) ?></td></tr>
                                <?php if ($desc>0): ?><tr><td style="color:#15803d;padding:2px 0">Cupón <?= !empty($pedido['cupon_codigo'])?'<code style="background:#f0fdf4;padding:1px 5px;border-radius:3px;font-size:11px">'.htmlspecialchars($pedido['cupon_codigo']).'</code>':'' ?></td><td style="text-align:right;font-family:monospace;color:#15803d">−$<?= number_format($desc,2) ?></td></tr><?php endif; ?>
                                <?php if ($cenvio>0): ?><tr><td style="color:var(--sub);padding:2px 0">Envío</td><td style="text-align:right;font-family:monospace">$<?= number_format($cenvio,2) ?></td></tr><?php endif; ?>
                                <tr style="border-top:1px solid #e5e7eb"><td style="font-weight:700;color:var(--ink);padding-top:4px">Total</td><td style="text-align:right;font-family:monospace;font-weight:700;color:var(--ink);padding-top:4px">$<?= number_format($pedido['total'],2) ?></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div id="pdxNoResults" class="pdx-empty" style="display:none;margin-top:10px">
            <div class="pdx-empty-icon"></div>
            <div class="pdx-empty-title">Sin resultados</div>
            <div class="pdx-empty-sub">No hay pedidos con los filtros seleccionados.</div>
        </div>
        <div class="pag-bar" id="pdx-pag-bar">
            <div class="pag-left">
                <span class="pag-info" id="pdx-pag-info"></span>
                <select class="pag-size" id="pdx-pag-size">
                    <option value="10" selected>10 / pág</option>
                    <option value="25">25 / pág</option>
                    <option value="50">50 / pág</option>
                    <option value="100">100 / pág</option>
                </select>
            </div>
            <div class="pag-controls" id="pdx-pag-ctrl"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; /* lista */ ?>

</div>

<!-- Modal editar domicilio del pedido -->
<div id="modalDireccionPedido" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-75">
    <div class="card rounded-2xl shadow-2xl max-w-2xl w-full p-6 bg-white">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Editar domicilio y factura</h2>
                <p class="text-sm text-slate-500">Pedido <strong id="direccionPedidoLabel" class="text-slate-800"></strong></p>
            </div>
            <button onclick="cerrarModalDireccionPedido()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form id="formDireccionPedido" onsubmit="guardarDireccionPedido(event)">
            <input type="hidden" id="direccion_pedido_id" name="pedido_id">

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-1">No. Factura</label>
                <input type="text" id="direccion_num_factura" name="num_factura" maxlength="60"
                       placeholder="Opcional"
                       class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm font-mono">
                <p class="text-xs text-slate-400 mt-1">Se guarda junto con el domicilio del pedido.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Calle</label>
                    <input type="text" id="direccion_calle" name="calle" maxlength="160" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Numero</label>
                    <input type="text" id="direccion_numero" name="numero" maxlength="60" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Colonia</label>
                    <input type="text" id="direccion_colonia" name="colonia" maxlength="120" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Estado</label>
                    <select id="direccion_estado" name="estado" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm">
                        <option value="">Seleccionar</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Municipio / Ciudad</label>
                    <select id="direccion_ciudad" name="ciudad" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm" disabled>
                        <option value="">Primero selecciona un estado</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">CP</label>
                    <input type="text" id="direccion_cp" name="cp" maxlength="12" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Recibe</label>
                    <input type="text" id="direccion_quien_recibe" name="quien_recibe" maxlength="160" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Referencias</label>
                    <textarea id="direccion_referencias" name="referencias" maxlength="500" rows="3" class="input-field w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"></textarea>
                </div>
            </div>

            <div class="bg-sky-50 border border-sky-200 rounded-xl p-3 my-4">
                <p class="text-xs text-sky-800">
                    Solo se actualizan datos de entrega y el número de factura. No cambia productos, importe, pago ni estado del pedido.
                </p>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="cerrarModalDireccionPedido()"
                        class="flex-1 bg-slate-100 text-slate-700 py-2.5 rounded-xl font-medium hover:bg-slate-200 transition text-sm">
                    Cancelar
                </button>
                <button type="submit"
                        class="flex-1 bg-sky-600 hover:bg-sky-700 text-white py-2.5 rounded-xl font-semibold transition text-sm">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Número de Factura -->
<div id="modalNumFactura" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-75">
    <div class="card rounded-2xl shadow-2xl max-w-sm w-full p-6 bg-white">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold text-slate-900">Número de Factura</h2>
            <button onclick="cerrarModalNumFactura()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <p class="text-sm text-slate-500 mb-4">
            Ingresa el folio o número de factura SAT para el pedido
            <strong id="numFacturaPedidoLabel" class="text-slate-800"></strong>.
        </p>

        <form id="formNumFactura" onsubmit="guardarNumFactura(event)">
            <input type="hidden" id="pedido_id_num_factura" name="pedido_id">

            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-1">Núm. Factura</label>
                <input type="text"
                       id="inputNumFactura"
                       name="num_factura"
                       maxlength="60"
                       required
                       placeholder="Ej. A1234, CDFI-0099…"
                       class="input-field w-full px-4 py-3 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-400 text-sm font-mono">
                <p class="text-xs text-slate-400 mt-1">Máx. 60 caracteres</p>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="cerrarModalNumFactura()"
                        class="flex-1 bg-slate-100 text-slate-700 py-2.5 rounded-xl font-medium hover:bg-slate-200 transition text-sm">
                    Cancelar
                </button>
                <button type="submit"
                        class="flex-1 bg-teal-600 hover:bg-teal-700 text-white py-2.5 rounded-xl font-semibold transition text-sm">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para subir comprobante de envío -->
<div id="modalComprobanteEnvio" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-75">
    <div class="card rounded-2xl shadow-2xl max-w-md w-full p-6 bg-white">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-slate-900">Subir Comprobante de Envío</h2>
            <button onclick="cerrarModalEnvio()" class="text-slate-500 hover:text-slate-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <form id="formComprobanteEnvio" onsubmit="subirComprobanteEnvio(event)" enctype="multipart/form-data">
            <input type="hidden" id="pedido_id_envio" name="pedido_id">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    Comprobante (Guía, foto del paquete, etc.)
                </label>
                <input type="file" 
                       name="comprobante_envio"
                       accept="image/*,.pdf"
                       required
                       class="input-field w-full px-4 py-3 rounded-xl">
                <p class="text-xs text-slate-500 mt-1">PDF o imagen (máx. 5MB)</p>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="cerrarModalEnvio()" class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300 transition">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 btn-primary text-white py-3 rounded-xl font-medium">
                    Subir y Marcar En Ruta
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para subir factura electrónica -->
<div id="modalFactura" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-75">
    <div class="card rounded-2xl shadow-2xl max-w-md w-full p-6 bg-white">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-slate-900">Subir Factura Electrónica</h2>
            <button onclick="cerrarModalFactura()" class="text-slate-500 hover:text-slate-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 mb-4">
            <p class="text-sm text-blue-800">
                ℹSube los archivos de la factura generada. Puedes subir solo el PDF, solo el XML, o ambos.
            </p>
        </div>
        
        <form id="formFactura" onsubmit="subirFactura(event)" enctype="multipart/form-data">
            <input type="hidden" id="pedido_id_factura" name="pedido_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Factura PDF
                    </label>
                    <input type="file" 
                           name="factura_pdf"
                           accept=".pdf"
                           class="input-field w-full px-4 py-3 rounded-xl">
                    <p class="text-xs text-slate-500 mt-1">Solo archivos PDF (máx. 5MB)</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Factura XML
                    </label>
                    <input type="file" 
                           name="factura_xml"
                           accept=".xml"
                           class="input-field w-full px-4 py-3 rounded-xl">
                    <p class="text-xs text-slate-500 mt-1">Solo archivos XML (máx. 5MB)</p>
                </div>
            </div>
            
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 my-4">
                <p class="text-xs text-amber-800">
                    <strong>Importante:</strong> Debes subir al menos un archivo (PDF o XML)
                </p>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="cerrarModalFactura()" class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300 transition">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white py-3 rounded-xl font-medium">
                    Subir Factura
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Confirmación -->
<div id="modalConfirmacion" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-75">
    <div class="card rounded-2xl shadow-2xl max-w-md w-full p-6 bg-white transform scale-95 transition-transform" id="modalConfirmacionCard">
        <div class="text-center mb-6">
            <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4" id="iconoConfirmacion">
                <!-- Icono se llenará dinámicamente -->
            </div>
            <h2 class="text-2xl font-bold text-slate-900 mb-2" id="tituloConfirmacion"></h2>
            <p class="text-slate-600" id="mensajeConfirmacion"></p>
        </div>

        <!-- Sección fecha retroactiva (solo visible cuando aplica) -->
        <div id="seccionFechaRetroactiva" class="hidden mb-4 p-3 bg-amber-50 border border-amber-300 rounded-xl">
            <p class="text-xs font-bold text-amber-800 mb-2">Pedido del último día hábil del mes anterior</p>
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" id="chkFechaRetroactiva" class="mt-0.5 accent-amber-600">
                <span class="text-xs text-amber-900">
                    Usar fecha de verificación (<span id="lblFechaVerificar" class="font-semibold"></span>)
                    como fecha de confirmación de pago
                </span>
            </label>
        </div>
        
        <div class="flex gap-3">
            <button onclick="cerrarModalConfirmacion()" 
                    class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-semibold hover:bg-slate-300 transition">
                Cancelar
            </button>
            <button id="btnConfirmarAccion" 
                    class="flex-1 text-white py-3 rounded-xl font-semibold transition">
                Confirmar
            </button>
        </div>
    </div>
</div>

<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
<script>
function abrirModalDireccionPedido(datos) {
    const pedidoId = datos.pedido_id || '';
    document.getElementById('direccion_pedido_id').value = pedidoId;
    document.getElementById('direccionPedidoLabel').textContent = '#' + String(pedidoId).padStart(4, '0');
    document.getElementById('direccion_calle').value = datos.calle || '';
    document.getElementById('direccion_numero').value = datos.numero || '';
    document.getElementById('direccion_colonia').value = datos.colonia || '';
    document.getElementById('direccion_cp').value = datos.cp || '';
    document.getElementById('direccion_quien_recibe').value = datos.quien_recibe || '';
    document.getElementById('direccion_referencias').value = datos.referencias || '';
    document.getElementById('direccion_num_factura').value = datos.num_factura || '';
    document.getElementById('modalDireccionPedido').classList.remove('hidden');

    if (typeof initUbicaciones === 'function') {
        initUbicaciones({
            selectEstado: '#direccion_estado',
            selectMunicipio: '#direccion_ciudad',
            valorEstado: datos.estado || '',
            valorMunicipio: datos.ciudad || '',
            basePath: '<?= BASE_PATH ?>',
        });
    }

    setTimeout(() => document.getElementById('direccion_calle').focus(), 50);
}

function cerrarModalDireccionPedido() {
    document.getElementById('modalDireccionPedido').classList.add('hidden');
    document.getElementById('formDireccionPedido').reset();
}

function guardarDireccionPedido(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Guardando...';

    const fd = new FormData(e.target);
    fd.append('action', 'guardar_direccion_pedido');

    fetch('kanban.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                mostrarAlerta(d.message, 'success');
                cerrarModalDireccionPedido();
                setTimeout(() => window.location.reload(), 450);
            } else {
                mostrarAlerta(d.message || 'Error', 'error');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        })
        .catch(() => {
            mostrarAlerta('Error de conexion', 'error');
            btn.disabled = false;
            btn.innerHTML = orig;
        });
}

function abrirModalNumFactura(pedidoId, numActual) {
    document.getElementById('pedido_id_num_factura').value = pedidoId;
    document.getElementById('numFacturaPedidoLabel').textContent = '#' + String(pedidoId).padStart(4, '0');
    document.getElementById('inputNumFactura').value = numActual || '';
    document.getElementById('modalNumFactura').classList.remove('hidden');
    setTimeout(() => document.getElementById('inputNumFactura').focus(), 50);
}

function cerrarModalNumFactura() {
    document.getElementById('modalNumFactura').classList.add('hidden');
    document.getElementById('formNumFactura').reset();
}

function guardarNumFactura(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Guardando…';

    const fd = new FormData(e.target);
    fd.append('action', 'guardar_num_factura');

    fetch('kanban.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                mostrarAlerta(d.message, 'success');
                cerrarModalNumFactura();
                // Actualizar el badge en la tarjeta sin recargar página
                const card = document.querySelector(`.kanban-card[data-pedido-id="${fd.get('pedido_id')}"]`);
                if (card) {
                    let badge = card.querySelector('.nf-badge');
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'nf-badge inline-block mt-1 px-2 py-0.5 bg-teal-50 text-teal-700 border border-teal-200 rounded text-[11px] font-mono font-semibold';
                        const btn2 = card.querySelector('[data-nf-btn]');
                        if (btn2) btn2.parentNode.insertBefore(badge, btn2.nextSibling);
                    }
                    badge.textContent = '' + d.num_factura;
                    // Cambiar texto del botón a "Editar N° Factura"
                    const nfBtn = card.querySelector('[data-nf-btn]');
                    if (nfBtn) nfBtn.textContent = 'Editar N° Factura';
                }
            } else {
                mostrarAlerta(d.message || 'Error', 'error');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        })
        .catch(() => {
            mostrarAlerta('Error de conexión', 'error');
            btn.disabled = false;
            btn.innerHTML = orig;
        });
}

function abrirModalEnvio(pedidoId) {
    document.getElementById('pedido_id_envio').value = pedidoId;
    document.getElementById('modalComprobanteEnvio').classList.remove('hidden');
}

function cerrarModalEnvio() {
    document.getElementById('modalComprobanteEnvio').classList.add('hidden');
    document.getElementById('formComprobanteEnvio').reset();
}

// ============================================
// FUNCIONES PARA MODAL DE FACTURA
// ============================================

function abrirModalFactura(pedidoId) {
    document.getElementById('pedido_id_factura').value = pedidoId;
    document.getElementById('modalFactura').classList.remove('hidden');
}

function cerrarModalFactura() {
    document.getElementById('modalFactura').classList.add('hidden');
    document.getElementById('formFactura').reset();
}

function subirFactura(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', 'subir_factura');
    
    // Validar que al menos un archivo esté seleccionado
    const pdf = formData.get('factura_pdf');
    const xml = formData.get('factura_xml');
    
    if ((!pdf || pdf.size === 0) && (!xml || xml.size === 0)) {
        mostrarAlerta('Debe seleccionar al menos un archivo (PDF o XML)', 'error');
        return;
    }
    
    const btn = e.target.querySelector('button[type="submit"]');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Subiendo...';
    
    fetch('kanban.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarAlerta(data.message, 'success');
            cerrarModalFactura();
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarAlerta(data.message, 'error');
            btn.disabled = false;
            btn.innerHTML = btnText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('Error al subir factura', 'error');
        btn.disabled = false;
        btn.innerHTML = btnText;
    });
}

// ============================================
// FIN FUNCIONES FACTURA
// ============================================

function subirComprobanteEnvio(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', 'subir_comprobante_envio');
    
    const btn = e.target.querySelector('button[type="submit"]');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Subiendo...';
    
    fetch('kanban.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarAlerta(data.message, 'success');
            cerrarModalEnvio();
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarAlerta(data.message, 'error');
            btn.disabled = false;
            btn.innerHTML = btnText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('Error al subir comprobante', 'error');
        btn.disabled = false;
        btn.innerHTML = btnText;
    });
}

function cambiarEstado(pedidoId, nuevoEstado) {
    const configuraciones = {
        'confirmado': {
            titulo: 'Aprobar Pago',
            mensaje: '¿Confirmar que el pago ha sido verificado correctamente?',
            icono: '',
            iconoBg: 'bg-blue-100',
            iconoColor: 'text-blue-600',
            btnColor: 'bg-blue-500 hover:bg-blue-600'
        },
        'entregado': {
            titulo: 'Marcar como Entregado',
            mensaje: '¿Confirmar que el pedido ha sido entregado al cliente?',
            icono: '',
            iconoBg: 'bg-green-100',
            iconoColor: 'text-green-600',
            btnColor: 'bg-green-500 hover:bg-green-600'
        }
    };
    
    const config = configuraciones[nuevoEstado] || {
        titulo: 'Cambiar Estado',
        mensaje: `¿Cambiar estado a "${nuevoEstado}"?`,
        icono: '',
        iconoBg: 'bg-gray-100',
        iconoColor: 'text-gray-600',
        btnColor: 'bg-sage-500 hover:bg-sage-600'
    };
    
    mostrarModalConfirmacion(
        config.titulo,
        config.mensaje,
        config.icono,
        config.iconoBg,
        config.iconoColor,
        config.btnColor,
        () => ejecutarCambioEstado(pedidoId, nuevoEstado)
    );
}

function confirmarPago(pedidoId, fechaVerificar) {
    // Mostrar/ocultar sección retroactiva
    const seccion = document.getElementById('seccionFechaRetroactiva');
    const chk     = document.getElementById('chkFechaRetroactiva');
    const lbl     = document.getElementById('lblFechaVerificar');
    if (fechaVerificar) {
        const d = new Date(fechaVerificar);
        const fmt = d.toLocaleDateString('es-MX', {day:'2-digit', month:'long', year:'numeric'});
        lbl.textContent = fmt;
        chk.checked = true; // por defecto marcado
        seccion.classList.remove('hidden');
    } else {
        chk.checked = false;
        seccion.classList.add('hidden');
    }

    mostrarModalConfirmacion(
        'Confirmar Pago',
        '¿Confirmar que el pago fue validado por Solumedic?',
        '',
        'bg-blue-100',
        'text-blue-600',
        'bg-blue-500 hover:bg-blue-600',
        () => ejecutarConfirmarPago(pedidoId, fechaVerificar)
    );
}

function ejecutarConfirmarPago(pedidoId, fechaVerificar) {
    cerrarModalConfirmacion();

    const chk = document.getElementById('chkFechaRetroactiva');
    const usarFechaRetroactiva = fechaVerificar && chk && chk.checked;

    const formData = new FormData();
    formData.append('action', 'confirmar_pago');
    formData.append('pedido_id', pedidoId);
    if (usarFechaRetroactiva) {
        formData.append('fecha_confirmacion_retroactiva', fechaVerificar);
    }

    fetch('kanban.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarAlerta(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            mostrarAlerta(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('Error al confirmar pago', 'error');
    });
}

function ejecutarCambioEstado(pedidoId, nuevoEstado) {
    cerrarModalConfirmacion();
    
    const formData = new FormData();
    formData.append('action', 'cambiar_estado');
    formData.append('pedido_id', pedidoId);
    formData.append('estado', nuevoEstado);
    
    fetch('kanban.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarAlerta(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            mostrarAlerta(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('Error al cambiar estado', 'error');
    });
}

function mostrarModalConfirmacion(titulo, mensaje, icono, iconoBg, iconoColor, btnColor, callback) {
    const modal = document.getElementById('modalConfirmacion');
    const card = document.getElementById('modalConfirmacionCard');
    const iconoDiv = document.getElementById('iconoConfirmacion');
    const tituloEl = document.getElementById('tituloConfirmacion');
    const mensajeEl = document.getElementById('mensajeConfirmacion');
    const btnConfirmar = document.getElementById('btnConfirmarAccion');
    
    // Configurar contenido
    iconoDiv.className = `w-20 h-20 ${iconoBg} rounded-full flex items-center justify-center mx-auto mb-4`;
    iconoDiv.innerHTML = `<span class="text-5xl">${icono}</span>`;
    tituloEl.textContent = titulo;
    mensajeEl.textContent = mensaje;
    
    // Configurar botón
    btnConfirmar.className = `flex-1 ${btnColor} text-white py-3 rounded-xl font-semibold transition`;
    
    // Asignar callback
    btnConfirmar.onclick = callback;
    
    // Mostrar modal con animación
    modal.classList.remove('hidden');
    setTimeout(() => {
        card.classList.remove('scale-95');
        card.classList.add('scale-100');
    }, 10);
}

function cerrarModalConfirmacion() {
    const modal = document.getElementById('modalConfirmacion');
    const card = document.getElementById('modalConfirmacionCard');
    
    // Animar salida
    card.classList.remove('scale-100');
    card.classList.add('scale-95');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Cerrar modal al hacer clic fuera
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('modalConfirmacion');
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            cerrarModalConfirmacion();
        }
    });
    
    // Inicializar notificaciones
    if (window.notificationManager) {
        // Mostrar botón para activar notificaciones
        notificationManager.showPermissionButton();
        
        // Si ya tiene permisos, monitorear mensajes de todos los pedidos activos
        if (Notification.permission === 'granted') {
            <?php 
            // Obtener todos los pedidos activos para monitorear
            $pedidosActivos = [];
            foreach ($pedidos_por_estado as $estado => $pedidos) {
                foreach ($pedidos as $pedido) {
                    $pedidosActivos[] = $pedido['id'];
                }
            }
            
            // Monitoreo de nuevos mensajes en pedidos activos
            if (!empty($pedidosActivos)):
            ?>
            (function () {
                const POLL_CHAT = 15_000;
                let _tChat = null;
                const pedidosActivos = <?= json_encode($pedidosActivos) ?>;

                async function _tickChat() {
                    for (const pedidoId of pedidosActivos) {
                        const formData = new FormData();
                        formData.append('action', 'check_new_messages');
                        formData.append('pedido_id', pedidoId);
                        formData.append('user_type', 'admin');
                        formData.append('last_check', localStorage.getItem('last_check_kanban_' + pedidoId) || new Date(Date.now() - 60000).toISOString());
                        try {
                            const response = await fetch(window.BASE_PATH + 'api/check-notifications.php', { method: 'POST', body: formData });
                            const data = await response.json();
                            if (data.has_new_messages && !sessionStorage.getItem('notified_' + pedidoId)) {
                                notificationManager.show('Nuevo mensaje de cliente', {
                                    body: `Pedido #${pedidoId} - ${data.count} mensaje(s) nuevo(s)`,
                                    tag: 'chat-admin-' + pedidoId,
                                    url: window.BASE_PATH + `admin/chat-admin.php?pedido_id=${pedidoId}&return=kanban`
                                });
                                notificationManager.playNotificationSound();
                                sessionStorage.setItem('notified_' + pedidoId, 'true');
                                setTimeout(() => sessionStorage.removeItem('notified_' + pedidoId), 300000);
                            }
                            localStorage.setItem('last_check_kanban_' + pedidoId, new Date().toISOString());
                        } catch (e) { console.error('Error checking messages for pedido ' + pedidoId, e); }
                    }
                    if (document.visibilityState === 'visible')
                        _tChat = setTimeout(_tickChat, POLL_CHAT);
                }

                window._kanbanStopChat  = () => { clearTimeout(_tChat); _tChat = null; };
                window._kanbanStartChat = () => { window._kanbanStopChat(); _tChat = setTimeout(_tickChat, POLL_CHAT); };
                window._kanbanStartChat();
            })();
            <?php endif; ?>
        }
    }
    
    // ACTUALIZAR BADGES DE MENSAJES - INDEPENDIENTE DE NOTIFICACIONES
    // Este código se ejecuta SIEMPRE, sin importar los permisos de notificaciones
    <?php 
    // Obtener todos los pedidos activos para monitorear badges
    $pedidosActivosBadges = [];
    foreach ($pedidos_por_estado as $estado => $pedidos) {
        foreach ($pedidos as $pedido) {
            $pedidosActivosBadges[] = $pedido['id'];
        }
    }
    
    if (!empty($pedidosActivosBadges)):
    ?>
    
    // Re-renderiza una tarjeta completa desde el servidor y la sustituye en el DOM
    async function refrescarTarjeta(pedidoId) {
        const formData = new FormData();
        formData.append('action', 'obtener_html_tarjeta');
        formData.append('pedido_id', pedidoId);
        const response = await fetch('kanban.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (!data.success) return null;
        const temp = document.createElement('div');
        temp.innerHTML = data.html.trim();
        return { elemento: temp.firstElementChild, estado: data.estado };
    }
    
    async function actualizarBadgesMensajes() {
        const pedidosActivos = <?= json_encode($pedidosActivosBadges) ?>;
        
        if (pedidosActivos.length === 0) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'obtener_conteo_mensajes');
            formData.append('pedidos_ids', JSON.stringify(pedidosActivos));
            
            const response = await fetch('kanban.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            console.log('Respuesta del servidor:', data);
            
            if (data.success && data.conteos) {
                // Actualizar cada badge
                Object.keys(data.conteos).forEach(pedidoId => {
                    const count = data.conteos[pedidoId];
                    
                    // Buscar el enlace del chat para este pedido
                    const chatLink = document.querySelector(`a[data-chat-link][data-pedido-id="${pedidoId}"]`);
                    
                    if (!chatLink) {
                        console.log('No se encontró enlace de chat para pedido:', pedidoId);
                        return;
                    }
                    
                    // Buscar el badge existente
                    let badge = chatLink.querySelector('.badge-mensajes');
                    
                    if (count > 0) {
                        if (badge) {
                            // Actualizar el badge existente
                            badge.textContent = count;
                            console.log(`Badge actualizado para pedido ${pedidoId}: ${count} mensajes`);
                        } else {
                            // Crear el badge si no existe
                            const newBadge = document.createElement('span');
                            newBadge.className = 'badge-mensajes absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center';
                            newBadge.textContent = count;
                            chatLink.appendChild(newBadge);
                            console.log(`Badge creado para pedido ${pedidoId}: ${count} mensajes`);
                        }
                    } else {
                        // Remover el badge si no hay mensajes
                        if (badge) {
                            badge.remove();
                            console.log(`Badge eliminado para pedido ${pedidoId}`);
                        }
                    }
                });
                
                // Verificar si algún pedido cambió de estado
                if (data.estados) {
                    Object.keys(data.estados).forEach(pedidoId => {
                        const estadoNuevo = data.estados[pedidoId];
                        
                        // Buscar la tarjeta del pedido
                        const tarjeta = document.querySelector(`.kanban-card[data-pedido-id="${pedidoId}"]`);
                        
                        if (!tarjeta) {
                            console.log('No se encontró tarjeta para pedido:', pedidoId);
                            return;
                        }
                        
                        const estadoActual = tarjeta.getAttribute('data-estado');
                        
                        // Si cambió el estado, re-renderizar la tarjeta completa desde el servidor
                        if (estadoActual !== estadoNuevo) {
                            console.log(`Pedido ${pedidoId} cambió de estado: ${estadoActual} → ${estadoNuevo}`);
                            
                            const columnaDest = document.querySelector(`.kanban-cards[data-estado="${estadoNuevo}"]`);
                            
                            if (columnaDest) {
                                // Animar salida
                                tarjeta.style.transition = 'all 0.3s ease-out';
                                tarjeta.style.opacity = '0';
                                tarjeta.style.transform = 'scale(0.8)';
                                
                                setTimeout(async () => {
                                    // Obtener HTML fresco del servidor
                                    const resultado = await refrescarTarjeta(pedidoId);
                                    if (!resultado) return;
                                    
                                    const nuevaTarjeta = resultado.elemento;
                                    nuevaTarjeta.style.opacity = '0';
                                    nuevaTarjeta.style.transform = 'scale(0.8)';
                                    nuevaTarjeta.style.transition = 'all 0.3s ease';
                                    
                                    // Quitar tarjeta vieja e insertar la nueva en la columna destino
                                    tarjeta.remove();
                                    columnaDest.insertBefore(nuevaTarjeta, columnaDest.firstChild);
                                    
                                    // Animar entrada
                                    setTimeout(() => {
                                        nuevaTarjeta.style.opacity = '1';
                                        nuevaTarjeta.style.transform = 'scale(1)';
                                        nuevaTarjeta.classList.add('ring-2', 'ring-blue-500');
                                        setTimeout(() => nuevaTarjeta.classList.remove('ring-2', 'ring-blue-500'), 2000);
                                    }, 50);
                                    
                                    actualizarContadores();
                                    mostrarToast(`Pedido #${pedidoId} movido a ${getNombreEstado(estadoNuevo)}`, 'info');
                                }, 300);
                            }
                        }
                    });
                }
            } else {
                console.error('Error en respuesta:', data);
            }
        } catch (error) {
            console.error('Error actualizando badges de mensajes:', error);
        }
    }
    
    // Función auxiliar para obtener el nombre legible del estado
    function getNombreEstado(estado) {
        const nombres = {
            'pendiente': 'Pendientes',
            'por_verificar': 'Por Verificar',
            'confirmado': 'Confirmados',
            'en_ruta': 'En Ruta'
        };
        return nombres[estado] || estado;
    }
    
    // Ejecutar inmediatamente y luego cada 10 s (solo pestaña activa)
    console.log('Iniciando sistema de actualización de badges...');
    let _tBadges = null;
    async function _tickBadges() {
        await actualizarBadgesMensajes();
        if (document.visibilityState === 'visible')
            _tBadges = setTimeout(_tickBadges, 10_000);
    }
    window._kanbanStopBadges  = () => { clearTimeout(_tBadges); _tBadges = null; };
    window._kanbanStartBadges = () => { window._kanbanStopBadges(); _tBadges = setTimeout(_tickBadges, 10_000); };
    actualizarBadgesMensajes();
    window._kanbanStartBadges();
    
    <?php endif; ?>
    
    // VERIFICAR NUEVOS PEDIDOS CADA 10 SEGUNDOS
    let ultimoTimestamp = '<?= $ultimo_timestamp ?>';
    
    async function verificarNuevosPedidos() {
        if (document.visibilityState !== 'visible') return;
        try {
            const formData = new FormData();
            formData.append('action', 'verificar_nuevos_pedidos');
            formData.append('ultimo_timestamp', ultimoTimestamp);
            
            const response = await fetch('kanban.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success && data.hay_nuevos) {
                console.log(`${data.cantidad} nuevo(s) pedido(s) detectado(s)!`);
                
                // Actualizar el timestamp
                ultimoTimestamp = data.ultimo_timestamp;
                
                // Agregar cada pedido nuevo a su columna correspondiente
                data.pedidos.forEach(item => {
                    agregarPedidoAColumna(item.pedido, item.detalle, item.mensajes_no_leidos);
                });
                
                // Mostrar notificación
                mostrarNotificacionNuevoPedido(data.cantidad);
                
                // Actualizar contadores
                actualizarContadores();
            }
        } catch (error) {
            console.error('Error verificando nuevos pedidos:', error);
        }
    }
    
    function agregarPedidoAColumna(pedido, detalle, mensajes_no_leidos) {
        const estado = pedido.estado;
        const columna = document.querySelector(`[data-estado="${estado}"] .space-y-3`);
        
        if (!columna) {
            console.error(`No se encontró columna para estado: ${estado}`);
            return;
        }
        
        // Verificar si el pedido ya existe
        if (document.querySelector(`[data-pedido-id="${pedido.id}"]`)) {
            console.log(`Pedido #${pedido.id} ya existe en el tablero`);
            return;
        }
        
        // Crear el HTML de la tarjeta usando kanban-card.php estructura
        const cardHTML = generarHTMLTarjeta(pedido, detalle, mensajes_no_leidos);
        
        // Crear elemento temporal
        const temp = document.createElement('div');
        temp.innerHTML = cardHTML;
        const tarjeta = temp.firstElementChild;
        
        // Remover mensaje "Sin pedidos" si existe
        const sinPedidos = columna.querySelector('.text-center.py-8');
        if (sinPedidos) {
            sinPedidos.remove();
        }
        
        // Agregar al principio de la columna con animación
        tarjeta.style.opacity = '0';
        tarjeta.style.transform = 'translateY(-20px)';
        columna.insertBefore(tarjeta, columna.firstChild);
        
        // Animar entrada
        setTimeout(() => {
            tarjeta.style.transition = 'all 0.5s ease';
            tarjeta.style.opacity = '1';
            tarjeta.style.transform = 'translateY(0)';
        }, 50);
        
        console.log(`Pedido #${pedido.id} agregado a columna ${estado}`);
    }
    
    function generarHTMLTarjeta(pedido, detalle, mensajes_no_leidos) {
        const escapeHtml = value => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        const pedidoId = String(pedido.id).padStart(4, '0');
        const total = parseFloat(pedido.total).toFixed(2);
        const canal = pedido.canal || 'cliente_directo';
        const entregaDirecta = canal === 'representante_directo' || Number(pedido.entrega_directa || 0) === 1;
        const cfdiPendiente = Number(pedido.requiere_factura || 0) === 1 && !pedido.factura_pdf && !pedido.factura_xml;
        const liquidacion = pedido.estado_liquidacion || 'no_aplica';
        const pagoValidar = ['pendiente', 'por_verificar'].includes(pedido.estado);
        const representanteNombre = pedido.representante_nombre_real || '';
        const fecha = new Date(pedido.created_at).toLocaleDateString('es-MX', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const productosHTML = detalle.map(item => 
            `${item.producto} (x${item.cantidad})`
        ).join(', ');
        
        const badgeHTML = mensajes_no_leidos > 0 ? 
            `<span class="badge-mensajes absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center">${mensajes_no_leidos}</span>` : '';
        
        const comprobanteHTML = pedido.comprobante_pago ? 
            `<div class="mb-2">
                <a href="<?= url('descargar-pedido-archivo.php') ?>?pedido=${pedido.id}&tipo=comprobante" target="_blank" class="w-full bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-3 py-2 rounded-lg text-xs font-semibold flex items-center justify-center transition">
                    Ver comprobante pago
                </a>
            </div>` : '';

        const canalBadge = entregaDirecta
            ? '<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Entrega directa</span>'
            : (canal === 'representante_qr'
                ? '<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200">QR rep</span>'
                : ((canal === 'cliente_directo' && pedido.representante_admin_id)
                    ? '<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200">Tienda</span>'
                    : '<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Web</span>'));

        const badgesHTML = `
            <div class="mb-3 flex flex-wrap gap-1">
                ${canalBadge}
                ${representanteNombre ? `<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Rep: ${escapeHtml(representanteNombre)}</span>` : ''}
                ${pedido.metodo_pago === 'efectivo' && liquidacion === 'pendiente' ? '<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-orange-50 text-orange-700 border border-orange-200">Efectivo pendiente</span>' : ''}
                ${cfdiPendiente ? '<span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">CFDI pendiente</span>' : ''}
            </div>`;
        
        return `
        <div class="kanban-card card rounded-xl p-4 bg-white shadow-md hover:shadow-lg transition"
             data-pedido-id="${pedido.id}"
             data-estado="${pedido.estado}"
             data-canal="${canal}"
             data-representante-admin-id="${pedido.representante_admin_id || ''}"
             data-entrega-directa="${entregaDirecta ? '1' : '0'}"
             data-cfdi-pendiente="${cfdiPendiente ? '1' : '0'}"
             data-liquidacion="${liquidacion}"
             data-pago-validar="${pagoValidar ? '1' : '0'}">
            <div class="flex justify-between items-start mb-3">
                <div class="flex-1">
                    <h3 class="font-bold text-slate-900 text-sm">Pedido #${pedidoId}</h3>
                    <p class="text-xs text-slate-600">${fecha}</p>
                </div>
                <p class="font-bold text-terracotta-600">$${total}</p>
            </div>
            
            <div class="mb-3 pb-3 border-b border-slate-100">
                <p class="text-xs text-slate-600">Cliente:</p>
                <p class="font-semibold text-slate-900 text-sm truncate">${pedido.telefono}</p>
                ${pedido.nombre ? `<p class="text-xs text-slate-600 truncate">${pedido.nombre}</p>` : ''}
            </div>

            ${badgesHTML}
            
            <div class="mb-3">
                <p class="text-xs text-slate-600 mb-1">${detalle.length} producto${detalle.length != 1 ? 's' : ''}</p>
                <div class="text-xs text-slate-500 line-clamp-2">${productosHTML}</div>
            </div>
            
            ${comprobanteHTML}
            
            <div class="mt-3 pt-3 border-t border-slate-100 space-y-2">
                <a href="chat-admin.php?pedido_id=${pedido.id}&return=kanban" 
                   data-pedido-id="${pedido.id}"
                   data-chat-link
                   class="w-full bg-sage-500 hover:bg-sage-600 text-white px-3 py-2 rounded-lg text-xs font-medium flex items-center justify-center gap-1 transition relative">
                    Chat
                    ${badgeHTML}
                </a>
            </div>
        </div>`;
    }
    
    function mostrarNotificacionNuevoPedido(cantidad) {
        const notif = document.createElement('div');
        notif.className = 'fixed top-20 right-4 bg-green-600 text-white px-6 py-4 rounded-xl shadow-2xl z-50 flex items-center gap-3';
        notif.innerHTML = `
            <div class="text-3xl"></div>
            <div>
                <p class="font-bold">${cantidad} Nuevo${cantidad > 1 ? 's' : ''} Pedido${cantidad > 1 ? 's' : ''}!</p>
                <p class="text-sm opacity-90">Se ${cantidad > 1 ? 'han agregado' : 'ha agregado'} automáticamente</p>
            </div>
        `;
        document.body.appendChild(notif);
        
        // Reproducir sonido si hay permisos
        if (window.notificationManager) {
            window.notificationManager.playNotificationSound();
        }
        
        setTimeout(() => {
            notif.style.transition = 'all 0.5s ease';
            notif.style.opacity = '0';
            notif.style.transform = 'translateX(400px)';
            setTimeout(() => notif.remove(), 500);
        }, 5000);
    }
    
    function actualizarContadores() {
        // Contar pedidos por columna
        const estados = ['pendiente', 'por_verificar', 'confirmado', 'en_ruta', 'entregado'];
        let totalPedidos = 0;
        
        estados.forEach(estado => {
            const columna = document.querySelector(`[data-estado="${estado}"]`);
            if (columna) {
                const cards = columna.querySelectorAll('.kanban-card');
                const count = cards.length;
                totalPedidos += count;
                
                // Actualizar badge de la columna
                const badge = columna.querySelector('.kanban-count');
                if (badge) {
                    badge.textContent = count;
                    
                    // Animación
                    badge.style.transform = 'scale(1.3)';
                    setTimeout(() => {
                        badge.style.transition = 'all 0.3s ease';
                        badge.style.transform = 'scale(1)';
                    }, 200);
                }
            }
        });
        
        // Actualizar total en el header si existe
        const totalBadge = document.querySelector('.text-3xl.font-bold.text-slate-900');
        if (totalBadge && totalBadge.textContent.match(/^\d+$/)) {
            totalBadge.textContent = totalPedidos;
        }
    }
    
    // Verificar nuevos pedidos cada 10 s (solo pestaña activa)
    console.log('Iniciando verificación de nuevos pedidos...');
    let _tPedidos = null;
    async function _tickPedidos() {
        await verificarNuevosPedidos();
        if (document.visibilityState === 'visible')
            _tPedidos = setTimeout(_tickPedidos, 10_000);
    }
    window._kanbanStopPedidos  = () => { clearTimeout(_tPedidos); _tPedidos = null; };
    window._kanbanStartPedidos = () => { window._kanbanStopPedidos(); _tPedidos = setTimeout(_tickPedidos, 10_000); };
    window._kanbanStartPedidos();

    // Pausa/reanuda todos los timers con visibilitychange
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (window._kanbanStartChat)  window._kanbanStartChat();
            window._kanbanStartBadges();
            window._kanbanStartPedidos();
            // Disparar inmediatamente al volver
            actualizarBadgesMensajes();
            verificarNuevosPedidos();
        } else {
            if (window._kanbanStopChat)  window._kanbanStopChat();
            window._kanbanStopBadges();
            window._kanbanStopPedidos();
        }
    });
    
});

function filtrarKanbanOperacion(filtro) {
    localStorage.setItem('kanbanFiltroOperacion', filtro);

    document.querySelectorAll('.filtro-operacion').forEach(btn => {
        const activo = btn.dataset.filtroOperacion === filtro;
        btn.classList.toggle('is-active', activo);
        btn.setAttribute('aria-pressed', activo ? 'true' : 'false');
    });

    let visibles = 0;
    document.querySelectorAll('.kanban-card').forEach(card => {
        const mostrar =
            filtro === 'todos' ||
            (filtro === 'cliente_directo' && card.dataset.canal === 'cliente_directo' && !card.dataset.representanteAdminId) ||
            (filtro === 'tienda_rep' && card.dataset.canal === 'cliente_directo' && !!card.dataset.representanteAdminId) ||
            (filtro === 'representante_qr' && card.dataset.canal === 'representante_qr') ||
            (filtro === 'representante_directo' && card.dataset.entregaDirecta === '1') ||
            (filtro === 'pago_validar' && card.dataset.pagoValidar === '1') ||
            (filtro === 'efectivo_pendiente' && card.dataset.liquidacion === 'pendiente') ||
            (filtro === 'cfdi_pendiente' && card.dataset.cfdiPendiente === '1');

        card.style.display = mostrar ? '' : 'none';
        if (mostrar) visibles++;
    });

    const conteo = document.getElementById('kanbanFiltroConteo');
    if (conteo) conteo.textContent = visibles;

    document.querySelectorAll('.kanban-cards').forEach(col => {
        const cardsVisibles = col.querySelectorAll('.kanban-card:not([style*="display: none"])').length;
        let empty = col.querySelector('[data-empty-filter]');
        if (cardsVisibles === 0) {
            if (!empty) {
                empty = document.createElement('div');
                empty.dataset.emptyFilter = '1';
                empty.className = 'kb-empty text-center py-8 text-sm rounded-xl';
                empty.textContent = 'Sin pedidos con este filtro';
                col.appendChild(empty);
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const filtroOperacion = localStorage.getItem('kanbanFiltroOperacion') || 'todos';
    filtrarKanbanOperacion(filtroOperacion);
});
</script>

<!-- ── Vista Lista JS ──────────────────────────────────────────────────── -->
<script src="<?= asset('js/paginator.js') ?>"></script>
<script>
/* --- pdx lista helpers --- */
(function() {
  let stActivo = 'todos', opActivo = 'todos', busqueda = '', repActivo = '';

  function pdxSyncSearch() {
    const inp = document.getElementById('pdxBuscar');
    const sel = document.getElementById('pdxRepFiltro');
    busqueda  = inp ? inp.value.trim().toLowerCase() : '';
    repActivo = sel ? sel.value : '';
  }

  const pag = new Paginator({
    rows:     () => document.querySelectorAll('#pdxList .pdx-row'),
    bar:      '#pdx-pag-bar',
    info:     '#pdx-pag-info',
    ctrl:     '#pdx-pag-ctrl',
    sizeEl:   '#pdx-pag-size',
    noResult: '#pdxNoResults',
    unit:     'pedido', units: 'pedidos',
  });

  window.pdxToggle = function(row) { row.classList.toggle('is-open'); };

  window.pdxSt = function(estado) {
    stActivo = estado;
    document.querySelectorAll('[data-st]').forEach(b =>
      b.classList.toggle('p-active-teal', b.dataset.st === estado));
    pdxAplicar();
  };

  window.pdxOp = function(op) {
    opActivo = op;
    document.querySelectorAll('[data-op]').forEach(b =>
      b.classList.toggle('p-active', b.dataset.op === op));
    pdxAplicar();
  };

  window.pdxApply = pdxAplicar;
  window.pdxPaginar = (page) => pag.paginate(page);

  function pdxAplicar() {
    pdxSyncSearch();
    pag.filter(row => {
      const canal       = row.dataset.canal;
      const directa     = row.dataset.entregaDirecta === '1';
      const cfdi        = row.dataset.cfdiPendiente === '1';
      const liquidacion = row.dataset.liquidacion;
      const estado      = row.dataset.estado;

      let passOp = false;
      if      (opActivo === 'todos')                passOp = true;
      else if (opActivo === 'cliente_directo')       passOp = (canal === 'cliente_directo' && !row.dataset.repId);
      else if (opActivo === 'representante_qr')      passOp = canal === 'representante_qr';
      else if (opActivo === 'representante_directo') passOp = directa;
      else if (opActivo === 'efectivo_pendiente')    passOp = liquidacion === 'pendiente';
      else if (opActivo === 'cfdi_pendiente')        passOp = cfdi;
      else passOp = canal === opActivo;

      const passSt  = stActivo === 'todos' || estado === stActivo;
      const passBus = !busqueda || (row.dataset.searchText || '').includes(busqueda);
      const passRep = !repActivo || row.dataset.repId === repActivo;
      return passOp && passSt && passBus && passRep;
    });
  }

  document.addEventListener('DOMContentLoaded', () => pdxAplicar());
})();

window.pdxCambiarEstado = function(pedidoId, nuevoEstado) {
  fetch('kanban.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: `action=cambiar_estado&pedido_id=${pedidoId}&estado=${nuevoEstado}&csrf_token=<?= $_SESSION['csrf_token'] ?? '' ?>`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const row = document.querySelector(`.pdx-row[data-pedido-id="${pedidoId}"]`);
      if (row) {
        row.className = row.className.replace(/\bs-\S+/, '') + ` s-${nuevoEstado}`;
        row.dataset.estado = nuevoEstado;
        const sel = row.querySelector('.pdx-status-sel');
        if (sel) { sel.className = sel.className.replace(/\bs-\S+/, '') + ` s-${nuevoEstado}`; }
      }
      pdxShowToast('Estado actualizado', '#16a34a');
    } else {
      pdxShowToast(data.message || 'Error al cambiar estado', '#dc2626');
    }
  })
  .catch(() => pdxShowToast('Error de red', '#dc2626'));
};

window.pdxAprobar = function(pedidoId) {
  if (!confirm('¿Confirmar pago y cambiar a Confirmado?')) return;
  fetch('kanban.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: `action=confirmar_pago&pedido_id=${pedidoId}&csrf_token=<?= $_SESSION['csrf_token'] ?? '' ?>`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      pdxShowToast(data.message || 'Error al aprobar', '#dc2626');
    }
  })
  .catch(() => pdxShowToast('Error de red', '#dc2626'));
};

window.pdxShowToast = function(msg, color) {
  const el = document.createElement('div');
  el.textContent = msg;
  el.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:600;color:#fff;background:${color};box-shadow:0 4px 16px rgba(0,0,0,.2);animation:pdxFadeIn .2s ease`;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3000);
};
</script>

<!-- Configuración de rutas para JavaScript -->
<script>
    window.BASE_PATH = '<?= BASE_PATH ?>';
</script>

<!-- Notificaciones Push -->
<script src="<?= asset('js/notifications.js') ?>"></script>

</body>
</html>
