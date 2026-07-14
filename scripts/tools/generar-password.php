<?php
/**
 * Generador de Passwords Hasheados
 * Utilidad para crear hashes de contraseñas para usuarios del sistema
 */

// Solo ejecutar en desarrollo
if ($_SERVER['SERVER_NAME'] !== 'localhost' && $_SERVER['SERVER_NAME'] !== '127.0.0.1') {
    die('Este script solo puede ejecutarse en desarrollo');
}

$password_generado = null;
$hash_generado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && !empty($_POST['password'])) {
        $password = $_POST['password'];
        $hash_generado = password_hash($password, PASSWORD_DEFAULT);
        $password_generado = $password;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Passwords - Solumedic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            padding: 2rem 0;
        }
        .tool-card {
            max-width: 800px;
            margin: 0 auto;
        }
        .hash-output {
            background: #f1f3f5;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            font-size: 0.9rem;
        }
        .copy-btn {
            cursor: pointer;
        }
        pre {
            background: #2d3748;
            color: #68d391;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="tool-card">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0">
                        <i class="bi bi-key"></i> Generador de Passwords Hasheados
                    </h3>
                    <p class="mb-0 small">Para usuarios del sistema Solumedic</p>
                </div>
                
                <div class="card-body">
                    <!-- Alerta de seguridad -->
                    <div class="alert alert-warning" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Importante:</strong> Este script solo debe usarse en desarrollo. 
                        No dejar accesible en producción.
                    </div>
                    
                    <!-- Formulario -->
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-lock"></i> Contraseña a Hashear
                            </label>
                            <div class="input-group">
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    name="password" 
                                    id="inputPassword"
                                    placeholder="Ingrese la contraseña"
                                    required
                                >
                                <button 
                                    class="btn btn-outline-secondary" 
                                    type="button"
                                    onclick="generarPasswordAleatorio()"
                                    title="Generar contraseña aleatoria"
                                >
                                    <i class="bi bi-shuffle"></i>
                                </button>
                            </div>
                            <small class="text-muted">
                                Recomendación: Mínimo 8 caracteres, incluir mayúsculas, números y símbolos
                            </small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cpu"></i> Generar Hash
                        </button>
                    </form>
                    
                    <?php if ($hash_generado): ?>
                        <hr class="my-4">
                        
                        <!-- Resultado -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">
                                <i class="bi bi-check-circle"></i> Password Original
                            </label>
                            <div class="input-group">
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    value="<?= htmlspecialchars($password_generado) ?>"
                                    readonly
                                    id="passwordOriginal"
                                >
                                <button 
                                    class="btn btn-outline-secondary copy-btn" 
                                    type="button"
                                    onclick="copiar('passwordOriginal')"
                                    title="Copiar"
                                >
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-primary">
                                <i class="bi bi-hash"></i> Hash Generado
                            </label>
                            <div class="hash-output mb-2" id="hashGenerado">
                                <?= htmlspecialchars($hash_generado) ?>
                            </div>
                            <button 
                                class="btn btn-sm btn-outline-primary" 
                                onclick="copiar('hashGenerado')"
                            >
                                <i class="bi bi-clipboard"></i> Copiar Hash
                            </button>
                        </div>
                        
                        <!-- SQL para insertar -->
                        <div class="alert alert-info">
                            <h6 class="alert-heading">
                                <i class="bi bi-database"></i> SQL para Insertar Usuario
                            </h6>
                            <pre>INSERT INTO usuarios_sistema 
  (username, password, nombre, email, rol_id, activo)
VALUES 
  (
    'usuario_aqui',
    '<?= htmlspecialchars($hash_generado) ?>',
    'Nombre Completo',
    'email@solumedic.com',
    1, -- ID del rol (1=Admin, 2=Director General, etc.)
    1
  );</pre>
                            <button 
                                class="btn btn-sm btn-outline-info" 
                                onclick="copiar('sqlInsert')"
                            >
                                <i class="bi bi-clipboard"></i> Copiar SQL
                            </button>
                        </div>
                        
                        <!-- SQL para actualizar -->
                        <div class="alert alert-secondary">
                            <h6 class="alert-heading">
                                <i class="bi bi-arrow-repeat"></i> SQL para Actualizar Password
                            </h6>
                            <pre>UPDATE usuarios_sistema 
SET password = '<?= htmlspecialchars($hash_generado) ?>'
WHERE username = 'usuario_aqui';</pre>
                            <button 
                                class="btn btn-sm btn-outline-secondary" 
                                onclick="copiar('sqlUpdate')"
                            >
                                <i class="bi bi-clipboard"></i> Copiar SQL
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="card-footer text-muted">
                    <small>
                        <i class="bi bi-info-circle"></i>
                        Los hashes se generan con <code>password_hash()</code> usando <code>PASSWORD_DEFAULT</code> (bcrypt)
                    </small>
                </div>
            </div>
            
            <!-- Ejemplos de roles -->
            <div class="card shadow mt-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-shield"></i> IDs de Roles
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Rol</th>
                                <th>Código</th>
                                <th>Nivel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>1</code></td>
                                <td>Administrador</td>
                                <td><code>admin</code></td>
                                <td>0</td>
                            </tr>
                            <tr>
                                <td><code>2</code></td>
                                <td>Director General</td>
                                <td><code>director_general</code></td>
                                <td>1</td>
                            </tr>
                            <tr>
                                <td><code>3</code></td>
                                <td>Director de Unidad de Negocio</td>
                                <td><code>director_unidad</code></td>
                                <td>2</td>
                            </tr>
                            <tr>
                                <td><code>4</code></td>
                                <td>Gerente</td>
                                <td><code>gerente</code></td>
                                <td>3</td>
                            </tr>
                            <tr>
                                <td><code>5</code></td>
                                <td>Representante</td>
                                <td><code>representante</code></td>
                                <td>4</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Botón volver -->
            <div class="text-center mt-4">
                <a href="/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Volver al inicio
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function copiar(elementId) {
            const elemento = document.getElementById(elementId);
            const texto = elemento.tagName === 'INPUT' ? elemento.value : elemento.textContent;
            
            navigator.clipboard.writeText(texto).then(() => {
                // Mostrar feedback
                const btn = event.target.closest('button');
                const iconOriginal = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check"></i> Copiado';
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = iconOriginal;
                    btn.classList.remove('btn-success');
                }, 2000);
            });
        }
        
        function generarPasswordAleatorio() {
            const longitud = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*";
            let password = "";
            
            // Asegurar al menos un caracter de cada tipo
            const tipos = [
                "abcdefghijklmnopqrstuvwxyz",
                "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
                "0123456789",
                "!@#$%&*"
            ];
            
            tipos.forEach(tipo => {
                password += tipo.charAt(Math.floor(Math.random() * tipo.length));
            });
            
            // Completar el resto
            for (let i = password.length; i < longitud; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            
            // Mezclar caracteres
            password = password.split('').sort(() => 0.5 - Math.random()).join('');
            
            document.getElementById('inputPassword').value = password;
        }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<div id="sqlInsert" style="display: none;">INSERT INTO usuarios_sistema 
  (username, password, nombre, email, rol_id, activo)
VALUES 
  (
    'usuario_aqui',
    '<?= htmlspecialchars($hash_generado ?? '') ?>',
    'Nombre Completo',
    'email@solumedic.com',
    1,
    1
  );</div>

<div id="sqlUpdate" style="display: none;">UPDATE usuarios_sistema 
SET password = '<?= htmlspecialchars($hash_generado ?? '') ?>'
WHERE username = 'usuario_aqui';</div>
