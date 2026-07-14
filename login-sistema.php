<?php
/**
 * Login del Sistema - Unificado
 * Soporta tanto login de administradores legacy como nuevo sistema de roles
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/paths.php';

// Si ya está autenticado con el nuevo sistema, redirigir
if (isset($_SESSION['sistema_token'])) {
    header('Location: ' . url('admin/usuarios-sistema.php'));
    exit;
}

// Si está autenticado con el sistema legacy, redirigir
if (isset($_SESSION['admin_id'])) {
    header('Location: ' . url('admin/productos.php'));
    exit;
}

$error = '';

// Verificar si viene de acceso denegado
if (isset($_GET['error']) && $_GET['error'] === 'acceso_denegado') {
    $error = 'Debes iniciar sesión para acceder a esa página';
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor complete todos los campos';
    } else {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        require_once __DIR__ . '/models/UsuarioSistema.php';
        $usuarioSistema = new UsuarioSistema($db);
        
        // Intentar login con nuevo sistema de roles
        $resultado = $usuarioSistema->login($username, $password);
        
        if ($resultado['success']) {
            // Login exitoso
            $_SESSION['sistema_token'] = $resultado['token'];
            $_SESSION['usuario_sistema_id'] = $resultado['usuario']['id'];
            $_SESSION['usuario_nombre'] = $resultado['usuario']['nombre'];
            $_SESSION['usuario_rol'] = $resultado['usuario']['rol_codigo'];
            $_SESSION['usuario_rol_nombre'] = $resultado['usuario']['rol_nombre'];
            
            // Redirigir según rol
            if ($resultado['usuario']['rol_codigo'] === 'admin') {
                header('Location: ' . url('admin/usuarios-sistema.php'));
            } else {
                header('Location: ' . url('admin/productos.php'));
            }
            exit;
        } else {
            $error = $resultado['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema - Solumedic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
        }
        .login-header i {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .login-body {
            padding: 2.5rem 2rem;
        }
        .form-control {
            border-radius: 10px;
            padding: 0.75rem 1rem;
            border: 2px solid #e0e0e0;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .input-group-text {
            background: transparent;
            border: 2px solid #e0e0e0;
            border-right: none;
            border-radius: 10px 0 0 10px;
        }
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        .login-footer {
            text-align: center;
            padding: 1.5rem 2rem;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <i class="bi bi-shield-lock"></i>
            <h1 class="h3 mb-0">Solumedic</h1>
            <p class="mb-0 opacity-75">Sistema de Gestión</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Usuario</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>
                        <input 
                            type="text" 
                            class="form-control" 
                            name="usuario" 
                            placeholder="Ingrese su usuario"
                            required 
                            autofocus
                            value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
                        >
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-semibold">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input 
                            type="password" 
                            class="form-control" 
                            name="password" 
                            placeholder="Ingrese su contraseña"
                            required
                        >
                    </div>
                </div>
                
                <button type="submit" class="btn btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Iniciar Sesión
                </button>
            </form>
            
            <div class="text-center mt-3">
                <a href="<?= url('index.php') ?>" class="text-decoration-none text-muted">
                    <i class="bi bi-arrow-left"></i> Volver a la tienda
                </a>
            </div>
        </div>
        
        <div class="login-footer">
            <i class="bi bi-info-circle me-1"></i>
            Sistema de roles y permisos activo
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
