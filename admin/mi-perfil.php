<?php
require_once '../includes/auth_admin.php';
require_once '../models/Administrador.php';
require_once '../config/database.php';

$adminModel = new Administrador();

// ── AJAX: cambiar contraseña ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cambiar_password') {
    header('Content-Type: application/json');

    $actual    = $_POST['password_actual']    ?? '';
    $nueva     = $_POST['password_nueva']     ?? '';
    $confirmar = $_POST['password_confirmar'] ?? '';

    if (!$actual || !$nueva || !$confirmar) {
        echo json_encode(['ok' => false, 'msg' => 'Todos los campos son obligatorios.']);
        exit;
    }

    if (strlen($nueva) < 8) {
        echo json_encode(['ok' => false, 'msg' => 'La contraseña nueva debe tener al menos 8 caracteres.']);
        exit;
    }

    if ($nueva !== $confirmar) {
        echo json_encode(['ok' => false, 'msg' => 'La nueva contraseña y su confirmación no coinciden.']);
        exit;
    }

    $pdo  = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("SELECT password FROM administradores WHERE id = ? AND activo = 1");
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($actual, $row['password'])) {
        echo json_encode(['ok' => false, 'msg' => 'La contraseña actual es incorrecta.']);
        exit;
    }

    $ok = $adminModel->cambiarPassword((int)$_SESSION['admin_id'], $nueva);
    echo json_encode($ok
        ? ['ok' => true,  'msg' => '¡Contraseña actualizada correctamente!']
        : ['ok' => false, 'msg' => 'Error al guardar. Intenta de nuevo.']
    );
    exit;
}

$admin = $adminModel->getById((int)$_SESSION['admin_id']);
$pageTitle = 'Mi Perfil';
require_once '../includes/header.php';
?>

<div class="max-w-xl mx-auto py-8 px-4">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Mi Perfil</h1>
        <p class="text-sm text-slate-500 mt-1">Información de tu cuenta y seguridad</p>
    </div>

    <!-- Datos de cuenta -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-5 shadow-sm">
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Cuenta</p>

        <div class="flex items-center gap-3 py-3 border-b border-slate-100">
            <div class="w-9 h-9 rounded-xl bg-slate-100 grid place-items-center flex-shrink-0">
                <span class="text-lg"></span>
            </div>
            <div>
                <p class="text-xs text-slate-400">Nombre</p>
                <p class="font-semibold text-slate-900"><?= htmlspecialchars($admin['nombre'] ?? '') ?></p>
            </div>
        </div>

        <?php if (!empty($admin['email'])): ?>
        <div class="flex items-center gap-3 py-3 border-b border-slate-100">
            <div class="w-9 h-9 rounded-xl bg-slate-100 grid place-items-center flex-shrink-0">
                <span class="text-lg"></span>
            </div>
            <div>
                <p class="text-xs text-slate-400">Correo</p>
                <p class="font-semibold text-slate-900"><?= htmlspecialchars($admin['email']) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex items-center gap-3 py-3">
            <div class="w-9 h-9 rounded-xl bg-slate-100 grid place-items-center flex-shrink-0">
                <span class="text-lg"></span>
            </div>
            <div>
                <p class="text-xs text-slate-400">Rol</p>
                <p class="font-semibold text-slate-900"><?= htmlspecialchars($admin['rol_nombre'] ?? 'Administrador') ?></p>
            </div>
        </div>
    </div>

    <!-- Cambiar contraseña -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Cambiar contraseña</p>

        <form id="form-pw" autocomplete="off" novalidate class="space-y-4">

            <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1" for="pw-actual">
                    Contraseña actual
                </label>
                <div class="relative">
                    <input id="pw-actual" name="password_actual" type="password"
                           autocomplete="current-password" placeholder="••••••••"
                           class="w-full h-11 border border-slate-200 rounded-xl px-4 pr-11 text-sm bg-slate-50 text-slate-900 focus:outline-none focus:border-slate-400 focus:bg-white transition">
                    <button type="button" onclick="togglePw('pw-actual', this)"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1" for="pw-nueva">
                    Nueva contraseña
                </label>
                <div class="relative">
                    <input id="pw-nueva" name="password_nueva" type="password"
                           autocomplete="new-password" placeholder="••••••••"
                           oninput="checkStrength(this.value)"
                           class="w-full h-11 border border-slate-200 rounded-xl px-4 pr-11 text-sm bg-slate-50 text-slate-900 focus:outline-none focus:border-slate-400 focus:bg-white transition">
                    <button type="button" onclick="togglePw('pw-nueva', this)"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <div class="mt-1.5 h-1 bg-slate-100 rounded-full overflow-hidden">
                    <div id="strength-bar" class="h-full rounded-full transition-all duration-300" style="width:0"></div>
                </div>
                <p id="strength-hint" class="text-xs text-slate-400 mt-1">Mínimo 8 caracteres</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1" for="pw-confirmar">
                    Confirmar nueva contraseña
                </label>
                <div class="relative">
                    <input id="pw-confirmar" name="password_confirmar" type="password"
                           autocomplete="new-password" placeholder="••••••••"
                           class="w-full h-11 border border-slate-200 rounded-xl px-4 pr-11 text-sm bg-slate-50 text-slate-900 focus:outline-none focus:border-slate-400 focus:bg-white transition">
                    <button type="button" onclick="togglePw('pw-confirmar', this)"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Alerta -->
            <div id="pw-alert" class="hidden text-sm font-medium px-4 py-3 rounded-xl"></div>

            <button type="submit" id="btn-guardar"
                    class="w-full h-11 bg-slate-900 hover:bg-slate-700 text-white font-semibold rounded-xl text-sm transition flex items-center justify-center gap-2 disabled:opacity-50">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                Guardar contraseña
            </button>
        </form>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('svg').innerHTML = isText
        ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
        : '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
}

