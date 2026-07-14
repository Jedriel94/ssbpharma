<?php
$detalle = $pedidoModel->getDetalle($pedido['id']);
$mensajes_cliente_no_leidos = $mensajeModel->contarNoLeidosAdmin($pedido['id']);

// Detectar si el cliente solicitó liga de pago (método de pago = 'liga_pago' y sin liga asignada)
$solicita_liga = ($pedido['metodo_pago'] === 'liga_pago' && empty($pedido['liga_pago']));
$canal = $pedido['canal'] ?? 'cliente_directo';
$es_entrega_directa = (($pedido['canal'] ?? '') === 'representante_directo') || ((int)($pedido['entrega_directa'] ?? 0) === 1);
$factura_pendiente = !empty($pedido['requiere_factura']) && empty($pedido['factura_pdf']) && empty($pedido['factura_xml']);

// ── Badge "mes anterior" ─────────────────────────────────────────────────
// Detecta si el pedido entró a por_verificar en un mes anterior al actual
$badge_fin_mes = false;
$fecha_verificar_str = '';
if (!empty($pedido['fecha_por_verificar']) && $pedido['estado'] === 'por_verificar') {
    $fv = new DateTime($pedido['fecha_por_verificar'], new DateTimeZone('America/Mexico_City'));
    $hoy = new DateTime('now', new DateTimeZone('America/Mexico_City'));
    if ($fv->format('Y-m') < $hoy->format('Y-m')) {
        $badge_fin_mes = true;
        $fecha_verificar_str = $fv->format('d/m/Y');
    }
}

// Detectar si hay solicitud de liga en los mensajes
$mensajes = $mensajeModel->getByPedido($pedido['id']);
$tiene_solicitud_liga = false;
foreach ($mensajes as $msg) {
    if ($msg['usuario_tipo'] === 'cliente' && 
        (stripos($msg['mensaje'], 'solicita liga de pago') !== false || 
         stripos($msg['mensaje'], 'liga de pago') !== false)) {
        $tiene_solicitud_liga = true;
        break;
    }
}
?>

