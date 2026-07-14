-- =====================================================
-- SCRIPT DE VERIFICACIÓN POST-DEPLOY
-- Ejecutar en phpMyAdmin de Hostinger después del deploy
-- =====================================================

-- 1. Verificar que todas las tablas existen
SELECT 'Verificando tablas...' AS status;

SELECT 
    CASE 
        WHEN COUNT(*) = 7 THEN '✅ Todas las tablas existen'
        ELSE '❌ Faltan tablas'
    END AS resultado,
    COUNT(*) AS tablas_encontradas,
    7 AS tablas_esperadas
FROM information_schema.tables 
WHERE table_schema = DATABASE()
AND table_name IN (
    'usuarios', 'clientes', 'categorias', 'productos', 
    'pedidos', 'detalle_pedidos', 'mensajes_pedidos'
);

-- 2. Listar todas las tablas con conteo de registros
SELECT 
    table_name AS tabla,
    table_rows AS registros_aprox
FROM information_schema.tables 
WHERE table_schema = DATABASE()
ORDER BY table_name;

-- 3. Verificar estructura de tabla pedidos (incluyendo nuevas columnas)
SELECT 'Verificando columnas de pedidos...' AS status;

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
AND table_name = 'pedidos'
ORDER BY ordinal_position;

-- 4. Verificar estados válidos en ENUM
SELECT 'Verificando estados válidos...' AS status;

SELECT column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
AND table_name = 'pedidos'
AND column_name = 'estado';

-- 5. Verificar usuario admin existe
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Usuario admin existe'
        ELSE '❌ Usuario admin NO existe'
    END AS resultado
FROM usuarios 
WHERE username = 'admin';

-- 6. Contar registros por tabla
SELECT 'Resumen de datos...' AS status;

SELECT 
    (SELECT COUNT(*) FROM usuarios) AS total_usuarios,
    (SELECT COUNT(*) FROM clientes) AS total_clientes,
    (SELECT COUNT(*) FROM categorias) AS total_categorias,
    (SELECT COUNT(*) FROM productos) AS total_productos,
    (SELECT COUNT(*) FROM pedidos) AS total_pedidos,
    (SELECT COUNT(*) FROM detalle_pedidos) AS total_detalles,
    (SELECT COUNT(*) FROM mensajes_pedidos) AS total_mensajes;

-- 7. Verificar índices importantes
SELECT 'Verificando índices...' AS status;

SELECT 
    table_name, 
    index_name, 
    column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
AND table_name IN ('pedidos', 'productos', 'clientes')
ORDER BY table_name, index_name;

-- 8. Ver últimos pedidos (si existen)
SELECT 'Últimos 5 pedidos...' AS status;

SELECT 
    id,
    cliente_id,
    total,
    estado,
    fecha_creacion
FROM pedidos
ORDER BY fecha_creacion DESC
LIMIT 5;

-- =====================================================
-- RESULTADO ESPERADO:
-- ✅ 7 tablas existentes
-- ✅ Usuario admin presente
-- ✅ Estados del ENUM correctos
-- ✅ Columnas comprobante_envio presente
-- =====================================================
