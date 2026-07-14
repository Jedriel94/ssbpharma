-- Parámetros
SET @pedido_id   := 46;
SET @nueva_fecha := '2026-08-06 11:58:00';

START TRANSACTION;

-- Tomar la fecha actual del pedido y calcular el desplazamiento
SELECT created_at
INTO @fecha_vieja
FROM pedidos
WHERE id = @pedido_id
FOR UPDATE;

SET @delta_segundos := TIMESTAMPDIFF(SECOND, @fecha_vieja, @nueva_fecha);

-- Pedido principal
UPDATE pedidos
SET
    created_at = DATE_ADD(created_at, INTERVAL @delta_segundos SECOND),
    updated_at = DATE_ADD(updated_at, INTERVAL @delta_segundos SECOND),
    fecha_pago = IF(fecha_pago IS NULL, NULL, DATE_ADD(fecha_pago, INTERVAL @delta_segundos SECOND)),
    fecha_por_verificar = IF(fecha_por_verificar IS NULL, NULL, DATE_ADD(fecha_por_verificar, INTERVAL @delta_segundos SECOND)),
    fecha_confirmacion_pago = IF(fecha_confirmacion_pago IS NULL, NULL, DATE_ADD(fecha_confirmacion_pago, INTERVAL @delta_segundos SECOND)),
    fecha_entrega_directa = IF(fecha_entrega_directa IS NULL, NULL, DATE_ADD(fecha_entrega_directa, INTERVAL @delta_segundos SECOND))
WHERE id = @pedido_id;

-- Tablas afines al pedido
UPDATE detalle_pedidos
SET created_at = DATE_ADD(created_at, INTERVAL @delta_segundos SECOND)
WHERE pedido_id = @pedido_id;

UPDATE kit_ventas
SET created_at = DATE_ADD(created_at, INTERVAL @delta_segundos SECOND)
WHERE pedido_id = @pedido_id;

UPDATE mensajes_pedido
SET created_at = DATE_ADD(created_at, INTERVAL @delta_segundos SECOND)
WHERE pedido_id = @pedido_id;

UPDATE cupones_uso
SET fecha_uso = DATE_ADD(fecha_uso, INTERVAL @delta_segundos SECOND)
WHERE pedido_id = @pedido_id;

UPDATE liga_pago_queue
SET
    created_at = DATE_ADD(created_at, INTERVAL @delta_segundos SECOND),
    processed_at = IF(processed_at IS NULL, NULL, DATE_ADD(processed_at, INTERVAL @delta_segundos SECOND))
WHERE pedido_id = @pedido_id;

UPDATE representante_inventario_movimientos
SET created_at = DATE_ADD(created_at, INTERVAL @delta_segundos SECOND)
WHERE pedido_id = @pedido_id;

COMMIT;