<div class="kanban-card card rounded-xl p-4 bg-white shadow-md hover:shadow-lg transition" 
     data-pedido-id="<?= $pedido['id'] ?>" 
     data-estado="<?= $pedido['estado'] ?>"
     data-canal="<?= htmlspecialchars($pedido['canal'] ?? 'cliente_directo') ?>"
     data-representante-admin-id="<?= htmlspecialchars((string)($pedido['representante_admin_id'] ?? '')) ?>"
     data-entrega-directa="<?= $es_entrega_directa ? '1' : '0' ?>"
     data-cfdi-pendiente="<?= $factura_pendiente ? '1' : '0' ?>"
     data-liquidacion="<?= htmlspecialchars($pedido['estado_liquidacion'] ?? 'no_aplica') ?>"
     data-pago-validar="<?= in_array($pedido['estado'], ['pendiente', 'por_verificar'], true) ? '1' : '0' ?>">
    <!-- Header de la tarjeta -->
    <div class="flex justify-between items-start mb-3">
        <div class="flex-1">
            <h3 class="font-bold text-slate-900 text-sm">
                Pedido #<?= str_pad($pedido['id'], 4, '0', STR_PAD_LEFT) ?>
            </h3>
            <p class="text-xs text-slate-600">
                <?= date('d/m H:i', strtotime($pedido['created_at'])) ?>
            </p>
        </div>
        <p class="font-bold text-terracotta-600">
            $<?= number_format($pedido['total'], 2) ?>
        </p>
    </div>
    
    <!-- Cliente -->
    <div class="mb-3 pb-3 border-b border-slate-100">
        <p class="text-xs text-slate-600">👤 Cliente:</p>
        <p class="font-semibold text-slate-900 text-sm truncate">
            <?= htmlspecialchars($pedido['telefono']) ?>
        </p>
        <?php if (!empty($pedido['nombre'])): ?>
            <p class="text-xs text-slate-600 truncate"><?= htmlspecialchars($pedido['nombre']) ?></p>
        <?php endif; ?>
    </div>

    <!-- Origen / representante -->
    <div class="mb-3 flex flex-wrap gap-1">
        <?php if ($es_entrega_directa): ?>
            <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                Entrega directa
            </span>
        <?php else: ?>
            <div class="flex flex-col gap-1 w-full">
                <?php if ($canal === 'representante_qr'): ?>
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200 w-fit">
                        QR rep
                    </span>
                <?php elseif ($canal === 'cliente_directo' && !empty($pedido['representante_admin_id'])): ?>
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200 w-fit">
                        Tienda
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200 w-fit">
                        Web
                    </span>
                <?php endif; ?>
                <?php if (!empty($pedido['representante_nombre_real'])): ?>
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200 w-fit">
                        Rep: <?= htmlspecialchars($pedido['representante_nombre_real']) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($es_entrega_directa && !empty($pedido['representante_nombre_real'])): ?>
            <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                Rep: <?= htmlspecialchars($pedido['representante_nombre_real']) ?>
            </span>
        <?php endif; ?>

        <?php if (($pedido['metodo_pago'] ?? '') === 'efectivo' && ($pedido['estado_liquidacion'] ?? '') === 'pendiente'): ?>
            <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-orange-50 text-orange-700 border border-orange-200">
                Efectivo pendiente
            </span>
        <?php elseif (($pedido['metodo_pago'] ?? '') === 'efectivo' && ($pedido['estado_liquidacion'] ?? '') === 'liquidado'): ?>
            <span class="inline-flex items-center px-2 py-1 rounded-lg text-[11px] font-bold bg-green-50 text-green-700 border border-green-200">
                Efectivo liquidado
            </span>
        <?php endif; ?>
    </div>
    
    <!-- Productos (resumido) -->
    <div class="mb-3">
        <p class="text-xs text-slate-600 mb-1">
            📦 <?= count($detalle) ?> producto<?= count($detalle) != 1 ? 's' : '' ?>
        </p>
        <div class="text-xs text-slate-500 line-clamp-2">
            <?= implode(', ', array_map(fn($item) => $item['producto'] . ' (x' . $item['cantidad'] . ')', $detalle)) ?>
        </div>
    </div>
    
    <!-- Badge: Solicitud de Liga de Pago -->
    <?php if ($solicita_liga || $tiene_solicitud_liga): ?>
        <div class="mb-3 p-2 bg-purple-50 border-2 border-purple-300 rounded-lg animate-pulse">
            <p class="text-xs font-bold text-purple-800 flex items-center gap-1">
                🔗 Cliente solicita Liga de Pago
            </p>
            <p class="text-xs text-purple-600 mt-1">
                Abre el chat para enviar el enlace
            </p>
        </div>
    <?php endif; ?>

    <!-- Badge: Fin de mes anterior -->
    <?php if ($badge_fin_mes): ?>
        <div class="mb-3 p-2 bg-amber-50 border-2 border-amber-400 rounded-lg"
             title="Verificado el último día laborable de <?= htmlspecialchars($fecha_verificar_str) ?>">
            <p class="text-xs font-bold text-amber-800 flex items-center gap-1">
                ⚠️ Verificado en mes anterior
            </p>
            <p class="text-xs text-amber-700 mt-0.5">
                Verificado el <?= htmlspecialchars($fecha_verificar_str) ?> — al confirmar podrás usar esa fecha
            </p>
        </div>
    <?php endif; ?>
    
    <!-- Comprobantes -->
    <?php if (!empty($pedido['comprobante_pago'])): ?>
        <div class="mb-2">
            <a href="../uploads/comprobantes/<?= htmlspecialchars($pedido['comprobante_pago']) ?>" 
               target="_blank"
               class="text-xs text-blue-600 hover:text-blue-800 flex items-center gap-1">
                💳 Ver comprobante pago
            </a>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($pedido['comprobante_envio'])): ?>
        <div class="mb-2">
            <a href="../uploads/comprobantes_envio/<?= htmlspecialchars($pedido['comprobante_envio']) ?>" 
               target="_blank"
               class="text-xs text-purple-600 hover:text-purple-800 flex items-center gap-1">
                📤 Ver comprobante envío
            </a>
        </div>
    <?php endif; ?>
    
    <!-- Factura Electrónica -->
    <?php if ($pedido['requiere_factura']): ?>
        <div class="mb-2 p-2 bg-amber-50 border border-amber-200 rounded-lg">
            <p class="text-xs font-semibold text-amber-800 mb-1">🧾 Requiere Factura</p>
            
            <?php if (!empty($pedido['factura_pdf']) || !empty($pedido['factura_xml'])): ?>
                <div class="space-y-1">
                    <?php if (!empty($pedido['factura_pdf'])): ?>
                        <a href="../uploads/facturas/<?= htmlspecialchars($pedido['factura_pdf']) ?>" 
                           target="_blank"
                           class="text-xs text-green-600 hover:text-green-800 flex items-center gap-1">
                            ✅ Ver PDF
                        </a>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['factura_xml'])): ?>
                        <a href="../uploads/facturas/<?= htmlspecialchars($pedido['factura_xml']) ?>" 
                           target="_blank" download
                           class="text-xs text-green-600 hover:text-green-800 flex items-center gap-1">
                            ✅ Descargar XML
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-xs text-amber-600">⏳ Factura pendiente</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Datos de Envío y Fiscales (solo en estado Confirmado) -->
    <?php if ($pedido['estado'] === 'confirmado'): ?>
        
        <!-- Datos de Envío -->
        <?php if (!empty($pedido['calle']) || !empty($pedido['numero']) || !empty($pedido['cp_envio'])): ?>
            <div class="mb-2 p-2 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-xs font-semibold text-blue-800 mb-2">📦 Datos de Envío</p>
                <div class="space-y-1 text-xs text-blue-900">
                    <?php if (!empty($pedido['calle']) || !empty($pedido['numero'])): ?>
                        <p><strong>📍 Dirección:</strong> <?= htmlspecialchars($pedido['calle'] ?? '') ?> <?= htmlspecialchars($pedido['numero'] ?? '') ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['colonia'])): ?>
                        <p><strong>🏘️ Colonia:</strong> <?= htmlspecialchars($pedido['colonia']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['ciudad']) || !empty($pedido['estado_envio']) || !empty($pedido['cp_envio'])): ?>
                        <p><strong>🗺️ Ubicación:</strong> 
                            <?php
                            $ubicacion = array_filter([
                                $pedido['ciudad'] ?? '',
                                $pedido['estado_envio'] ?? '',
                                'CP: ' . ($pedido['cp_envio'] ?? '')
                            ]);
                            echo htmlspecialchars(implode(', ', $ubicacion));
                            ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['referencias'])): ?>
                        <p><strong>📌 Referencias:</strong> <?= htmlspecialchars($pedido['referencias']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['quien_recibe'])): ?>
                        <p><strong>🙋 Recibe:</strong> <?= htmlspecialchars($pedido['quien_recibe']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Datos Fiscales (si requiere factura) -->
        <?php if ($pedido['requiere_factura']): ?>
            <div class="mb-2 p-2 bg-purple-50 border border-purple-200 rounded-lg">
                <p class="text-xs font-semibold text-purple-800 mb-2">🧾 Datos Fiscales</p>
                <div class="space-y-1 text-xs text-purple-900">
                    <?php if (!empty($pedido['rfc'])): ?>
                        <p><strong>🆔 RFC:</strong> <?= htmlspecialchars($pedido['rfc']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['razon_social'])): ?>
                        <p><strong>🏢 Razón Social:</strong> <?= htmlspecialchars($pedido['razon_social']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['email_factura'])): ?>
                        <p><strong>📧 Email:</strong> <?= htmlspecialchars($pedido['email_factura']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['codigo_postal'])): ?>
                        <p><strong>📮 CP Fiscal:</strong> <?= htmlspecialchars($pedido['codigo_postal']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['regimen_fiscal'])): ?>
                        <p><strong>📊 Régimen:</strong> <?= htmlspecialchars($pedido['regimen_fiscal']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pedido['uso_cfdi'])): ?>
                        <p><strong>📄 Uso CFDI:</strong> <?= htmlspecialchars($pedido['uso_cfdi']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Acciones según estado -->
    <div class="mt-3 pt-3 border-t border-slate-100 space-y-2">
        
        <!-- Chat -->
        <a href="chat-admin.php?pedido_id=<?= $pedido['id'] ?>&return=kanban" 
           data-pedido-id="<?= $pedido['id'] ?>"
           data-chat-link
           class="w-full bg-sage-500 hover:bg-sage-600 text-white px-3 py-2 rounded-lg text-xs font-medium flex items-center justify-center gap-1 transition relative">
            💬 Chat
            <?php if ($mensajes_cliente_no_leidos > 0): ?>
                <span class="badge-mensajes absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center">
                    <?= $mensajes_cliente_no_leidos ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- Número de Factura -->
        <?php $num_factura_val = $pedido['num_factura'] ?? ''; ?>
        <?php if (!empty($num_factura_val)): ?>
            <span class="nf-badge inline-block px-2 py-0.5 bg-teal-50 text-teal-700 border border-teal-200 rounded text-[11px] font-mono font-semibold">
                🔢 <?= htmlspecialchars($num_factura_val) ?>
            </span>
        <?php endif; ?>
        <button data-nf-btn
                onclick="abrirModalNumFactura(<?= $pedido['id'] ?>, '<?= htmlspecialchars(addslashes($num_factura_val)) ?>')"
                class="w-full bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 px-3 py-2 rounded-lg text-xs font-semibold flex items-center justify-center gap-1 transition">
            <?= empty($num_factura_val) ? '🔢 N° Factura' : '✏️ Editar N° Factura' ?>
        </button>
        
        <!-- Botones específicos por estado -->
        <?php if ($pedido['estado'] === 'por_verificar' && !empty($pedido['comprobante_pago'])): ?>
            <button onclick="confirmarPago(<?= $pedido['id'] ?>, <?= $badge_fin_mes ? htmlspecialchars(json_encode($pedido['fecha_por_verificar']), ENT_QUOTES) : 'null' ?>)"
                    class="w-full bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                ✅ Confirmar Pago<?= $es_entrega_directa && !$factura_pendiente ? ' → Entregado' : ' → Confirmado' ?>
            </button>
        <?php endif; ?>
        
        <?php if ($pedido['estado'] === 'confirmado'): ?>
            <!-- Botón Subir Factura (si requiere factura y no tiene archivos) -->
            <?php if ($pedido['requiere_factura'] && empty($pedido['factura_pdf']) && empty($pedido['factura_xml'])): ?>
                <button onclick="abrirModalFactura(<?= $pedido['id'] ?>)"
                        class="w-full bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                    🧾 Subir Factura
                </button>
            <?php endif; ?>
            
            <?php if ($es_entrega_directa): ?>
                <?php if (!$factura_pendiente): ?>
                    <button onclick="cambiarEstado(<?= $pedido['id'] ?>, 'entregado')"
                            class="w-full bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                        ✅ Cerrar como Entregado
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <button onclick="abrirModalEnvio(<?= $pedido['id'] ?>)"
                        class="w-full bg-purple-500 hover:bg-purple-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                    📤 Subir Guía → En Ruta
                </button>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($pedido['estado'] === 'en_ruta'): ?>
            <!-- Botón Subir Factura (si requiere factura y no tiene archivos) -->
            <?php if ($pedido['requiere_factura'] && empty($pedido['factura_pdf']) && empty($pedido['factura_xml'])): ?>
                <button onclick="abrirModalFactura(<?= $pedido['id'] ?>)"
                        class="w-full bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                    🧾 Subir Factura
                </button>
            <?php endif; ?>
            
            <button onclick="cambiarEstado(<?= $pedido['id'] ?>, 'entregado')"
                    class="w-full bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                ✅ Marcar Entregado
            </button>
        <?php endif; ?>
        
        <?php if ($pedido['estado'] === 'entregado'): ?>
            <!-- Botón Subir Factura (si requiere factura y no tiene archivos) -->
            <?php if ($pedido['requiere_factura'] && empty($pedido['factura_pdf']) && empty($pedido['factura_xml'])): ?>
                <button onclick="abrirModalFactura(<?= $pedido['id'] ?>)"
                        class="w-full bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-xs font-medium transition">
                    🧾 Subir Factura
                </button>
            <?php endif; ?>
        <?php endif; ?>
        
    </div>
</div>
