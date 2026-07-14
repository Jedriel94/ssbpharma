<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Rutas - BotiKit</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-6">🔧 Verificación de Rutas</h1>
            
            <?php
            require_once __DIR__ . '/../config/paths.php';
            
            $checks = [
                'Funcionando' => true,
                'BASE_PATH' => BASE_PATH,
                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'NOT SET',
                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'NOT SET',
                'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET',
                'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET',
                '__DIR__' => __DIR__,
                'Entorno Detectado' => (BASE_PATH === '/proceso/') ? 'LOCALHOST' : 'PRODUCCIÓN',
            ];
            
            echo '<div class="space-y-3">';
            foreach ($checks as $key => $value) {
                $isSuccess = ($key === 'Funcionando' || $key === 'Entorno Detectado');
                $bgColor = $isSuccess ? 'bg-green-50' : 'bg-blue-50';
                $textColor = $isSuccess ? 'text-green-900' : 'text-blue-900';
                $borderColor = $isSuccess ? 'border-green-200' : 'border-blue-200';
                
                echo "<div class='p-4 rounded-lg border-2 {$bgColor} {$borderColor}'>";
                echo "<div class='flex justify-between items-center'>";
                echo "<span class='font-semibold {$textColor}'>{$key}:</span>";
                echo "<span class='font-mono text-sm {$textColor}'>" . htmlspecialchars($value) . "</span>";
                echo "</div>";
                echo "</div>";
            }
            echo '</div>';
            ?>
            
            <div class="mt-8 p-4 bg-gray-50 rounded-lg">
                <h2 class="font-bold text-gray-900 mb-3">🧪 Pruebas de URLs:</h2>
                <div class="space-y-2 text-sm font-mono">
                    <div>url('index.php') = <span class="text-blue-600"><?= url('index.php') ?></span></div>
                    <div>url('admin/productos.php') = <span class="text-blue-600"><?= url('admin/productos.php') ?></span></div>
                    <div>asset('css/style.css') = <span class="text-blue-600"><?= asset('css/style.css') ?></span></div>
                </div>
            </div>
            
            <div class="mt-6 flex gap-3">
                <a href="<?= url('index.php') ?>" class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                    ← Volver al Home
                </a>
                <a href="<?= url('login-admin.php') ?>" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
                    Login Admin
                </a>
            </div>
        </div>
    </div>
</body>
</html>
