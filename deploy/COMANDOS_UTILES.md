# 🔧 Comandos Útiles para Mantenimiento

## 📊 Consultas SQL Útiles

### Ver estadísticas generales
```sql
-- Resumen del sistema
SELECT 
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente') as pendientes,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'por_verificar') as por_verificar,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'confirmado') as confirmados,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'en_ruta') as en_ruta,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'entregado') as entregados,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'cancelado') as cancelados,
    (SELECT SUM(total) FROM pedidos WHERE estado != 'cancelado') as ventas_totales;
```

### Ver últimos pedidos
```sql
-- Últimos 10 pedidos con información del cliente
SELECT 
    p.id,
    p.total,
    p.estado,
    p.fecha_creacion,
    c.nombre,
    c.telefono
FROM pedidos p
JOIN clientes c ON p.cliente_id = c.id
ORDER BY p.fecha_creacion DESC
LIMIT 10;
```

### Ver productos más vendidos
```sql
-- Top 10 productos más vendidos
SELECT 
    pr.nombre,
    pr.precio,
    SUM(dp.cantidad) as total_vendido,
    SUM(dp.subtotal) as ingresos_totales
FROM detalle_pedidos dp
JOIN productos pr ON dp.producto_id = pr.id
JOIN pedidos p ON dp.pedido_id = p.id
WHERE p.estado != 'cancelado'
GROUP BY pr.id, pr.nombre, pr.precio
ORDER BY total_vendido DESC
LIMIT 10;
```

### Ver clientes frecuentes
```sql
-- Clientes con más pedidos
SELECT 
    c.nombre,
    c.telefono,
    c.email,
    COUNT(p.id) as total_pedidos,
    SUM(p.total) as total_gastado
FROM clientes c
JOIN pedidos p ON c.id = p.cliente_id
WHERE p.estado != 'cancelado'
GROUP BY c.id, c.nombre, c.telefono, c.email
ORDER BY total_pedidos DESC
LIMIT 10;
```

### Limpiar datos antiguos
```sql
-- Eliminar pedidos cancelados de hace más de 6 meses
DELETE FROM pedidos 
WHERE estado = 'cancelado' 
AND fecha_creacion < DATE_SUB(NOW(), INTERVAL 6 MONTH);

-- Eliminar mensajes de pedidos completados hace más de 1 año
DELETE mp FROM mensajes_pedidos mp
JOIN pedidos p ON mp.pedido_id = p.id
WHERE p.estado = 'entregado'
AND p.fecha_actualizacion < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

### Optimizar tablas
```sql
-- Optimizar todas las tablas
OPTIMIZE TABLE usuarios;
OPTIMIZE TABLE clientes;
OPTIMIZE TABLE categorias;
OPTIMIZE TABLE productos;
OPTIMIZE TABLE pedidos;
OPTIMIZE TABLE detalle_pedidos;
OPTIMIZE TABLE mensajes_pedidos;
```

## 📁 Comandos de Archivos

### Comprimir uploads para backup (Linux/Mac)
```bash
# Backup de imágenes de productos
tar -czf backup_productos_$(date +%Y%m%d).tar.gz uploads/productos/