function checkStrength(pw) {
    const bar  = document.getElementById('strength-bar');
    const hint = document.getElementById('strength-hint');
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const levels = [
        { w: '0%',   bg: '',          txt: 'Mínimo 8 caracteres' },
        { w: '25%',  bg: '#ef4444',   txt: 'Débil' },
        { w: '50%',  bg: '#f97316',   txt: 'Regular' },
        { w: '75%',  bg: '#eab308',   txt: 'Buena' },
        { w: '90%',  bg: '#22c55e',   txt: 'Fuerte' },
        { w: '100%', bg: '#16a34a',   txt: 'Muy fuerte' },
    ];
    const lvl = pw.length === 0 ? levels[0] : levels[Math.min(score, 5)];
    bar.style.width      = pw.length === 0 ? '0' : lvl.w;
    bar.style.background = lvl.bg;
    hint.textContent     = lvl.txt;
}

function showAlert(msg, ok) {
    const el = document.getElementById('pw-alert');
    el.textContent = msg;
    el.className = ok
        ? 'text-sm font-medium px-4 py-3 rounded-xl bg-green-50 text-green-700 border border-green-200'
        : 'text-sm font-medium px-4 py-3 rounded-xl bg-red-50 text-red-700 border border-red-200';
    el.classList.remove('hidden');
    if (ok) setTimeout(() => el.classList.add('hidden'), 3000);
}

document.getElementById('form-pw').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-guardar');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" opacity=".25"/><path d="M21 12a9 9 0 00-9-9"/></svg> Guardando…';

    const fd = new FormData(this);
    fd.append('action', 'cambiar_password');

    try {
        const res  = await fetch(location.href, { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch {
            showAlert('Error del servidor. Intenta de nuevo.', false);
            return;
        }
        showAlert(data.msg, data.ok);
        if (data.ok) {
            this.reset();
            checkStrength('');
            setTimeout(() => { location.href = '<?= url('admin/dashboard.php') ?>'; }, 1200);
        }
    } catch {
        showAlert('Error de conexión. Intenta de nuevo.', false);
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Guardar contraseña`;
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
