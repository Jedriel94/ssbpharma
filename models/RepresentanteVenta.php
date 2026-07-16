<?php
require_once __DIR__ . '/../config/database.php';

class RepresentanteVenta {
    private $db;

    public function __construct($pdo = null) {
        if ($pdo) {
            $this->db = $pdo;
        } else {
            $this->db = Database::getInstance()->getConnection();
        }
    }

    public function crear($representante_id, $representante_admin_id, $datos, $items, $comprobanteFile = null) {
        $representante_admin_id = $this->validarId($representante_admin_id, 'Usuario representante invalido');
        return $this->crearConIdentidad(null, $representante_admin_id, $datos, $items, $comprobanteFile);
    }

    public function crearPorAdmin($representante_admin_id, $datos, $items, $comprobanteFile = null) {
        $representante_admin_id = $this->validarId($representante_admin_id, 'Usuario representante invalido');
        return $this->crearConIdentidad(null, $representante_admin_id, $datos, $items, $comprobanteFile);
    }

    /**
     * Crear pedido a partir de un kit usando el inventario personal del rep.
     * El precio del kit se distribuye proporcionalmente entre sus productos.
     */
    public function crearDesdeKitPorAdmin($representante_admin_id, $kit_id, $datos) {
        $representante_admin_id = $this->validarId($representante_admin_id, 'Usuario representante invalido');
        $kit_id                 = $this->validarId($kit_id, 'Kit invalido');

        // Load kit
        $stmt = $this->db->prepare('SELECT * FROM kits WHERE id = ? AND activo = 1 LIMIT 1');
        $stmt->execute([$kit_id]);
        $kit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kit) {
            return ['success' => false, 'mensaje' => 'Kit no encontrado o inactivo'];
        }