# Backup de comprobantes
tar -czf backup_comprobantes_$(date +%Y%m%d).tar.gz uploads/comprobantes_pago/ uploads/comprobantes_envio/
```

### Verificar espacio en disco (Hostinger)
```bash
# Via SSH (si tienes acceso)
du -sh uploads/*
du -sh *
df -h
```

### Permisos correctos (via SSH o File Manager)
```bash
# Permisos recomendados
find uploads/ -type d -exec chmod 755 {} \;
find uploads/ -type f -exec chmod 644 {} \;
chmod 644 config/database.php
```

## 🔐 Seguridad

### Generar contraseña segura (PHP)
```php
<?php
// Ejecutar en un archivo temporal y luego eliminar
$password = "tu_nueva_contraseña";
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Hash para SQL: " . $hash;
?>
```

### Verificar integridad de archivos
```sql
-- Ver últimas modificaciones en la BD
SELECT 
    table_name, 
    update_time
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY update_time DESC;
```

## 📊 Monitoreo

### Ver logs de error PHP
```bash
# Ubicación típica en Hostinger
tail -f /home/u123456789/public_html/botikitpedidos/error_log

# Ver últimas 50 líneas
tail -n 50 error_log
```

### Consultas de diagnóstico
```sql
-- Ver conexiones activas
SHOW PROCESSLIST;

-- Ver tamaño de tablas
SELECT 
    table_name AS 'Tabla',
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Tamaño (MB)'
FROM information_schema.TABLES 
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC;

-- Ver índices de una tabla
SHOW INDEX FROM pedidos;
```

## 🚀 Optimización

### Añadir índices si es necesario
```sql
-- Índice en fecha_creacion para consultas rápidas
ALTER TABLE pedidos ADD INDEX idx_fecha_creacion (fecha_creacion);

-- Índice en estado para filtros rápidos
ALTER TABLE pedidos ADD INDEX idx_estado (estado);

-- Índice compuesto para búsquedas complejas
ALTER TABLE pedidos ADD INDEX idx_estado_fecha (estado, fecha_creacion);
```

### Cachear consultas frecuentes (PHP)
```php
<?php
// Ejemplo de cache simple con archivos
function getCachedData($key, $callback, $ttl = 3600) {
    $cache_file = __DIR__ . '/cache/' . md5($key) . '.cache';
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        return unserialize(file_get_contents($cache_file));
    }
    
    $data = $callback();
    file_put_contents($cache_file, serialize($data));
    return $data;
}

// Uso
$productos = getCachedData('productos_activos', function() use ($db) {
    $stmt = $db->query("SELECT * FROM productos WHERE activo = 1");
    return $stmt->fetchAll();
}, 1800); // Cache 30 minutos
?>
```

## 📧 Email (si configuras SMTP)

### Enviar email de prueba
```php
<?php
// test_email.php
$to = "tu_email@ejemplo.com";
$subject = "Prueba desde BotiKit Pedidos";
$message = "Este es un email de prueba del sistema.";
$headers = "From: noreply@tudominio.com";

if (mail($to, $subject, $message, $headers)) {
    echo "Email enviado correctamente";
} else {
    echo "Error al enviar email";
}
?>
```

## 🔄 Actualizaciones

### Aplicar cambios de BD sin afectar datos
```sql
-- Agregar columna nueva (ejemplo)
ALTER TABLE productos 
ADD COLUMN destacado TINYINT(1) DEFAULT 0 
AFTER activo;

-- Modificar columna existente
ALTER TABLE productos 
MODIFY COLUMN descripcion TEXT;

-- Agregar nuevo estado al ENUM (si fuera necesario en el futuro)
ALTER TABLE pedidos 
MODIFY COLUMN estado ENUM('pendiente','por_verificar','confirmado','en_ruta','entregado','cancelado','nuevo_estado');
```

## 📱 WhatsApp Integration (Futuro)

### Preparar tabla para logs de WhatsApp
```sql
-- Crear tabla para logs de notificaciones (opcional)
CREATE TABLE logs_notificaciones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pedido_id INT NOT NULL,
    tipo VARCHAR(50) NOT NULL, -- 'email', 'whatsapp', 'push'
    destinatario VARCHAR(255),
    mensaje TEXT,
    estado VARCHAR(20), -- 'enviado', 'fallido', 'pendiente'
    fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
);
```

## 🔍 Troubleshooting

### Resetear sistema (SOLO EN EMERGENCIA)
```sql
-- ⚠️ CUIDADO: Esto eliminará TODOS los pedidos
-- Usar solo en desarrollo o reseteo completo

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE mensajes_pedidos;
TRUNCATE TABLE detalle_pedidos;
TRUNCATE TABLE pedidos;
TRUNCATE TABLE clientes;
SET FOREIGN_KEY_CHECKS = 1;

-- Mantener productos y categorías intactos
```

### Restaurar pedido borrado accidentalmente
```sql
-- Si tienes backup reciente
-- 1. Exportar tabla de backup
-- 2. Importar con INSERT IGNORE para no duplicar
-- 3. O usar este query para copiar un pedido específico:

INSERT INTO pedidos SELECT * FROM backup_pedidos WHERE id = 123;
```

## 📈 Reportes

### Ventas por mes
```sql
SELECT 
    DATE_FORMAT(fecha_creacion, '%Y-%m') as mes,
    COUNT(*) as total_pedidos,
    SUM(total) as ventas_totales,
    AVG(total) as ticket_promedio
FROM pedidos
WHERE estado != 'cancelado'
GROUP BY DATE_FORMAT(fecha_creacion, '%Y-%m')
ORDER BY mes DESC
LIMIT 12;
```

### Productos con bajo stock (si implementas inventario)
```sql
-- Si agregas columna 'stock'
SELECT 
    nombre,
    stock,
    precio
FROM productos
WHERE activo = 1 
AND stock < 10
ORDER BY stock ASC;
```

---

## 🎯 Tips Finales

### Performance
- Mantén menos de 10,000 pedidos en estado activo
- Archiva pedidos entregados de hace más de 1 año
- Limpia mensajes de chat antiguos mensualmente
- Optimiza imágenes antes de subir (< 500KB)

### Seguridad
- Cambia contraseñas cada 3 meses
- Revisa error_log semanalmente
- Mantén backups en 3 lugares diferentes
- Actualiza PHP cuando Hostinger lo recomiende

### Mantenimiento
- Backup semanal de BD
- Backup mensual de archivos
- Revisar espacio en disco mensualmente
- Limpiar archivos temporales trimestralmente

---

**BotiKit Pedidos - Sistema de Gestión v1.0**
