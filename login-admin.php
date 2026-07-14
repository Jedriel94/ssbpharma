<?php
session_start();
require_once __DIR__ . '/models/Administrador.php';

$adminModel = new Administrador();

// Si ya está autenticado, redirigir a dashboard
if (isset($_SESSION['admin_id'])) {
    $adminActual = $adminModel->getById($_SESSION['admin_id']);
    if (($adminActual['rol_codigo'] ?? '') === 'representante') {
        header('Location: representante/index.php');
    } else {
        header('Location: admin/dashboard.php');
    }
    exit;
}

// Verificar si viene de acceso denegado
if (isset($_GET['error']) && $_GET['error'] === 'acceso_denegado') {
    $error = 'Debes iniciar sesión como administrador para acceder a esa página';
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($email) && !empty($password)) {
        $admin = $adminModel->autenticar($email, $password);
        
        if ($admin) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_usuario'] = $admin['usuario'];
            $_SESSION['admin_nombre'] = $admin['nombre'];

            $adminConRol = $adminModel->getById($admin['id']);
            if (($adminConRol['rol_codigo'] ?? '') === 'representante') {
                header('Location: representante/index.php');
            } else {
                header('Location: admin/dashboard.php');
            }
            exit;
        } else {
            $error = 'Correo o contraseña incorrectos';
        }
    } else {
        $error = 'Por favor complete todos los campos';
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="min-h-screen flex items-center justify-center px-4 py-8">
    <div class="card rounded-3xl shadow-2xl max-w-md w-full p-8">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="text-6xl mb-4"></div>
            <h1 class="text-3xl font-bold text-slate-900 mb-2">Acceso personal</h1>
            <p class="text-slate-600">Ingresa tu correo y contraseña para continuar</p>
        </div>
        
        <!-- Mensaje de error -->
        <?php if (isset($error)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <!-- Formulario de Login -->
        <form method="POST" action="login-admin.php">
            
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    Correo electrónico
                </label>
                <input type="email" 
                       name="email" 
                       required
                       autofocus
                       class="input-field w-full px-4 py-3 rounded-xl"
                       placeholder="tu@correo.com">
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    Contraseña
                </label>
                <input type="password" 
                       name="password" 
                       required
                       class="input-field w-full px-4 py-3 rounded-xl"
                       placeholder="Ingresa tu contraseña">
            </div>
            
            <div class="space-y-3">
                <button type="submit" 
                        class="w-full btn-primary text-white py-4 rounded-xl font-semibold shadow-lg">
                    Iniciar Sesión
                </button>
                
                <a href="recuperar-password.php" 
                   class="block text-center text-sm text-slate-500 hover:text-slate-700 transition">
                    ¿Olvidaste tu contraseña?
                </a>

                <a href="index.php" 
                   class="block w-full text-center px-4 py-3 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200 transition">
                    ← Volver al Inicio
                </a>
            </div>
            
        </form>
        

        
    </div>
</div>

<p class="text-center text-xs mt-6 pb-6" style="color:var(--text-muted)">Rev. 101</p>

</body>
</html>