        // Load kit products
        $stmt = $this->db->prepare(
            'SELECT kp.producto_id, kp.cantidad, p.producto
             FROM kit_productos kp
             INNER JOIN productos p ON kp.producto_id = p.id
             WHERE kp.kit_id = ? AND p.activo = 1'
        );
        $stmt->execute([$kit_id]);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($productos)) {
            return ['success' => false, 'mensaje' => 'El kit no tiene productos activos'];
        }

        // Distribute total kit price across all physical units sold.
        // If N kits are sold, denominator must include N to avoid inflating totals.
        $cantidad_kits     = max(1, (int)($datos['cantidad_kits'] ?? 1));
        $precio_total_kit  = (float)$kit['precio_kit'] * $cantidad_kits;
        $total_units_kit   = array_sum(array_column($productos, 'cantidad'));
        $total_units_venta = $total_units_kit * $cantidad_kits;
        if ($total_units_kit <= 0 || $total_units_venta <= 0) {
            return ['success' => false, 'mensaje' => 'El kit no tiene unidades configuradas'];
        }
        $precio_por_unidad = round($precio_total_kit / $total_units_venta, 2);

        $items      = [];
        $suma_check = 0.0;
        foreach ($productos as $p) {
            $cantidad_total = (int)$p['cantidad'] * $cantidad_kits;
            $subtotal       = round($precio_por_unidad * $cantidad_total, 2);
            $suma_check += $subtotal;
            $items[] = [
                'producto_id'     => (int)$p['producto_id'],
                'cantidad'        => $cantidad_total,
                'precio_unitario' => $precio_por_unidad,
            ];
        }
        // Fix rounding difference on last item
        $diff = round($precio_total_kit - $suma_check, 2);
        if ($diff != 0.0) {
            $last = &$items[count($items) - 1];
            if ($last['cantidad'] > 0) {
                $last['precio_unitario'] = round($last['precio_unitario'] + ($diff / $last['cantidad']), 2);
            }
        }

        // Tag notes with kit name (and quantity if > 1)
        $qty_txt  = $cantidad_kits > 1 ? " x{$cantidad_kits}" : '';
        $nota_kit = '[Kit: ' . $kit['nombre'] . $qty_txt . ']';
        $datos['notas'] = $nota_kit . (!empty($datos['notas']) ? ' ' . trim($datos['notas']) : '');
        $datos['metodo_pago'] = '';

        return $this->crearConIdentidad(null, $representante_admin_id, $datos, $items, null);
    }

    private function crearConIdentidad($representante_id, $representante_admin_id, $datos, $items, $comprobanteFile = null) {
        try {
            $items = $this->normalizarItems($items);
            if (empty($items)) {
                throw new Exception('Selecciona al menos un producto');
            }

            $telefono = preg_replace('/\D+/', '', $datos['telefono'] ?? '');
            if (strlen($telefono) < 10) {
                throw new Exception('El telefono del cliente debe tener al menos 10 digitos');
            }

            $metodo_pago = $datos['metodo_pago'] ?? '';
            if (!in_array($metodo_pago, ['transferencia', 'efectivo', 'liga_pago', ''], true)) {
                throw new Exception('Metodo de pago no valido');
            }
            $metodo_pago_db = $metodo_pago ?: null; // empty = deferred, store NULL

            $requiere_factura = !empty($datos['requiere_factura']) ? 1 : 0;
            if ($requiere_factura) {
                $this->validarDatosFiscales($datos);
            }

            // Coupon data from client-side validation
            $cupon_id_input = (int)($datos['cupon_id'] ?? 0);
            $cupon_codigo_input = trim($datos['cupon_codigo'] ?? '');
            $cupon_descuento_input = max(0.0, (float)($datos['cupon_descuento'] ?? 0));

            $this->db->beginTransaction();

            $cliente = $this->obtenerOCrearCliente($telefono, trim($datos['cliente_nombre'] ?? ''), $representante_admin_id);
            $this->actualizarExtrasCliente($cliente['id'], $datos);
            $productosVenta = [];
            $total = 0;

            foreach ($items as $item) {
                $inventario = $this->bloquearInventario($representante_id, $representante_admin_id, $item['producto_id']);
                if (!$inventario) {
                    throw new Exception('El representante no tiene inventario asignado de uno de los productos');
                }
                if ((int)$inventario['cantidad_disponible'] < $item['cantidad']) {
                    throw new Exception('Inventario insuficiente para ' . $inventario['producto']);
                }

                // Use precio override if provided (e.g. kit sales), otherwise look up rangos
                if (isset($item['precio_unitario']) && (float)$item['precio_unitario'] > 0) {
                    $precio = (float)$item['precio_unitario'];
                } else {
                    $precio = $this->obtenerPrecio($item['producto_id'], $item['cantidad']);
                    if ($precio === null) {
                        throw new Exception('No hay precio configurado para ' . $inventario['producto']);
                    }
                }

                $subtotal = $precio * $item['cantidad'];
                $total += $subtotal;
                $productosVenta[] = [
                    'producto_id' => $item['producto_id'],
                    'producto' => $inventario['producto'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $precio,
                    'subtotal' => $subtotal,
                    'cantidad_antes' => (int)$inventario['cantidad_disponible'],
                    'cantidad_despues' => (int)$inventario['cantidad_disponible'] - $item['cantidad']
                ];
            }

            // Apply coupon discount
            $cupon_descuento_db = 0.0;
            $cupon_codigo_db = null;
            if ($cupon_id_input > 0 && $cupon_descuento_input > 0) {
                $cupon_descuento_db = min($cupon_descuento_input, $total);
                $cupon_codigo_db = $cupon_codigo_input ?: null;
                $total -= $cupon_descuento_db;
            }

            $comprobante = null;
            if ($metodo_pago === 'transferencia') {
                $comprobante = $this->guardarComprobante($comprobanteFile);
            }

            // Empty = deferred (procesar-pago.php will set the real method)
            $estado = ($metodo_pago === 'liga_pago' || $metodo_pago === '') ? 'pendiente' : 'por_verificar';
            $estado_liquidacion = $metodo_pago === 'efectivo' ? 'pendiente' : 'no_aplica';

            $stmt = $this->db->prepare("
                INSERT INTO pedidos
                    (cliente_id, total, estado, notas, comprobante_pago, metodo_pago, requiere_factura,
                     rfc, razon_social, email_factura, codigo_postal, uso_cfdi, regimen_fiscal,
                     representante_admin_id, canal, entrega_directa, estado_liquidacion, fecha_entrega_directa,
                     cupon_codigo, cupon_descuento, fecha_por_verificar)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?,
                     ?, 'representante_directo', 1, ?, NOW(),
                     ?, ?, IF(? = 'por_verificar', NOW(), NULL))
            ");
            $stmt->execute([
                $cliente['id'],
                $total,
                $estado,
                trim($datos['notas'] ?? '') ?: null,
                $comprobante,
                $metodo_pago_db,
                $requiere_factura,
                $requiere_factura ? strtoupper(trim($datos['rfc'] ?? '')) : null,
                $requiere_factura ? trim($datos['razon_social'] ?? '') : null,
                $requiere_factura ? trim($datos['email_factura'] ?? '') : null,
                $requiere_factura ? trim($datos['codigo_postal'] ?? '') : null,
                $requiere_factura ? trim($datos['uso_cfdi'] ?? '') : null,
                $requiere_factura ? trim($datos['regimen_fiscal'] ?? '') : null,
                $representante_admin_id,
                $estado_liquidacion,
                $cupon_codigo_db,
                $cupon_descuento_db,
                $estado  // para el IF(? = 'por_verificar', ...) de fecha_por_verificar
            ]);

            $pedido_id = (int)$this->db->lastInsertId();

            // Batch-fetch tasa de impuesto de cada producto para guardarla en el detalle
            $pids = array_unique(array_column($productosVenta, 'producto_id'));
            $phs  = implode(',', array_fill(0, count($pids), '?'));
            $simp = $this->db->prepare("SELECT id, impuesto FROM productos WHERE id IN ($phs)");
            $simp->execute(array_map('intval', $pids));
            $impuestoMap = $simp->fetchAll(PDO::FETCH_KEY_PAIR); // [ id => tasa ]

            $stmtDetalle = $this->db->prepare("
                INSERT INTO detalle_pedidos
                    (pedido_id, producto_id, cantidad, precio_unitario, subtotal, impuesto)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ");
            $stmtInv = $this->db->prepare("
                UPDATE representante_inventario
                SET cantidad_disponible = cantidad_disponible - ?,
                    cantidad_reservada  = cantidad_reservada  + ?,
                    representante_admin_id = COALESCE(representante_admin_id, ?)
                WHERE representante_admin_id = ?
                  AND producto_id = ?
            ");
            $stmtMov = $this->db->prepare("
                INSERT INTO representante_inventario_movimientos
                    (representante_admin_id, producto_id, pedido_id, tipo, cantidad, cantidad_antes, cantidad_despues, notas)
                VALUES
                    (?, ?, ?, 'reserva', ?, ?, ?, ?)
            ");

            foreach ($productosVenta as $producto) {
                $imp = (float)($impuestoMap[$producto['producto_id']] ?? 0.16);
                $stmtDetalle->execute([
                    $pedido_id,
                    $producto['producto_id'],
                    $producto['cantidad'],
                    $producto['precio_unitario'],
                    $producto['subtotal'],
                    $imp,
                ]);

                $stmtInv->execute([
                    $producto['cantidad'],
                    $producto['cantidad'],
                    $representante_admin_id,
                    $representante_admin_id,
                    $producto['producto_id']
                ]);

                $stmtMov->execute([
                    $representante_admin_id,
                    $producto['producto_id'],
                    $pedido_id,
                    $producto['cantidad'],
                    $producto['cantidad_antes'],
                    $producto['cantidad_despues'],
                    'Venta directa de representante'
                ]);
            }

            if ($requiere_factura) {
                $this->actualizarDatosFiscalesCliente($telefono, $datos);
            }

            $this->db->commit();

            // Register coupon usage after commit
            if ($cupon_id_input > 0 && $cupon_descuento_db > 0) {
                try {
                    require_once __DIR__ . '/Cupon.php';
                    $cuponModel = new Cupon();
                    $total_bruto = $total + $cupon_descuento_db;
                    $cuponModel->registrarUso($cupon_id_input, $pedido_id, $cupon_descuento_db, $total_bruto, $cliente['id'], null, $representante_admin_id);
                } catch (\Exception $e) {
                    // Non-fatal: coupon usage registration failure should not roll back the order
                    error_log('RepresentanteVenta: failed to register coupon usage: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'pedido_id' => $pedido_id,
                'total' => $total,
                'estado' => $estado,
                'mensaje' => 'Venta registrada'
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

    private function validarId($id, $mensaje) {
        $id = (int)$id;
        if ($id <= 0) {
            throw new Exception($mensaje);
        }
        return $id;
    }

    private function normalizarItems($items) {
        if (!is_array($items)) {
            throw new Exception('Los productos deben enviarse como arreglo');
        }

        $normalizados = [];
        foreach ($items as $item) {
            $producto_id = (int)($item['producto_id'] ?? 0);
            $cantidad = (int)($item['cantidad'] ?? 0);

            if ($producto_id <= 0 || $cantidad <= 0) {
                continue;
            }

            if (!isset($normalizados[$producto_id])) {
                $normalizados[$producto_id] = [
                    'producto_id' => $producto_id,
                    'cantidad' => 0
                ];
            }
            $normalizados[$producto_id]['cantidad'] += $cantidad;
            // Preserve precio_unitario override if provided (used in kit sales)
            if (isset($item['precio_unitario']) && (float)$item['precio_unitario'] > 0) {
                $normalizados[$producto_id]['precio_unitario'] = (float)$item['precio_unitario'];
            }
        }

        return array_values($normalizados);
    }

    private function validarDatosFiscales($datos) {
        $requeridos = ['rfc', 'razon_social', 'email_factura', 'codigo_postal', 'uso_cfdi', 'regimen_fiscal'];
        foreach ($requeridos as $campo) {
            if (trim($datos[$campo] ?? '') === '') {
                throw new Exception('Completa los datos fiscales para solicitar CFDI');
            }
        }

        $rfc = strtoupper(trim($datos['rfc']));
        if (strlen($rfc) < 12 || strlen($rfc) > 13) {
            throw new Exception('RFC no valido');
        }

        if (!filter_var(trim($datos['email_factura']), FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Correo de factura no valido');
        }

        if (!preg_match('/^\d{5}$/', trim($datos['codigo_postal']))) {
            throw new Exception('Codigo postal fiscal no valido');
        }
    }

    private function actualizarExtrasCliente($cliente_id, $datos) {
        $campos = [];
        $valores = [];

        $camposTexto = ['calle', 'numero', 'colonia', 'cp', 'ciudad', 'estado', 'referencias', 'quien_recibe', 'email_factura', 'especialidad', 'nombre_medico', 'telefono_medico'];
        foreach ($camposTexto as $campo) {
            $val = trim($datos[$campo] ?? '');
            if ($val !== '') {
                $campos[] = "`$campo` = ?";
                $valores[] = $val;
            }
        }

        // notif_factura: solo si viene explicitamente en los datos (checkbox)
        if (array_key_exists('notif_factura', $datos)) {
            $campos[] = '`notif_factura` = ?';
            $valores[] = empty($datos['notif_factura']) ? 0 : 1;
        }

        $tipo = trim($datos['tipo_cliente'] ?? '');
        if (in_array($tipo, ['medico', 'paciente'], true)) {
            $campos[] = '`tipo_cliente` = ?';
            $valores[] = $tipo;
        }

        if (empty($campos)) return;

        $valores[] = (int)$cliente_id;
        $stmt = $this->db->prepare('UPDATE clientes SET ' . implode(', ', $campos) . ' WHERE id = ?');
        $stmt->execute($valores);
    }

    private function obtenerOCrearCliente($telefono, $nombre, $representante_admin_id) {
        $stmt = $this->db->prepare("SELECT * FROM clientes WHERE telefono = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$telefono]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            $updates = [];
            $params = [];

            if ($nombre !== '') {
                $updates[] = 'nombre = ?';
                $params[] = $nombre;
            }
            if (empty($cliente['representante_admin_id'])) {
                $updates[] = 'representante_admin_id = ?';
                $params[] = $representante_admin_id;
            }

            if (!empty($updates)) {
                $params[] = $cliente['id'];
                $stmt = $this->db->prepare('UPDATE clientes SET ' . implode(', ', $updates) . ' WHERE id = ?');
                $stmt->execute($params);
                $stmt = $this->db->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
                $stmt->execute([$cliente['id']]);
                $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            return $cliente;
        }

        $stmt = $this->db->prepare("INSERT INTO clientes (telefono, nombre, representante_admin_id) VALUES (?, ?, ?)");
        $stmt->execute([$telefono, $nombre ?: null, $representante_admin_id]);

        $cliente_id = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
        $stmt->execute([$cliente_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Confirmar reserva: mueve qty de reservada → vendida.
     * Llamar cuando el pedido alcanza el umbral de estado configurado.
     */
    public function confirmarReserva(int $pedido_id): void {
        $stmt = $this->db->prepare("
            SELECT dp.producto_id, dp.cantidad, p.representante_admin_id
            FROM detalle_pedidos dp
            JOIN pedidos p ON p.id = dp.pedido_id
            WHERE dp.pedido_id = ? AND p.representante_admin_id IS NOT NULL
        ");
        $stmt->execute([$pedido_id]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lineas)) return;

        $rep_id = (int)$lineas[0]['representante_admin_id'];
        $stmtUpd = $this->db->prepare("
            UPDATE representante_inventario
            SET cantidad_reservada = GREATEST(cantidad_reservada - ?, 0),
                cantidad_vendida   = cantidad_vendida + ?
            WHERE representante_admin_id = ? AND producto_id = ?
        ");
        $stmtBefore = $this->db->prepare("
            SELECT cantidad_reservada, cantidad_vendida FROM representante_inventario
            WHERE representante_admin_id = ? AND producto_id = ?
        ");
        $stmtMov = $this->db->prepare("
            INSERT INTO representante_inventario_movimientos
                (representante_admin_id, producto_id, pedido_id, tipo, cantidad, cantidad_antes, cantidad_despues, notas)
            VALUES (?, ?, ?, 'venta', ?, ?, ?, NULL)
        ");
        foreach ($lineas as $l) {
            $qty = (int)$l['cantidad'];
            $pid = (int)$l['producto_id'];

            $stmtBefore->execute([$rep_id, $pid]);
            $row   = $stmtBefore->fetch(PDO::FETCH_ASSOC);
            $antes = (int)($row['cantidad_reservada'] ?? 0) + (int)($row['cantidad_vendida'] ?? 0);

            $stmtUpd->execute([$qty, $qty, $rep_id, $pid]);
            $stmtMov->execute([$rep_id, $pid, $pedido_id, $qty, $antes, $antes - $qty + $qty]);
        }
    }

    /**
     * Liberar reserva: mueve qty de reservada → disponible.
     * Llamar cuando el pedido se cancela.
     */
    public function liberarReserva(int $pedido_id): void {
        $stmt = $this->db->prepare("
            SELECT dp.producto_id, dp.cantidad, p.representante_admin_id
            FROM detalle_pedidos dp
            JOIN pedidos p ON p.id = dp.pedido_id
            WHERE dp.pedido_id = ? AND p.representante_admin_id IS NOT NULL
        ");
        $stmt->execute([$pedido_id]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lineas)) return;

        $rep_id = (int)$lineas[0]['representante_admin_id'];
        $stmtUpd = $this->db->prepare("
            UPDATE representante_inventario
            SET cantidad_reservada  = GREATEST(cantidad_reservada - ?, 0),
                cantidad_disponible = cantidad_disponible + ?
            WHERE representante_admin_id = ? AND producto_id = ?
        ");
        $stmtBefore = $this->db->prepare("
            SELECT cantidad_disponible FROM representante_inventario
            WHERE representante_admin_id = ? AND producto_id = ?
        ");
        $stmtMov = $this->db->prepare("
            INSERT INTO representante_inventario_movimientos
                (representante_admin_id, producto_id, pedido_id, tipo, cantidad, cantidad_antes, cantidad_despues, notas)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
        ");
        foreach ($lineas as $l) {
            $qty = (int)$l['cantidad'];
            $pid = (int)$l['producto_id'];

            $stmtBefore->execute([$rep_id, $pid]);
            $antes = (int)($stmtBefore->fetchColumn() ?? 0);

            $stmtUpd->execute([$qty, $qty, $rep_id, $pid]);
            $stmtMov->execute([$rep_id, $pid, $pedido_id, 'liberacion_reserva', $qty, $antes, $antes + $qty]);
        }
    }

    private function bloquearInventario($representante_id, $representante_admin_id, $producto_id) {
        $stmt = $this->db->prepare("
            SELECT
                ri.*,
                p.producto
            FROM representante_inventario ri
            INNER JOIN productos p ON p.id = ri.producto_id
            WHERE ri.representante_admin_id = ?
              AND ri.producto_id = ?
              AND p.activo = 1
            FOR UPDATE
        ");
        $stmt->execute([(int)$representante_admin_id, (int)$producto_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function obtenerPrecio($producto_id, $cantidad) {
        // Buscar rango exacto: cantidad cae dentro del intervalo
        $stmt = $this->db->prepare("
            SELECT precio
            FROM rangos_precios
            WHERE producto_id = ?
              AND cantidad_min <= ?
              AND (cantidad_max >= ? OR cantidad_max IS NULL)
            ORDER BY cantidad_min DESC
            LIMIT 1
        ");
        $stmt->execute([(int)$producto_id, (int)$cantidad, (int)$cantidad]);
        $precio = $stmt->fetchColumn();

        if ($precio !== false) {
            return (float)$precio;
        }

        // Fallback: si la cantidad supera todos los rangos definidos,
        // usar el rango con cantidad_min más alta (el último tramo aplicable)
        $stmt = $this->db->prepare("
            SELECT precio
            FROM rangos_precios
            WHERE producto_id = ?
              AND cantidad_min <= ?
            ORDER BY cantidad_min DESC
            LIMIT 1
        ");
        $stmt->execute([(int)$producto_id, (int)$cantidad]);
        $precio = $stmt->fetchColumn();
        return $precio === false ? null : (float)$precio;
    }

    private function guardarComprobante($file) {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se pudo recibir el comprobante');
        }

        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($file['type'], $allowedTypes, true)) {
            throw new Exception('El comprobante debe ser PDF, JPG o PNG');
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('El comprobante no debe superar 5MB');
        }

        $uploadDir = uploads_dir('comprobantes') . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'rep_pago_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $uploadPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('No se pudo guardar el comprobante');
        }

        return $filename;
    }

    private function actualizarDatosFiscalesCliente($telefono, $datos) {
        $stmt = $this->db->prepare("
            UPDATE clientes
            SET rfc = ?,
                razon_social = ?,
                email_factura = ?,
                codigo_postal = ?,
                uso_cfdi = ?,
                regimen_fiscal = ?
            WHERE telefono = ?
        ");
        $stmt->execute([
            strtoupper(trim($datos['rfc'] ?? '')),
            trim($datos['razon_social'] ?? ''),
            trim($datos['email_factura'] ?? ''),
            trim($datos['codigo_postal'] ?? ''),
            trim($datos['uso_cfdi'] ?? ''),
            trim($datos['regimen_fiscal'] ?? ''),
            $telefono
        ]);
    }
}
