<?php
session_start();
require_once __DIR__ . '/models/Administrador.php';

$adminModel = new Administrador();
$token   = trim($_GET['token'] ?? '');
$admin   = null;
$exito   = false;
$error   = '';

if (empty($token)) {
    $error = 'Enlace inválido o expirado.';
} else {
    $admin = $adminModel->getByResetToken($token);
    if (!$admin) {
        $error = 'El enlace es inválido o ya expiró. Solicita uno nuevo.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    $nueva       = $_POST['password']        ?? '';
    $confirmacion = $_POST['password_confirm'] ?? '';

    if (strlen($nueva) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $confirmacion) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $adminModel->cambiarPassword($admin['id'], $nueva);
        $adminModel->clearResetToken($admin['id']);
        $exito = true;
        $admin = null; // No mostrar el form
    }
}
?>
<?php include 'includes/header.php'; ?>

<div class="min-h-screen flex items-center justify-center px-4 py-8">
    <div class="card rounded-3xl shadow-2xl max-w-md w-full p-8">

        <?php if ($exito): ?>
            <div class="text-center">
                <div class="text-6xl mb-4"></div>
                <h1 class="text-2xl font-bold text-slate-900 mb-2">¡Contraseña actualizada!</h1>
                <p class="text-slate-600 mb-6">Ya puedes iniciar sesión con tu nueva contraseña.</p>
                <a href="login-admin.php" class="w-full btn-primary text-white py-3 rounded-xl font-semibold block text-center">
                    Ir al inicio de sesión
                </a>
            </div>

        <?php elseif ($error && !$admin): ?>
            <div class="text-center">
                <div class="text-6xl mb-4"></div>
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Enlace no válido</h1>
                <p class="text-slate-600 mb-6"><?= htmlspecialchars($error) ?></p>
                <a href="recuperar-password.php" class="w-full btn-primary text-white py-3 rounded-xl font-semibold block text-center">
                    Solicitar nuevo enlace
                </a>
            </div>

        <?php else: ?>
            <div class="text-center mb-8">
                <div class="text-6xl mb-4"></div>
                <h1 class="text-3xl font-bold text-slate-900 mb-2">Nueva contraseña</h1>
                <p class="text-slate-600">Hola, <strong><?= htmlspecialchars($admin['nombre'] ?? '') ?></strong>. Elige una contraseña segura.</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="restablecer-password.php?token=<?= urlencode($token) ?>">
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Nueva contraseña</label>
                    <input type="password"
                           name="password"
                           required
                           minlength="8"
                           autofocus
                           class="input-field w-full px-4 py-3 rounded-xl"
                           placeholder="Mínimo 8 caracteres">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Confirmar contraseña</label>
                    <input type="password"
                           name="password_confirm"
                           required
                           minlength="8"
                           class="input-field w-full px-4 py-3 rounded-xl"
                           placeholder="Repite la contraseña">
                </div>

                <div class="space-y-3">
                    <button type="submit" class="w-full btn-primary text-white py-4 rounded-xl font-semibold shadow-lg">
                        Guardar nueva contraseña
                    </button>
                    <a href="login-admin.php"
                       class="block w-full text-center px-4 py-3 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200 transition">
                        Cancelar
                    </a>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
