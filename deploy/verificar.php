<?php
/**
 * Script de verificación post-deploy
 * Ejecutar visitando: https://tudominio.com/botikitpedidos/deploy/verificar.php
 * 
 * ⚠️ IMPORTANTE: Eliminar este archivo después de verificar
 */

// Configuración
$mostrar_errores = true; // Cambiar a false en producción
$password_verificacion = "deploy2025"; // Cambia esto por seguridad

if ($mostrar_errores) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación Post-Deploy - BotiKit Pedidos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .check-item { transition: all 0.3s ease; }
        .check-item:hover { transform: translateX(5px); }
    </style>
</head>
<body class="bg-gray-100 p-8">

<?php
// Verificar password de acceso
if (!isset($_GET['password']) || $_GET['password'] !== $password_verificacion) {
    echo '<div class="max-w-2xl mx-auto bg-white rounded-lg shadow-lg p-8">';
    echo '<h1 class="text-2xl font-bold text-red-600 mb-4">🔒 Acceso Protegido</h1>';
    echo '<p class="mb-4">Este script requiere una contraseña para ejecutarse.</p>';
    echo '<p class="text-sm text-gray-600">URL: verificar.php?password=tu_password</p>';
    echo '</div>';
    exit;
}
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg shadow-lg p-8 mb-6">
        <h1 class="text-3xl font-bold text-blue-600 mb-2">🚀 Verificación Post-Deploy</h1>
        <p class="text-gray-600 mb-4">BotiKit Pedidos - Sistema de Gestión de Pedidos</p>
        <p class="text-sm text-yellow-600 bg-yellow-50 p-3 rounded">
            ⚠️ <strong>IMPORTANTE:</strong> Elimina este archivo después de verificar: <code>/deploy/verificar.php</code>
        </p>
    </div>

    <?php
    $resultados = [];
    $errores = [];

    // 1. Verificar conexión a base de datos
    echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
    echo '<h2 class="text-xl font-bold text-gray-800 mb-4">1️⃣ Conexión a Base de Datos</h2>';
    
    try {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        
        echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-4 mb-2">';
        echo '<p class="text-green-800 font-semibold">✅ Conexión exitosa a la base de datos</p>';
        echo '</div>';
        $resultados['db_connection'] = true;
    } catch (Exception $e) {
        echo '<div class="check-item bg-red-50 border-l-4 border-red-500 p-4 mb-2">';
        echo '<p class="text-red-800 font-semibold">❌ Error de conexión: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div>';
        $resultados['db_connection'] = false;
        $errores[] = 'Conexión a BD fallida';
    }
    echo '</div>';

    // 2. Verificar tablas
    if ($resultados['db_connection']) {
        echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
        echo '<h2 class="text-xl font-bold text-gray-800 mb-4">2️⃣ Estructura de Base de Datos</h2>';
        
        $tablas_requeridas = ['usuarios', 'clientes', 'categorias', 'productos', 'pedidos', 'detalle_pedidos', 'mensajes_pedidos'];
        $tablas_encontradas = [];
        
        $stmt = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tablas_encontradas[] = $row[0];
        }
        
        foreach ($tablas_requeridas as $tabla) {
            if (in_array($tabla, $tablas_encontradas)) {
                echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-3 mb-2">';
                echo '<p class="text-green-800">✅ Tabla <code class="bg-green-100 px-2 py-1 rounded">' . $tabla . '</code> existe</p>';
                echo '</div>';
            } else {
                echo '<div class="check-item bg-red-50 border-l-4 border-red-500 p-3 mb-2">';
                echo '<p class="text-red-800">❌ Tabla <code class="bg-red-100 px-2 py-1 rounded">' . $tabla . '</code> NO existe</p>';
                echo '</div>';
                $errores[] = "Falta tabla: $tabla";
            }
        }
        echo '</div>';

        // 3. Verificar columnas críticas
        echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
        echo '<h2 class="text-xl font-bold text-gray-800 mb-4">3️⃣ Columnas Críticas</h2>';
        
        $stmt = $db->query("SHOW COLUMNS FROM pedidos LIKE 'comprobante_envio'");
        if ($stmt->rowCount() > 0) {
            echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-3 mb-2">';
            echo '<p class="text-green-800">✅ Columna <code>comprobante_envio</code> existe en tabla pedidos</p>';
            echo '</div>';
        } else {
            echo '<div class="check-item bg-red-50 border-l-4 border-red-500 p-3 mb-2">';
            echo '<p class="text-red-800">❌ Columna <code>comprobante_envio</code> NO existe</p>';
            echo '</div>';
            $errores[] = "Falta columna comprobante_envio";
        }
        
        $stmt = $db->query("SHOW COLUMNS FROM pedidos WHERE Field='estado'");
        $estado_info = $stmt->fetch();
        if (strpos($estado_info['Type'], 'en_ruta') !== false) {
            echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-3 mb-2">';
            echo '<p class="text-green-800">✅ ENUM de estados actualizado correctamente</p>';
            echo '<p class="text-xs text-gray-600 ml-4 mt-1">' . htmlspecialchars($estado_info['Type']) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="check-item bg-yellow-50 border-l-4 border-yellow-500 p-3 mb-2">';
            echo '<p class="text-yellow-800">⚠️ ENUM de estados podría no estar actualizado</p>';
            echo '</div>';
        }
        echo '</div>';

        // 4. Verificar usuario admin
        echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
        echo '<h2 class="text-xl font-bold text-gray-800 mb-4">4️⃣ Usuario Administrador</h2>';
        
        $stmt = $db->query("SELECT * FROM usuarios WHERE username = 'admin'");
        $admin = $stmt->fetch();
        
        if ($admin) {
            echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-3 mb-2">';
            echo '<p class="text-green-800 font-semibold">✅ Usuario admin existe</p>';
            echo '<div class="ml-4 mt-2 text-sm text-gray-700">';
            echo '<p>👤 Username: <code>' . htmlspecialchars($admin['username']) . '</code></p>';
            echo '<p>📧 Email: <code>' . htmlspecialchars($admin['email']) . '</code></p>';
            echo '<p>🔑 Rol: <code>' . htmlspecialchars($admin['rol']) . '</code></p>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<div class="check-item bg-red-50 border-l-4 border-red-500 p-3 mb-2">';
            echo '<p class="text-red-800">❌ Usuario admin NO existe</p>';
            echo '</div>';
            $errores[] = "Usuario admin no encontrado";
        }
        echo '</div>';
    }

    // 5. Verificar carpetas y permisos
    echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
    echo '<h2 class="text-xl font-bold text-gray-800 mb-4">5️⃣ Carpetas y Permisos</h2>';
    
    $carpetas_uploads = [
        __DIR__ . '/../uploads/comprobantes_pago',
        __DIR__ . '/../uploads/comprobantes_envio',
        __DIR__ . '/../uploads/productos'
    ];
    
    foreach ($carpetas_uploads as $carpeta) {
        $carpeta_nombre = basename($carpeta);
        if (is_dir($carpeta)) {
            if (is_writable($carpeta)) {
                echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-3 mb-2">';
                echo '<p class="text-green-800">✅ Carpeta <code>' . $carpeta_nombre . '</code> existe y es escribible</p>';
                echo '</div>';
            } else {
                echo '<div class="check-item bg-yellow-50 border-l-4 border-yellow-500 p-3 mb-2">';
                echo '<p class="text-yellow-800">⚠️ Carpeta <code>' . $carpeta_nombre . '</code> existe pero NO es escribible</p>';
                echo '<p class="text-xs text-gray-600 ml-4 mt-1">Cambia permisos a 755</p>';
                echo '</div>';
                $errores[] = "Carpeta $carpeta_nombre no escribible";
            }
        } else {
            echo '<div class="check-item bg-red-50 border-l-4 border-red-500 p-3 mb-2">';
            echo '<p class="text-red-800">❌ Carpeta <code>' . $carpeta_nombre . '</code> NO existe</p>';
            echo '</div>';
            $errores[] = "Falta carpeta $carpeta_nombre";
        }
    }
    echo '</div>';

    // 6. Verificar archivos críticos
    echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
    echo '<h2 class="text-xl font-bold text-gray-800 mb-4">6️⃣ Archivos Críticos</h2>';
    
    $archivos_criticos = [
        'config/database.php',
        'includes/header.php',
        'admin/kanban.php',
        'js/notifications.js',
        'api/check-notifications.php',
        'seguimiento.php',
        'index.php'
    ];
    
    foreach ($archivos_criticos as $archivo) {
        $ruta_completa = __DIR__ . '/../' . $archivo;
        if (file_exists($ruta_completa)) {
            echo '<div class="check-item bg-green-50 border-l-4 border-green-500 p-3 mb-2">';
            echo '<p class="text-green-800">✅ <code>' . $archivo . '</code></p>';
            echo '</div>';
        } else {
            echo '<div class="check-item bg-red-50 border-l-4 border-red-500 p-3 mb-2">';
            echo '<p class="text-red-800">❌ <code>' . $archivo . '</code> NO existe</p>';
            echo '</div>';
            $errores[] = "Falta archivo: $archivo";
        }
    }
    echo '</div>';

    // 7. Verificar configuración PHP
    echo '<div class="bg-white rounded-lg shadow-lg p-6 mb-4">';
    echo '<h2 class="text-xl font-bold text-gray-800 mb-4">7️⃣ Configuración PHP</h2>';
    
    $config_items = [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'display_errors' => ini_get('display_errors') ? 'On' : 'Off'
    ];
    
    echo '<div class="bg-blue-50 p-4 rounded">';
    echo '<table class="w-full text-sm">';
    foreach ($config_items as $key => $value) {
        echo '<tr class="border-b border-blue-200">';
        echo '<td class="py-2 font-semibold text-blue-900">' . $key . '</td>';
        echo '<td class="py-2 text-blue-700"><code>' . $value . '</code></td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';
    
    if (ini_get('display_errors')) {
        echo '<div class="mt-3 bg-yellow-50 border-l-4 border-yellow-500 p-3">';
        echo '<p class="text-yellow-800">⚠️ <strong>Recomendación:</strong> Desactiva <code>display_errors</code> en producción</p>';
        echo '</div>';
    }
    echo '</div>';

    // Resumen final
    echo '<div class="bg-white rounded-lg shadow-lg p-6">';
    echo '<h2 class="text-2xl font-bold text-gray-800 mb-4">📊 Resumen de Verificación</h2>';
    
    if (empty($errores)) {
        echo '<div class="bg-green-50 border-l-4 border-green-500 p-6">';
        echo '<p class="text-2xl font-bold text-green-800 mb-2">✅ ¡Todo en orden!</p>';
        echo '<p class="text-green-700">El sistema está correctamente configurado y listo para producción.</p>';
        echo '<div class="mt-4 p-4 bg-white rounded">';
        echo '<p class="font-semibold text-gray-800 mb-2">Próximos pasos:</p>';
        echo '<ul class="list-disc ml-6 text-gray-700 space-y-1">';
        echo '<li>Elimina este archivo de verificación</li>';
        echo '<li>Cambia la contraseña del admin</li>';
        echo '<li>Configura SSL/HTTPS</li>';
        echo '<li>Realiza una prueba completa del flujo de pedidos</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';
    } else {
        echo '<div class="bg-red-50 border-l-4 border-red-500 p-6">';
        echo '<p class="text-2xl font-bold text-red-800 mb-2">❌ Se encontraron problemas</p>';
        echo '<p class="text-red-700 mb-4">Revisa los siguientes errores antes de continuar:</p>';
        echo '<ul class="list-disc ml-6 text-red-700 space-y-1">';
        foreach ($errores as $error) {
            echo '<li>' . htmlspecialchars($error) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
    echo '</div>';
    ?>

    <div class="mt-6 bg-gray-800 text-white rounded-lg p-6">
        <p class="text-sm">
            <strong>BotiKit Pedidos</strong> - Sistema de Gestión de Pedidos v1.0<br>
            Desarrollado con ❤️ en Octubre 2025<br>
            <span class="text-gray-400">Verificación ejecutada el <?= date('d/m/Y H:i:s') ?></span>
        </p>
    </div>
</div>

</body>
</html>
