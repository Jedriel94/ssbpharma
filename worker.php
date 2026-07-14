<?php
/**
 * Worker para Generación Automática de Ligas de Pago
 * 
 * Este script corre en loop infinito en la PC del administrador.
 * Lee la cola de trabajos pendientes y ejecuta la macro para generar ligas.
 * 
 * Uso:
 *   php worker.php
 * 
 * Para detener:
 *   Ctrl+C
 */

require_once __DIR__ . '/config/database.php';

// Configuración
define('WORKER_INTERVAL', 10);  // Segundos entre cada verificación
define('MAX_INTENTOS', 3);      // Reintentos máximos por job
/* define('MACRO_PATH', __DIR__ . '/macro.exe');  // Ruta al ejecutable de la macro */
define('MACRO_PATH', __DIR__ . '/macro-simulador.bat');  // Ruta al ejecutable de la macro


// Banner de inicio
echo str_repeat("=", 60) . "\n";
echo "🚀 WORKER - GENERADOR AUTOMÁTICO DE LIGAS DE PAGO\n";
echo str_repeat("=", 60) . "\n";
echo "Iniciado: " . date('Y-m-d H:i:s') . "\n";
echo "Intervalo: " . WORKER_INTERVAL . " segundos\n";
echo "Presiona Ctrl+C para detener\n";
echo str_repeat("=", 60) . "\n\n";

// Conectar a base de datos
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    echo "✅ Conexión a base de datos establecida\n\n";
} catch (Exception $e) {
    die("❌ ERROR: No se pudo conectar a la base de datos: " . $e->getMessage() . "\n");
}

// Contador de ciclos sin actividad (para heartbeat visual)
$ciclosSinActividad = 0;

