<?php
/**
 * Script de Verificación Rápida de Configuración de Producción
 * URL: https://solumedic.shop/columbia/deploy/verificar-produccion.php
 * 
 * Verificar que todas las configuraciones estén correctas antes del despliegue
 */

// Cargar configuración
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../config/database.php';

// Estilo básico
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Producción - Solumedic</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">🔍 Verificación de Configuración</h1>
            <p class="text-gray-600 mb-8">Dominio: <strong>https://solumedic.shop/columbia/</strong></p>

            <?php
            $checks = [];
            $allPassed = true;

            // ========================================
            // 1. Verificar BASE_PATH
            // ========================================
            $expectedBasePath = '/columbia/';
            $basePathOk = (BASE_PATH === $expectedBasePath);
            $checks[] = [
                'name' => 'BASE_PATH Configurado',
                'status' => $basePathOk,
                'expected' => $expectedBasePath,
                'actual' => BASE_PATH,
                'file' => 'config/paths.php'
            ];
            if (!$basePathOk) $allPassed = false;

            // ========================================
            // 2. Verificar que window.BASE_PATH esté expuesto
            // ========================================
            $headerContent = file_get_contents(__DIR__ . '/../includes/header.php');
            $windowBasePathExists = (strpos($headerContent, "window.BASE_PATH = '<?= BASE_PATH ?>'") !== false);
            $checks[] = [
                'name' => 'window.BASE_PATH en JavaScript',
                'status' => $windowBasePathExists,
                'expected' => 'Definido en header.php',
                'actual' => $windowBasePathExists ? 'Encontrado' : 'No encontrado',
                'file' => 'includes/header.php'
            ];
            if (!$windowBasePathExists) $allPassed = false;

            // ========================================
            // 3. Verificar .htaccess RewriteBase
            // ========================================
            if (file_exists(__DIR__ . '/../.htaccess')) {
                $htaccessContent = file_get_contents(__DIR__ . '/../.htaccess');
                $rewriteBaseOk = (strpos($htaccessContent, 'RewriteBase /columbia/') !== false);
                $rewriteBaseDev = (strpos($htaccessContent, 'RewriteBase /proceso/') !== false && strpos($htaccessContent, '# RewriteBase /proceso/') === false);
                
                $checks[] = [
                    'name' => 'RewriteBase en .htaccess',
                    'status' => $rewriteBaseOk && !$rewriteBaseDev,
                    'expected' => 'RewriteBase /columbia/',
                    'actual' => $rewriteBaseOk ? '/columbia/' : ($rewriteBaseDev ? '/proceso/ (dev)' : 'No encontrado'),
                    'file' => '.htaccess'
                ];
                if (!$rewriteBaseOk || $rewriteBaseDev) $allPassed = false;
            } else {
                $checks[] = [
                    'name' => '.htaccess',
                    'status' => false,
                    'expected' => 'Archivo debe existir',
                    'actual' => 'No encontrado',
                    'file' => '.htaccess'
                ];
                $allPassed = false;
            }

            // ========================================
            // 4. Verificar Conexión a Base de Datos
            // ========================================
            try {
                $db = Database::getInstance();
                $conn = $db->getConnection();
                $stmt = $conn->query("SELECT 1");
                $dbOk = true;
                $dbMessage = "Conectado: " . DB_HOST . "/" . DB_NAME;
            } catch (Exception $e) {
                $dbOk = false;
                $dbMessage = "Error: " . $e->getMessage();
                $allPassed = false;
            }
            
            $checks[] = [
                'name' => 'Conexión a Base de Datos',
                'status' => $dbOk,
                'expected' => 'Conexión exitosa',
                'actual' => $dbMessage,
                'file' => 'config/database.php'
            ];

            // ========================================
            // 5. Verificar Carpetas de Upload
            // ========================================
            $uploadDirs = [
                'uploads/',
                'uploads/productos/',
                'uploads/comprobantes/',
                'uploads/comprobantes_envio/',
                'uploads/fiscales/',
                'uploads/facturas/'
            ];

            foreach ($uploadDirs as $dir) {
                $fullPath = __DIR__ . '/../' . $dir;
                $exists = is_dir($fullPath);
                $writable = $exists && is_writable($fullPath);
                
                $checks[] = [
                    'name' => "Carpeta: $dir",
                    'status' => $exists && $writable,
                    'expected' => 'Existe y es escribible',
                    'actual' => !$exists ? 'No existe' : ($writable ? 'OK' : 'Sin permisos escritura'),
                    'file' => $dir
                ];
                
                if (!$exists || !$writable) $allPassed = false;
            }

            // ========================================
            // 6. Verificar HTTPS
            // ========================================
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
            $checks[] = [
                'name' => 'Protocolo HTTPS',
                'status' => $isHttps,
                'expected' => 'HTTPS habilitado',
                'actual' => $isHttps ? 'HTTPS' : 'HTTP (redirigirá a HTTPS)',
                'file' => '.htaccess'
            ];

            // ========================================
            // 7. Verificar Entorno Detectado
            // ========================================
            $isLocal = (stripos(__DIR__, 'laragon') !== false || 
                       stripos(__DIR__, 'xampp') !== false || 
                       stripos(__DIR__, 'wamp') !== false);
            
            $checks[] = [
                'name' => 'Entorno Detectado',
                'status' => !$isLocal,
                'expected' => 'Producción',
                'actual' => $isLocal ? 'Desarrollo Local' : 'Producción',
                'file' => 'config/paths.php'
            ];

            // ========================================
            // Mostrar Resultados
            // ========================================
            ?>

            <!-- Resumen General -->
            <div class="mb-8 p-6 rounded-lg <?= $allPassed ? 'bg-green-100 border-2 border-green-500' : 'bg-red-100 border-2 border-red-500' ?>">
                <div class="flex items-center gap-3">
                    <div class="text-4xl"><?= $allPassed ? '✅' : '❌' ?></div>
                    <div>
                        <h2 class="text-2xl font-bold <?= $allPassed ? 'text-green-900' : 'text-red-900' ?>">
                            <?= $allPassed ? '¡Todo Listo para Producción!' : 'Hay Problemas de Configuración' ?>
                        </h2>
                        <p class="<?= $allPassed ? 'text-green-700' : 'text-red-700' ?>">
                            <?php 
                            $passed = count(array_filter($checks, fn($c) => $c['status']));
                            $total = count($checks);
                            echo "$passed de $total verificaciones pasaron";
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Tabla de Verificaciones -->
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left">Estado</th>
                            <th class="px-4 py-3 text-left">Verificación</th>
                            <th class="px-4 py-3 text-left">Esperado</th>
                            <th class="px-4 py-3 text-left">Actual</th>
                            <th class="px-4 py-3 text-left">Archivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($checks as $check): ?>
                        <tr class="border-b border-gray-200 <?= $check['status'] ? 'bg-green-50' : 'bg-red-50' ?>">
                            <td class="px-4 py-3 text-2xl">
                                <?= $check['status'] ? '✅' : '❌' ?>
                            </td>
                            <td class="px-4 py-3 font-semibold">
                                <?= htmlspecialchars($check['name']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?= htmlspecialchars($check['expected']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm font-mono <?= $check['status'] ? 'text-green-700' : 'text-red-700' ?>">
                                <?= htmlspecialchars($check['actual']) ?>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                <?= htmlspecialchars($check['file']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Información del Sistema -->
            <div class="mt-8 grid grid-cols-2 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h3 class="font-bold text-gray-700 mb-2">📁 Información del Servidor</h3>
                    <p class="text-sm"><strong>PHP:</strong> <?= phpversion() ?></p>
                    <p class="text-sm"><strong>Servidor:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></p>
                    <p class="text-sm"><strong>Host:</strong> <?= $_SERVER['HTTP_HOST'] ?? 'N/A' ?></p>
                    <p class="text-sm"><strong>Ruta:</strong> <?= __DIR__ ?></p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h3 class="font-bold text-gray-700 mb-2">🗄️ Base de Datos</h3>
                    <p class="text-sm"><strong>Host:</strong> <?= DB_HOST ?></p>
                    <p class="text-sm"><strong>Base:</strong> <?= DB_NAME ?></p>
                    <p class="text-sm"><strong>Usuario:</strong> <?= DB_USER ?></p>
                    <p class="text-sm"><strong>Charset:</strong> <?= DB_CHARSET ?></p>
                </div>
            </div>

            <!-- Botones de Acción -->
            <div class="mt-8 flex gap-4">
                <?php if ($allPassed): ?>
                    <a href="<?= url('') ?>" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg font-semibold transition">
                        🏠 Ir al Inicio
                    </a>
                    <a href="<?= url('login-admin.php') ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold transition">
                        🔐 Ir a Admin
                    </a>
                <?php else: ?>
                    <button onclick="location.reload()" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-lg font-semibold transition">
                        🔄 Volver a Verificar
                    </button>
                <?php endif; ?>
                
                <button onclick="window.print()" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-semibold transition">
                    🖨️ Imprimir Reporte
                </button>
            </div>

            <!-- Advertencia de Seguridad -->
            <div class="mt-8 p-4 bg-yellow-100 border-2 border-yellow-500 rounded-lg">
                <p class="text-yellow-900 font-semibold">⚠️ IMPORTANTE:</p>
                <p class="text-yellow-800 text-sm mt-2">
                    Este archivo debe ser eliminado o protegido después del despliegue para evitar exponer información sensible del sistema.
                </p>
            </div>
        </div>
    </div>

    <script>
        // Verificar que window.BASE_PATH esté disponible
        console.log('✅ window.BASE_PATH:', window.BASE_PATH);
        
        // Auto-refresh cada 30 segundos si hay errores
        <?php if (!$allPassed): ?>
        // setTimeout(() => location.reload(), 30000);
        <?php endif; ?>
    </script>
</body>
</html>
