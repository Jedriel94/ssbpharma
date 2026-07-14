<?php
ob_start();
require_once __DIR__ . '/../includes/auth_representante.php';
require_once __DIR__ . '/../models/Administrador.php';
require_once __DIR__ . '/../models/Configuracion.php';
require_once __DIR__ . '/../config/paths.php';

$adminModel = new Administrador();

// ── AJAX: cambiar contraseña ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cambiar_password') {
    ob_end_clean();
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

    // Verificar contraseña actual (getById() no incluye el campo password)
    $pdo  = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("SELECT password FROM administradores WHERE id = ? AND activo = 1");
    $stmt->execute([$representanteAdminId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($actual, $row['password'])) {
        echo json_encode(['ok' => false, 'msg' => 'La contraseña actual es incorrecta.']);
        exit;
    }

    $ok = $adminModel->cambiarPassword($representanteAdminId, $nueva);
    if ($ok) {
        echo json_encode(['ok' => true, 'msg' => '¡Contraseña actualizada correctamente!']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar. Intenta de nuevo.']);
    }
    exit;
}

$nombreTienda = Configuracion::get('nombre_tienda', 'Solumedic');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Mi Perfil — <?= htmlspecialchars($nombreTienda) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= asset('css/representante.css') ?>">
    <style>
        :root {
            --ink:        #101820;
            --muted:      #65717f;
            --paper:      #fbfaf7;
            --panel:      #ffffff;
            --line:       #e6e0d6;
            --field:      #f2eee7;
            --brand:      #126c6a;
            --brand-dark: #0b4f4e;
            --accent:     #d86f4d;
            --danger:     #b42318;
            --green:      #1a7a4a;
            --radius:     12px;
        }

        * { box-sizing: border-box; }
        html { overflow-x: hidden; }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                linear-gradient(135deg, rgba(18,108,106,.08), transparent 34%),
                linear-gradient(315deg, rgba(216,111,77,.10), transparent 28%),
                var(--paper);
            color: var(--ink);
        }

        .shell {
            width: min(100%, 960px);
            margin: 0 auto;
            padding: 18px 14px calc(80px + env(safe-area-inset-bottom));
        }

        /* ─── Topbar ─── */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            margin: -18px -14px 14px;
            padding: calc(14px + env(safe-area-inset-top)) 14px 12px;
            background: rgba(251,250,247,.92);
            border-bottom: 1px solid rgba(230,224,214,.85);
            backdrop-filter: blur(16px);
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .brand-lockup {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 10px;
        }

        .mark {
            width: 42px; height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: var(--brand);
            color: white;
            font-weight: 900;
            letter-spacing: .5px;
            box-shadow: 0 10px 28px rgba(18,108,106,.25);
            flex: 0 0 auto;
        }

        .eyebrow { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        h1 { margin: 0; font-size: 21px; line-height: 1.05; }

        .logout {
            min-width: 44px; height: 44px;
            border-radius: 12px;
            border: 1px solid var(--line);
            display: grid;
            place-items: center;
            color: var(--ink);
            text-decoration: none;
            background: white;
        }

        .logout svg, .info-icon svg, .btn-primary svg {
            width: 18px; height: 18px;
            stroke: currentColor; fill: none;
            stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        }

        /* ─── Page title ─── */
        .page-title { font-size: 22px; font-weight: 900; margin: 24px 0 4px; }
        .page-sub   { font-size: 13px; color: var(--muted); margin-bottom: 28px; }

        /* ─── Card ─── */
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 4px 16px rgba(16,24,32,.05);
        }

        .card-title {
            font-size: 13px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .06em;
            color: var(--muted); margin-bottom: 16px;
        }

        /* ─── Info row ─── */
        .info-row {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--line);
        }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-row:first-child { padding-top: 0; }

        .info-icon {
            width: 36px; height: 36px; background: var(--field);
            border-radius: 8px; display: grid; place-items: center; flex-shrink: 0;
        }
        .info-icon svg { stroke: var(--muted); }
        .info-label { font-size: 11px; color: var(--muted); margin-bottom: 1px; }
        .info-value  { font-size: 15px; font-weight: 700; }

        /* ─── Form ─── */
        .field { margin-bottom: 14px; }

        .field label {
            display: block; font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .05em;
            color: var(--muted); margin-bottom: 6px;
        }

        .field input {
            width: 100%; height: 48px;
            border: 1.5px solid var(--line); border-radius: 10px;
            padding: 0 44px 0 14px;
            font-size: 16px; font-family: inherit;
            background: var(--field); color: var(--ink);
            transition: border-color .15s;
        }
        .field input:focus { outline: none; border-color: var(--brand); background: var(--panel); }

        .field .pw-wrap { position: relative; }
        .field .pw-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: var(--muted); padding: 4px;
        }
        .field .pw-toggle svg {
            width: 18px; height: 18px; stroke: currentColor; fill: none;
            stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        }

        /* ─── Password strength ─── */
        .pw-strength { height: 4px; border-radius: 2px; background: var(--line); margin-top: 6px; overflow: hidden; }
        .pw-strength-bar { height: 100%; border-radius: 2px; transition: width .3s, background .3s; width: 0; }
        .pw-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }

        /* ─── Button ─── */
        .btn-primary {
            width: 100%; height: 52px;
            background: var(--brand); color: #fff;
            border: none; border-radius: 12px;
            font-size: 16px; font-weight: 800; font-family: inherit;
            cursor: pointer; margin-top: 4px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: opacity .15s;
            box-shadow: 0 6px 20px rgba(18,108,106,.3);
        }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
        .btn-primary:active:not(:disabled) { opacity: .85; }

        /* ─── Toast ─── */
        .toast {
            position: fixed;
            bottom: calc(76px + env(safe-area-inset-bottom));
            left: 50%; transform: translateX(-50%) translateY(20px);
            background: var(--ink); color: #fff;
            border-radius: 10px; padding: 12px 20px;
            font-size: 14px; font-weight: 700;
            pointer-events: none; opacity: 0;
            transition: opacity .25s, transform .25s;
            z-index: 100; max-width: calc(100vw - 32px);
            white-space: normal; text-align: center;
        }
        .toast.show  { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast.error { background: var(--danger); }
        .toast.success { background: var(--green); }

        /* bottom-nav → representante.css */
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div class="brand-row">
                <div class="brand-lockup">
                    <div class="mark">S</div>
                    <div>
                        <div class="eyebrow"><?= htmlspecialchars($nombreTienda) ?></div>
                        <h1><?= htmlspecialchars($representanteNombre) ?></h1>
                    </div>
                </div>
                <a class="logout" href="<?= url('logout-admin.php') ?>" aria-label="Cerrar sesión">
                    <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 3v18"/></svg>
                </a>
            </div>
        </header>

        <h2 class="page-title">Mi Perfil</h2>
        <p class="page-sub">Información de tu cuenta</p>

        <!-- Datos de cuenta -->
        <div class="card">
            <div class="card-title">Cuenta</div>

            <div class="info-row">
                <div class="info-icon">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                </div>
                <div>
                    <div class="info-label">Nombre</div>
                    <div class="info-value"><?= htmlspecialchars($representanteNombre) ?></div>
                </div>
            </div>

            <?php
            $usuario = $adminModel->getById($representanteAdminId);
            if (!empty($usuario['email'])):
            ?>
            <div class="info-row">
                <div class="info-icon">
                    <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 8l10 6 10-6"/></svg>
                </div>
                <div>
                    <div class="info-label">Correo</div>
                    <div class="info-value"><?= htmlspecialchars($usuario['email']) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($usuario['representante_codigo'])): ?>
            <div class="info-row">
                <div class="info-icon">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h.01M14 17h.01M17 14h.01M17 17h.01M20 14h.01M20 17h.01M20 20h.01M17 20h.01M14 20h.01"/></svg>
                </div>
                <div>
                    <div class="info-label">Código de representante</div>
                    <div class="info-value" style="font-family:monospace;letter-spacing:.05em"><?= htmlspecialchars($usuario['representante_codigo']) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Cambiar contraseña -->
        <div class="card">
            <div class="card-title">Cambiar contraseña</div>

            <form id="form-pw" autocomplete="off" novalidate>
                <div class="field">
                    <label for="pw-actual">Contraseña actual</label>
                    <div class="pw-wrap">
                        <input id="pw-actual" name="password_actual" type="password" autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="pw-toggle" onclick="togglePw('pw-actual', this)" aria-label="Mostrar/ocultar">
                            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label for="pw-nueva">Nueva contraseña</label>
                    <div class="pw-wrap">
                        <input id="pw-nueva" name="password_nueva" type="password" autocomplete="new-password" placeholder="••••••••" oninput="checkStrength(this.value)">
                        <button type="button" class="pw-toggle" onclick="togglePw('pw-nueva', this)" aria-label="Mostrar/ocultar">
                            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div class="pw-strength"><div class="pw-strength-bar" id="strength-bar"></div></div>
                    <div class="pw-hint" id="strength-hint">Mínimo 8 caracteres</div>
                </div>

                <div class="field">
                    <label for="pw-confirmar">Confirmar nueva contraseña</label>
                    <div class="pw-wrap">
                        <input id="pw-confirmar" name="password_confirmar" type="password" autocomplete="new-password" placeholder="••••••••">
                        <button type="button" class="pw-toggle" onclick="togglePw('pw-confirmar', this)" aria-label="Mostrar/ocultar">
                            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary" id="btn-guardar">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Guardar contraseña
                </button>
            </form>
        </div>

    </main>

    <?php $navActive = ''; require __DIR__ . '/_nav.php'; ?>

    <div class="toast" id="toast"></div>

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
            { w: '0%',   bg: '#e8e3da', txt: 'Mínimo 8 caracteres' },
            { w: '25%',  bg: '#e74c3c', txt: 'Débil' },
            { w: '50%',  bg: '#e67e22', txt: 'Regular' },
            { w: '75%',  bg: '#f1c40f', txt: 'Buena' },
            { w: '90%',  bg: '#2ecc71', txt: 'Fuerte' },
            { w: '100%', bg: '#1a7a4a', txt: 'Muy fuerte' },
        ];
        const lvl = pw.length === 0 ? levels[0] : levels[Math.min(score, 5)];
        bar.style.width = pw.length === 0 ? '0' : lvl.w;
        bar.style.background = lvl.bg;
        hint.textContent = lvl.txt;
    }

    function showToast(msg, type = 'success') {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast show ' + type;
        setTimeout(() => t.className = 'toast', 3200);
    }

    document.getElementById('form-pw').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-guardar');
        btn.disabled = true;
        btn.textContent = 'Guardando…';

        const fd = new FormData(this);
        fd.append('action', 'cambiar_password');

        try {
            const res  = await fetch(location.href, { method: 'POST', body: fd });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch {
                console.error('Respuesta no-JSON:', text);
                showToast('Error del servidor. Revisa la consola.', 'error');
                return;
            }
            if (data.ok) {
                showToast(data.msg, 'success');
                this.reset();
                checkStrength('');
                setTimeout(() => { location.href = '<?= url('representante/index.php') ?>'; }, 1200);
            } else {
                showToast(data.msg, 'error');
            }
        } catch {
            showToast('Error de conexión. Intenta de nuevo.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Guardar contraseña`;
        }
    });
    </script>
</body>
</html>