// Loop infinito
while (true) {
    try {
        // 1. Buscar próximo job pendiente
        $stmt = $pdo->prepare("
            SELECT * FROM liga_pago_queue 
            WHERE estado = 'pendiente' 
            AND intentos < :max_intentos
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt->execute(['max_intentos' => MAX_INTENTOS]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($job) {
            // Resetear contador de inactividad
            $ciclosSinActividad = 0;
            
            echo "\n" . str_repeat("-", 60) . "\n";
            echo "📋 NUEVO JOB #" . $job['id'] . "\n";
            echo "   Pedido: #" . $job['pedido_id'] . "\n";
            echo "   Monto: $" . number_format($job['monto'], 2) . "\n";
            echo "   Cliente: " . $job['nombre_cliente'] . "\n";
            echo "   Intento: " . ($job['intentos'] + 1) . "/" . MAX_INTENTOS . "\n";
            echo str_repeat("-", 60) . "\n";
            
            // 2. Marcar como procesando
            $stmt = $pdo->prepare("
                UPDATE liga_pago_queue 
                SET estado = 'procesando',
                    intentos = intentos + 1
                WHERE id = ?
            ");
            $stmt->execute([$job['id']]);
            
            echo "[" . date('H:i:s') . "] ⏳ Ejecutando macro...\n";
            
            // 3. EJECUTAR MACRO
            // Formato: macro.exe 999.00 123 "Cliente" 1 --headless
            $comando = sprintf(
                '"%s" %s %s "%s" 1 --headless',
                MACRO_PATH,
                $job['monto'],
                $job['pedido_id'],
                addslashes($job['nombre_cliente'])
            );
            
            echo "[" . date('H:i:s') . "] 💻 Comando: $comando\n";
            
            // Ejecutar y capturar salida
            $output = shell_exec($comando . " 2>&1");
            
            // Buscar la última URL válida en la salida
            $lineas = explode("\n", $output);
            $enlace = null;
            
            // Recorrer de atrás hacia adelante buscando una URL válida
            for ($i = count($lineas) - 1; $i >= 0; $i--) {
                $linea = trim($lineas[$i]);
                if (filter_var($linea, FILTER_VALIDATE_URL)) {
                    $enlace = $linea;
                    break;
                }
            }
            
            echo "[" . date('H:i:s') . "] 📤 Respuesta de macro: " . substr($output, 0, 200) . "...\n";
            echo "[" . date('H:i:s') . "] 🔗 URL extraída: $enlace\n";
            
            // 4. Validar que retornó un enlace válido
            if (empty($enlace)) {
                throw new Exception("La macro no retornó ninguna URL válida");
            }
            
            echo "[" . date('H:i:s') . "] ✅ Liga generada exitosamente\n";
            
            // 5. RECONECTAR A LA BASE DE DATOS
            // (La macro puede tardar más de 1 minuto, y MySQL cierra la conexión)
            echo "[" . date('H:i:s') . "] 🔄 Reconectando a base de datos...\n";
            try {
                $pdo = null; // Cerrar conexión anterior
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $pdo = new PDO($dsn, DB_USER, DB_PASS);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("SET time_zone = '-06:00'");
                echo "[" . date('H:i:s') . "] ✅ Conexión restablecida\n";
            } catch (PDOException $e) {
                throw new Exception("Error al reconectar: " . $e->getMessage());
            }
            
            // 6. Guardar enlace en tabla pedidos
            $stmt = $pdo->prepare("UPDATE pedidos SET liga_pago = ? WHERE id = ?");
            $stmt->execute([$enlace, $job['pedido_id']]);
            
            echo "[" . date('H:i:s') . "] 💾 Enlace guardado en pedido #" . $job['pedido_id'] . "\n";
            
            // 7. Marcar job como completado
            $stmt = $pdo->prepare("
                UPDATE liga_pago_queue 
                SET estado = 'completado', 
                    enlace_generado = ?,
                    processed_at = NOW(),
                    error_mensaje = NULL
                WHERE id = ?
            ");
            $stmt->execute([$enlace, $job['id']]);
            
            echo "[" . date('H:i:s') . "] 🎉 Job completado exitosamente\n";
            echo str_repeat("=", 60) . "\n";
            
        } else {
            // No hay jobs pendientes
            $ciclosSinActividad++;
            
            // Mostrar heartbeat cada 6 ciclos (1 minuto si interval=10s)
            if ($ciclosSinActividad % 6 == 0) {
                echo "[" . date('H:i:s') . "] 💤 Esperando trabajos... (" . ($ciclosSinActividad * WORKER_INTERVAL) . "s sin actividad)\n";
            } else {
                echo ".";
            }
        }
        
    } catch (Exception $e) {
        echo "\n❌ ERROR: " . $e->getMessage() . "\n";
        
        // Marcar job como error (si existe)
        if (isset($job) && $job) {
            try {
                // Reconectar a base de datos por si se perdió la conexión
                $pdo = null;
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $pdo = new PDO($dsn, DB_USER, DB_PASS);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("SET time_zone = '-06:00'");
                
                // Si alcanzó el máximo de intentos, marcar como error permanente
                $nuevoEstado = ($job['intentos'] + 1 >= MAX_INTENTOS) ? 'error' : 'pendiente';
                
                $stmt = $pdo->prepare("
                    UPDATE liga_pago_queue 
                    SET estado = ?,
                        error_mensaje = ?
                    WHERE id = ?
                ");
                
                // Limpiar mensaje de error: solo ASCII, máximo 500 caracteres
                $errorMensaje = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
                $errorMensaje = substr($errorMensaje, 0, 500);
                
                $stmt->execute([
                    $nuevoEstado,
                    $errorMensaje,
                    $job['id']
                ]);
                
                if ($nuevoEstado === 'error') {
                    echo "⚠️  Job marcado como ERROR después de " . MAX_INTENTOS . " intentos\n";
                } else {
                    echo "🔄 Job regresado a cola para reintento\n";
                }
                
            } catch (Exception $updateError) {
                echo "⚠️  No se pudo actualizar el estado del job: " . $updateError->getMessage() . "\n";
            }
        }
        
        echo str_repeat("=", 60) . "\n\n";
    }
    
    // Esperar antes de siguiente iteración
    sleep(WORKER_INTERVAL);
}
