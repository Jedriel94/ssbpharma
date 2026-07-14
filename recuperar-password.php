<?php
session_start();
require_once __DIR__ . '/models/Administrador.php';
require_once __DIR__ . '/utils/Mailer.php';
require_once __DIR__ . '/config/paths.php';

$adminModel = new Administrador();
$enviado = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido.';
    } else {
        $admin = $adminModel->getByEmail($email);

        if ($admin) {
            // Generar token seguro
            $token   = bin2hex(random_bytes(32));
            $adminModel->setResetToken($admin['id'], $token);

            $link = absUrl('restablecer-password.php') . '?token=' . urlencode($token);
            Mailer::sendPasswordReset($email, $admin['nombre'], $link);
        }

        // Siempre mostrar el mismo mensaje (no revelar si el email existe)
        $enviado = true;
    }
}
?>
<?php include 'includes/header.php'; ?>

<div class="min-h-screen flex items-center justify-center px-4 py-8">
    <div class="card rounded-3xl shadow-2xl max-w-md w-full p-8">

        <div class="text-center mb-8">
            <div class="text-6xl mb-4">📧</div>
            <h1 class="text-3xl font-bold text-slate-900 mb-2">Recuperar contraseña</h1>
            <p class="text-slate-600">Te enviaremos un enlace para restablecer tu contraseña</p>
        </div>

        <?php if ($enviado): ?>
            <div class="p-5 bg-teal-50 border border-teal-200 rounded-2xl text-center">
                <div class="text-4xl mb-3">✅</div>
                <p class="font-semibold text-teal-800 mb-1">Correo enviado</p>
                <p class="text-teal-700 text-sm">Si el correo está registrado, recibirás un enlace válido por <strong>1 hora</strong>. Revisa también tu carpeta de spam.</p>
            </div>
            <a href="login-admin.php" class="mt-6 block w-full text-center px-4 py-3 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200 transition">
                ← Volver al inicio de sesión
            </a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
                    ❌ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="recuperar-password.php">
                <div class="mb-5">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">✉️ Correo electrónico</label>
                    <input type="email"
                           name="email"
                           required
                           autofocus
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>"
                           class="input-field w-full px-4 py-3 rounded-xl"
                           placeholder="tu@correo.com">
                </div>

                <div class="space-y-3">
                    <button type="submit" class="w-full btn-primary text-white py-4 rounded-xl font-semibold shadow-lg">
                        Enviar enlace de restablecimiento
                    </button>
                    <a href="login-admin.php"
                       class="block w-full text-center px-4 py-3 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200 transition">
                        ← Volver al inicio de sesión
                    </a>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
