<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Pedido.php';
require_once __DIR__ . '/models/Cliente.php';
require_once __DIR__ . '/models/MetodoPago.php';
require_once __DIR__ . '/models/Configuracion.php';
require_once __DIR__ . '/includes/EcartPayClient.php';
require_once __DIR__ . '/includes/OpenPayClient.php';
require_once __DIR__ . '/utils/Mailer.php';

// Obtener conexión a base de datos
$db = Database::getInstance();
$pdo = $db->getConnection();

$pedidoModel = new Pedido();
$clienteModel = new Cliente();
$metodoPagoModel = new MetodoPago();

$terminos_url_cfg = trim((string)Configuracion::get('terminos_condiciones_url', ''));
$aviso_url_cfg    = trim((string)Configuracion::get('aviso_privacidad_url', ''));
$terminos_url     = filter_var($terminos_url_cfg, FILTER_VALIDATE_URL) ? $terminos_url_cfg : '';
$aviso_url        = filter_var($aviso_url_cfg, FILTER_VALIDATE_URL) ? $aviso_url_cfg : '';

// Procesar AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'crear_preferencia_mp':
            try {
                $pedido_id = intval($_POST['pedido_id'] ?? 0);
                $telefono  = $_POST['telefono'] ?? '';

                if (!$pedido_id || !$telefono) {
                    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                    exit;
                }

                $mpMetodo = $metodoPagoModel->getByMetodo('mercado_pago');
                if (!$mpMetodo || empty($mpMetodo['mp_access_token'])) {
                    echo json_encode(['success' => false, 'message' => 'Mercado Pago no está configurado']);
                    exit;
                }

                $pedidoData = $pedidoModel->getById($pedido_id);
                if (!$pedidoData || $pedidoData['telefono'] !== $telefono) {
                    echo json_encode(['success' => false, 'message' => 'Pedido no válido']);
                    exit;
                }

                // Construir back_urls dinámicamente
                $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
                $selfPath = strtok($_SERVER['REQUEST_URI'], '?');
                $backBase = $baseUrl . $selfPath . '?pedido_id=' . $pedido_id . '&telefono=' . urlencode($telefono);

                $preference = [
                    'items' => [[
                        'title'       => 'Pedido #' . $pedido_id . ' - Solumedic Shop',
                        'quantity'    => 1,
                        'unit_price'  => round((float)$pedidoData['total'], 2),
                        'currency_id' => 'MXN',
                    ]],
                    'back_urls' => [
                        'success' => $backBase . '&mp_status=approved',
                        'failure' => $backBase . '&mp_status=rejected',
                        'pending' => $backBase . '&mp_status=pending',
                    ],
                    'statement_descriptor' => 'Solumedic Shop',
                    'external_reference'   => 'PEDIDO_' . $pedido_id,
                ];

                // auto_return solo funciona con URLs públicas (no localhost)
                $isPublicHost = !in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']) &&
                                strpos($_SERVER['HTTP_HOST'], 'localhost') === false;
                if ($isPublicHost) {
                    $preference['auto_return'] = 'approved';
                }

                // Si NO se permite pago sin cuenta, forzar login con cuenta MP
                if (empty($mpMetodo['mp_sin_cuenta'])) {
                    $preference['purpose'] = 'wallet_purchase';
                }

                $accessToken = $mpMetodo['mp_access_token'];
                $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preference));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken,
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                // En entorno local puede faltar el CA bundle; en producción siempre verificar
                if (!$isPublicHost) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                }
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $result = json_decode($response, true);
                if ($httpCode === 201 && isset($result['id'])) {
                    echo json_encode([
                        'success'       => true,
                        'preference_id' => $result['id'],
                        'init_point'    => $result['init_point'] ?? '',
                    ]);
                } else {
                    error_log("MP create preference error ({$httpCode}): " . $response);
                    echo json_encode(['success' => false, 'message' => 'Error al crear preferencia de pago con Mercado Pago']);
                }
            } catch (Exception $e) {
                error_log("Error en crear_preferencia_mp: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error interno: ' . $e->getMessage()]);
            }
            exit;

        case 'mp_payment_success':
            try {
                $pedido_id      = intval($_POST['pedido_id'] ?? 0);
                $payment_id     = trim($_POST['payment_id'] ?? '');
                $status         = trim($_POST['status'] ?? '');
                $external_ref   = trim($_POST['external_reference'] ?? '');

                if (!$pedido_id || !$payment_id) {
                    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                    exit;
                }

                $stmt = $pdo->prepare("
                    UPDATE pedidos
                    SET estado = 'confirmado',
                        metodo_pago = 'mercado_pago',
                        mp_payment_id = :payment_id,
                        mp_status = :status,
                        fecha_pago = NOW()
                    WHERE id = :pedido_id
                ");
                $stmt->execute([
                    'payment_id' => $payment_id,
                    'status'     => $status,
                    'pedido_id'  => $pedido_id,
                ]);

                // Mensaje en el chat
                require_once __DIR__ . '/models/MensajePedido.php';
                $mensajeModel = new MensajePedido($pdo);
                $mensajeModel->create(
                    $pedido_id,
                    'cliente',
                    "💳 Pago realizado con Mercado Pago\n✅ Payment ID: {$payment_id}\n📋 Estado: {$status}"
                );

                echo json_encode(['success' => true, 'message' => 'Pago registrado exitosamente']);
            } catch (Exception $e) {
                error_log("Error en mp_payment_success: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'solicitar_liga_pago':
            try {
                $pedido_id = $_POST['pedido_id'] ?? 0;
                $telefono  = $_POST['telefono'] ?? '';
                
                if (empty($pedido_id) || empty($telefono)) {
                    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                    exit;
                }
                
                // Obtener datos del pedido para la cola
                $stmt = $pdo->prepare("
                    SELECT p.total, COALESCE(c.nombre, 'Cliente') as nombre 
                    FROM pedidos p 
                    JOIN clientes c ON p.cliente_id = c.id 
                    WHERE p.id = ?
                ");
                $stmt->execute([$pedido_id]);
                $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$pedido) {
                    echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
                    exit;
                }
                
                // Verificar que no exista ya un job pendiente/procesando para este pedido
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM liga_pago_queue 
                    WHERE pedido_id = ? 
                    AND estado IN ('pendiente', 'procesando')
                ");
                $stmt->execute([$pedido_id]);
                $existe = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existe['total'] > 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Tu solicitud ya está siendo procesada. Por favor espera...'
                    ]);
                    exit;
                }
                
                // Insertar job en la cola para el worker
                $stmt = $pdo->prepare("
                    INSERT INTO liga_pago_queue 
                    (pedido_id, monto, nombre_cliente, metodo_pago, estado) 
                    VALUES (?, ?, ?, 'liga_pago', 'pendiente')
                ");
                $stmt->execute([
                    $pedido_id,
                    $pedido['total'],
                    $pedido['nombre']
                ]);
                
                // Crear mensaje automático en el chat
                require_once __DIR__ . '/models/MensajePedido.php';
                $mensajeModel = new MensajePedido($pdo);
                
                $mensajeModel->create(
                    $pedido_id,
                    'cliente',
                    '🔗 Cliente solicita liga de pago para realizar el pago en línea (Generación automática en proceso...)'
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Solicitud enviada. Estamos generando tu liga de pago automáticamente...'
                ]);
            } catch (Exception $e) {
                error_log("Error en solicitar_liga_pago: " . $e->getMessage());
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error al procesar solicitud: ' . $e->getMessage()
                ]);
            }
            exit;
            
        case 'limpiar_liga_pago':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            
            if (empty($pedido_id)) {
                echo json_encode(['success' => false, 'message' => 'Pedido no válido']);
                exit;
            }
            
            // Limpiar liga_pago del pedido
            $stmt = $pdo->prepare("UPDATE pedidos SET liga_pago = NULL WHERE id = ?");
            $stmt->execute([$pedido_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Liga anterior eliminada'
            ]);
            exit;
            
        case 'generar_liga_rep':
            try {
                $pedido_id = intval($_POST['pedido_id'] ?? 0);
                $telefono  = $_POST['telefono'] ?? '';

                if (!$pedido_id) {
                    echo json_encode(['success' => false, 'message' => 'ID de pedido requerido']);
                    exit;
                }

                $ligaConfig = $metodoPagoModel->getByMetodo('liga_pago');
                $epConfig   = $metodoPagoModel->getByMetodo('ecartpay');

                $pedidoData = $pedidoModel->getById($pedido_id);
                if (!$pedidoData) {
                    echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
                    exit;
                }

                $total    = round((float) $pedidoData['total'], 2);
                $detalle  = $pedidoModel->getDetalle($pedido_id);
                $prods    = array_slice(array_map(fn($d) => $d['producto'], $detalle), 0, 2);
                $concepto = (implode(', ', $prods) ?: 'Pedido') . ' #' . $pedido_id;

                // ── Generar liga via endpoints públicos de EcartPay ──────────
                $meUrl = trim($ligaConfig['banco'] ?? '');
                $meUrlParsed = parse_url($meUrl);
                $meUrlHost   = $meUrlParsed['host'] ?? '';
                $validHosts  = ['ecartpay.com', 'app.ecart.com'];
                if (empty($meUrl) || !in_array($meUrlHost, $validHosts) || strpos($meUrlParsed['path'] ?? '', '/me/') === false) {
                    echo json_encode(['success' => false, 'message' => 'Configura la URL /me/ de EcartPay en Administración → Métodos de pago → Liga de Pago.']);
                    exit;
                }

                // Extraer PAYLINK_ID del URL /me/{id}
                $paylinkId  = basename(parse_url($meUrl, PHP_URL_PATH));
                $ecartBase  = ($meUrlParsed['scheme'] ?? 'https') . '://' . $meUrlHost;

                // Paso 1: obtener account_id del paylink
                $ctx1 = stream_context_create(['http' => [
                    'method'  => 'GET',
                    'header'  => "Content-Type: application/json\r\n",
                    'timeout' => 10,
                ]]);
                $raw1 = @file_get_contents($ecartBase . '/_/paylink/' . $paylinkId, false, $ctx1);
                if ($raw1 === false) {
                    echo json_encode(['success' => false, 'message' => 'No se pudo conectar con EcartPay. Intenta de nuevo.']);
                    exit;
                }
                $paylink    = json_decode($raw1, true);
                $account_id = $paylink['id'] ?? null;
                if (!$account_id) {
                    echo json_encode(['success' => false, 'message' => 'EcartPay no devolvió un account_id válido. Verifica la URL /me/.']);
                    exit;
                }

                // Paso 2: crear la orden con monto y concepto
                $orderBody = json_encode([
                    'account_id' => $account_id,
                    'amount'     => $total,
                    'currency'   => 'MXN',
                    'concept'    => $concepto,
                ]);
                $ctx2 = stream_context_create(['http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($orderBody) . "\r\n",
                    'content' => $orderBody,
                    'timeout' => 10,
                ]]);
                $raw2 = @file_get_contents($ecartBase . '/_/orders', false, $ctx2);
                if ($raw2 === false) {
                    echo json_encode(['success' => false, 'message' => 'Error al crear la orden en EcartPay.']);
                    exit;
                }
                $orderResp = json_decode($raw2, true);

                if (!empty($orderResp['pay_link']) && parse_url($orderResp['pay_link'], PHP_URL_HOST) !== parse_url($ecartBase, PHP_URL_HOST)) {
                    $liga = $orderResp['pay_link'];
                } elseif (!empty($orderResp['id'])) {
                    $liga = $ecartBase . '/pay/' . $orderResp['id'];
                } else {
                    echo json_encode(['success' => false, 'message' => 'EcartPay no devolvió una liga válida.']);
                    exit;
                }

                $pdo->prepare("UPDATE pedidos SET liga_pago = :liga, metodo_pago = 'liga_pago' WHERE id = :id")
                    ->execute([':liga' => $liga, ':id' => $pedido_id]);

                echo json_encode([
                    'success'  => true,
                    'liga'     => $liga,
                    'monto'    => number_format($total, 2),
                    'concepto' => $concepto,
                ]);
            } catch (Exception $e) {
                error_log("Error en generar_liga_rep: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error interno al generar la liga']);
            }
            exit;

        case 'verificar_liga_pago':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            
            if (empty($pedido_id)) {
                echo json_encode(['success' => false, 'message' => 'Pedido no válido']);
                exit;
            }
            
            // Obtener liga de pago del pedido
            $stmt = $pdo->prepare("SELECT liga_pago FROM pedidos WHERE id = ?");
            $stmt->execute([$pedido_id]);
            $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pedido && !empty($pedido['liga_pago'])) {
                echo json_encode([
                    'success' => true,
                    'liga_pago' => $pedido['liga_pago']
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'liga_pago' => null
                ]);
            }
            exit;
            
        case 'paypal_payment_success':
            try {
                $pedido_id = $_POST['pedido_id'] ?? 0;
                $order_id = $_POST['order_id'] ?? '';
                $transaction_id = $_POST['transaction_id'] ?? '';
                $payer_email = $_POST['payer_email'] ?? '';
                $payer_name = $_POST['payer_name'] ?? '';
                
                if (!$pedido_id || !$order_id || !$transaction_id) {
                    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                    exit;
                }
                
                // Actualizar pedido
                $stmt = $pdo->prepare("
                    UPDATE pedidos 
                    SET 
                        estado = 'confirmado',
                        metodo_pago = 'paypal',
                        paypal_order_id = :order_id,
                        paypal_transaction_id = :transaction_id,
                        paypal_payer_email = :payer_email,
                        paypal_payer_name = :payer_name,
                        fecha_pago = NOW()
                    WHERE id = :pedido_id
                ");
                
                $result = $stmt->execute([
                    'order_id' => $order_id,
                    'transaction_id' => $transaction_id,
                    'payer_email' => $payer_email,
                    'payer_name' => $payer_name,
                    'pedido_id' => $pedido_id
                ]);
                
                if (!$result) {
                    throw new Exception('Error al actualizar el pedido');
                }
                
                // Crear mensaje en el chat
                require_once __DIR__ . '/models/MensajePedido.php';
                $mensajeModel = new MensajePedido($pdo);
                
                $mensajeModel->create(
                    $pedido_id,
                    'cliente',
                    "💳 Pago realizado con PayPal\n✅ Transaction ID: {$transaction_id}\n📧 Email: {$payer_email}"
                );
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Pago procesado exitosamente'
                ]);
                
            } catch (Exception $e) {
                error_log("Error en paypal_payment_success: " . $e->getMessage());
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error: ' . $e->getMessage()
                ]);
            }
            exit;
            
        case 'subir_comprobante':
            try {
                $pedido_id = $_POST['pedido_id'] ?? 0;
                $metodo_pago = $_POST['metodo_pago'] ?? '';
                $modo_rep_post = ($_POST['modo'] ?? '') === 'rep';
                // "Rep opera la tienda" solo aplica cuando se activo la sesion explicita desde entrar-tienda.
                $rep_en_tienda_post = !$modo_rep_post && !empty($_SESSION['_rep_modo']);
                $rep_efectivo_legacy_post = false;
                $requiere_factura = isset($_POST['requiere_factura']) && $_POST['requiere_factura'] === '1' ? 1 : 0;
                $telefono = $_POST['telefono'] ?? '';
                $nombre_cliente_post = trim($_POST['nombre'] ?? '');
                $especialidad = trim($_POST['especialidad'] ?? '');
                $tipo_cliente_post = trim($_POST['tipo_cliente'] ?? '');

                if (!in_array($tipo_cliente_post, ['medico', 'paciente'], true) && !empty($telefono)) {
                    $cli_tipo = $clienteModel->getByTelefono($telefono);
                    $tipo_cliente_post = trim((string)($cli_tipo['tipo_cliente'] ?? 'medico'));
                }
                if (!in_array($tipo_cliente_post, ['medico', 'paciente'], true)) {
                    $tipo_cliente_post = 'medico';
                }
                $es_medico_post = $tipo_cliente_post === 'medico';

                // En flujo normal de tienda (cliente directo o rep opera tienda), especialidad es obligatoria solo para médico.
                if (!$modo_rep_post && $es_medico_post && $especialidad === '') {
                    echo json_encode(['success' => false, 'message' => 'La especialidad es obligatoria para procesar el pago.']);
                    exit;
                }

                if ($metodo_pago === 'efectivo' && !empty($pedido_id)) {
                    $stmtLegacyEf = $pdo->prepare("SELECT nombre_representante, comprobante_pago, estado_liquidacion, estado FROM pedidos WHERE id = :id LIMIT 1");
                    $stmtLegacyEf->execute([':id' => $pedido_id]);
                    $legacyEfRow = $stmtLegacyEf->fetch(PDO::FETCH_ASSOC);
                    if ($legacyEfRow) {
                        $rep_efectivo_legacy_post = empty($legacyEfRow['comprobante_pago'])
                            && (
                                ($legacyEfRow['estado_liquidacion'] ?? '') === 'pendiente'
                                || ($legacyEfRow['estado'] ?? '') === 'por_verificar'
                            );
                    }
                }
                
                // En rep desde su modulo y rep opera tienda no se exige archivo inmediato.
                // El comprobante puede subirse despues desde el modulo de representante.
                $requiere_comprobante_efectivo = $rep_efectivo_legacy_post;
                $metodo_no_requiere_archivo = in_array($metodo_pago, ['paypal', 'mercado_pago', 'ecartpay', 'openpay'], true)
                    || ($metodo_pago === 'efectivo' && !$requiere_comprobante_efectivo);

                if ($modo_rep_post || $metodo_no_requiere_archivo) {
                    // Modo rep: guardar el método seleccionado y marcar como por_verificar
                    if ($modo_rep_post && !empty($metodo_pago)) {
                        $metodos_validos = ['transferencia','tienda','tarjeta','liga_pago','paypal','oxxo','mercado_pago','ecartpay','openpay','efectivo'];
                        $metodo_db = in_array($metodo_pago, $metodos_validos) ? $metodo_pago : null;
                        if ($metodo_pago === 'liga_pago') {
                            // En modo rep, si el representante confirma cobro con liga_pago,
                            // también pasa a por_verificar (el cobro ya fue recibido)
                            $pdo->prepare("UPDATE pedidos SET metodo_pago = 'liga_pago', estado = 'por_verificar', fecha_pago = NOW(), fecha_por_verificar = COALESCE(fecha_por_verificar, NOW()) WHERE id = :id")
                                ->execute([':id' => $pedido_id]);
                        } else {
                            $liq_set = $metodo_pago === 'efectivo' ? ", estado_liquidacion = 'pendiente'" : '';
                            $pdo->prepare("UPDATE pedidos SET metodo_pago = :metodo{$liq_set}, estado = 'por_verificar', fecha_pago = NOW(), fecha_por_verificar = COALESCE(fecha_por_verificar, NOW()) WHERE id = :id")
                                ->execute([':metodo' => $metodo_db, ':id' => $pedido_id]);
                        }
                    } elseif ($metodo_pago === 'efectivo') {
                        $pdo->prepare("UPDATE pedidos SET metodo_pago = 'efectivo', estado_liquidacion = 'pendiente', estado = 'por_verificar', fecha_pago = NOW(), fecha_por_verificar = COALESCE(fecha_por_verificar, NOW()) WHERE id = :id")
                            ->execute([':id' => $pedido_id]);
                    }
                    $datos_envio = [
                        'calle'        => trim($_POST['calle'] ?? ''),
                        'numero'       => trim($_POST['numero'] ?? ''),
                        'colonia'      => trim($_POST['colonia'] ?? ''),
                        'cp'           => trim($_POST['cp'] ?? ''),
                        'estado'       => trim($_POST['estado'] ?? ''),
                        'ciudad'       => trim($_POST['ciudad'] ?? ''),
                        'referencias'  => trim($_POST['referencias'] ?? ''),
                        'quien_recibe' => trim($_POST['quien_recibe'] ?? '')
                    ];

                    $clienteDataRep = null;
                    $envioVacioRep = !array_filter($datos_envio, function ($v) {
                        return $v !== '';
                    });

                    // En modo representante, si no vienen datos en el POST, usar los datos guardados del cliente.
                    if ($modo_rep_post && $envioVacioRep && !empty($telefono)) {
                        $clienteDataRep = $clienteModel->getByTelefono($telefono);
                        if ($clienteDataRep) {
                            $datos_envio = [
                                'calle'        => trim((string)($clienteDataRep['calle'] ?? '')),
                                'numero'       => trim((string)($clienteDataRep['numero'] ?? '')),
                                'colonia'      => trim((string)($clienteDataRep['colonia'] ?? '')),
                                'cp'           => trim((string)($clienteDataRep['cp'] ?? '')),
                                'estado'       => trim((string)($clienteDataRep['estado'] ?? '')),
                                'ciudad'       => trim((string)($clienteDataRep['ciudad'] ?? '')),
                                'referencias'  => trim((string)($clienteDataRep['referencias'] ?? '')),
                                'quien_recibe' => trim((string)($clienteDataRep['quien_recibe'] ?? '')),
                            ];
                        }
                    }

                    $hayDatosEnvioRep = (bool)array_filter($datos_envio, function ($v) {
                        return $v !== '';
                    });
                    if ($hayDatosEnvioRep) {
                        $pedidoModel->actualizarDatosEnvio($pedido_id, $datos_envio);
                    }

                    // Guardar nombre del cliente si se envió en la confirmación final.
                    if (!empty($nombre_cliente_post) && !empty($telefono)) {
                        $cliente_nombre_row = $clienteModel->getByTelefono($telefono);
                        if ($cliente_nombre_row) {
                            $clienteModel->update($cliente_nombre_row['id'], $telefono, $nombre_cliente_post);
                        }
                    }

                    // En flujos de tienda (con/sin QR), guardar también dirección del cliente para futuros pedidos.
                    if (!$modo_rep_post && !empty($telefono)) {
                        $clienteModel->updateDatosEnvio(
                            $telefono,
                            $datos_envio['calle'],
                            $datos_envio['numero'],
                            $datos_envio['colonia'],
                            $datos_envio['cp'],
                            $datos_envio['estado'],
                            $datos_envio['ciudad'],
                            $datos_envio['referencias'],
                            $datos_envio['quien_recibe']
                        );
                        $pdo->prepare("UPDATE clientes SET tipo_cliente = :tc WHERE telefono = :tel")
                            ->execute([':tc' => $tipo_cliente_post, ':tel' => $telefono]);
                        if ($es_medico_post && $especialidad !== '') {
                            $pdo->prepare("UPDATE clientes SET especialidad = :e WHERE telefono = :tel")
                                ->execute([':e' => $especialidad, ':tel' => $telefono]);
                        }
                    }
                    
                    if ($requiere_factura) {
                        $datos_fiscales = [
                            'rfc'            => strtoupper(trim($_POST['rfc'] ?? '')),
                            'razon_social'   => trim($_POST['razon_social'] ?? ''),
                            'email_factura'  => trim($_POST['email_factura'] ?? ''),
                            'codigo_postal'  => trim($_POST['codigo_postal'] ?? ''),
                            'uso_cfdi'       => trim($_POST['uso_cfdi'] ?? 'G03'),
                            'regimen_fiscal' => trim($_POST['regimen_fiscal'] ?? '')
                        ];
                        $pedidoModel->actualizarDatosFiscales($pedido_id, $datos_fiscales);
                        if (!empty($telefono)) {
                            $clienteModel->updateDatosFiscales($telefono, $datos_fiscales['rfc'], $datos_fiscales['razon_social'], $datos_fiscales['email_factura'], $datos_fiscales['codigo_postal'], $datos_fiscales['uso_cfdi'], $datos_fiscales['regimen_fiscal']);
                        }
                    }
                    
                    $nombre_medico        = trim($_POST['nombre_medico'] ?? '');
                    $telefono_medico      = trim($_POST['telefono_medico'] ?? '');
                    $nombre_representante = trim($_POST['nombre_representante'] ?? '');

                    // En modo representante, si no se enviaron datos adicionales, recuperar del cliente.
                    if ($modo_rep_post
                        && $nombre_medico === ''
                        && $telefono_medico === ''
                        && $nombre_representante === ''
                        && !empty($telefono)
                    ) {
                        if ($clienteDataRep === null) {
                            $clienteDataRep = $clienteModel->getByTelefono($telefono);
                        }
                        if ($clienteDataRep) {
                            $nombre_medico = trim((string)($clienteDataRep['nombre_medico'] ?? ''));
                            $telefono_medico = trim((string)($clienteDataRep['telefono_medico'] ?? ''));
                            $nombre_representante = trim((string)($clienteDataRep['nombre_representante'] ?? ''));
                        }
                    }

                    if (!empty($nombre_medico) || !empty($telefono_medico) || !empty($nombre_representante)) {
                        $pedidoModel->actualizarDatosAdicionales($pedido_id, $nombre_medico, $telefono_medico, $nombre_representante);
                        if (!empty($telefono)) {
                            $clienteModel->updateDatosAdicionales($telefono, $nombre_medico, $telefono_medico, $nombre_representante);
                        }
                    }
                    // Guardar email para notificaciones (siempre, independiente de factura)
                    $email_notif = trim($_POST['email_factura'] ?? '');
                    if (!empty($email_notif) && filter_var($email_notif, FILTER_VALIDATE_EMAIL)) {
                        $pdo->prepare("UPDATE pedidos SET email_factura = :email WHERE id = :id AND (email_factura IS NULL OR email_factura = '')")->execute([':email' => $email_notif, ':id' => $pedido_id]);
                        if (!empty($telefono)) {
                            $pdo->prepare("UPDATE clientes SET email_factura = :email WHERE telefono = :tel AND (email_factura IS NULL OR email_factura = '')")->execute([':email' => $email_notif, ':tel' => $telefono]);
                        }
                    }
                    // Guardar preferencias de notificación
                    if (!empty($telefono)) {
                        $n_conf = isset($_POST['notif_confirmacion']) ? 1 : 0;
                        $n_fact = isset($_POST['notif_factura']) ? 1 : 0;
                        $pdo->prepare("UPDATE clientes SET notif_confirmacion = :nc, notif_factura = :nf WHERE telefono = :tel")->execute([':nc' => $n_conf, ':nf' => $n_fact, ':tel' => $telefono]);
                    }
                    // Guardar comprobante si fue proporcionado (opcional en modo rep)
                    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                        $fileRep = $_FILES['comprobante'];
                        $allowedTypesRep = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
                        if ($fileRep['size'] <= 5 * 1024 * 1024 && in_array($fileRep['type'], $allowedTypesRep)) {
                            $uploadDirRep = __DIR__ . '/uploads/comprobantes/';
                            if (!is_dir($uploadDirRep)) mkdir($uploadDirRep, 0755, true);
                            $extRep = pathinfo($fileRep['name'], PATHINFO_EXTENSION);
                            $fnRep  = 'comprobante_' . $pedido_id . '_' . time() . '.' . $extRep;
                            if (move_uploaded_file($fileRep['tmp_name'], $uploadDirRep . $fnRep)) {
                                $pdo->prepare("UPDATE pedidos SET comprobante_pago = :c WHERE id = :id")
                                    ->execute([':c' => $fnRep, ':id' => $pedido_id]);
                            }
                        }
                    }
                    // Notificar al admin que el pedido entró a Por Verificar
                    try {
                        $pv_row = $pdo->prepare("SELECT p.total, c.nombre FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? LIMIT 1");
                        $pv_row->execute([$pedido_id]);
                        $pv_data = $pv_row->fetch(PDO::FETCH_ASSOC);
                        if ($pv_data) {
                            Mailer::sendNuevoPorVerificar(
                                (int)$pedido_id,
                                $pv_data['nombre'] ?? $telefono,
                                $telefono,
                                $metodo_pago,
                                '$' . number_format((float)($pv_data['total'] ?? 0), 2)
                            );
                        }
                    } catch (Exception $e) {
                        error_log('sendNuevoPorVerificar (rep): ' . $e->getMessage());
                    }
                    echo json_encode(['success' => true, 'message' => 'Cobro confirmado']);
                    exit;
                }
                
                // Validar archivo (métodos que sí requieren comprobante)
                if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No se recibió el comprobante']);
                    exit;
                }
                
                $file = $_FILES['comprobante'];
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
                
                // Guardar archivo
                $uploadDir = __DIR__ . '/uploads/comprobantes/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'comprobante_' . $pedido_id . '_' . time() . '.' . $extension;
                $uploadPath = $uploadDir . $filename;
                
                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    echo json_encode(['success' => false, 'message' => 'Error al guardar el comprobante']);
                    exit;
                }
                
                // Preparar datos de envío
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
                
                // Preparar datos fiscales si requiere factura
                $datos_fiscales = [];
                if ($requiere_factura) {
                    $datos_fiscales = [
                        'rfc' => strtoupper(trim($_POST['rfc'] ?? '')),
                        'razon_social' => trim($_POST['razon_social'] ?? ''),
                        'email_factura' => trim($_POST['email_factura'] ?? ''),
                        'codigo_postal' => trim($_POST['codigo_postal'] ?? ''),
                        'uso_cfdi' => trim($_POST['uso_cfdi'] ?? 'G03'),
                        'regimen_fiscal' => trim($_POST['regimen_fiscal'] ?? '')
                    ];
                    
                    // También guardar datos fiscales del cliente para futuros pedidos
                    if (!empty($telefono)) {
                        $clienteModel->updateDatosFiscales(
                            $telefono,
                            $datos_fiscales['rfc'],
                            $datos_fiscales['razon_social'],
                            $datos_fiscales['email_factura'],
                            $datos_fiscales['codigo_postal'],
                            $datos_fiscales['uso_cfdi'],
                            $datos_fiscales['regimen_fiscal']
                        );
                    }
                }
                
                // Actualizar pedido con comprobante, datos de envío y datos fiscales
                $pedidoModel->actualizarComprobante($pedido_id, $filename, $metodo_pago, $requiere_factura, $datos_envio, $datos_fiscales);
                if (!$modo_rep_post && !empty($telefono)) {
                    $clienteModel->updateDatosEnvio(
                        $telefono,
                        $datos_envio['calle'],
                        $datos_envio['numero'],
                        $datos_envio['colonia'],
                        $datos_envio['cp'],
                        $datos_envio['estado'],
                        $datos_envio['ciudad'],
                        $datos_envio['referencias'],
                        $datos_envio['quien_recibe']
                    );
                    $pdo->prepare("UPDATE clientes SET tipo_cliente = :tc WHERE telefono = :tel")
                        ->execute([':tc' => $tipo_cliente_post, ':tel' => $telefono]);
                    if ($es_medico_post && $especialidad !== '') {
                        $pdo->prepare("UPDATE clientes SET especialidad = :e WHERE telefono = :tel")
                            ->execute([':e' => $especialidad, ':tel' => $telefono]);
                    }
                }

                // Guardar nombre del cliente si se envió en la confirmación final.
                if (!empty($nombre_cliente_post) && !empty($telefono)) {
                    $cliente_nombre_row = $clienteModel->getByTelefono($telefono);
                    if ($cliente_nombre_row) {
                        $clienteModel->update($cliente_nombre_row['id'], $telefono, $nombre_cliente_post);
                    }
                }
                if ($metodo_pago === 'efectivo') {
                    $pdo->prepare("UPDATE pedidos SET estado_liquidacion = 'pendiente' WHERE id = :id")
                        ->execute([':id' => $pedido_id]);
                }

                // Guardar email para notificaciones si no fue guardado ya por datos fiscales
                if (!$requiere_factura) {
                    $email_notif = trim($_POST['email_factura'] ?? '');
                    if (!empty($email_notif) && filter_var($email_notif, FILTER_VALIDATE_EMAIL)) {
                        $pdo->prepare("UPDATE pedidos SET email_factura = :email WHERE id = :id AND (email_factura IS NULL OR email_factura = '')")->execute([':email' => $email_notif, ':id' => $pedido_id]);
                        if (!empty($telefono)) {
                            $pdo->prepare("UPDATE clientes SET email_factura = :email WHERE telefono = :tel AND (email_factura IS NULL OR email_factura = '')")->execute([':email' => $email_notif, ':tel' => $telefono]);
                        }
                    }
                }
                // Guardar preferencias de notificación
                if (!empty($telefono)) {
                    $n_conf = isset($_POST['notif_confirmacion']) ? 1 : 0;
                    $n_fact = isset($_POST['notif_factura']) ? 1 : 0;
                    $pdo->prepare("UPDATE clientes SET notif_confirmacion = :nc, notif_factura = :nf WHERE telefono = :tel")->execute([':nc' => $n_conf, ':nf' => $n_fact, ':tel' => $telefono]);
                }

                // Actualizar datos adicionales (médico y representante) en el pedido
                $nombre_medico = trim($_POST['nombre_medico'] ?? '');
                $telefono_medico = trim($_POST['telefono_medico'] ?? '');
                $nombre_representante = trim($_POST['nombre_representante'] ?? '');
                if (!empty($nombre_medico) || !empty($telefono_medico) || !empty($nombre_representante)) {
                    $pedidoModel->actualizarDatosAdicionales($pedido_id, $nombre_medico, $telefono_medico, $nombre_representante);
                    
                    // También guardar en el cliente para futuros pedidos
                    if (!empty($telefono)) {
                        $clienteModel->updateDatosAdicionales($telefono, $nombre_medico, $telefono_medico, $nombre_representante);
                    }
                }
                // Notificar al admin que el pedido entró a Por Verificar
                try {
                    $pv_row2 = $pdo->prepare("SELECT p.total, c.nombre FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? LIMIT 1");
                    $pv_row2->execute([$pedido_id]);
                    $pv_data2 = $pv_row2->fetch(PDO::FETCH_ASSOC);
                    if ($pv_data2) {
                        Mailer::sendNuevoPorVerificar(
                            (int)$pedido_id,
                            $pv_data2['nombre'] ?? $telefono,
                            $telefono,
                            $metodo_pago,
                            '$' . number_format((float)($pv_data2['total'] ?? 0), 2)
                        );
                    }
                } catch (Exception $e) {
                    error_log('sendNuevoPorVerificar (comprobante): ' . $e->getMessage());
                }
                echo json_encode(['success' => true, 'message' => 'Comprobante subido exitosamente']);
            } catch (Exception $e) {
                error_log("Error en subir_comprobante: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
            
        case 'actualizar_datos_envio':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $telefono = $_POST['telefono'] ?? '';
            $nombre = trim($_POST['nombre'] ?? '');
            $calle = trim($_POST['calle'] ?? '');
            $numero = trim($_POST['numero'] ?? '');
            $colonia = trim($_POST['colonia'] ?? '');
            $cp = trim($_POST['cp'] ?? '');
            $estado = trim($_POST['estado'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $referencias = trim($_POST['referencias'] ?? '');
            $quien_recibe = trim($_POST['quien_recibe'] ?? '');
            
            // Actualizar nombre del cliente si fue proporcionado
            if (!empty($nombre) && !empty($telefono)) {
                $cliente = $clienteModel->getByTelefono($telefono);
                if ($cliente) {
                    $clienteModel->update($cliente['id'], $telefono, $nombre);
                }
            }
            
            // Preparar datos de envío
            $datos_envio = [
                'calle' => $calle,
                'numero' => $numero,
                'colonia' => $colonia,
                'cp' => $cp,
                'estado' => $estado,
                'ciudad' => $ciudad,
                'referencias' => $referencias,
                'quien_recibe' => $quien_recibe
            ];
            
            // Guardar en la tabla pedidos (dirección de este pedido específico)
            if (!empty($pedido_id)) {
                $pedidoModel->actualizarDatosEnvio($pedido_id, $datos_envio);
            }
            
            // Guardar en la tabla clientes (dirección por defecto para futuros pedidos)
            if (!empty($telefono)) {
                $clienteModel->updateDatosEnvio(
                    $telefono,
                    $calle,
                    $numero,
                    $colonia,
                    $cp,
                    $estado,
                    $ciudad,
                    $referencias,
                    $quien_recibe
                );
            }
            
            // Guardar email para notificaciones
            $email_notif_envio = trim($_POST['email_factura'] ?? '');
            if (!empty($email_notif_envio) && filter_var($email_notif_envio, FILTER_VALIDATE_EMAIL)) {
                if (!empty($pedido_id)) {
                    $pdo->prepare("UPDATE pedidos SET email_factura = :email WHERE id = :id")->execute([':email' => $email_notif_envio, ':id' => $pedido_id]);
                }
                if (!empty($telefono)) {
                    $pdo->prepare("UPDATE clientes SET email_factura = :email WHERE telefono = :tel")->execute([':email' => $email_notif_envio, ':tel' => $telefono]);
                }
            }
            // Guardar preferencias de notificación (solo confirmación — factura se guarda en el form fiscal)
            if (!empty($telefono)) {
                $n_conf = isset($_POST['notif_confirmacion']) ? 1 : 0;
                $pdo->prepare("UPDATE clientes SET notif_confirmacion = :nc WHERE telefono = :tel")->execute([':nc' => $n_conf, ':tel' => $telefono]);
            }

            // Guardar tipo de cliente
            $tipo_cliente_post = trim($_POST['tipo_cliente'] ?? '');
            if (in_array($tipo_cliente_post, ['medico', 'paciente'], true) && !empty($telefono)) {
                $pdo->prepare("UPDATE clientes SET tipo_cliente = :tc WHERE telefono = :tel")->execute([':tc' => $tipo_cliente_post, ':tel' => $telefono]);
            }

            echo json_encode(['success' => true, 'message' => 'Datos de envío actualizados']);
            exit;
            
        case 'guardar_datos_fiscales':
            $telefono = $_POST['telefono'] ?? '';
            $rfc = strtoupper(trim($_POST['rfc'] ?? ''));
            $razon_social = trim($_POST['razon_social'] ?? '');
            $email_factura = trim($_POST['email_factura'] ?? '');
            $codigo_postal = trim($_POST['codigo_postal'] ?? '');
            $uso_cfdi = trim($_POST['uso_cfdi'] ?? 'G03');
            $regimen_fiscal = trim($_POST['regimen_fiscal'] ?? '');
            
            if (empty($telefono)) {
                echo json_encode(['success' => false, 'message' => 'Teléfono no válido']);
                exit;
            }
            
            // Actualizar datos fiscales del cliente
            try {
                $clienteModel->updateDatosFiscales(
                    $telefono,
                    $rfc,
                    $razon_social,
                    $email_factura,
                    $codigo_postal,
                    $uso_cfdi,
                    $regimen_fiscal
                );
                // Guardar preferencia de notificación de factura
                $n_fact = isset($_POST['notif_factura']) ? 1 : 0;
                $pdo->prepare("UPDATE clientes SET notif_factura = :nf WHERE telefono = :tel")->execute([':nf' => $n_fact, ':tel' => $telefono]);
                echo json_encode(['success' => true, 'message' => '✅ Datos fiscales guardados para futuros pedidos']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
            }
            exit;
            
        case 'crear_orden_ecartpay':
            try {
                $pedido_id = intval($_POST['pedido_id'] ?? 0);
                $telefono  = $_POST['telefono'] ?? '';

                if (!$pedido_id || !$telefono) {
                    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                    exit;
                }

                $epConfig = $metodoPagoModel->getByMetodo('ecartpay');
                if (!$epConfig || empty($epConfig['ecartpay_public_key'])) {
                    echo json_encode(['success' => false, 'message' => 'EcartPay no está configurado']);
                    exit;
                }

                $pedidoData  = $pedidoModel->getById($pedido_id);
                $clienteData = $clienteModel->getByTelefono($telefono);

                if (!$pedidoData || $pedidoData['telefono'] !== $telefono) {
                    echo json_encode(['success' => false, 'message' => 'Pedido no válido']);
                    exit;
                }

                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl  = $scheme . '://' . $_SERVER['HTTP_HOST'];
                $selfPath = strtok($_SERVER['REQUEST_URI'], '?');
                $rootPath = rtrim(dirname($selfPath), '/');

                $redirectUrl = $baseUrl . $selfPath
                    . '?pedido_id=' . $pedido_id
                    . '&telefono=' . urlencode($telefono)
                    . '&ep_status=paid';
                $notifyUrl = $baseUrl . $rootPath . '/api/ecartpay.php?action=webhook';

                $nombre = trim($clienteData['nombre'] ?? '');
                $partes  = explode(' ', $nombre, 2);

                $ecartClient = new EcartPayClient(
                    publicKey:  $epConfig['ecartpay_public_key'],
                    privateKey: $epConfig['ecartpay_private_key'],
                    sandbox:    (bool) $epConfig['ecartpay_sandbox'],
                    cacheGet:   function () use ($epConfig) {
                        if (!empty($epConfig['ecartpay_token_cache']) && !empty($epConfig['ecartpay_token_expires'])) {
                            if (time() < (int) $epConfig['ecartpay_token_expires']) {
                                return $epConfig['ecartpay_token_cache'];
                            }
                        }
                        return null;
                    },
                    cacheSet:   function (string $token, int $expiresAt) use ($pdo) {
                        $pdo->prepare(
                            "UPDATE metodos_pago SET ecartpay_token_cache = :t, ecartpay_token_expires = :e WHERE metodo = 'ecartpay'"
                        )->execute(['t' => $token, 'e' => $expiresAt]);
                    },
                );

                $order = $ecartClient->createOrder([
                    'email'        => $clienteData['email_factura'] ?? '',
                    'first_name'   => $partes[0] ?? 'Cliente',
                    'last_name'    => $partes[1] ?? '',
                    'phone'        => $telefono,
                    'notify_url'   => $notifyUrl,
                    'redirect_url' => $redirectUrl,
                    'items'        => [[
                        'name'     => 'Pedido #' . $pedido_id . ' - Solumedic Shop',
                        'quantity' => 1,
                        'price'    => round((float) $pedidoData['total'], 2),
                    ]],
                ]);

                if (!$order) {
                    echo json_encode(['success' => false, 'message' => 'Error al crear la orden en EcartPay. Verifica las credenciales.']);
                    exit;
                }

                $pdo->prepare("UPDATE pedidos SET ecartpay_order_id = :oid WHERE id = :pid")
                    ->execute(['oid' => $order['id'], 'pid' => $pedido_id]);

                echo json_encode(['success' => true, 'pay_link' => $order['pay_link']]);
            } catch (Exception $e) {
                error_log("Error en crear_orden_ecartpay: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error interno al procesar EcartPay']);
            }
            exit;

        case 'crear_cargo_openpay':
            try {
                $pedido_id      = intval($_POST['pedido_id'] ?? 0);
                $telefono       = $_POST['telefono'] ?? '';
                $token_id       = trim($_POST['token_id'] ?? '');
                $device_session = trim($_POST['device_session_id'] ?? '');

                if (!$pedido_id || !$telefono || !$token_id || !$device_session) {
                    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                    exit;
                }

                $opConfig = $metodoPagoModel->getByMetodo('openpay');
                if (!$opConfig || empty($opConfig['openpay_merchant_id']) || empty($opConfig['openpay_private_key'])) {
                    echo json_encode(['success' => false, 'message' => 'OpenPay no está configurado']);
                    exit;
                }

                $pedidoData = $pedidoModel->getById($pedido_id);
                if (!$pedidoData || $pedidoData['telefono'] !== $telefono) {
                    echo json_encode(['success' => false, 'message' => 'Pedido no válido']);
                    exit;
                }

                $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl     = $scheme . '://' . $_SERVER['HTTP_HOST'];
                $selfPath    = strtok($_SERVER['REQUEST_URI'], '?');
                $redirectUrl = $baseUrl . $selfPath
                    . '?pedido_id=' . $pedido_id
                    . '&telefono=' . urlencode($telefono)
                    . '&op_status=completed';

                $opClient = new OpenPayClient(
                    $opConfig['openpay_merchant_id'],
                    $opConfig['openpay_private_key'],
                    (bool) $opConfig['openpay_sandbox']
                );

                $charge = $opClient->crearCargo([
                    'source_id'         => $token_id,
                    'device_session_id' => $device_session,
                    'amount'            => round((float) $pedidoData['total'], 2),
                    'description'       => 'Pedido #' . $pedido_id . ' - Solumedic Shop',
                    'order_id'          => 'PEDIDO_' . $pedido_id,
                    'redirect_url'      => $redirectUrl,
                ]);

                if (!$charge) {
                    echo json_encode(['success' => false, 'message' => 'No se pudo conectar con OpenPay. Intenta de nuevo.']);
                    exit;
                }

                // Si la API devolvió un error de OpenPay (4xx)
                if (!empty($charge['error_code'])) {
                    $errorDesc = $charge['description'] ?? 'Tarjeta rechazada. Verifica los datos.';
                    echo json_encode(['success' => false, 'message' => $errorDesc]);
                    exit;
                }

                $chargeId = $charge['id']     ?? '';
                $status   = $charge['status'] ?? '';

                if ($chargeId) {
                    $pdo->prepare("UPDATE pedidos SET openpay_charge_id = :cid WHERE id = :pid")
                        ->execute(['cid' => $chargeId, 'pid' => $pedido_id]);
                }

                if ($status === 'completed') {
                    $pdo->prepare("
                        UPDATE pedidos
                        SET estado = 'confirmado', metodo_pago = 'openpay', fecha_pago = NOW()
                        WHERE id = :pid AND (estado IS NULL OR estado NOT IN ('confirmado'))
                    ")->execute(['pid' => $pedido_id]);
                    require_once __DIR__ . '/models/MensajePedido.php';
                    $mOp = new MensajePedido($pdo);
                    $mOp->create($pedido_id, 'cliente', "💳 Pago realizado con OpenPay\n✅ Charge ID: {$chargeId}\n📋 Estado: aprobado");
                    echo json_encode(['success' => true, 'status' => 'completed', 'charge_id' => $chargeId]);
                } elseif ($status === 'in_progress') {
                    $redirect3ds = $charge['payment_method']['url'] ?? '';
                    echo json_encode(['success' => true, 'status' => 'in_progress', 'redirect_url' => $redirect3ds, 'charge_id' => $chargeId]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Pago rechazado. Verifica los datos de tu tarjeta.']);
                }
            } catch (Exception $e) {
                error_log("Error en crear_cargo_openpay: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error interno al procesar el pago']);
            }
            exit;

        case 'guardar_datos_adicionales':
            $pedido_id = $_POST['pedido_id'] ?? 0;
            $telefono = $_POST['telefono'] ?? '';
            $tipo_cliente_ad = trim($_POST['tipo_cliente'] ?? 'medico');
            $nombre_medico = trim($_POST['nombre_medico'] ?? '');
            $telefono_medico = trim($_POST['telefono_medico'] ?? '');
            $nombre_representante = trim($_POST['nombre_representante'] ?? '');
            $especialidad = trim($_POST['especialidad'] ?? '');

            // Para modo Médico: el médico ES el propio cliente
            if ($tipo_cliente_ad === 'medico' && !empty($telefono)) {
                $cli_ad = $clienteModel->getByTelefono(preg_replace('/\D+/', '', $telefono));
                if ($cli_ad) {
                    $nombre_medico   = $cli_ad['nombre'] ?? $nombre_medico;
                    $telefono_medico = preg_replace('/\D+/', '', $telefono);
                }
            }
            
            if (empty($pedido_id)) {
                echo json_encode(['success' => false, 'message' => 'ID de pedido no válido']);
                exit;
            }
            
            // Actualizar datos adicionales del pedido
            try {
                $pedidoModel->actualizarDatosAdicionales($pedido_id, $nombre_medico, $telefono_medico, $nombre_representante);
                
                // También actualizar los datos del cliente para futuros pedidos
                if (!empty($telefono)) {
                    $clienteModel->updateDatosAdicionales($telefono, $nombre_medico, $telefono_medico, $nombre_representante);
                    if ($especialidad !== '') {
                        $pdo->prepare("UPDATE clientes SET especialidad = :e WHERE telefono = :tel")->execute([':e' => $especialidad, ':tel' => $telefono]);
                    }
                }
                
                echo json_encode(['success' => true, 'message' => '✅ Datos adicionales guardados']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Obtener datos
$pedido_id = $_GET['pedido_id'] ?? 0;
$telefono = $_GET['telefono'] ?? '';

if (empty($pedido_id) || empty($telefono)) {
    header('Location: index.php');
    exit;
}

$pedido = $pedidoModel->getById($pedido_id);
$cliente = $clienteModel->getByTelefono($telefono);

// Cargar especialidades activas
$_esp_stmt = $pdo->query("SELECT nombre FROM especialidades WHERE activo=1 ORDER BY orden,nombre");
$especialidades_lista_pp = $_esp_stmt ? $_esp_stmt->fetchAll(PDO::FETCH_COLUMN) : [];

// Determinar flujo de pago activo
if (session_status() === PHP_SESSION_NONE) session_start();
$modo_rep      = ($_GET['modo'] ?? '') === 'rep';
$rep_en_tienda = !empty($_SESSION['_rep_modo']);
$rep_efectivo_legacy = !$rep_en_tienda
    && (($pedido['metodo_pago'] ?? '') === 'efectivo')
    && empty($pedido['comprobante_pago'])
    && (
        (($pedido['estado_liquidacion'] ?? '') === 'pendiente')
        || (($pedido['estado'] ?? '') === 'por_verificar')
    );
$efectivo_requiere_comprobante = $rep_efectivo_legacy;

if ($modo_rep) {
    $flujo_pago = 'a';
} elseif ($rep_en_tienda) {
    $flujo_pago = 'b';
} elseif (!empty($_COOKIE['botikit_rep_admin'])) {
    $flujo_pago = 'c';
} else {
    $flujo_pago = 'd';
}

// Cargar métodos activos según el flujo
$metodosActivos = $metodoPagoModel->getActivosPorFlujo($flujo_pago);

// Liga de Pago: forzar en flujos A y B aunque esté inactiva en BD
if (in_array($flujo_pago, ['a', 'b'])) {
    $tieneliga = array_filter($metodosActivos, fn($m) => $m['metodo'] === 'liga_pago');
    if (empty($tieneliga)) {
        $ligaConfig = $metodoPagoModel->getByMetodo('liga_pago');
        if ($ligaConfig) $metodosActivos[] = $ligaConfig;
    }
}

if (!$pedido || !$cliente) {
    header('Location: index.php');
    exit;
}


// Validar que el pedido pertenezca al cliente
if ($pedido['telefono'] !== $telefono) {
    header('Location: index.php');
    exit;
}

// Verificar si hay un representante desde cookie
$representante_desde_cookie = isset($_COOKIE['botikit_rep_nombre']) ? $_COOKIE['botikit_rep_nombre'] : null;
$campo_readonly = !empty($representante_desde_cookie);

// Manejar retorno de EcartPay (redirect_url)
$ep_status = $_GET['ep_status'] ?? '';
$ep_auto_confirm = false;
if ($ep_status === 'paid') {
    try {
        $stmtEpUpdate = $pdo->prepare("
            UPDATE pedidos
            SET estado = 'confirmado',
                metodo_pago = 'ecartpay',
                fecha_pago = NOW()
            WHERE id = :pid
              AND ecartpay_order_id IS NOT NULL
              AND (estado IS NULL OR estado != 'confirmado')
        ");
        $stmtEpUpdate->execute(['pid' => $pedido_id]);

        if ($stmtEpUpdate->rowCount() > 0) {
            require_once __DIR__ . '/models/MensajePedido.php';
            $mensajeEp = new MensajePedido($pdo);
            $mensajeEp->create($pedido_id, 'cliente', "💳 Pago realizado con EcartPay\n📋 Estado: aprobado");
            $ep_auto_confirm = true;
            $pedido = $pedidoModel->getById($pedido_id);
        }
    } catch (Exception $e) {
        error_log("Error al confirmar pago EcartPay en retorno: " . $e->getMessage());
    }
}

// Manejar retorno de OpenPay (3DS redirect o completado directo)
$op_status = $_GET['op_status'] ?? '';
$op_auto_confirm = false;
if ($op_status === 'completed') {
    try {
        $opConfigRet = $metodoPagoModel->getByMetodo('openpay');
        if ($opConfigRet && !empty($opConfigRet['openpay_merchant_id']) && !empty($opConfigRet['openpay_private_key'])) {
            $stmtCid = $pdo->prepare("SELECT openpay_charge_id FROM pedidos WHERE id = :pid LIMIT 1");
            $stmtCid->execute(['pid' => $pedido_id]);
            $cidRow   = $stmtCid->fetch(PDO::FETCH_ASSOC);
            $chargeId = $cidRow['openpay_charge_id'] ?? '';
            if ($chargeId) {
                $opClientRet = new OpenPayClient(
                    $opConfigRet['openpay_merchant_id'],
                    $opConfigRet['openpay_private_key'],
                    (bool) $opConfigRet['openpay_sandbox']
                );
                $chargeRet = $opClientRet->obtenerCargo($chargeId);
                if ($chargeRet && ($chargeRet['status'] ?? '') === 'completed') {
                    $stmtOpRet = $pdo->prepare("
                        UPDATE pedidos
                        SET estado = 'confirmado', metodo_pago = 'openpay', fecha_pago = NOW()
                        WHERE id = :pid AND (estado IS NULL OR estado NOT IN ('confirmado'))
                    ");
                    $stmtOpRet->execute(['pid' => $pedido_id]);
                    if ($stmtOpRet->rowCount() > 0) {
                        require_once __DIR__ . '/models/MensajePedido.php';
                        $mensajeOp = new MensajePedido($pdo);
                        $mensajeOp->create($pedido_id, 'cliente', "💳 Pago realizado con OpenPay\n✅ Charge ID: {$chargeId}\n📋 Estado: aprobado");
                        $op_auto_confirm = true;
                        $pedido = $pedidoModel->getById($pedido_id);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error al confirmar pago OpenPay en retorno: " . $e->getMessage());
    }
}

// Manejar retorno de Mercado Pago (back_url)
$mp_status = $_GET['mp_status'] ?? '';
$mp_payment_id = $_GET['payment_id'] ?? '';
$mp_auto_confirm = false;
if (!empty($mp_status) && !empty($mp_payment_id) && $mp_status === 'approved') {
    try {
        $stmtMpUpdate = $pdo->prepare("
            UPDATE pedidos
            SET estado = 'confirmado',
                metodo_pago = 'mercado_pago',
                mp_payment_id = :payment_id,
                mp_status = :status,
                fecha_pago = NOW()
            WHERE id = :pedido_id AND metodo_pago != 'confirmado'
        ");
        $stmtMpUpdate->execute([
            'payment_id' => $mp_payment_id,
            'status'     => $mp_status,
            'pedido_id'  => $pedido_id,
        ]);

        require_once __DIR__ . '/models/MensajePedido.php';
        $mensajeModelMp = new MensajePedido($pdo);
        $mensajeModelMp->create(
            $pedido_id,
            'cliente',
            "💳 Pago realizado con Mercado Pago\n✅ Payment ID: {$mp_payment_id}\n📋 Estado: aprobado"
        );
        $mp_auto_confirm = true;
        // Refresh pedido data
        $pedido = $pedidoModel->getById($pedido_id);
    } catch (Exception $e) {
        error_log("Error al confirmar pago MP en retorno: " . $e->getMessage());
    }
}

?>

<?php include 'includes/header.php'; ?>
<?php if (!$modo_rep): ?>
<link rel="stylesheet" href="<?= asset('css/cliente-mobile.css') ?>">
<script>document.body.classList.add('cliente-app');</script>
<?php endif; ?>

<?php
$_paypal = $metodoPagoModel->getByMetodo('paypal');
$_paypal_client_id = htmlspecialchars($_paypal['paypal_client_id'] ?? '');
$_paypal_funding = !empty($_paypal['paypal_sin_cuenta']) ? 'enable-funding=card' : 'disable-funding=card';

$_mp = $metodoPagoModel->getByMetodo('mercado_pago');
$_mp_public_key = htmlspecialchars($_mp['mp_public_key'] ?? '');
$_mp_sin_cuenta = !empty($_mp['mp_sin_cuenta']);

$_op = $metodoPagoModel->getByMetodo('openpay');
$_op_merchant_id = htmlspecialchars($_op['openpay_merchant_id'] ?? '');
$_op_public_key  = htmlspecialchars($_op['openpay_public_key'] ?? '');
$_op_sandbox     = !empty($_op['openpay_sandbox']);
?>
<?php if (!$modo_rep): ?>
<!-- PayPal SDK -->
<script src="https://www.paypal.com/sdk/js?client-id=<?= $_paypal_client_id ?>&currency=MXN&locale=es_MX&<?= $_paypal_funding ?>"></script>
<!-- Mercado Pago SDK -->
<?php if (!empty($_mp_public_key)): ?>
<script src="https://sdk.mercadopago.com/js/v2"></script>
<?php endif; ?>
<!-- OpenPay SDK -->
<?php if (!empty($_op_merchant_id) && !empty($_op_public_key)): ?>
<script src="https://js.openpay.mx/openpay.v1.min.js"></script>
<script src="https://js.openpay.mx/openpay-data.v1.min.js"></script>
<?php endif; ?>
<?php endif; ?>

<style>
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); max-height: 0; }
    to   { opacity: 1; transform: translateY(0);     max-height: 2000px; }
}
/* ── Rep mode mobile-first ── */
<?php if ($modo_rep): ?>
.hamburger { display: none !important; }
.rep-header {
    position: sticky; top: 0; z-index: 30;
    background: rgba(255,255,255,.97);
    border-bottom: 2px solid #e2d9cf;
    backdrop-filter: blur(16px);
    margin: -8px -16px 20px;
    padding: 14px 16px calc(14px + env(safe-area-inset-top));
}
.rep-total-badge {
    font-size: 28px; font-weight: 900; line-height: 1;
    color: #D86F4D;
}
.rep-btn-metodo { min-height: 60px; font-size: 17px; }
.rep-copy-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: #f1ede7; color: #101820;
    font-size: 12px; font-weight: 900; text-transform: uppercase;
    letter-spacing: .04em; border: none; border-radius: 6px;
    padding: 6px 10px; cursor: pointer; transition: background .15s;
    white-space: nowrap;
}
.rep-copy-btn:active { background: #d9d0c5; }
.rep-copy-btn.copied { background: #d1fae5; color: #065f46; }
.rep-data-row {
    display: flex; align-items: center;
    justify-content: space-between; gap: 10px;
    padding: 8px 0; border-bottom: 1px solid #f0ebe3;
}
.rep-data-row:last-child { border-bottom: 0; }
.confirm-btn-rep {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 40;
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
    background: rgba(255,255,255,.97); border-top: 2px solid #e2d9cf;
    backdrop-filter: blur(16px);
}
body { padding-bottom: 90px; }
<?php endif; ?>
</style>

<div class="<?= $modo_rep ? '' : 'cliente-shell cliente-screen' ?> w-full px-4 <?= $modo_rep ? 'py-2 max-w-xl' : 'py-8 max-w-4xl' ?> mx-auto">

<?php if ($modo_rep): ?>
    <!-- Rep: sticky header with total and share button -->
    <div class="rep-header">
        <div class="flex items-center justify-between gap-3 mb-2">
            <a href="<?= url('representante/index.php') ?>"
               class="min-h-9 px-4 rounded-lg bg-slate-950 text-white font-bold text-sm grid place-items-center">Inicio</a>
            <button onclick="compartirEnlacePago()"
                    class="flex items-center gap-2 bg-green-500 text-white text-sm font-bold px-4 py-2 rounded-xl active:bg-green-600">
                📱 Compartir enlace
            </button>
        </div>
        <div class="flex items-end justify-between">
            <div>
                <div class="text-xs font-bold uppercase text-slate-400 tracking-wide">Cobrar pedido #<?= $pedido['id'] ?></div>
                <div class="rep-total-badge">$<?= number_format($pedido['total'], 2) ?> <span class="text-base font-semibold text-slate-400">MXN</span></div>
            </div>
            <div class="text-right text-xs text-slate-500">
                <?= htmlspecialchars($cliente['nombre'] ?? 'Cliente') ?><br>
                <?= htmlspecialchars($telefono) ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Normal header -->
    <div class="mb-8">
        <a href="seguimiento.php?telefono=<?= htmlspecialchars($telefono) ?>" class="text-terracotta-600 hover:underline mb-2 inline-block">
            ← Volver al seguimiento
        </a>
        <h1 class="text-3xl font-bold text-slate-900 mb-2">💳 Procesar Pago</h1>
        <p class="text-slate-600">Pedido #<?= $pedido['id'] ?> - Total: <span class="font-bold text-terracotta-600">$<?= number_format($pedido['total'], 2) ?></span></p>
    </div>
<?php endif; ?>

    <!-- Modal de Cámara -->
    <div id="modalCamara" class="modal-backdrop fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-black bg-opacity-75">
        <div class="card rounded-2xl shadow-2xl max-w-2xl w-full p-6 bg-white">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-slate-900">📸 Tomar Foto del Comprobante</h2>
                <button onclick="cerrarCamara()" class="text-slate-500 hover:text-slate-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Video de la cámara -->
            <div class="relative bg-black rounded-xl overflow-hidden mb-4">
                <video id="video" autoplay playsinline class="w-full h-auto max-h-96"></video>
                <canvas id="canvas" class="hidden"></canvas>
                
                <!-- Overlay con guías -->
                <div class="absolute inset-0 pointer-events-none">
                    <div class="absolute inset-4 border-2 border-dashed border-white opacity-50 rounded-lg"></div>
                </div>
            </div>
            
            <div class="flex gap-3">
                <button onclick="cerrarCamara()" class="flex-1 bg-slate-200 text-slate-700 py-3 rounded-xl font-medium hover:bg-slate-300 transition">
                    Cancelar
                </button>
                <button onclick="capturarFoto()" class="flex-1 btn-primary text-white py-3 rounded-xl font-medium flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    Capturar
                </button>
            </div>
            
            <p class="text-xs text-slate-500 text-center mt-3">
                💡 Asegúrate de que el comprobante sea legible
            </p>
        </div>
    </div>

    <div class="<?= $modo_rep ? 'flex flex-col gap-4' : 'grid grid-cols-1 lg:grid-cols-2 gap-6' ?>">
        
        <!-- Columna Izquierda: Métodos de Pago -->
        <div class="space-y-6">
            
            <!-- Selector de Método de Pago -->
            <div class="card rounded-2xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-slate-900 mb-4">Selecciona tu método de pago</h2>
                
                <div class="space-y-3">
                    <?php 
                    $iconos = [
                        'transferencia' => '🏦',
                        'oxxo' => '🏪',
                        'tienda' => '🏪',
                        'tarjeta' => '💳',
                        'liga_pago' => '🔗',
                        'paypal' => '<img src="https://www.paypalobjects.com/webstatic/mktg/logo/pp_cc_mark_37x23.jpg" alt="PayPal" class="h-6">',
                        'mercado_pago' => '💳',
                        'ecartpay' => '🛒'
                    ];
                    $colores = [
                        'transferencia' => 'bg-sage-100',
                        'oxxo' => 'bg-blue-100',
                        'tienda' => 'bg-blue-100',
                        'tarjeta' => 'bg-purple-100',
                        'liga_pago' => 'bg-purple-100',
                        'paypal' => 'bg-blue-100',
                        'mercado_pago' => 'bg-sky-100',
                        'ecartpay' => 'bg-green-100'
                    ];
                    
                    foreach ($metodosActivos as $metodo): 
                        $key = $metodo['metodo'];
                        $icono = $iconos[$key] ?? '💳';
                        $color = $colores[$key] ?? 'bg-slate-100';
                    ?>
                        <button onclick="seleccionarMetodo('<?= $key ?>')" 
                                id="btn-<?= $key ?>"
                                class="w-full <?= $modo_rep ? 'p-5 rep-btn-metodo' : 'p-4' ?> rounded-xl border-2 border-slate-200 hover:border-terracotta-400 hover:bg-terracotta-50 transition-all text-left flex items-center gap-4 active:scale-[.98]">
                            <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center border-2 border-slate-300 shadow-sm">
                                <?php if ($key === 'paypal'): ?>
                                    <img src="assets/images/logopaypal.png" alt="PayPal" class="h-7 w-auto object-contain">
                                <?php elseif ($key === 'mercado_pago'): ?>
                                    <span class="text-xl font-black text-sky-600">MP</span>
                                <?php else: ?>
                                    <span class="text-2xl"><?= $icono ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-semibold text-slate-900"><?= htmlspecialchars($metodo['nombre_display']) ?></h3>
                                <?php if (!empty($metodo['descripcion'])): ?>
                                    <p class="text-sm text-slate-600"><?= htmlspecialchars($metodo['descripcion']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="w-6 h-6 rounded-full border-2 border-slate-300" id="check-<?= $key ?>"></div>
                        </button>
                    <?php endforeach; ?>
                    

                </div>
            </div>
            
            <?php if (!$modo_rep): ?>
            <!-- Panel Especial: PayPal (oculto inicialmente) -->
            <div id="panel-paypal" class="card rounded-2xl shadow-lg p-6 bg-blue-50 border-2 border-blue-200 hidden">
                <h2 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-3">
                    <img src="https://www.paypalobjects.com/webstatic/mktg/logo/pp_cc_mark_37x23.jpg" alt="PayPal" class="h-6">
                    Pagar con PayPal
                </h2>
                
                <div class="text-center py-8">
                    <div class="w-24 h-24 bg-white rounded-xl flex items-center justify-center mx-auto mb-4 shadow-md border-2 border-blue-200 p-4">
                        <img src="https://www.paypalobjects.com/webstatic/mktg/logo/pp_cc_mark_37x23.jpg" alt="PayPal" class="w-full h-auto">
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900 mb-2">Pago Seguro con PayPal</h3>
                    <p class="text-slate-600 mb-6">
                        Serás redirigido a PayPal para completar tu pago de forma segura. <br>
                        Acepta tarjetas de crédito/débito y saldo PayPal.
                    </p>
                    
                    <div class="bg-white rounded-xl p-6 mb-6 border-2 border-blue-300">
                        <p class="text-2xl font-bold text-blue-900 mb-2">Total a pagar:</p>
                        <p class="text-4xl font-black text-blue-600">$<?= number_format($pedido['total'], 2) ?> MXN</p>
                    </div>
                    
                    <!-- Botón PayPal -->
                    <div id="paypal-button-container" class="mb-4"></div>
                    
                    <p class="text-xs text-slate-500">
                        🔒 Transacción segura procesada por PayPal
                    </p>
                </div>
            </div>

            <!-- Panel Especial: Mercado Pago (oculto inicialmente) -->
            <div id="panel-mercado-pago" class="card rounded-2xl shadow-lg p-6 bg-sky-50 border-2 border-sky-200 hidden">
                <h2 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-3">
                    💳 Pagar con Mercado Pago
                </h2>

                <?php if ($mp_auto_confirm): ?>
                <!-- Pago ya confirmado al regresar de MP -->
                <div class="text-center py-6">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-4xl">✅</span>
                    </div>
                    <h3 class="text-lg font-semibold text-green-800 mb-2">¡Pago aprobado!</h3>
                    <p class="text-slate-600">Tu pago con Mercado Pago fue procesado exitosamente.</p>
                    <p class="text-xs text-slate-500 mt-2">Payment ID: <?= htmlspecialchars($mp_payment_id) ?></p>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <div class="bg-white rounded-xl p-6 mb-6 border-2 border-sky-300">
                        <p class="text-2xl font-bold text-sky-900 mb-2">Total a pagar:</p>
                        <p class="text-4xl font-black text-sky-600">$<?= number_format($pedido['total'], 2) ?> MXN</p>
                    </div>

                    <p class="text-slate-600 mb-4 text-sm">
                        Al hacer clic, serás redirigido al checkout de Mercado Pago para completar tu pago de forma segura con tarjeta de crédito, débito o saldo MP.
                    </p>

                    <!-- Contenedor del botón de Mercado Pago -->
                    <div id="mp-wallet-container" class="mb-4"></div>

                    <div id="mp-loading" class="text-center py-4 hidden">
                        <span class="inline-block w-8 h-8 border-4 border-sky-300 border-t-sky-600 rounded-full animate-spin mb-2"></span>
                        <p class="text-sm text-slate-600">Preparando el pago...</p>
                    </div>

                    <button id="btn-iniciar-mp"
                            onclick="iniciarPagoMP()"
                            class="w-full py-4 rounded-xl font-bold text-white text-lg transition-all"
                            style="background: linear-gradient(135deg, #009EE3 0%, #00A8E0 100%);">
                        🛒 Pagar con Mercado Pago
                    </button>

                    <p class="text-xs text-slate-500 mt-3">
                        🔒 Transacción segura procesada por Mercado Pago
                    </p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Panel Especial: EcartPay (oculto inicialmente) -->
            <div id="panel-ecartpay" class="card rounded-2xl shadow-lg p-6 bg-green-50 border-2 border-green-200 hidden">
                <h2 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-3">
                    🛒 Pagar con EcartPay
                </h2>

                <?php if ($ep_auto_confirm): ?>
                <div class="text-center py-6">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-4xl">✅</span>
                    </div>
                    <h3 class="text-lg font-semibold text-green-800 mb-2">¡Pago aprobado!</h3>
                    <p class="text-slate-600">Tu pago con EcartPay fue procesado exitosamente.</p>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <div class="bg-white rounded-xl p-6 mb-6 border-2 border-green-300">
                        <p class="text-2xl font-bold text-green-900 mb-2">Total a pagar:</p>
                        <p class="text-4xl font-black text-green-600">$<?= number_format($pedido['total'], 2) ?> MXN</p>
                    </div>

                    <p class="text-slate-600 mb-4 text-sm">
                        Al hacer clic, serás redirigido a EcartPay para completar tu pago de forma segura con tarjeta de crédito o débito.
                    </p>

                    <div id="ep-loading" class="text-center py-4 hidden">
                        <span class="inline-block w-8 h-8 border-4 border-green-300 border-t-green-600 rounded-full animate-spin mb-2"></span>
                        <p class="text-sm text-slate-600">Preparando el pago...</p>
                    </div>

                    <button id="btn-iniciar-ep"
                            onclick="iniciarPagoEcartPay()"
                            class="w-full py-4 rounded-xl font-bold text-white text-lg transition-all"
                            style="background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);">
                        🛒 Pagar con EcartPay
                    </button>

                    <p class="text-xs text-slate-500 mt-3">
                        🔒 Transacción segura procesada por EcartPay
                    </p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; // end !$modo_rep — PayPal, MP, EcartPay panels ?>

            <?php if (!$modo_rep && !empty($_op_merchant_id) && !empty($_op_public_key)): ?>
            <!-- Panel OpenPay: tarjeta inline con tokenización JS -->
            <div id="panel-openpay" class="card rounded-2xl shadow-lg p-6 bg-indigo-50 border-2 border-indigo-200 hidden">
                <h2 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-3">
                    💳 Pagar con Tarjeta (OpenPay)
                </h2>

                <?php if ($op_auto_confirm): ?>
                <div class="text-center py-6">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-4xl">✅</span>
                    </div>
                    <h3 class="text-lg font-semibold text-green-800 mb-2">¡Pago aprobado!</h3>
                    <p class="text-slate-600">Tu pago con OpenPay fue procesado exitosamente.</p>
                </div>
                <?php else: ?>
                <form id="openpay-form" autocomplete="off" onsubmit="return false;">
                    <div class="bg-white rounded-xl p-4 mb-5 border-2 border-indigo-300 text-center">
                        <p class="text-sm font-semibold text-indigo-700 uppercase tracking-wide mb-1">Total a pagar</p>
                        <p class="text-4xl font-black text-indigo-600">$<?= number_format($pedido['total'], 2) ?> <span class="text-base font-semibold">MXN</span></p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Número de tarjeta <span class="text-red-500">*</span></label>
                            <input type="text" id="op-card-number"
                                   class="input-field w-full px-4 py-3 rounded-xl tracking-widest"
                                   placeholder="1234 5678 9012 3456" maxlength="19"
                                   autocomplete="cc-number" inputmode="numeric">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Titular de la tarjeta <span class="text-red-500">*</span></label>
                            <input type="text" id="op-holder-name"
                                   class="input-field w-full px-4 py-3 rounded-xl uppercase"
                                   placeholder="NOMBRE COMO APARECE EN LA TARJETA"
                                   autocomplete="cc-name">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Mes / Año <span class="text-red-500">*</span></label>
                                <div class="flex gap-2">
                                    <input type="text" id="op-exp-month"
                                           class="input-field w-full px-3 py-3 rounded-xl text-center"
                                           placeholder="MM" maxlength="2"
                                           autocomplete="cc-exp-month" inputmode="numeric">
                                    <input type="text" id="op-exp-year"
                                           class="input-field w-full px-3 py-3 rounded-xl text-center"
                                           placeholder="AA" maxlength="2"
                                           autocomplete="cc-exp-year" inputmode="numeric">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">CVV <span class="text-red-500">*</span></label>
                                <input type="text" id="op-cvv"
                                       class="input-field w-full px-4 py-3 rounded-xl text-center"
                                       placeholder="123" maxlength="4"
                                       autocomplete="cc-csc" inputmode="numeric">
                            </div>
                        </div>
                    </div>

                    <input type="hidden" id="op-device-session-id" value="">

                    <div id="op-error" class="bg-red-50 border border-red-300 text-red-700 text-sm rounded-xl px-4 py-3 hidden mb-3"></div>

                    <div id="op-loading" class="text-center py-4 hidden">
                        <span class="inline-block w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-2"></span>
                        <p class="text-sm text-slate-600">Procesando pago de forma segura...</p>
                    </div>

                    <button type="button" id="btn-pagar-openpay"
                            onclick="procesarPagoOpenPay()"
                            class="w-full py-4 rounded-xl font-bold text-white text-lg transition-all"
                            style="background: linear-gradient(135deg, #4338ca 0%, #3730a3 100%);">
                        💳 Pagar $<?= number_format($pedido['total'], 2) ?> MXN
                    </button>
                    <p class="text-xs text-slate-500 text-center mt-3">🔒 Transacción segura procesada por OpenPay (BBVA)</p>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; // panel-openpay ?>

            <?php if ($modo_rep): ?>
            <!-- Panel: Efectivo (solo modo rep) -->
            <div id="panel-efectivo" class="card rounded-2xl shadow-lg p-6 bg-green-50 border-2 border-green-200 hidden">
                <h2 class="text-xl font-bold text-slate-900 mb-4">💵 Pago en Efectivo</h2>
                <div class="text-center py-4">
                    <div class="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-5xl">💵</span>
                    </div>
                    <div class="bg-white rounded-2xl border-2 border-green-300 p-4 mb-4">
                        <p class="text-sm font-semibold text-green-700 uppercase tracking-wide mb-1">Total a cobrar</p>
                        <p class="text-4xl font-black text-green-800">$<?= number_format($pedido['total'], 2) ?></p>
                        <p class="text-sm text-slate-500 mt-1">MXN</p>
                    </div>
                    <p class="text-slate-600 text-sm">Confirma la venta cuando recibas el efectivo del cliente.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Panel Especial: Liga de Pago (oculto inicialmente) -->
            <div id="panel-liga-pago" class="card rounded-2xl shadow-lg p-6 bg-purple-50 border-2 border-purple-200 hidden">
                <h2 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-2">
                    🔗 Liga de Pago
                </h2>

                <?php if ($modo_rep): ?>
                <!-- ── REP MODE: genera la liga al instante ────────────────── -->
                <div id="rep-liga-sin-generar" class="text-center py-6">
                    <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-4xl">🔗</span>
                    </div>
                    <p class="text-slate-700 font-semibold mb-1">Total del pedido</p>
                    <p class="text-3xl font-black text-purple-700 mb-4">$<?= number_format($pedido['total'], 2) ?></p>
                    <p class="text-slate-600 text-sm mb-6">
                        Se generará un enlace de pago seguro vía EcartPay para compartirle al cliente.
                    </p>
                    <button id="btnGenerarLiga" type="button" onclick="generarLigaRep()"
                            class="w-full py-4 px-6 bg-purple-600 hover:bg-purple-700 text-white rounded-2xl font-bold text-lg transition active:scale-95">
                        🔗 Generar Liga de Pago
                    </button>
                </div>

                <div id="rep-liga-generada" class="hidden">
                    <div class="bg-green-50 border-2 border-green-300 rounded-2xl p-4 mb-4 text-center">
                        <p class="text-green-700 font-bold text-lg mb-1">✅ ¡Liga generada!</p>
                        <p class="text-slate-600 text-sm">Abre el enlace e ingresa estos datos en EcartPay:</p>
                    </div>

                    <!-- Monto y Concepto para copiar -->
                    <div class="bg-white rounded-2xl border-2 border-purple-200 divide-y divide-purple-100 mb-4">
                        <div class="flex items-center justify-between p-3 gap-2">
                            <div>
                                <p class="text-xs text-slate-500 uppercase font-bold">Monto</p>
                                <p id="rep-liga-monto-txt" class="text-2xl font-black text-purple-700">—</p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between p-3 gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-slate-500 uppercase font-bold">Concepto</p>
                                <p id="rep-liga-concepto-txt" class="text-sm font-semibold text-slate-800 truncate">—</p>
                            </div>
                        </div>
                    </div>

                    <!-- Link display -->
                    <div class="bg-white rounded-2xl border border-purple-300 p-3 mb-4 flex items-center gap-2">
                        <a id="rep-liga-url" href="#" target="_blank"
                           class="flex-1 text-purple-700 text-xs font-medium break-all">—</a>
                        <button type="button" onclick="copiarTextoRep(this, document.getElementById('rep-liga-url').textContent)"
                                class="flex-shrink-0 bg-purple-100 hover:bg-purple-200 text-purple-700 rounded-xl px-3 py-2 text-sm font-semibold transition">
                            📋 Copiar
                        </button>
                    </div>

                    <!-- Upload proof buttons -->
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <button type="button" onclick="document.getElementById('comprobante-rep').click()"
                                class="py-3 bg-sage-500 hover:bg-sage-600 text-white rounded-xl font-semibold transition active:scale-95 flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Subir Archivo
                        </button>
                        <button type="button" onclick="abrirCamaraRep()"
                                class="py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-xl font-semibold transition active:scale-95 flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Tomar Foto
                        </button>
                    </div>

                    <!-- File name preview -->
                    <p id="rep-nombre-archivo" class="text-xs text-slate-500 text-center mb-3 hidden"></p>

                    <!-- Hidden file input for rep comprobante -->
                    <input type="file" id="comprobante-rep" name="comprobante"
                           accept=".pdf,.jpg,.jpeg,.png,image/jpeg,image/png,application/pdf"
                           onchange="previsualizarArchivoRep(this)" class="hidden">

                    <!-- Abrir / Regenerar -->
                    <div class="flex items-center justify-between">
                        <button type="button" onclick="abrirLigaRep()"
                                class="text-purple-600 text-sm underline hover:text-purple-800">🔗 Abrir liga</button>
                        <button type="button" onclick="regenerarLigaRep()"
                                class="text-slate-400 text-sm underline hover:text-slate-600">🔄 Regenerar</button>
                    </div>
                </div>
                <?php else: ?>
                <div id="estado-liga-pago">
                    <!-- Estado: Sin generar -->
                    <div id="sin-solicitar" class="text-center py-8">
                        <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <span class="text-4xl">🔗</span>
                        </div>
                        <p class="text-slate-700 font-semibold mb-1">Total del pedido</p>
                        <p class="text-3xl font-black text-purple-700 mb-4">$<?= number_format($pedido['total'], 2) ?></p>
                        <p class="text-slate-600 text-sm mb-6">
                            Se generará un enlace de pago seguro vía EcartPay para que puedas pagar en línea.
                        </p>
                        <button id="btnGenerarLigaCliente" type="button" onclick="generarLigaCliente()"
                                class="w-full py-4 px-6 bg-purple-600 hover:bg-purple-700 text-white rounded-2xl font-bold text-lg transition active:scale-95">
                            🔗 Generar Liga de Pago
                        </button>
                    </div>

                    <!-- Estado: Liga generada -->
                    <div id="liga-recibida" class="hidden">
                        <div class="bg-green-50 border-2 border-green-300 rounded-2xl p-4 mb-4 text-center">
                            <p class="text-green-700 font-bold text-lg mb-1">✅ ¡Liga generada!</p>
                            <?php if ($rep_en_tienda): ?>
                            <p class="text-slate-600 text-sm">Comparte este enlace con el cliente para que pague en línea:</p>
                            <?php else: ?>
                            <p class="text-slate-600 text-sm">Haz clic en el botón para proceder al pago:</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($rep_en_tienda): ?>
                        <!-- Rep en tienda: mostrar enlace copiable para compartir al cliente -->
                        <div class="bg-white rounded-2xl border border-purple-300 p-3 mb-4 flex items-center gap-2">
                            <a id="enlace-pago" href="#" target="_blank"
                               class="flex-1 text-purple-700 text-xs font-medium break-all">—</a>
                            <button type="button"
                                    onclick="copiarTextoRep(this, document.getElementById('enlace-pago').href)"
                                    class="flex-shrink-0 bg-purple-600 hover:bg-purple-700 text-white rounded-xl px-4 py-2 text-sm font-bold transition active:scale-95">
                                📋 Copiar enlace
                            </button>
                        </div>
                        <!-- Comprobante de pago (opcional) -->
                        <div class="mt-2">
                            <p class="text-xs font-bold uppercase text-slate-500 mb-2">Comprobante de pago (opcional)</p>
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <button type="button" onclick="document.getElementById('comp-tienda').click()"
                                        class="py-3 bg-[#126c6a] text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-2 active:scale-95">
                                    📎 Subir archivo
                                </button>
                                <button type="button" onclick="abrirCamaraTienda()"
                                        class="py-3 bg-blue-500 text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-2 active:scale-95">
                                    📷 Tomar foto
                                </button>
                            </div>
                            <input type="file" id="comp-tienda"
                                   accept=".pdf,.jpg,.jpeg,.png,image/jpeg,image/png,application/pdf"
                                   onchange="previsualizarCompTienda(this)" class="hidden">
                            <p id="comp-tienda-nombre" class="text-xs text-slate-500 text-center mb-3 hidden"></p>
                        </div>
                        <?php else: ?>
                        <a id="enlace-pago" href="" target="_blank"
                           onclick="scrollAlComprobante()"
                           class="block w-full py-4 px-6 bg-purple-600 hover:bg-purple-700 text-white rounded-2xl font-bold text-lg transition text-center mb-4">
                            🔗 Ir a la Plataforma de Pago →
                        </a>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-3">
                            <p class="text-sm text-amber-800">
                                <strong>⚠️ Importante:</strong> Después de pagar, regresa aquí y sube tu comprobante para que podamos verificar tu pedido.
                            </p>
                        </div>
                        <?php endif; ?>

                        <div class="text-center">
                            <button type="button" onclick="regenerarLigaCliente()"
                                    class="text-slate-400 hover:text-slate-600 font-semibold text-sm underline">
                                🔄 Regenerar liga
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Panel de Datos Bancarios (oculto inicialmente) -->
            <div id="panel-datos-bancarios" class="card rounded-2xl shadow-lg p-6 hidden">
                <h3 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                    <span id="icono-metodo"></span>
                    <span id="titulo-metodo"></span>
                </h3>
                
                <div class="bg-cream-100 rounded-xl p-4 <?= $modo_rep ? '' : 'space-y-3' ?> mb-4" id="datos-bancarios">
                    <!-- Se llenará dinámicamente -->
                </div>

                <?php if ($modo_rep): ?>
                <div id="rep-copy-panel" class="hidden mb-4"></div>
                <?php endif; ?>
                
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
                    <p class="text-sm text-amber-800">
                        <strong>⚠️ Importante:</strong> Realiza el pago por el monto exacto de <strong>$<?= number_format($pedido['total'], 2) ?></strong> y sube tu comprobante.
                    </p>
                </div>
                
                <!-- Formulario de Comprobante -->
                <form id="formComprobante" onsubmit="subirComprobante(event)" enctype="multipart/form-data">
                    <input type="hidden" id="metodo_pago_selected" name="metodo_pago">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-3">
                            📎 Comprobante de Pago <span class="text-red-500">*</span>
                        </label>
                        
                        <!-- Preview de la imagen -->
                        <div id="preview-container" class="hidden mb-4">
                            <div class="relative bg-slate-100 rounded-xl overflow-hidden">
                                <img id="preview-image" src="" alt="Vista previa" class="w-full h-auto max-h-64 object-contain">
                                <button type="button" onclick="limpiarArchivo()" 
                                        class="absolute top-2 right-2 bg-red-500 text-white p-2 rounded-full hover:bg-red-600 transition">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <p id="nombre-archivo" class="text-sm text-slate-600 mt-2 text-center"></p>
                        </div>
                        
                        <!-- Botones de opciones -->
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <button type="button" onclick="document.getElementById('comprobante').click()" 
                                    class="bg-sage-500 text-white py-3 px-4 rounded-xl font-medium hover:bg-sage-600 transition flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                Subir Archivo
                            </button>
                            <button type="button" onclick="abrirCamara()" 
                                    class="bg-blue-500 text-white py-3 px-4 rounded-xl font-medium hover:bg-blue-600 transition flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                Tomar Foto
                            </button>
                        </div>
                        
                        <!-- Input oculto para archivos -->
                        <input type="file" 
                               id="comprobante"
                               name="comprobante"
                               accept=".pdf,.jpg,.jpeg,.png,image/jpeg,image/png,application/pdf"
                               onchange="previsualizarArchivo(this)"
                               required
                               class="hidden">
                        
                        <p class="text-xs text-slate-500 text-center">Formatos: PDF, JPG, PNG (Máx. 5MB)</p>
                    </div>
                </form>
            </div>
            
        </div>
        
        <?php if (!$modo_rep): ?>
        <!-- Columna Derecha: Datos de Envío -->
        <div class="space-y-6">
            
            <div class="card rounded-2xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-slate-900 mb-4">📦 Datos de Envío</h2>
                
                <form id="formEnvio" onsubmit="return false;">
                    <div class="space-y-4">
                        
                        <?php if (empty($cliente['nombre'])): ?>
                        <!-- Mostrar campo solo si no tiene nombre -->
                        <div class="bg-purple-50 border-2 border-purple-200 rounded-xl p-4">
                            <label class="block text-sm font-medium text-purple-900 mb-2">
                                👋 ¿Cómo te gustaría que te llamáramos? <span class="text-xs text-purple-600">(Opcional)</span>
                            </label>
                            <input type="text" name="nombre" id="nombre_cliente"
                                   class="input-field w-full px-4 py-3 rounded-xl border-purple-300" 
                                   placeholder="Tu nombre">
                            <p class="text-xs text-purple-700 mt-2">
                                💡 Nos ayuda a personalizar tu experiencia
                            </p>
                        </div>
                        <?php else: ?>
                        <!-- Mostrar nombre guardado de forma subtle -->
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl">👤</span>
                                <div>
                                    <p class="text-xs text-slate-500">Cliente</p>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($cliente['nombre']) ?></p>
                                </div>
                            </div>
                            <button type="button" onclick="editarNombre()" 
                                    class="text-purple-600 hover:text-purple-800 text-sm font-medium">
                                ✏️ Editar
                            </button>
                        </div>
                        <input type="hidden" name="nombre" id="nombre_hidden" value="<?= htmlspecialchars($cliente['nombre']) ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2 flex items-center gap-2">
                                🏠 Calle
                                <span id="alerta-calle" class="hidden text-red-500 text-xs">⚠️ Campo obligatorio</span>
                            </label>
                            <input type="text" name="calle" id="calle" required
                                   value="<?= htmlspecialchars($cliente['calle'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre de la calle">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2 flex items-center gap-2">
                                    🔢 Número
                                    <span id="alerta-numero" class="hidden text-red-500 text-xs">⚠️ Obligatorio</span>
                                </label>
                                <input type="text" name="numero" id="numero" required
                                       value="<?= htmlspecialchars($cliente['numero'] ?? '') ?>"
                                       class="input-field w-full px-4 py-3 rounded-xl" placeholder="123">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2 flex items-center gap-2">
                                    📮 CP
                                    <span id="alerta-cp" class="hidden text-red-500 text-xs">⚠️ Obligatorio</span>
                                </label>
                                <input type="text" name="cp" id="cp" required maxlength="5"
                                       value="<?= htmlspecialchars($cliente['cp'] ?? '') ?>"
                                       class="input-field w-full px-4 py-3 rounded-xl" placeholder="12345"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">🏘️ Colonia</label>
                            <input type="text" name="colonia" id="colonia" required
                                   value="<?= htmlspecialchars($cliente['colonia'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Colonia">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">🗺️ Estado</label>
                            <select name="estado" id="estado" required class="input-field w-full px-4 py-3 rounded-xl">
                                <option value="">Selecciona un estado</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">🏙️ Municipio / Alcaldía</label>
                            <select name="ciudad" id="ciudad" required disabled
                                   class="input-field w-full px-4 py-3 rounded-xl">
                                <option value="">— Primero selecciona un estado —</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">📍 Referencias</label>
                            <textarea name="referencias" id="referencias" rows="2"
                                      class="input-field w-full px-4 py-3 rounded-xl"
                                      placeholder="Referencias para encontrar el domicilio..."><?= htmlspecialchars($cliente['referencias'] ?? '') ?></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                🙋 Quien Recibe <span class="text-xs text-slate-500">(Opcional)</span>
                            </label>
                            <input type="text" name="quien_recibe" id="quien_recibe"
                                   value="<?= htmlspecialchars($cliente['quien_recibe'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl" placeholder="Nombre de quien recibe">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                📧 Correo Electrónico <span class="text-xs text-slate-500">(Opcional - para recibir confirmaciones)</span>
                            </label>
                            <input type="email"
                                   name="email_factura"
                                   id="email_factura"
                                   value="<?= htmlspecialchars($cliente['email_factura'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl"
                                   placeholder="correo@ejemplo.com">
                            <!-- Preferencias de notificación (visible solo si hay email) -->
                            <div class="mt-2 space-y-1" id="notif-prefs" style="<?= empty($cliente['email_factura'] ?? '') ? 'display:none' : '' ?>">
                                <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none">
                                    <input type="checkbox" name="notif_confirmacion" id="notif_confirmacion" value="1"
                                           class="w-4 h-4 accent-teal-600 rounded"
                                           <?= (int)($cliente['notif_confirmacion'] ?? 1) ? 'checked' : '' ?>>
                                    Confirmar pedido y pago
                                </label>
                            </div>
                            <script>
                            document.getElementById('email_factura').addEventListener('input', function() {
                                document.getElementById('notif-prefs').style.display = this.value.trim() ? '' : 'none';
                            });
                            </script>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Tipo de cliente</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-2 cursor-pointer flex-1 justify-center border-2 rounded-xl py-3 font-bold transition <?= ($cliente['tipo_cliente'] ?? 'medico') === 'medico' ? 'border-terracotta-500 bg-terracotta-50 text-terracotta-600' : 'border-slate-200 text-slate-600' ?>" id="lbl-medico">
                                    <input type="radio" name="tipo_cliente" value="medico" class="hidden" <?= ($cliente['tipo_cliente'] ?? 'medico') === 'medico' ? 'checked' : '' ?> onchange="toggleTipoCliente(this)">
                                    👨‍⚕️ Médico
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer flex-1 justify-center border-2 rounded-xl py-3 font-bold transition <?= ($cliente['tipo_cliente'] ?? '') === 'paciente' ? 'border-terracotta-500 bg-terracotta-50 text-terracotta-600' : 'border-slate-200 text-slate-600' ?>" id="lbl-paciente">
                                    <input type="radio" name="tipo_cliente" value="paciente" class="hidden" <?= ($cliente['tipo_cliente'] ?? '') === 'paciente' ? 'checked' : '' ?> onchange="toggleTipoCliente(this)">
                                    🧑 Paciente
                                </label>
                            </div>
                        </div>

                        <div id="pp-bloque-especialidad">
                            <label class="block text-sm font-medium text-slate-700 mb-2">🔬 Especialidad</label>
                            <?php
                                $esp_val = $cliente['especialidad'] ?? '';
                                $esp_en_lista = in_array($esp_val, $especialidades_lista_pp);
                            ?>
                            <select id="especialidad_pp_sel" class="input-field w-full px-4 py-3 rounded-xl" onchange="onPPEspChange(this)">
                                <option value="">— Seleccionar —</option>
                                <?php foreach ($especialidades_lista_pp as $_e): ?>
                                    <option value="<?= htmlspecialchars($_e) ?>" <?= $esp_val === $_e ? 'selected' : '' ?>><?= htmlspecialchars($_e) ?></option>
                                <?php endforeach; ?>
                                <option value="__otro__" <?= ($esp_val && !$esp_en_lista) ? 'selected' : '' ?>>Otro...</option>
                            </select>
                            <input type="hidden" name="especialidad" id="especialidad_pp" value="<?= htmlspecialchars($esp_val) ?>">
                            <input type="text" id="especialidad_pp_otro" class="input-field w-full px-4 py-3 rounded-xl mt-2"
                                   placeholder="Escribe la especialidad..."
                                   value="<?= (!$esp_en_lista && $esp_val) ? htmlspecialchars($esp_val) : '' ?>"
                                   style="<?= (!$esp_en_lista && $esp_val) ? '' : 'display:none' ?>"
                                   oninput="document.getElementById('especialidad_pp').value=this.value">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                👔 Nombre del Representante
                                <?php if ($campo_readonly): ?>
                                    <span class="text-xs text-green-600 ml-2">(✓ Asignado automáticamente)</span>
                                <?php endif; ?>
                            </label>
                            <input type="text"
                                   name="nombre_representante"
                                   id="nombre_representante"
                                   value="<?= htmlspecialchars($representante_desde_cookie ?? $cliente['nombre_representante'] ?? $pedido['nombre_representante'] ?? '') ?>"
                                   class="input-field w-full px-4 py-3 rounded-xl <?= $campo_readonly ? 'bg-gray-100 cursor-not-allowed' : '' ?>"
                                   placeholder="María García (opcional)"
                                   <?= $campo_readonly ? 'readonly' : '' ?>>
                            <?php if ($campo_readonly): ?>
                                <p class="text-xs text-slate-600 mt-1">
                                    🔒 Este representante fue asignado automáticamente desde tu enlace de referido
                                </p>
                            <?php endif; ?>
                        </div>

                               <input type="hidden"
                                   name="nombre_medico"
                                   id="nombre_medico"
                                   value="<?= htmlspecialchars($cliente['nombre_medico'] ?? $pedido['nombre_medico'] ?? '') ?>">
                               <input type="hidden"
                                   name="telefono_medico"
                                   id="telefono_medico"
                                   value="<?= htmlspecialchars($cliente['telefono_medico'] ?? $pedido['telefono_medico'] ?? '') ?>">
                        
                        <label class="flex items-start gap-3 p-4 rounded-xl border border-slate-200 bg-slate-50 cursor-pointer">
                            <input type="checkbox"
                                   id="checkTerminosPrivacidad"
                                   class="mt-1 w-5 h-5 accent-teal-600 rounded"
                                   onchange="validarFormularioCompleto()">
                            <span class="text-sm text-slate-700 leading-relaxed">
                                He leído y acepto los
                                <?php if (!empty($terminos_url)): ?>
                                    <a href="<?= htmlspecialchars($terminos_url) ?>" target="_blank" rel="noopener noreferrer" class="text-terracotta-600 font-semibold underline">Términos y Condiciones</a>
                                <?php else: ?>
                                    Términos y Condiciones
                                <?php endif; ?>
                                y el
                                <?php if (!empty($aviso_url)): ?>
                                    <a href="<?= htmlspecialchars($aviso_url) ?>" target="_blank" rel="noopener noreferrer" class="text-terracotta-600 font-semibold underline">Aviso de Privacidad</a>
                                <?php else: ?>
                                    Aviso de Privacidad
                                <?php endif; ?>.
                            </span>
                        </label>
                    </div>
                </form>
            </div>
            
            <!-- Sección de Facturación Electrónica -->
            <div class="card rounded-2xl shadow-lg p-6">
                <div class="mb-4">
                    <label class="flex items-center gap-3 cursor-pointer group">
                        <input type="checkbox" 
                               id="checkRequiereFactura" 
                               onchange="toggleFacturacion()"
                               class="w-5 h-5 text-terracotta-500 rounded focus:ring-2 focus:ring-terracotta-500">
                        <span class="text-slate-900 font-semibold group-hover:text-terracotta-600 transition">
                            🧾 Requiero Factura Electrónica
                        </span>
                    </label>
                </div>
                
                <!-- Formulario de Datos Fiscales (oculto por defecto) -->
                <div id="panelFacturacion" class="hidden">
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4">
                        <p class="text-sm text-blue-800">
                            ℹ️ Completa tus datos fiscales. El proveedor generará tu factura electrónica y la recibirás en tu correo.
                        </p>
                    </div>
                    
                    <form id="formDatosFiscales" onsubmit="guardarDatosFiscales(event)">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2 flex items-center gap-2">
                                    🆔 RFC
                                    <span id="alerta-rfc" class="hidden text-red-500 text-xs">⚠️ Campo obligatorio</span>
                                </label>
                                <input type="text" 
                                       name="rfc" 
                                       id="rfc" 
                                       maxlength="13"
                                       value="<?= htmlspecialchars($cliente['rfc'] ?? '') ?>"
                                       class="input-field w-full px-4 py-3 rounded-xl uppercase" 
                                       placeholder="XAXX010101000"
                                       oninput="this.value = this.value.toUpperCase()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2 flex items-center gap-2">
                                    🏢 Razón Social
                                    <span id="alerta-razon_social" class="hidden text-red-500 text-xs">⚠️ Campo obligatorio</span>
                                </label>
                                <input type="text" 
                                       name="razon_social" 
                                       id="razon_social"
                                       value="<?= htmlspecialchars($cliente['razon_social'] ?? '') ?>"
                                       class="input-field w-full px-4 py-3 rounded-xl" 
                                       placeholder="Nombre completo o razón social">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        📮 Código Postal <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           name="codigo_postal" 
                                           id="codigo_postal"
                                           maxlength="5"
                                           value="<?= htmlspecialchars($cliente['codigo_postal'] ?? '') ?>"
                                           class="input-field w-full px-4 py-3 rounded-xl" 
                                           placeholder="12345"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        📋 Régimen Fiscal
                                    </label>
                                    <select name="regimen_fiscal" 
                                            id="regimen_fiscal" 
                                            class="input-field w-full px-4 py-3 rounded-xl">
                                        <option value="">Seleccionar</option>
                                        <option value="601" <?= ($cliente['regimen_fiscal'] ?? '') === '601' ? 'selected' : '' ?>>601 - General de Ley Personas Morales</option>
                                        <option value="603" <?= ($cliente['regimen_fiscal'] ?? '') === '603' ? 'selected' : '' ?>>603 - Personas Morales con Fines no Lucrativos</option>
                                        <option value="605" <?= ($cliente['regimen_fiscal'] ?? '') === '605' ? 'selected' : '' ?>>605 - Sueldos y Salarios e Ingresos Asimilados a Salarios</option>
                                        <option value="606" <?= ($cliente['regimen_fiscal'] ?? '') === '606' ? 'selected' : '' ?>>606 - Arrendamiento</option>
                                        <option value="607" <?= ($cliente['regimen_fiscal'] ?? '') === '607' ? 'selected' : '' ?>>607 - Régimen de Enajenación o Adquisición de Bienes</option>
                                        <option value="608" <?= ($cliente['regimen_fiscal'] ?? '') === '608' ? 'selected' : '' ?>>608 - Demás ingresos</option>
                                        <option value="610" <?= ($cliente['regimen_fiscal'] ?? '') === '610' ? 'selected' : '' ?>>610 - Residentes en el Extranjero sin Establecimiento Permanente en México</option>
                                        <option value="611" <?= ($cliente['regimen_fiscal'] ?? '') === '611' ? 'selected' : '' ?>>611 - Ingresos por Dividendos (socios y accionistas)</option>
                                        <option value="612" <?= ($cliente['regimen_fiscal'] ?? '') === '612' ? 'selected' : '' ?>>612 - Personas Físicas con Actividades Empresariales y Profesionales</option>
                                        <option value="614" <?= ($cliente['regimen_fiscal'] ?? '') === '614' ? 'selected' : '' ?>>614 - Ingresos por intereses</option>
                                        <option value="615" <?= ($cliente['regimen_fiscal'] ?? '') === '615' ? 'selected' : '' ?>>615 - Régimen de los ingresos por obtención de premios</option>
                                        <option value="616" <?= ($cliente['regimen_fiscal'] ?? '') === '616' ? 'selected' : '' ?>>616 - Sin obligaciones fiscales</option>
                                        <option value="620" <?= ($cliente['regimen_fiscal'] ?? '') === '620' ? 'selected' : '' ?>>620 - Sociedades Cooperativas de Producción que optan por diferir sus ingresos</option>
                                        <option value="621" <?= ($cliente['regimen_fiscal'] ?? '') === '621' ? 'selected' : '' ?>>621 - Incorporación Fiscal</option>
                                        <option value="622" <?= ($cliente['regimen_fiscal'] ?? '') === '622' ? 'selected' : '' ?>>622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras</option>
                                        <option value="623" <?= ($cliente['regimen_fiscal'] ?? '') === '623' ? 'selected' : '' ?>>623 - Opcional para Grupos de Sociedades</option>
                                        <option value="624" <?= ($cliente['regimen_fiscal'] ?? '') === '624' ? 'selected' : '' ?>>624 - Coordinados</option>
                                        <option value="625" <?= ($cliente['regimen_fiscal'] ?? '') === '625' ? 'selected' : '' ?>>625 - Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas</option>
                                        <option value="626" <?= ($cliente['regimen_fiscal'] ?? '') === '626' ? 'selected' : '' ?>>626 - Régimen Simplificado de Confianza</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    📄 Uso de CFDI <span class="text-red-500">*</span>
                                </label>
                                <select name="uso_cfdi" 
                                        id="uso_cfdi" 
                                        class="input-field w-full px-4 py-3 rounded-xl">
                                    <option value="G01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'G01' ? 'selected' : '' ?>>G01 - Adquisición de mercancías</option>
                                    <option value="G02" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'G02' ? 'selected' : '' ?>>G02 - Devoluciones, descuentos o bonificaciones</option>
                                    <option value="G03" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'G03' ? 'selected' : '' ?>>G03 - Gastos en general</option>
                                    <option value="I01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I01' ? 'selected' : '' ?>>I01 - Construcciones</option>
                                    <option value="I02" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I02' ? 'selected' : '' ?>>I02 - Mobiliario y equipo de oficina por inversiones</option>
                                    <option value="I03" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I03' ? 'selected' : '' ?>>I03 - Equipo de transporte</option>
                                    <option value="I04" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I04' ? 'selected' : '' ?>>I04 - Equipo de cómputo y accesorios</option>
                                    <option value="I05" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I05' ? 'selected' : '' ?>>I05 - Dados, troqueles, moldes, matrices y herramental</option>
                                    <option value="I06" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I06' ? 'selected' : '' ?>>I06 - Comunicaciones telefónicas</option>
                                    <option value="I07" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I07' ? 'selected' : '' ?>>I07 - Comunicaciones satelitales</option>
                                    <option value="I08" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'I08' ? 'selected' : '' ?>>I08 - Otra maquinaria y equipo</option>
                                    <option value="D01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D01' ? 'selected' : '' ?>>D01 - Honorarios médicos, dentales y gastos hospitalarios</option>
                                    <option value="D02" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D02' ? 'selected' : '' ?>>D02 - Gastos médicos por incapacidad o discapacidad</option>
                                    <option value="D03" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D03' ? 'selected' : '' ?>>D03 - Gastos funerales</option>
                                    <option value="D04" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D04' ? 'selected' : '' ?>>D04 - Donativos</option>
                                    <option value="D05" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D05' ? 'selected' : '' ?>>D05 - Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)</option>
                                    <option value="D06" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D06' ? 'selected' : '' ?>>D06 - Aportaciones voluntarias al SAR</option>
                                    <option value="D07" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D07' ? 'selected' : '' ?>>D07 - Primas por seguros de gastos médicos</option>
                                    <option value="D08" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D08' ? 'selected' : '' ?>>D08 - Gastos de transportación escolar obligatoria</option>
                                    <option value="D09" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D09' ? 'selected' : '' ?>>D09 - Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones</option>
                                    <option value="D10" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'D10' ? 'selected' : '' ?>>D10 - Pagos por servicios educativos (colegiaturas)</option>
                                    <option value="S01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'S01' ? 'selected' : '' ?>>S01 - Sin efectos fiscales</option>
                                    <option value="CP01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'CP01' ? 'selected' : '' ?>>CP01 - Pagos</option>
                                    <option value="CN01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'CN01' ? 'selected' : '' ?>>CN01 - Nómina</option>
                                    <option value="P01" <?= ($cliente['uso_cfdi'] ?? 'G03') === 'P01' ? 'selected' : '' ?>>P01 - Por definir</option>
                                </select>
                            </div>

                            <!-- Notificación de factura (solo visible dentro del panel de facturación) -->
                            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                                <input type="checkbox" name="notif_factura" id="notif_factura" value="1"
                                       class="w-4 h-4 accent-teal-600 rounded"
                                       <?= (int)($cliente['notif_factura'] ?? 1) ? 'checked' : '' ?>>
                                <span>📧 Recibir factura electrónica por correo</span>
                            </label>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">📄 Constancia fiscal <span class="text-slate-400 font-normal text-xs">(opcional · PDF o imagen)</span></label>
                                <div id="pp-constancia-drop"
                                     onclick="document.getElementById('pp_constancia_fiscal').click()"
                                     class="border-2 border-dashed border-slate-300 rounded-xl p-4 text-center cursor-pointer transition hover:border-terracotta-400 hover:bg-cream-50">
                                    <div id="pp-constancia-placeholder" class="text-slate-400 text-sm">📄 Toca para seleccionar archivo</div>
                                    <div id="pp-constancia-filename" class="hidden text-sm font-semibold" style="color:#E07856"></div>
                                </div>
                                <input id="pp_constancia_fiscal" name="constancia_fiscal" type="file" accept=".pdf,image/*" class="hidden"
                                       onchange="onPPConstanciaChange(this)">
                            </div>

                            <div id="alertaFacturacion" class="hidden bg-amber-50 border border-amber-200 rounded-xl p-4">
                                <p class="text-sm text-amber-800">
                                    ⚠️ <strong>Faltan datos fiscales.</strong> Por favor complétalos o el proveedor te los solicitará.
                                </p>
                            </div>
                            
                            <button type="submit" class="bg-terracotta-500 hover:bg-terracotta-600 text-white w-full py-3 rounded-xl font-medium transition">
                                💾 Guardar Datos Fiscales para Futuros Pedidos
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
        <?php endif; // end !$modo_rep — Columna Derecha ?>
        
    </div>

    <!-- ============================================
         BOTÓN FINAL DE CONFIRMACIÓN
    ============================================ -->
    <?php if (!$modo_rep): ?>
    <div id="seccionConfirmacion" class="mt-8 hidden">
        <div class="card rounded-2xl shadow-2xl p-8 bg-gradient-to-br from-white to-cream-100 border-2 border-terracotta-300">
            
            <!-- Checklist de validación -->
            <div class="mb-6">
                <h3 class="text-lg font-bold text-slate-900 mb-4">📋 Verificación antes de confirmar:</h3>
                <div class="space-y-2 text-sm">
                    <div id="check-metodo" class="flex items-center gap-2 text-slate-500">
                        <span class="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center">✗</span>
                        <span>Método de pago seleccionado</span>
                    </div>
                    <div id="check-comprobante" class="flex items-center gap-2 text-slate-500">
                        <span class="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center">✗</span>
                        <span>Comprobante adjunto</span>
                    </div>
                    <div id="check-envio" class="flex items-center gap-2 text-slate-500">
                        <span class="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center">✗</span>
                        <span>Términos y privacidad aceptados</span>
                    </div>
                    <div id="check-factura" class="flex items-center gap-2 text-slate-500">
                        <span class="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center">✓</span>
                        <span>Datos de factura (opcional)</span>
                    </div>
                </div>
            </div>

            <!-- Alerta de advertencia -->
            <div class="bg-amber-50 border-2 border-amber-300 rounded-xl p-4 mb-6">
                <p class="text-sm text-amber-900 font-semibold mb-2">
                    ⚠️ Al confirmar este proceso:
                </p>
                <ul class="text-xs text-amber-800 space-y-1 ml-4 list-disc">
                    <li>Tu comprobante de pago será enviado para revisión</li>
                    <li>Los datos de envío serán registrados en tu pedido</li>
                    <li>Recibirás una confirmación por WhatsApp/Email</li>
                    <li><strong>No podrás modificar estos datos después</strong></li>
                </ul>
            </div>

            <!-- Botón de confirmación final -->
            <button type="button" 
                    id="btnConfirmarPago" 
                    onclick="confirmarProcesoPago()" 
                    disabled
                    class="w-full py-5 rounded-2xl font-bold text-lg shadow-xl transition-all transform disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                    style="background:#0F172A; color:white;">
                🚀 CONFIRMAR Y PROCESAR PAGO
            </button>
            
            <p class="text-xs text-slate-500 text-center mt-4">
                💡 Asegúrate de que todos los datos estén correctos antes de confirmar
            </p>
        </div>
    </div>
    <?php endif; // !$modo_rep — seccionConfirmacion ?>

    <?php if ($modo_rep): ?>
    <!-- Rep: sticky bottom confirm button -->
    <div class="confirm-btn-rep">
        <button type="button"
                id="btnConfirmarPagoRep"
                onclick="confirmarPagoRep()"
                disabled
                class="w-full py-4 rounded-2xl font-bold text-lg shadow-xl transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                style="background:#126c6a; color:white; font-size:18px;">
            ✓ CONFIRMAR COBRO
        </button>
    </div>
    <?php endif; ?>

</div>

<script>
let metodoSeleccionado = null;
const MODO_REP = <?= $modo_rep ? 'true' : 'false' ?>;
const REP_EN_TIENDA = <?= $rep_en_tienda ? 'true' : 'false' ?>;
const EFECTIVO_REQUIERE_COMPROBANTE = <?= $efectivo_requiere_comprobante ? 'true' : 'false' ?>;
const PEDIDO_TOTAL_REP = '<?= number_format($pedido['total'], 2) ?>';
let comprobanteSubido = false;
let datosEnvioConfirmados = false;
let requiereFactura = false;

// Cargar datos de métodos de pago desde la base de datos
const datosBancarios = {
    <?php 
    $metodosJS = [];
    foreach ($metodosActivos as $metodo):
        $key = $metodo['metodo'];
        $datosHTML = '<div class="text-sm space-y-2">';
        
        if ($metodo['metodo'] === 'transferencia') {
            // Datos de transferencia bancaria
            if (!empty($metodo['banco'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Banco:</span><span class="font-semibold">' . htmlspecialchars($metodo['banco']) . '</span></div>';
            }
            if (!empty($metodo['titular'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Titular:</span><span class="font-semibold">' . htmlspecialchars($metodo['titular']) . '</span></div>';
            }
            if (!empty($metodo['cuenta'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Cuenta:</span><span class="font-semibold font-mono">' . htmlspecialchars($metodo['cuenta']) . '</span></div>';
            }
            if (!empty($metodo['clabe'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">CLABE:</span><span class="font-semibold font-mono">' . htmlspecialchars($metodo['clabe']) . '</span></div>';
            }
            if (!empty($metodo['rfc_empresa'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">RFC:</span><span class="font-semibold font-mono">' . htmlspecialchars($metodo['rfc_empresa']) . '</span></div>';
            }
            // Referencia = número de pedido
            $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Referencia:</span><span class="font-semibold font-mono">' . $pedido_id . '</span></div>';
        } else if ($metodo['metodo'] === 'oxxo' || $metodo['metodo'] === 'tienda') {
            // Datos de OXXO/Tienda
            if (!empty($metodo['banco'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Banco:</span><span class="font-semibold">' . htmlspecialchars($metodo['banco']) . '</span></div>';
            }
            if (!empty($metodo['numero_tarjeta'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Número de Tarjeta:</span><span class="font-semibold font-mono">' . htmlspecialchars($metodo['numero_tarjeta']) . '</span></div>';
            }
            // Referencia = número de pedido
            $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Referencia:</span><span class="font-semibold font-mono">' . $pedido_id . '</span></div>';
            if (!empty($metodo['beneficiario'])) {
                $datosHTML .= '<div class="flex justify-between"><span class="text-slate-600">Beneficiario:</span><span class="font-semibold">' . htmlspecialchars($metodo['beneficiario']) . '</span></div>';
            }
        }
        
        // Instrucciones si existen
        if (!empty($metodo['instrucciones'])) {
            $datosHTML .= '<p class="text-xs text-slate-600 mt-3 pt-3 border-t border-slate-200">💡 ' . nl2br(htmlspecialchars($metodo['instrucciones'])) . '</p>';
        }
        
        $datosHTML .= '</div>';

        // Build copyRows for rep mode: single copy-all button
        $copyRowsHTML = '';
        if ($modo_rep) {
            $copyFields = [];
            if ($key === 'transferencia') {
                if (!empty($metodo['banco']))     $copyFields[] = ['Banco',   $metodo['banco']];
                if (!empty($metodo['titular']))   $copyFields[] = ['Titular', $metodo['titular']];
                if (!empty($metodo['cuenta']))    $copyFields[] = ['Cuenta',  $metodo['cuenta']];
                if (!empty($metodo['clabe']))     $copyFields[] = ['CLABE',   $metodo['clabe']];
                if (!empty($metodo['rfc_empresa'])) $copyFields[] = ['RFC',  $metodo['rfc_empresa']];
                $copyFields[] = ['Referencia', (string)$pedido_id];
            } elseif ($key === 'oxxo' || $key === 'tienda') {
                if (!empty($metodo['banco']))          $copyFields[] = ['Banco',        $metodo['banco']];
                if (!empty($metodo['numero_tarjeta'])) $copyFields[] = ['Tarjeta',      $metodo['numero_tarjeta']];
                $copyFields[] = ['Referencia', (string)$pedido_id];
                if (!empty($metodo['beneficiario']))   $copyFields[] = ['Beneficiario', $metodo['beneficiario']];
            }
            if (!empty($copyFields)) {
                $allText = implode("\n", array_map(fn($f) => $f[0] . ': ' . $f[1], $copyFields));
                $allText .= "\nTotal: $" . number_format($pedido['total'], 2);
                $copyRowsHTML = '<button type="button" class="w-full py-3 rounded-xl font-bold text-white text-sm" '
                    . 'style="background:#101820" data-copyall="' . htmlspecialchars($allText, ENT_QUOTES) . '" onclick="copiarTextoData(this)">'
                    . '📋 Copiar</button>';
            }
        }
        
        $metodosJS[] = $key . ': {
        titulo: \'' . addslashes($metodo['nombre_display']) . '\',
        icono: \'💳\',
        datos: `' . $datosHTML . '`,
        copyRows: `' . $copyRowsHTML . '`
    }';
    endforeach;
    
    echo implode(",\n    ", $metodosJS);
    ?>
};

function seleccionarMetodo(metodo) {
    // Liga de pago tiene su propio panel — delegar siempre
    if (metodo === 'liga_pago') {
        seleccionarLigaPago();
        return;
    }

    metodoSeleccionado = metodo;
    
    // Obtener lista de métodos disponibles dinámicamente
    const metodosDisponibles = Object.keys(datosBancarios);
    
    // Actualizar botones de métodos bancarios
    metodosDisponibles.forEach(m => {
        const btn = document.getElementById(`btn-${m}`);
        const check = document.getElementById(`check-${m}`);
        
        if (btn && check) {
            if (m === metodo) {
                btn.classList.add('border-terracotta-400', 'bg-terracotta-50');
                check.classList.add('bg-terracotta-500', 'border-terracotta-500');
                check.innerHTML = '<svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>';
            } else {
                btn.classList.remove('border-terracotta-400', 'bg-terracotta-50');
                check.classList.remove('bg-terracotta-500', 'border-terracotta-500');
                check.innerHTML = '';
            }
        }
    });
    
    // Resetear botón de Liga de Pago
    const btnLiga = document.getElementById('btn-liga_pago');
    const checkLiga = document.getElementById('check-liga_pago');
    if (btnLiga && checkLiga) {
        btnLiga.classList.remove('border-terracotta-400', 'bg-terracotta-50', 'border-purple-500', 'bg-purple-50');
        checkLiga.classList.remove('bg-terracotta-500', 'border-terracotta-500', 'bg-purple-600', 'border-purple-600');
        checkLiga.style.background = '';
        checkLiga.style.borderColor = '';
        checkLiga.style.display = '';
        checkLiga.style.alignItems = '';
        checkLiga.style.justifyContent = '';
        checkLiga.innerHTML = '';
    }
    
    // Ocultar panel de Liga de Pago
    document.getElementById('panel-liga-pago').classList.add('hidden');

    // Ocultar panel de Efectivo (rep mode)
    const panelEfectivo = document.getElementById('panel-efectivo');
    if (panelEfectivo) panelEfectivo.classList.add('hidden');

    // Ocultar panel de PayPal
    const panelPaypal = document.getElementById('panel-paypal');
    if (panelPaypal) panelPaypal.classList.add('hidden');

    // Ocultar panel de Mercado Pago
    const panelMp = document.getElementById('panel-mercado-pago');
    if (panelMp) panelMp.classList.add('hidden');

    // Ocultar panel de EcartPay
    const panelEp = document.getElementById('panel-ecartpay');
    if (panelEp) panelEp.classList.add('hidden');

    // Ocultar panel de OpenPay
    const panelOp = document.getElementById('panel-openpay');
    if (panelOp) panelOp.classList.add('hidden');
    
    // Si es Efectivo (rep mode), mostrar panel efectivo
    if (metodo === 'efectivo') {
        if (!MODO_REP && EFECTIVO_REQUIERE_COMPROBANTE) {
            document.getElementById('metodo_pago_selected').value = metodo;

            const panel = document.getElementById('panel-datos-bancarios');
            const titulo = document.getElementById('titulo-metodo');
            const icono = document.getElementById('icono-metodo');
            const datos = document.getElementById('datos-bancarios');
            const repCopyPanel = document.getElementById('rep-copy-panel');

            if (titulo) titulo.textContent = 'Pago en Efectivo';
            if (icono) icono.textContent = '💵';
            if (datos) {
                datos.innerHTML = `
                    <div class="text-sm space-y-2">
                        <p class="text-slate-700 font-semibold">Sube el comprobante del deposito en efectivo para continuar.</p>
                        <p class="text-slate-600">Monto a comprobar: <strong>$${PEDIDO_TOTAL_REP}</strong></p>
                    </div>`;
            }
            if (repCopyPanel) repCopyPanel.classList.add('hidden');
            if (panel) {
                panel.classList.remove('hidden');
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            comprobanteSubido = false;
            const checkComprobanteLabel = document.querySelector('#check-comprobante span:last-child');
            if (checkComprobanteLabel) checkComprobanteLabel.textContent = 'Comprobante adjunto';
            validarFormularioCompleto();
            return;
        }

        const panelEf = document.getElementById('panel-efectivo');
        if (panelEf) {
            panelEf.classList.remove('hidden');
            panelEf.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        document.getElementById('metodo_pago_selected').value = metodo;
        document.getElementById('panel-datos-bancarios').classList.add('hidden');
        const repCopyPanel = document.getElementById('rep-copy-panel');
        if (repCopyPanel) repCopyPanel.classList.add('hidden');
        comprobanteSubido = true; // efectivo no necesita comprobante
        if (MODO_REP) {
            const btn = document.getElementById('btnConfirmarPagoRep');
            if (btn) { btn.disabled = false; btn.style.background = '#126c6a'; }
        }
        validarFormularioCompleto();
        return;
    }

    // Si es PayPal, mostrar panel especial
    if (metodo === 'paypal') {
        document.getElementById('panel-paypal').classList.remove('hidden');
        document.getElementById('panel-paypal').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById('metodo_pago_selected').value = metodo;
        
        // Ocultar panel de datos bancarios ya que PayPal no necesita comprobante manual
        document.getElementById('panel-datos-bancarios').classList.add('hidden');
        
        // Cambiar label del checklist a PayPal
        const checkComprobanteLabel = document.querySelector('#check-comprobante span:last-child');
        if (checkComprobanteLabel) checkComprobanteLabel.textContent = 'Pago con PayPal completado';
        
        validarFormularioCompleto();
        return;
    }

    // Si es Mercado Pago, mostrar panel especial
    if (metodo === 'mercado_pago') {
        if (panelMp) {
            panelMp.classList.remove('hidden');
            panelMp.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        document.getElementById('metodo_pago_selected').value = metodo;
        document.getElementById('panel-datos-bancarios').classList.add('hidden');

        const checkComprobanteLabel = document.querySelector('#check-comprobante span:last-child');
        if (checkComprobanteLabel) checkComprobanteLabel.textContent = 'Pago con Mercado Pago completado';

        validarFormularioCompleto();
        return;
    }

    // Si es EcartPay, mostrar panel especial
    if (metodo === 'ecartpay') {
        if (panelEp) {
            panelEp.classList.remove('hidden');
            panelEp.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        document.getElementById('metodo_pago_selected').value = metodo;
        document.getElementById('panel-datos-bancarios').classList.add('hidden');

        const checkEpLabel = document.querySelector('#check-comprobante span:last-child');
        if (checkEpLabel) checkEpLabel.textContent = 'Pago con EcartPay completado';

        validarFormularioCompleto();
        return;
    }

    // Si es OpenPay, mostrar panel de tarjeta inline
    if (metodo === 'openpay') {
        if (panelOp) {
            panelOp.classList.remove('hidden');
            panelOp.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        document.getElementById('metodo_pago_selected').value = metodo;
        document.getElementById('panel-datos-bancarios').classList.add('hidden');

        const checkOpLabel = document.querySelector('#check-comprobante span:last-child');
        if (checkOpLabel) checkOpLabel.textContent = 'Pago con OpenPay completado';

        validarFormularioCompleto();
        return;
    }
    const checkComprobanteLabel = document.querySelector('#check-comprobante span:last-child');
    if (checkComprobanteLabel) checkComprobanteLabel.textContent = 'Comprobante adjunto';
    
    // Mostrar datos bancarios
    const panel = document.getElementById('panel-datos-bancarios');
    const titulo = document.getElementById('titulo-metodo');
    const icono = document.getElementById('icono-metodo');
    const datos = document.getElementById('datos-bancarios');
    
    if (datosBancarios[metodo]) {
        titulo.textContent = datosBancarios[metodo].titulo;
        icono.textContent = datosBancarios[metodo].icono;
        datos.innerHTML = datosBancarios[metodo].datos;
        document.getElementById('metodo_pago_selected').value = metodo;
    }
    
    panel.classList.remove('hidden');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // Rep mode: mostrar botones de copiar
    if (MODO_REP && datosBancarios[metodo] && datosBancarios[metodo].copyRows) {
        const repCopyPanel = document.getElementById('rep-copy-panel');
        if (repCopyPanel) {
            repCopyPanel.classList.remove('hidden');
            repCopyPanel.innerHTML = datosBancarios[metodo].copyRows;
        }
    } else {
        const repCopyPanel = document.getElementById('rep-copy-panel');
        if (repCopyPanel) repCopyPanel.classList.add('hidden');
    }

    // Rep mode: habilitar botón confirm si hay método seleccionado (comprobante no requerido hasta después)
    if (MODO_REP) {
        const btn = document.getElementById('btnConfirmarPagoRep');
        if (btn) { btn.disabled = false; btn.style.background = '#126c6a'; }
    }

    // Actualizar checklist
    validarFormularioCompleto();
}

// ── Rep en tienda: confirmar pago de liga ────────────────────────────────
function previsualizarCompTienda(input) {
    const p = document.getElementById('comp-tienda-nombre');
    if (!input.files[0]) return;
    p.textContent = '\uD83D\uDCCE ' + input.files[0].name;
    p.classList.remove('hidden');
    // Copiar archivo al input principal que lee confirmarProcesoPago()
    const dt = new DataTransfer();
    dt.items.add(input.files[0]);
    document.getElementById('comprobante').files = dt.files;
    comprobanteSubido = true;
    mostrarAlerta('\u2705 Comprobante listo. Confirma todos los datos abajo.', 'success');
    validarFormularioCompleto();
    const sec = document.getElementById('seccionConfirmacion');
    if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function abrirCamaraTienda() {
    const input = document.getElementById('comp-tienda');
    input.setAttribute('capture', 'environment');
    input.click();
}

function confirmarPagoLigaTienda(btn) {
    btn.disabled = true;
    btn.innerHTML = '\u23F3 Procesando...';

    const fd = new FormData();
    fd.append('action', 'subir_comprobante');
    fd.append('pedido_id', <?= $pedido['id'] ?>);
    fd.append('telefono', '<?= $telefono ?>');
    fd.append('metodo_pago', 'liga_pago');
    fd.append('modo', 'rep');

    const file = document.getElementById('comp-tienda').files[0];
    if (file) fd.append('comprobante', file);

    fetch('<?= basename($_SERVER['PHP_SELF']) ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.innerHTML = '\u2705 Confirmado';
                btn.classList.replace('bg-green-600', 'bg-slate-400');
                btn.classList.remove('hover:bg-green-700');
                document.getElementById('confirm-liga-tienda-ok').classList.remove('hidden');
            } else {
                alert('\u274C ' + (data.message || 'Error al confirmar'));
                btn.disabled = false;
                btn.innerHTML = '\u2705 Confirmar pago recibido';
            }
        })
        .catch(() => {
            alert('Error de red al confirmar el pago');
            btn.disabled = false;
            btn.innerHTML = '\u2705 Confirmar pago recibido';
        });
}

function subirComprobante(e) {
    e.preventDefault();
    
    // Solo marcar como comprobante listo (no enviar aún)
    const file = document.getElementById('comprobante').files[0];
    if (!file) {
        mostrarAlerta('Por favor selecciona un comprobante', 'error');
        return;
    }
    
    comprobanteSubido = true;
    mostrarAlerta('✅ Comprobante listo. Confirma todos los datos abajo.', 'success');
    
    // Actualizar checklist
    validarFormularioCompleto();
    
    // Scroll a la sección de confirmación
    const seccionConfirmacion = document.getElementById('seccionConfirmacion');
    seccionConfirmacion.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Nueva función que se ejecuta al confirmar todo el proceso
function confirmarProcesoPago() {
    // Validar datos de envío obligatorios
    const calle = document.getElementById('calle').value.trim();
    const numero = document.getElementById('numero').value.trim();
    const cp = document.getElementById('cp').value.trim();
    
    if (!calle || !numero || !cp) {
        mostrarAlerta('⚠️ Por favor completa los datos de envío obligatorios (Calle, Número y CP) antes de procesar el pago.', 'error');
        
        // Scroll al formulario de envío
        document.getElementById('formEnvio').scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const checkTerminos = document.getElementById('checkTerminosPrivacidad');
    const aceptaTerminos = checkTerminos ? !!checkTerminos.checked : false;
    if (!aceptaTerminos) {
        mostrarAlerta('⚠️ Debes aceptar los Términos y Condiciones y el Aviso de Privacidad para continuar.', 'error');
        const chk = document.getElementById('checkTerminosPrivacidad');
        if (chk) chk.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    // Validar datos fiscales si requiere factura
    const requiereFactura = document.getElementById('checkRequiereFactura').checked;
    if (requiereFactura) {
        const rfc = document.getElementById('rfc').value.trim();
        const razonSocial = document.getElementById('razon_social').value.trim();
        
        if (!rfc || !razonSocial) {
            mostrarAlerta('⚠️ Por favor completa los datos fiscales obligatorios (RFC y Razón Social) antes de procesar el pago.', 'error');
            document.getElementById('panelFacturacion').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
    }

    const tipoClienteSel = document.querySelector('input[name="tipo_cliente"]:checked')?.value || 'medico';
    const especialidad = document.getElementById('especialidad_pp').value.trim();
    if (tipoClienteSel === 'medico' && !especialidad) {
        mostrarAlerta('⚠️ La especialidad es obligatoria para procesar el pago.', 'error');
        const bloqueEsp = document.getElementById('pp-bloque-especialidad');
        if (bloqueEsp) bloqueEsp.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    const formData = new FormData(document.getElementById('formComprobante'));
    formData.append('action', 'subir_comprobante');
    formData.append('pedido_id', <?= $pedido['id'] ?>);
    formData.append('telefono', '<?= $telefono ?>');
    formData.append('requiere_factura', requiereFactura ? '1' : '0');
    
    // Agregar datos de envío
    const nombreClienteInput = document.getElementById('nombre_cliente');
    const nombreClienteHidden = document.getElementById('nombre_hidden');
    const nombreCliente = nombreClienteInput
        ? nombreClienteInput.value.trim()
        : (nombreClienteHidden ? nombreClienteHidden.value.trim() : '');
    formData.append('nombre', nombreCliente);

    formData.append('calle', document.getElementById('calle').value.trim());
    formData.append('numero', document.getElementById('numero').value.trim());
    formData.append('colonia', document.getElementById('colonia').value.trim());
    formData.append('cp', document.getElementById('cp').value.trim());
    formData.append('estado', document.getElementById('estado').value.trim());
    formData.append('ciudad', document.getElementById('ciudad').value.trim());
    formData.append('referencias', document.getElementById('referencias').value.trim());
    formData.append('quien_recibe', document.getElementById('quien_recibe').value.trim());
    
    // Agregar datos adicionales (médico y representante)
    formData.append('nombre_medico', document.getElementById('nombre_medico').value.trim());
    formData.append('telefono_medico', document.getElementById('telefono_medico').value.trim());
    formData.append('nombre_representante', document.getElementById('nombre_representante').value.trim());
    formData.append('tipo_cliente', tipoClienteSel);
    formData.append('especialidad', especialidad);
    
    // Siempre enviar email para notificaciones (aunque no requiera factura)
    formData.append('email_factura', document.getElementById('email_factura').value.trim());

    // Agregar datos fiscales si requiere factura
    if (requiereFactura) {
        formData.append('rfc', document.getElementById('rfc').value.trim());
        formData.append('razon_social', document.getElementById('razon_social').value.trim());
        formData.append('codigo_postal', document.getElementById('codigo_postal').value.trim());
        formData.append('uso_cfdi', document.getElementById('uso_cfdi').value);
        formData.append('regimen_fiscal', document.getElementById('regimen_fiscal').value);
    }
    
    const btn = document.getElementById('btnConfirmarPago');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Procesando pago...';
    
    fetch('procesar-pago.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Mostrar mensaje de éxito
            btn.innerHTML = '✅ ¡Pago Procesado!';
            btn.style.background = 'linear-gradient(135deg, #10B981 0%, #059669 100%)';
            
            mostrarAlerta('✅ Pago procesado. Redirigiendo...', 'success');
            
            setTimeout(() => {
                window.location.href = 'seguimiento.php?telefono=<?= $telefono ?>';
            }, 2000);
        } else {
            mostrarAlerta(data.message, 'error');
            btn.disabled = false;
            btn.innerHTML = btnText;
        }
    })
    .catch(error => {
        mostrarAlerta('Error al procesar el pago', 'error');
        btn.disabled = false;
        btn.innerHTML = btnText;
    });
}

function editarNombre() {
    // Eliminar el input hidden con el valor viejo para evitar duplicado al enviar
    const hidden = document.getElementById('nombre_hidden');
    if (hidden) hidden.remove();

    const nombreDiv = event.target.closest('.bg-slate-50');
    nombreDiv.innerHTML = `
        <div class="bg-purple-50 border-2 border-purple-200 rounded-xl p-4">
            <label class="block text-sm font-medium text-purple-900 mb-2">
                👤 Nombre
            </label>
            <input type="text" name="nombre" id="nombre_cliente"
                   value="<?= htmlspecialchars($cliente['nombre'] ?? '') ?>"
                   class="input-field w-full px-4 py-3 rounded-xl border-purple-300" 
                   placeholder="Tu nombre">
            <p class="text-xs text-purple-700 mt-2">
                El nombre quedará guardado al confirmar y procesar el pago
            </p>
        </div>
    `;
    document.getElementById('nombre_cliente').focus();
}

function onPPEspChange(sel) {
    const otro = document.getElementById('especialidad_pp_otro');
    const hid  = document.getElementById('especialidad_pp');
    if (sel.value === '__otro__') {
        if (otro) { otro.style.display = ''; otro.focus(); }
        if (hid) hid.value = '';
    } else {
        if (otro) otro.style.display = 'none';
        if (hid) hid.value = sel.value;
    }
}

function onPPConstanciaChange(input) {
    const ph  = document.getElementById('pp-constancia-placeholder');
    const fn  = document.getElementById('pp-constancia-filename');
    const drp = document.getElementById('pp-constancia-drop');
    if (input.files && input.files[0]) {
        const f = input.files[0];
        const size = f.size > 1048576 ? (f.size/1048576).toFixed(1)+' MB' : Math.round(f.size/1024)+' KB';
        fn.textContent = '\u2713 ' + f.name + ' (' + size + ')';
        fn.classList.remove('hidden');
        ph.classList.add('hidden');
        drp.style.borderColor = '#E07856';
        drp.style.background  = 'rgba(224,120,86,.04)';
    } else {
        fn.classList.add('hidden');
        ph.classList.remove('hidden');
        drp.style.borderColor = '';
        drp.style.background  = '';
    }
}

function toggleTipoCliente(radio) {
    ['lbl-medico', 'lbl-paciente'].forEach(id => {
        const lbl = document.getElementById(id);
        if (!lbl) return;
        const checked = lbl.querySelector('input[type="radio"]').checked;
        lbl.classList.toggle('border-terracotta-500', checked);
        lbl.classList.toggle('bg-terracotta-50', checked);
        lbl.classList.toggle('text-terracotta-600', checked);
        lbl.classList.toggle('border-slate-200', !checked);
        lbl.classList.toggle('text-slate-600', !checked);
    });
    const esMedico = radio.value === 'medico';
    const bloqueMedico = document.getElementById('pp-bloque-medico-fields');
    if (bloqueMedico) bloqueMedico.style.display = esMedico ? 'none' : '';
    if (esMedico) {
        // Limpiar campos del médico del paciente
        const nm = document.getElementById('nombre_medico');
        const tm = document.getElementById('telefono_medico');
        if (nm) nm.value = '';
        if (tm) tm.value = '';
    }
}

function actualizarEnvio(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', 'actualizar_datos_envio');
    formData.append('pedido_id', <?= $pedido['id'] ?>);
    formData.append('telefono', '<?= $telefono ?>');
    
    const btn = e.target.querySelector('button[type="submit"]');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Confirmando...';
    
    fetch('procesar-pago.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        mostrarAlerta(data.message, data.success ? 'success' : 'error');
        
        if (data.success) {
            datosEnvioConfirmados = true;
            btn.innerHTML = '✅ Datos Confirmados';
            btn.disabled = true;
            btn.style.background = 'linear-gradient(135deg, #10B981 0%, #059669 100%)';
            
            // Actualizar checklist
            validarFormularioCompleto();
        } else {
            btn.disabled = false;
            btn.innerHTML = btnText;
        }
    });
}

// ============================================
// VALIDACIÓN DEL FORMULARIO COMPLETO
// ============================================

function validarFormularioCompleto() {
    if (MODO_REP) return; // Rep mode uses confirmarPagoRep() instead
    const checkTerminos = document.getElementById('checkTerminosPrivacidad');
    const aceptaTerminos = checkTerminos ? !!checkTerminos.checked : false;
    // Actualizar checklist visual
    actualizarCheck('check-metodo', metodoSeleccionado !== null);
    actualizarCheck('check-comprobante', comprobanteSubido);
    actualizarCheck('check-envio', aceptaTerminos);
    
    // Verificar si requiere factura
    const checkFactura = document.getElementById('checkRequiereFactura');
    if (checkFactura && checkFactura.checked) {
        const rfc = document.getElementById('rfc').value.trim();
        const razonSocial = document.getElementById('razon_social').value.trim();
        
        const facturaCompleta = rfc && razonSocial;
        actualizarCheck('check-factura', facturaCompleta);
        requiereFactura = true;
        
        // Si requiere factura, validar que esté completa
        const todoCompleto = metodoSeleccionado && comprobanteSubido && facturaCompleta && aceptaTerminos;
        habilitarBotonFinal(todoCompleto);
    } else {
        actualizarCheck('check-factura', true); // No requiere, marcar como OK
        requiereFactura = false;
        
        const todoCompleto = metodoSeleccionado && comprobanteSubido && aceptaTerminos;
        habilitarBotonFinal(todoCompleto);
    }
    
    // Mostrar sección de confirmación si al menos tiene método seleccionado
    const seccionConfirmacion = document.getElementById('seccionConfirmacion');
    if (metodoSeleccionado) {
        seccionConfirmacion.classList.remove('hidden');
    }
}

function actualizarCheck(id, completado) {
    const elemento = document.getElementById(id);
    if (!elemento) return;
    
    const icono = elemento.querySelector('.w-5');
    
    if (completado) {
        elemento.classList.remove('text-slate-500');
        elemento.classList.add('text-green-600');
        icono.classList.remove('border-slate-300');
        icono.classList.add('bg-green-500', 'border-green-500', 'text-white');
        icono.innerHTML = '✓';
    } else {
        elemento.classList.remove('text-green-600');
        elemento.classList.add('text-slate-500');
        icono.classList.remove('bg-green-500', 'border-green-500', 'text-white');
        icono.classList.add('border-slate-300');
        icono.innerHTML = '✗';
    }
}

function habilitarBotonFinal(habilitar) {
    const btn = document.getElementById('btnConfirmarPago');
    
    if (habilitar) {
        btn.disabled = false;
        btn.classList.add('hover:scale-105', 'hover:shadow-2xl');
        btn.style.background = 'linear-gradient(135deg, #E07856 0%, #D86F4D 100%)';
    } else {
        btn.disabled = true;
        btn.classList.remove('hover:scale-105', 'hover:shadow-2xl');
        btn.style.background = 'linear-gradient(135deg, #94A3B8 0%, #64748B 100%)';
    }
}

// ============================================
// FUNCIONES DE FACTURACIÓN ELECTRÓNICA
// ============================================

function toggleFacturacion() {
    const checkbox = document.getElementById('checkRequiereFactura');
    const panel = document.getElementById('panelFacturacion');
    const alerta = document.getElementById('alertaFacturacion');
    
    if (checkbox.checked) {
        // Mostrar panel
        panel.classList.remove('hidden');
        
        // Verificar si faltan datos fiscales
        const rfc = document.getElementById('rfc').value.trim();
        const razonSocial = document.getElementById('razon_social').value.trim();
        
        if (!rfc || !razonSocial) {
            alerta.classList.remove('hidden');
        } else {
            alerta.classList.add('hidden');
        }
        
        // Validar visualmente los campos RFC y Razón Social
        const inputRfc = document.getElementById('rfc');
        const inputRazonSocial = document.getElementById('razon_social');
        
        if (inputRfc) validarCampoFacturacion(inputRfc);
        if (inputRazonSocial) validarCampoFacturacion(inputRazonSocial);
        
        // Animación de entrada
        panel.style.animation = 'slideDown 0.3s ease';
    } else {
        // Ocultar panel y limpiar validaciones visuales
        panel.classList.add('hidden');
        alerta.classList.add('hidden');
        
        // Limpiar bordes de validación
        const inputRfc = document.getElementById('rfc');
        const inputRazonSocial = document.getElementById('razon_social');
        const alertaRfc = document.getElementById('alerta-rfc');
        const alertaRazonSocial = document.getElementById('alerta-razon_social');
        
        if (inputRfc) {
            inputRfc.classList.remove('border-red-500', 'border-green-500', 'border-2');
        }
        if (inputRazonSocial) {
            inputRazonSocial.classList.remove('border-red-500', 'border-green-500', 'border-2');
        }
        if (alertaRfc) {
            alertaRfc.classList.add('hidden');
        }
        if (alertaRazonSocial) {
            alertaRazonSocial.classList.add('hidden');
        }
    }
    
    // Validar formulario completo
    validarFormularioCompleto();
}

function guardarDatosFiscales(e) {
    e.preventDefault();
    
    const btn = e.target.querySelector('button[type="submit"]');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Guardando...';
    
    const formData = new FormData(e.target);
    formData.append('action', 'guardar_datos_fiscales');
    formData.append('telefono', '<?= $telefono ?>');
    
    fetch('procesar-pago.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        mostrarAlerta(data.message, data.success ? 'success' : 'error');
        
        if (data.success) {
            // Ocultar alerta de datos faltantes
            document.getElementById('alertaFacturacion').classList.add('hidden');
        }
        
        btn.disabled = false;
        btn.innerHTML = btnText;
    })
    .catch(error => {
        mostrarAlerta('Error al guardar datos fiscales', 'error');
        btn.disabled = false;
        btn.innerHTML = btnText;
    });
}

// Validación en tiempo real de campos fiscales
document.addEventListener('DOMContentLoaded', function() {
    const camposFiscales = ['rfc', 'razon_social'];
    
    camposFiscales.forEach(campo => {
        const input = document.getElementById(campo);
        if (input) {
            input.addEventListener('input', function() {
                // Validar campo individual con alertas visuales
                validarCampoFacturacion(this);
                
                // Validar estado general de facturación
                const alerta = document.getElementById('alertaFacturacion');
                const rfc = document.getElementById('rfc').value.trim();
                const razonSocial = document.getElementById('razon_social').value.trim();
                
                if (rfc && razonSocial) {
                    alerta.classList.add('hidden');
                } else {
                    alerta.classList.remove('hidden');
                }
                
                // Validar formulario completo
                validarFormularioCompleto();
            });
            
            input.addEventListener('blur', function() {
                validarCampoFacturacion(this);
            });
        }
    });
    
    // Validación en tiempo real de campos de envío obligatorios
    const camposEnvioObligatorios = ['calle', 'numero', 'cp'];
    
    camposEnvioObligatorios.forEach(campo => {
        const input = document.getElementById(campo);
        if (input) {
            input.addEventListener('input', function() {
                validarCampoEnvio(this);
            });
            
            input.addEventListener('blur', function() {
                validarCampoEnvio(this);
            });
        }
    });
    
    // Inicializar validaciones
    validarFormularioCompleto();
});

// Función para validar campos de envío individualmente
function validarCampoEnvio(input) {
    const valor = input.value.trim();
    const nombreCampo = input.id;
    const alerta = document.getElementById(`alerta-${nombreCampo}`);
    
    if (!valor) {
        // Campo vacío - agregar borde rojo y mostrar alerta
        input.classList.add('border-red-500', 'border-2');
        input.classList.remove('border-green-500');
        if (alerta) {
            alerta.classList.remove('hidden');
        }
    } else {
        // Campo con contenido - agregar borde verde y ocultar alerta
        input.classList.remove('border-red-500');
        input.classList.add('border-green-500', 'border-2');
        if (alerta) {
            alerta.classList.add('hidden');
        }
    }
}

// Función para validar campos de facturación individualmente
function validarCampoFacturacion(input) {
    const checkFactura = document.getElementById('checkRequiereFactura');
    
    // Solo validar si el checkbox de factura está activado
    if (!checkFactura || !checkFactura.checked) {
        return;
    }
    
    const valor = input.value.trim();
    const nombreCampo = input.id;
    const alerta = document.getElementById(`alerta-${nombreCampo}`);
    
    // Solo validar RFC y Razón Social (campos obligatorios)
    if (nombreCampo !== 'rfc' && nombreCampo !== 'razon_social') {
        return;
    }
    
    if (!valor) {
        // Campo vacío - agregar borde rojo y mostrar alerta
        input.classList.add('border-red-500', 'border-2');
        input.classList.remove('border-green-500');
        if (alerta) {
            alerta.classList.remove('hidden');
        }
    } else {
        // Campo con contenido - agregar borde verde y ocultar alerta
        input.classList.remove('border-red-500');
        input.classList.add('border-green-500', 'border-2');
        if (alerta) {
            alerta.classList.add('hidden');
        }
    }
}

// Función para abrir cámara (en móviles activa la cámara nativa)
function abrirCamara() {
    // Detectar si es dispositivo móvil
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    
    if (isMobile) {
        // En móviles, usar el input nativo con capture
        const input = document.getElementById('comprobante');
        input.click();
    } else {
        // En escritorio, abrir modal con cámara web
        abrirCamaraWeb();
    }
}

// Variables globales para la cámara
let stream = null;
let video = null;

// Función para abrir cámara web en escritorio
async function abrirCamaraWeb() {
    const modal = document.getElementById('modalCamara');
    video = document.getElementById('video');
    
    try {
        // Solicitar acceso a la cámara
        stream = await navigator.mediaDevices.getUserMedia({ 
            video: { 
                facingMode: 'environment', // Preferir cámara trasera
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            } 
        });
        
        video.srcObject = stream;
        modal.classList.remove('hidden');
        
    } catch (error) {
        console.error('Error al acceder a la cámara:', error);
        
        if (error.name === 'NotAllowedError') {
            mostrarAlerta('Permiso denegado. Por favor permite el acceso a la cámara.', 'error');
        } else if (error.name === 'NotFoundError') {
            mostrarAlerta('No se encontró ninguna cámara en este dispositivo.', 'error');
        } else {
            mostrarAlerta('Error al acceder a la cámara. Usa "Subir Archivo" en su lugar.', 'error');
        }
    }
}

// Función para capturar foto desde la cámara web
function capturarFoto() {
    const canvas = document.getElementById('canvas');
    const context = canvas.getContext('2d');
    
    // Ajustar tamaño del canvas al video
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    
    // Dibujar el frame actual del video en el canvas
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Convertir canvas a blob (archivo)
    canvas.toBlob(function(blob) {
        // Crear un archivo desde el blob
        const file = new File([blob], `comprobante_${Date.now()}.jpg`, { type: 'image/jpeg' });
        
        // Crear un DataTransfer para asignar el archivo al input
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        
        const input = document.getElementById('comprobante');
        input.files = dataTransfer.files;
        
        // Cerrar cámara
        cerrarCamara();
        
        // Previsualizar la foto capturada
        previsualizarArchivo(input);
        
        mostrarAlerta('Foto capturada exitosamente', 'success');
        
    }, 'image/jpeg', 0.92); // Calidad 92%
}

// Función para cerrar la cámara web
function cerrarCamara() {
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    
    const modal = document.getElementById('modalCamara');
    modal.classList.add('hidden');
    
    if (video) {
        video.srcObject = null;
    }
}

// Función para previsualizar archivo
function previsualizarArchivo(input) {
    const file = input.files[0];
    if (!file) return;
    
    const previewContainer = document.getElementById('preview-container');
    const previewImage = document.getElementById('preview-image');
    const nombreArchivo = document.getElementById('nombre-archivo');
    
    // Validar tamaño (5MB)
    if (file.size > 5 * 1024 * 1024) {
        mostrarAlerta('El archivo no debe superar 5MB', 'error');
        input.value = '';
        return;
    }
    
    // Mostrar nombre del archivo
    nombreArchivo.textContent = file.name;
    
    // Si es imagen, mostrar preview
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    } else if (file.type === 'application/pdf') {
        // Para PDF, mostrar icono
        previewImage.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="red" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M10 12h4"></path><path d="M10 16h4"></path></svg>';
        previewContainer.classList.remove('hidden');
    }
    
    // Marcar comprobante como subido
    comprobanteSubido = true;
    mostrarAlerta('✅ Comprobante adjuntado. Revisa los datos y confirma abajo.', 'success');
    
    // Validar formulario completo
    validarFormularioCompleto();
}

// Función para limpiar archivo
function limpiarArchivo() {
    const input = document.getElementById('comprobante');
    const previewContainer = document.getElementById('preview-container');
    
    input.value = '';
    previewContainer.classList.add('hidden');
    
    // Marcar comprobante como no subido
    comprobanteSubido = false;
    
    // Validar formulario completo
    validarFormularioCompleto();
}

// Inicializar al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar validaciones
    validarFormularioCompleto();

    // Si el pedido ya trae un metodo, preseleccionarlo para continuar donde se quedo.
    const metodoActualPedido = <?= json_encode($pedido['metodo_pago'] ?? '') ?>;
    if (metodoActualPedido) {
        seleccionarMetodo(metodoActualPedido);
    }
    
    // Verificar si ya hay liga de pago (cuando se recarga la página)
    verificarLigaPago();
    
    // Agregar manejadores a botones de método de pago
    document.querySelectorAll('.metodo-pago-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const metodo = this.getAttribute('data-metodo');
            if (metodo === 'liga_pago') {
                seleccionarLigaPago();
            }
        });
    });
});

// Función para seleccionar liga de pago
function seleccionarLigaPago() {
    metodoSeleccionado = 'liga_pago';
    
    // Actualizar todos los botones (resetear estado visual)
    document.querySelectorAll('.metodo-pago-btn').forEach(btn => {
        const check = btn.querySelector('.rounded-full');
        btn.classList.remove('border-terracotta-400', 'bg-terracotta-50', 'border-purple-500', 'bg-purple-50');
        check.classList.remove('bg-terracotta-500', 'border-terracotta-500', 'bg-purple-600', 'border-purple-600');
        check.innerHTML = '';
    });
    
    // También resetear botones dinámicos
    const metodosDisponibles = Object.keys(datosBancarios);
    metodosDisponibles.forEach(m => {
        const btn = document.getElementById(`btn-${m}`);
        const check = document.getElementById(`check-${m}`);
        if (btn && check) {
            btn.classList.remove('border-terracotta-400', 'bg-terracotta-50', 'border-purple-500', 'bg-purple-50');
            check.classList.remove('bg-terracotta-500', 'border-terracotta-500', 'bg-purple-600', 'border-purple-600');
            check.style.background = '';
            check.style.borderColor = '';
            check.style.display = '';
            check.style.alignItems = '';
            check.style.justifyContent = '';
            check.innerHTML = '';
        }
    });
    
    // Marcar liga_pago como seleccionado
    const btnLiga = document.getElementById('btn-liga_pago');
    const checkLiga = document.getElementById('check-liga_pago');
    if (btnLiga && checkLiga) {
        btnLiga.classList.add('border-terracotta-400', 'bg-terracotta-50');
        checkLiga.classList.add('bg-terracotta-500', 'border-terracotta-500');
        checkLiga.style.background = '';
        checkLiga.style.borderColor = '';
        checkLiga.style.display = 'flex';
        checkLiga.style.alignItems = 'center';
        checkLiga.style.justifyContent = 'center';
        checkLiga.innerHTML = '<svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>';
    }
    
    // Ocultar panel de datos bancarios
    document.getElementById('panel-datos-bancarios').classList.add('hidden');

    // Ocultar panel de Efectivo si estaba visible
    const panelEfectivo = document.getElementById('panel-efectivo');
    if (panelEfectivo) panelEfectivo.classList.add('hidden');
    
    // Mostrar panel de liga de pago
    document.getElementById('panel-liga-pago').classList.remove('hidden');
    
    // Scroll al panel
    document.getElementById('panel-liga-pago').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    // Marcar método de pago en el formulario
    document.getElementById('metodo_pago_selected').value = 'liga_pago';

    // Rep mode: desactivar confirm hasta que se genere la liga
    if (MODO_REP) {
        const btn = document.getElementById('btnConfirmarPagoRep');
        if (btn) {
            // Si ya fue generada (div rep-liga-generada visible), dejarlo habilitado
            const yaGenerada = !document.getElementById('rep-liga-generada').classList.contains('hidden');
            btn.disabled = !yaGenerada;
            btn.style.background = yaGenerada ? '#126c6a' : '#94a3b8';
        }
        return; // el resto del polling es para clientes
    }
    
    // Validar formulario
    validarFormularioCompleto();
}

// ── Liga de Pago - Modo Cliente (genera directo via EcartPay) ───────────────

function generarLigaCliente() {
    const btn = document.getElementById('btnGenerarLigaCliente');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Generando...'; }

    const fd = new FormData();
    fd.append('action', 'generar_liga_rep');
    fd.append('pedido_id', '<?= $pedido_id ?>');
    fd.append('telefono', '<?= htmlspecialchars($telefono) ?>');

    fetch('<?= basename($_SERVER['PHP_SELF']) ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('sin-solicitar').classList.add('hidden');
                const recibida = document.getElementById('liga-recibida');
                recibida.classList.remove('hidden');
                const enlaceEl = document.getElementById('enlace-pago');
                enlaceEl.href = data.liga;
                // En rep_en_tienda el <a> muestra el URL como texto visible
                if (enlaceEl.textContent === '—' || enlaceEl.textContent.trim() === '') {
                    enlaceEl.textContent = data.liga;
                }
                recibida.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                alert('❌ ' + (data.message || 'No se pudo generar la liga'));
                if (btn) { btn.disabled = false; btn.innerHTML = '🔗 Generar Liga de Pago'; }
            }
        })
        .catch(() => {
            alert('Error de red al generar la liga');
            if (btn) { btn.disabled = false; btn.innerHTML = '🔗 Generar Liga de Pago'; }
        });
}

function regenerarLigaCliente() {
    document.getElementById('liga-recibida').classList.add('hidden');
    document.getElementById('sin-solicitar').classList.remove('hidden');
    const btn = document.getElementById('btnGenerarLigaCliente');
    if (btn) { btn.disabled = false; btn.innerHTML = '🔗 Generar Liga de Pago'; }
}

// Scroll al comprobante cuando hacen clic en "Ir a la plataforma"
function scrollAlComprobante() {
    setTimeout(() => {
        const p = document.getElementById('panel-datos-bancarios');
        if (p) p.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
}

// Función para iniciar polling de verificación de liga (ya no se usa, se mantiene por compatibilidad)
function iniciarVerificacionLiga() {}

// ============================================
// REP MODE FUNCTIONS
// ============================================

// Helper: copia texto al portapapeles compatible con HTTP y HTTPS
function _clipboardCopy(texto, onSuccess, onFail) {
    // navigator.clipboard solo funciona en contexto seguro (HTTPS / localhost)
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(onSuccess).catch(() => {
            _execCommandCopy(texto, onSuccess, onFail);
        });
    } else {
        _execCommandCopy(texto, onSuccess, onFail);
    }
}

function _execCommandCopy(texto, onSuccess, onFail) {
    try {
        const ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;pointer-events:none';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) { onSuccess(); } else { if (onFail) onFail(); }
    } catch (e) {
        if (onFail) onFail();
    }
}

function copiarTexto(btn, texto, isAll = false) {
    _clipboardCopy(texto, () => {
        const orig = btn.innerHTML;
        btn.classList.add('copied');
        btn.innerHTML = isAll ? '✓ ¡Copiado!' : '✓ Copiado';
        setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = orig; }, 2000);
    }, () => {
        btn.innerHTML = '✗ Error'; setTimeout(() => { btn.innerHTML = isAll ? '📋 Copiar todo' : '📋 Copiar'; }, 2000);
    });
}

function copiarTextoData(btn) {
    const texto = btn.dataset.copyall || '';
    copiarTexto(btn, texto, true);
}

function compartirEnlacePago() {
    const url = location.origin + location.pathname + '?pedido_id=<?= $pedido['id'] ?>&telefono=<?= urlencode($telefono) ?>';
    const text = '🛒 Pago pedido #<?= $pedido['id'] ?> — $<?= number_format($pedido['total'], 2) ?> MXN\n' + url;
    if (navigator.share) {
        navigator.share({ title: 'Liga de pago', text: text }).catch(() => {});
    } else {
        navigator.clipboard.writeText(text).then(() => {
            alert('Enlace copiado al portapapeles');
        });
    }
}

// ── Liga de Pago - Rep Mode ──────────────────────────────────────────────────

let _ligaGeneradaUrl = '';

function generarLigaRep() {
    const btn = document.getElementById('btnGenerarLiga');
    btn.disabled = true;
    btn.innerHTML = '⏳ Generando...';

    const fd = new FormData();
    fd.append('action', 'generar_liga_rep');
    fd.append('pedido_id', '<?= $pedido['id'] ?>');
    fd.append('telefono', '<?= htmlspecialchars($telefono) ?>');

    fetch('<?= basename($_SERVER['PHP_SELF']) ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                _ligaGeneradaUrl = data.liga;
                mostrarLigaGeneradaRep(data.liga, data.monto, data.concepto);
            } else {
                alert('❌ ' + (data.message || 'No se pudo generar la liga'));
                btn.disabled = false;
                btn.innerHTML = '🔗 Generar Liga de Pago';
            }
        })
        .catch(() => {
            alert('Error de red al generar la liga');
            btn.disabled = false;
            btn.innerHTML = '🔗 Generar Liga de Pago';
        });
}

function mostrarLigaGeneradaRep(liga, monto, concepto) {
    _ligaGeneradaUrl = liga;
    document.getElementById('rep-liga-sin-generar').classList.add('hidden');
    const repLigaGenerada = document.getElementById('rep-liga-generada');
    repLigaGenerada.classList.remove('hidden');
    const urlEl = document.getElementById('rep-liga-url');
    urlEl.textContent = liga;
    urlEl.href = liga;
    if (monto)   document.getElementById('rep-liga-monto-txt').textContent   = '$' + monto + ' MXN';
    if (concepto) document.getElementById('rep-liga-concepto-txt').textContent = concepto;

    // Habilitar botón de confirmar
    const btnConf = document.getElementById('btnConfirmarPagoRep');
    if (btnConf) {
        btnConf.disabled = false;
        btnConf.style.background = '#126c6a';
        btnConf.innerHTML = '✅ Liga enviada — Confirmar';
    }

    repLigaGenerada.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function regenerarLigaRep() {
    _ligaGeneradaUrl = '';
    document.getElementById('rep-liga-sin-generar').classList.remove('hidden');
    document.getElementById('rep-liga-generada').classList.add('hidden');
    const btnConf = document.getElementById('btnConfirmarPagoRep');
    if (btnConf) { btnConf.disabled = true; btnConf.style.background = '#94a3b8'; }
    const btnGen = document.getElementById('btnGenerarLiga');
    if (btnGen) { btnGen.disabled = false; btnGen.innerHTML = '🔗 Generar Liga de Pago'; }
}

function copiarTextoRep(btn, texto) {
    _clipboardCopy(texto, () => {
        const orig = btn.innerHTML;
        btn.innerHTML = '✅ Copiado';
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    }, () => {
        btn.innerHTML = '✗ Error';
        setTimeout(() => { btn.innerHTML = '📋 Copiar'; }, 2000);
    });
}

function copiarLigaRep() {
    if (!_ligaGeneradaUrl) return;
    const btn = event.currentTarget;
    _clipboardCopy(_ligaGeneradaUrl, () => {
        const orig = btn.innerHTML;
        btn.innerHTML = '✅ Copiado';
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    }, () => {
        btn.innerHTML = '✗ Error';
        setTimeout(() => { btn.innerHTML = '📋 Copiar'; }, 2000);
    });
}

function compartirLigaWhatsApp() {
    if (!_ligaGeneradaUrl) return;
    const nombre = '<?= addslashes($pedido['nombre'] ?? 'cliente') ?>';
    const total  = '$<?= number_format($pedido['total'], 2) ?>';
    const pedNum = '<?= $pedido['id'] ?>';
    const texto  = `Hola ${nombre}, aquí está tu liga de pago seguro para el pedido #${pedNum}:\n\n💳 ${_ligaGeneradaUrl}\n\nTotal: ${total} MXN\n\nPuedes pagar con tarjeta de forma segura.`;
    const url    = 'https://wa.me/<?= preg_replace('/\D/', '', $telefono) ?>?text=' + encodeURIComponent(texto);
    window.open(url, '_blank');
}

function abrirLigaRep() {
    if (_ligaGeneradaUrl) window.open(_ligaGeneradaUrl, '_blank');
}

function previsualizarArchivoRep(input) {
    const label = document.getElementById('rep-nombre-archivo');
    if (input.files && input.files[0]) {
        label.textContent = '📎 ' + input.files[0].name;
        label.classList.remove('hidden');
    } else {
        label.classList.add('hidden');
    }
}

function abrirCamaraRep() {
    const input = document.getElementById('comprobante-rep');
    input.setAttribute('capture', 'environment');
    input.click();
}

function confirmarPagoRep() {
    const metodo = document.getElementById('metodo_pago_selected') ? document.getElementById('metodo_pago_selected').value : metodoSeleccionado;
    if (!metodo) { alert('Selecciona un método de pago'); return; }

    const btn = document.getElementById('btnConfirmarPagoRep');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Procesando...';

    const formData = new FormData();
    formData.append('action', 'subir_comprobante');
    formData.append('pedido_id', '<?= $pedido['id'] ?>');
    formData.append('telefono', '<?= $telefono ?>');
    formData.append('metodo_pago', metodo);
    formData.append('modo', 'rep');

    const fileRep = document.getElementById('comprobante-rep');
    if (fileRep && fileRep.files[0]) {
        formData.append('comprobante', fileRep.files[0]);
    } else {
        // Fallback: comprobante subido en el panel de datos bancarios (tienda/transferencia/oxxo)
        const fileAlt = document.getElementById('comprobante');
        if (fileAlt && fileAlt.files[0]) {
            formData.append('comprobante', fileAlt.files[0]);
        }
    }
    formData.append('requiere_factura', '0');
    // No shipping data needed for rep direct sales (entrega_directa = 1)
    formData.append('calle', '');
    formData.append('numero', '');
    formData.append('colonia', '');
    formData.append('cp', '');
    formData.append('estado', '');
    formData.append('ciudad', '');

    fetch('<?= basename($_SERVER['PHP_SELF']) ?>', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.innerHTML = '✅ ¡Cobro confirmado!';
                btn.style.background = '#059669';
                setTimeout(() => {
                    window.location.href = '<?= rtrim(str_replace(basename($_SERVER['PHP_SELF']), '', $_SERVER['PHP_SELF']), '/') ?>/representante/index.php';
                }, 1800);
            } else {
                alert(data.message || 'Error al confirmar el cobro');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        })
        .catch(() => {
            alert('Error de red al confirmar');
            btn.disabled = false;
            btn.innerHTML = orig;
        });
}

// ============================================
// PAYPAL INTEGRATION
// ============================================
if (document.getElementById('paypal-button-container')) {
    paypal.Buttons({
        style: {
            layout: 'vertical',
            color: 'blue',
            shape: 'rect',
            label: 'pay'
        },
        
        createOrder: function(data, actions) {
            return actions.order.create({
                purchase_units: [{
                    reference_id: 'PEDIDO_<?= $pedido['id'] ?>',
                    description: 'Pedido #<?= $pedido['id'] ?> - Solumedic Shop',
                    amount: {
                        currency_code: 'MXN',
                        value: '<?= number_format($pedido['total'], 2, '.', '') ?>'
                    }
                }],
                application_context: {
                    brand_name: 'Solumedic Shop',
                    locale: 'es-MX',
                    landing_page: 'BILLING',
                    shipping_preference: 'NO_SHIPPING',
                    user_action: 'PAY_NOW'
                }
            });
        },
        
        onApprove: async function(data, actions) {
            try {
                // Capturar el pago
                const details = await actions.order.capture();
                
                // Actualizar pedido en la base de datos
                const formData = new FormData();
                formData.append('action', 'paypal_payment_success');
                formData.append('pedido_id', <?= $pedido['id'] ?>);
                formData.append('order_id', details.id);
                formData.append('transaction_id', details.purchase_units[0].payments.captures[0].id);
                formData.append('payer_email', details.payer.email_address);
                formData.append('payer_name', details.payer.name.given_name + ' ' + details.payer.name.surname);
                
                const response = await fetch('procesar-pago.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    mostrarAlerta('✅ Pago completado exitosamente. ID: ' + details.id, 'success');
                    
                    // Marcar como pago completado
                    comprobanteSubido = true;
                    validarFormularioCompleto();
                    
                    // Scroll a la sección de envío
                    setTimeout(() => {
                        document.getElementById('formEnvio').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }, 2000);
                } else {
                    throw new Error(result.message || 'Error al procesar el pago');
                }
                
            } catch (error) {
                console.error('Error en PayPal:', error);
                mostrarAlerta('❌ Error al procesar el pago: ' + error.message, 'error');
            }
        },
        
        onCancel: function(data) {
            mostrarAlerta('⚠️ Pago cancelado. Puedes intentar nuevamente cuando estés listo.', 'info');
        },
        
        onError: function(err) {
            console.error('Error PayPal:', err);
            mostrarAlerta('❌ Ocurrió un error con PayPal. Intenta más tarde o selecciona otro método de pago.', 'error');
        }
        
    }).render('#paypal-button-container');
}

// ============================================
// MERCADO PAGO INTEGRATION
// ============================================
<?php if (!empty($_mp_public_key)): ?>
const mpPublicKey = '<?= $_mp_public_key ?>';
const mpSinCuenta = <?= $_mp_sin_cuenta ? 'true' : 'false' ?>;
let mpInstance = null;
let mpWalletBrick = null;

async function iniciarPagoMP() {
    const btnIniciar = document.getElementById('btn-iniciar-mp');
    const loadingDiv  = document.getElementById('mp-loading');

    // Mostrar spinner
    if (btnIniciar) btnIniciar.classList.add('hidden');
    if (loadingDiv) loadingDiv.classList.remove('hidden');

    try {
        // Solicitar preferencia al backend
        const formData = new FormData();
        formData.append('action', 'crear_preferencia_mp');
        formData.append('pedido_id', <?= (int)$pedido['id'] ?>);
        formData.append('telefono', '<?= htmlspecialchars($telefono) ?>');

        const response = await fetch('procesar-pago.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (!data.success) {
            mostrarAlerta('\u274c ' + data.message, 'error');
            if (btnIniciar) btnIniciar.classList.remove('hidden');
            if (loadingDiv) loadingDiv.classList.add('hidden');
            return;
        }

        // Sin cuenta: abrir Checkout Pro como lightbox modal (desktop) o redirección (móvil, gestionado por MP)
        if (mpSinCuenta) {
            if (loadingDiv) loadingDiv.classList.add('hidden');
            if (btnIniciar) btnIniciar.classList.remove('hidden');
            if (!mpInstance) {
                mpInstance = new MercadoPago(mpPublicKey, { locale: 'es-MX' });
            }
            mpInstance.checkout({
                preference: { id: data.preference_id },
                autoOpen: true,
            });
            return;
        }

        const preferenceId = data.preference_id;

        // Inicializar SDK si no estaba inicializado
        if (!mpInstance) {
            mpInstance = new MercadoPago(mpPublicKey, { locale: 'es-MX' });
        }

        // Limpiar contenedor
        document.getElementById('mp-wallet-container').innerHTML = '';
        if (loadingDiv) loadingDiv.classList.add('hidden');

        // Renderizar Wallet Brick
        // purpose: 'wallet_purchase' obliga al login con cuenta MP (sin cuenta deshabilitado)
        const brickInit = mpSinCuenta
            ? { preferenceId: preferenceId }
            : { preferenceId: preferenceId, purpose: 'wallet_purchase' };
        const bricksBuilder = mpInstance.bricks();
        mpWalletBrick = await bricksBuilder.create('wallet', 'mp-wallet-container', {
            initialization: brickInit,
            customization: {
                texts: { valueProp: 'smart_option' },
                visual: { buttonBackground: 'default', borderRadius: '12px' }
            },
            callbacks: {
                onReady: () => {
                    console.log('MP Wallet Brick ready');
                },
                onError: (error) => {
                    console.error('MP Brick error:', error);
                    mostrarAlerta('\u274c Error al cargar el bot\u00f3n de Mercado Pago. Intenta de nuevo.', 'error');
                    if (btnIniciar) btnIniciar.classList.remove('hidden');
                }
            }
        });

    } catch (err) {
        console.error('Error iniciarPagoMP:', err);
        mostrarAlerta('\u274c Error al conectar con Mercado Pago. Intenta m\u00e1s tarde.', 'error');
        if (btnIniciar) btnIniciar.classList.remove('hidden');
        if (loadingDiv) loadingDiv.classList.add('hidden');
    }
}

// Auto-mostrar panel de MP si regresó de MP con pago aprobado
<?php if ($mp_auto_confirm): ?>
document.addEventListener('DOMContentLoaded', function() {
    seleccionarMetodo('mercado_pago');
    comprobanteSubido = true;
    validarFormularioCompleto();
    mostrarAlerta('✅ ¡Pago con Mercado Pago aprobado! Puedes confirmar tu pedido abajo.', 'success');
    setTimeout(() => {
        const seccion = document.getElementById('seccionConfirmacion');
        if (seccion) seccion.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 800);
});
<?php endif; ?>
<?php endif; ?>

// ============================================
// ECARTPAY INTEGRATION
// ============================================
function iniciarPagoEcartPay() {
    const btn     = document.getElementById('btn-iniciar-ep');
    const loading = document.getElementById('ep-loading');

    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
    if (loading) loading.classList.remove('hidden');

    const formData = new FormData();
    formData.append('action',    'crear_orden_ecartpay');
    formData.append('pedido_id', '<?= (int)$pedido['id'] ?>');
    formData.append('telefono',  '<?= htmlspecialchars($telefono) ?>');

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.pay_link) {
                window.location.href = data.pay_link;
            } else {
                mostrarAlerta('❌ ' + (data.message || 'Error al iniciar EcartPay'), 'error');
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                if (loading) loading.classList.add('hidden');
            }
        })
        .catch(() => {
            mostrarAlerta('❌ Error de conexión con EcartPay', 'error');
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            if (loading) loading.classList.add('hidden');
        });
}

<?php if ($ep_auto_confirm): ?>
document.addEventListener('DOMContentLoaded', function() {
    seleccionarMetodo('ecartpay');
    comprobanteSubido = true;
    validarFormularioCompleto();
    mostrarAlerta('✅ ¡Pago con EcartPay aprobado! Puedes confirmar tu pedido abajo.', 'success');
    setTimeout(() => {
        const seccion = document.getElementById('seccionConfirmacion');
        if (seccion) seccion.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 800);
});
<?php endif; ?>

// ── OpenPay ────────────────────────────────────────────────────────────────
<?php if (!$modo_rep && !empty($_op_merchant_id) && !empty($_op_public_key)): ?>
const OP_MERCHANT_ID = <?= json_encode($_op_merchant_id) ?>;
const OP_PUBLIC_KEY  = <?= json_encode($_op_public_key) ?>;
const OP_SANDBOX     = <?= $_op_sandbox ? 'true' : 'false' ?>;

function initOpenPay() {
    if (typeof OpenPay === 'undefined') return;
    OpenPay.setId(OP_MERCHANT_ID);
    OpenPay.setApiKey(OP_PUBLIC_KEY);
    OpenPay.setSandboxMode(OP_SANDBOX);
    const dsid = OpenPay.deviceData.setup('openpay-form', 'op-device-session-id');
    const el = document.getElementById('op-device-session-id');
    if (el) el.value = dsid;
}

document.addEventListener('DOMContentLoaded', initOpenPay);

// Formatear número de tarjeta con espacios
const opCardInput = document.getElementById('op-card-number');
if (opCardInput) {
    opCardInput.addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').substring(0, 16);
        this.value = v.replace(/(.{4})/g, '$1 ').trim();
    });
}

function procesarPagoOpenPay() {
    const cardNumber = (document.getElementById('op-card-number')?.value || '').replace(/\s/g, '');
    const holderName = document.getElementById('op-holder-name')?.value || '';
    const expMonth   = document.getElementById('op-exp-month')?.value || '';
    const expYear    = document.getElementById('op-exp-year')?.value || '';
    const cvv        = document.getElementById('op-cvv')?.value || '';
    const errorDiv   = document.getElementById('op-error');
    const loadingDiv = document.getElementById('op-loading');
    const btnPagar   = document.getElementById('btn-pagar-openpay');

    if (errorDiv) { errorDiv.classList.add('hidden'); errorDiv.textContent = ''; }

    if (!cardNumber || !holderName || !expMonth || !expYear || !cvv) {
        if (errorDiv) { errorDiv.textContent = 'Por favor completa todos los campos de la tarjeta.'; errorDiv.classList.remove('hidden'); }
        return;
    }

    if (typeof OpenPay === 'undefined') {
        if (errorDiv) { errorDiv.textContent = 'Error al cargar OpenPay. Recarga la página e intenta de nuevo.'; errorDiv.classList.remove('hidden'); }
        return;
    }

    if (loadingDiv) loadingDiv.classList.remove('hidden');
    if (btnPagar)   btnPagar.disabled = true;

    OpenPay.token.create({
        card_number:      cardNumber,
        holder_name:      holderName,
        expiration_year:  expYear,
        expiration_month: expMonth,
        cvv2:             cvv,
    }, function (response) {
        const tokenId         = response.data.id;
        const deviceSessionId = document.getElementById('op-device-session-id')?.value || '';

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:            'crear_cargo_openpay',
                pedido_id:         '<?= (int)$pedido['id'] ?>',
                telefono:          '<?= htmlspecialchars($telefono) ?>',
                token_id:          tokenId,
                device_session_id: deviceSessionId,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (loadingDiv) loadingDiv.classList.add('hidden');
            if (btnPagar)   btnPagar.disabled = false;
            if (data.success) {
                if (data.status === 'completed') {
                    window.location.href = window.location.pathname
                        + '?pedido_id=<?= (int)$pedido['id'] ?>'
                        + '&telefono=<?= urlencode($telefono) ?>'
                        + '&op_status=completed';
                } else if (data.status === 'in_progress' && data.redirect_url) {
                    window.location.href = data.redirect_url;
                }
            } else {
                if (errorDiv) { errorDiv.textContent = data.message || 'Error al procesar el pago.'; errorDiv.classList.remove('hidden'); }
            }
        })
        .catch(() => {
            if (loadingDiv) loadingDiv.classList.add('hidden');
            if (btnPagar)   btnPagar.disabled = false;
            if (errorDiv)   { errorDiv.textContent = 'Error de conexión. Intenta de nuevo.'; errorDiv.classList.remove('hidden'); }
        });
    }, function (error) {
        if (loadingDiv) loadingDiv.classList.add('hidden');
        if (btnPagar)   btnPagar.disabled = false;
        const ec = error.data?.error_code;
        let msg = 'Error al validar la tarjeta.';
        if (ec === 1001) msg = 'Número de tarjeta inválido.';
        else if (ec === 1004) msg = 'CVV inválido.';
        else if (ec === 1005) msg = 'Fecha de expiración inválida.';
        else if (ec === 1007) msg = 'Titular de la tarjeta inválido.';
        if (errorDiv) { errorDiv.textContent = msg; errorDiv.classList.remove('hidden'); }
    });
}

<?php if ($op_auto_confirm): ?>
document.addEventListener('DOMContentLoaded', function() {
    seleccionarMetodo('openpay');
    comprobanteSubido = true;
    validarFormularioCompleto();
    mostrarAlerta('✅ ¡Pago con OpenPay aprobado! Puedes confirmar tu pedido abajo.', 'success');
    setTimeout(() => {
        const seccion = document.getElementById('seccionConfirmacion');
        if (seccion) seccion.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 800);
});
<?php endif; ?>
<?php endif; // OpenPay JS ?>
</script>

<script src="<?= BASE_PATH ?>js/ubicaciones.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initUbicaciones({
        selectEstado:    '#estado',
        selectMunicipio: '#ciudad',
        valorEstado:     <?= json_encode($cliente['estado'] ?? '') ?>,
        valorMunicipio:  <?= json_encode($cliente['ciudad'] ?? '') ?>,
        basePath:        '<?= BASE_PATH ?>',
        required:        true,
    });
});
</script>

</body>
</html>
