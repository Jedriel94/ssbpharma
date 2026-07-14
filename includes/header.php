<!-- Header con Menu para todas las páginas admin -->
<?php
// Cargar configuración de rutas
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Configuracion.php';

$_nombreTienda = Configuracion::get('nombre_tienda', 'Tienda');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdminLoggedIn = isset($_SESSION['admin_id']) && empty($_SESSION['_rep_modo']);
$adminNombre = $_SESSION['admin_nombre'] ?? '';
$_headerRolCodigo = $_SESSION['admin_rol_codigo'] ?? '';
$_headerSoloMetricas = in_array($_headerRolCodigo, ['director_general', 'director_unidad', 'gerente', 'viewer'], true);
$_headerEsViewer    = $_headerRolCodigo === 'viewer';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- ── Tipografía global Módulo Sistema ── -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('css/paginator.css') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        cream:      { 50:'#FFFFFF', 100:'#F8FAFC', 200:'#F1F5F9', 300:'#E2E8F0', 400:'#CBD5E1' },
                        sage:       { 400:'#94A3B8', 500:'#64748B', 600:'#475569' },
                        terracotta: { 50:'#FFF8F5', 400:'#E89B7E', 500:'#E07856', 600:'#D86F4D' },
                        slate:      { 800:'#1E293B', 900:'#0F172A' }
                    }
                }
            }
        }
    </script>
    <style>
        /* =====================================================================
           TIPOGRAFÍA GLOBAL — Módulo Sistema
           Fuente base: Outfit | Mono: DM Mono
        ===================================================================== */
        :root {
            --font-base: 'Inter', system-ui, sans-serif;
            --font-mono: 'JetBrains Mono', 'Courier New', monospace;
            --font-size-base: 14px;
            --font-size-sm:   12px;
            --font-size-lg:   16px;
            --font-size-xl:   20px;
            --font-size-2xl:  24px;
            --font-size-3xl:  30px;
            --line-height:    1.55;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { font-size: var(--font-size-base); }

        body {
            font-family: var(--font-base);
            font-size: var(--font-size-base);
            line-height: var(--line-height);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        h1, h2, h3, h4, h5, h6 { font-family: var(--font-base); font-weight: 700; line-height: 1.2; }
        h1 { font-size: var(--font-size-3xl); }
        h2 { font-size: var(--font-size-2xl); }
        h3 { font-size: var(--font-size-xl); }
        h4 { font-size: var(--font-size-lg); }

        code, kbd, pre, .font-mono, [class*="font-mono"] {
            font-family: var(--font-mono);
        }

        input, select, textarea, button {
            font-family: var(--font-base);
            font-size: var(--font-size-base);
        }

        /* Clases utilitarias tipográficas */
        .t-heading  { font-family: var(--font-base); font-weight: 700; }
        .t-label    { font-family: var(--font-base); font-size: var(--font-size-sm); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .t-mono     { font-family: var(--font-mono); }
        .t-muted    { color: var(--text-muted); }
        .t-secondary{ color: var(--text-secondary); }

        /* =====================================================================
           VARIABLES DE TEMA — se sobreescriben por clase en <body>
           Tema activo por defecto: minimal (sin clase adicional)
        ===================================================================== */
        :root {
            --bg-page:       #F8FAFC;
            --bg-card:       #FFFFFF;
            --bg-card-hover: #F8FAFC;
            --bg-menu:       #FFFFFF;
            --bg-menu-item:  #F1F5F9;
            --bg-input:      #FFFFFF;

            --border-card:   #E2E8F0;
            --border-input:  #CBD5E1;
            --border-focus:  #0F172A;

            --text-primary:  #0F172A;
            --text-secondary:#475569;
            --text-muted:    #94A3B8;
            --text-menu:     #1E293B;

            --accent:        #0F172A;
            --accent-text:   #FFFFFF;
            --accent-hover:  #1E293B;
            --accent2:       #FFFFFF;
            --accent2-border:#0F172A;
            --accent2-hover: #F8FAFC;
            --accent2-text:  #0F172A;

            --hamburger-bg:  #0F172A;
            --focus-ring:    rgba(15,23,42,.08);

            /* Neutralizadores de colores Tailwind */
            --tw-neu-50:   #F8FAFC;
            --tw-neu-100:  #F1F5F9;
            --tw-neu-200:  #E2E8F0;
            --tw-neu-300:  #CBD5E1;
            --tw-neu-400:  #94A3B8;
            --tw-neu-500:  #64748B;
            --tw-neu-600:  #475569;
            --tw-neu-700:  #334155;
            --tw-neu-800:  #1E293B;
            --tw-neu-900:  #0F172A;
        }

        /* TEMA: Terracota & Sage */
        body.theme-terracotta {
            --bg-page:       #FDFBF7;
            --bg-card:       rgba(255,255,255,.85);
            --bg-card-hover: rgba(255,255,255,.97);
            --bg-menu:       #FFF8F2;
            --bg-menu-item:  rgba(224,120,86,.1);
            --bg-input:      rgba(255,255,255,.9);

            --border-card:   rgba(224,120,86,.15);
            --border-input:  rgba(224,120,86,.25);
            --border-focus:  #E07856;

            --text-primary:  #2D1A0E;
            --text-secondary:#7A4A35;
            --text-muted:    #B5856A;
            --text-menu:     #5C2E1A;

            --accent:        #E07856;
            --accent-text:   #FFFFFF;
            --accent-hover:  #D86F4D;
            --accent2:       #8FA88B;
            --accent2-border:#8FA88B;
            --accent2-hover: #7A9576;
            --accent2-text:  #FFFFFF;

            --hamburger-bg:  #E07856;
            --focus-ring:    rgba(224,120,86,.15);

            --tw-neu-50:   #FFF8F5;
            --tw-neu-100:  #FDEEE7;
            --tw-neu-200:  #F8D5C0;
            --tw-neu-300:  #F0B99A;
            --tw-neu-400:  #E89B7E;
            --tw-neu-500:  #E07856;
            --tw-neu-600:  #D86F4D;
            --tw-neu-700:  #B85A3A;
            --tw-neu-800:  #8C3E25;
            --tw-neu-900:  #5C2511;
        }

        /* TEMA: Ocean Blue */
        body.theme-ocean {
            --bg-page:       #F0F5FA;
            --bg-card:       #FFFFFF;
            --bg-card-hover: #F5F8FC;
            --bg-menu:       #FFFFFF;
            --bg-menu-item:  rgba(74,112,169,.08);
            --bg-input:      #FFFFFF;

            --border-card:   #BFCFE8;
            --border-input:  #93B4D4;
            --border-focus:  #4A70A9;

            --text-primary:  #102040;
            --text-secondary:#2E5080;
            --text-muted:    #6A90B8;
            --text-menu:     #1D3A60;

            --accent:        #4A70A9;
            --accent-text:   #FFFFFF;
            --accent-hover:  #3A5A90;
            --accent2:       #8FABD4;
            --accent2-border:#8FABD4;
            --accent2-hover: #7A9AC6;
            --accent2-text:  #FFFFFF;

            --hamburger-bg:  #4A70A9;
            --focus-ring:    rgba(74,112,169,.15);

            --tw-neu-50:   #EEF4FA;
            --tw-neu-100:  #D8E8F5;
            --tw-neu-200:  #B0CDE8;
            --tw-neu-300:  #8AAFD8;
            --tw-neu-400:  #6A93C8;
            --tw-neu-500:  #4A70A9;
            --tw-neu-600:  #3A5A90;
            --tw-neu-700:  #2C4675;
            --tw-neu-800:  #1E3260;
            --tw-neu-900:  #102040;
        }

        /* TEMA: Dark (Oscuro) */
        body.theme-dark {
            --bg-page:       #0D1117;
            --bg-card:       #161B22;
            --bg-card-hover: #1C2330;
            --bg-menu:       #161B22;
            --bg-menu-item:  rgba(255,255,255,.06);
            --bg-input:      #1C2330;

            --border-card:   #30363D;
            --border-input:  #374151;
            --border-focus:  #58A6FF;

            --text-primary:  #E6EDF3;
            --text-secondary:#8B949E;
            --text-muted:    #484F58;
            --text-menu:     #C9D1D9;

            --accent:        #238636;
            --accent-text:   #FFFFFF;
            --accent-hover:  #2EA043;
            --accent2:       #1F6FEB;
            --accent2-border:#1F6FEB;
            --accent2-hover: #388BFD;
            --accent2-text:  #FFFFFF;

            --hamburger-bg:  #238636;
            --focus-ring:    rgba(88,166,255,.15);

            --tw-neu-50:   #1C2330;
            --tw-neu-100:  #21262D;
            --tw-neu-200:  #30363D;
            --tw-neu-300:  #3D444D;
            --tw-neu-400:  #484F58;
            --tw-neu-500:  #6E7681;
            --tw-neu-600:  #8B949E;
            --tw-neu-700:  #999999;
            --tw-neu-800:  #C9D1D9;
            --tw-neu-900:  #E6EDF3;
        }

        /* TEMA: Lavender */
        body.theme-lavender {
            --bg-page:       #F5F3FF;
            --bg-card:       #FFFFFF;
            --bg-card-hover: #FAF8FF;
            --bg-menu:       #FFFFFF;
            --bg-menu-item:  rgba(124,58,237,.07);
            --bg-input:      #FFFFFF;

            --border-card:   #DDD6FE;
            --border-input:  #C4B5FD;
            --border-focus:  #7C3AED;

            --text-primary:  #2E1065;
            --text-secondary:#5B21B6;
            --text-muted:    #A78BFA;
            --text-menu:     #3B0764;

            --accent:        #7C3AED;
            --accent-text:   #FFFFFF;
            --accent-hover:  #6D28D9;
            --accent2:       #A78BFA;
            --accent2-border:#A78BFA;
            --accent2-hover: #8B5CF6;
            --accent2-text:  #FFFFFF;

            --hamburger-bg:  #7C3AED;
            --focus-ring:    rgba(124,58,237,.12);

            --tw-neu-50:   #F5F3FF;
            --tw-neu-100:  #EDE9FE;
            --tw-neu-200:  #DDD6FE;
            --tw-neu-300:  #C4B5FD;
            --tw-neu-400:  #A78BFA;
            --tw-neu-500:  #8B5CF6;
            --tw-neu-600:  #7C3AED;
            --tw-neu-700:  #6D28D9;
            --tw-neu-800:  #5B21B6;
            --tw-neu-900:  #4C1D95;
        }

        /* =====================================================================
           ESTILOS BASE — usan variables
        ===================================================================== */
        body {
            background: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background .3s, color .3s;
        }

        .btn-primary {
            background: var(--accent);
            color: #FFFFFF !important;
            transition: background .2s ease;
        }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-primary * { color: #FFFFFF !important; }

        /* ── Toast container (Sistema) ── */
        #_sys-toast-c {
            position: fixed; top: 1rem; right: 1rem; z-index: 9999;
            display: flex; flex-direction: column; gap: 8px;
            pointer-events: none;
        }
        #_sys-toast-c > * { pointer-events: all; }
        @keyframes _toast-in { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

        .btn-secondary {
            background: var(--accent2);
            border: 1.5px solid var(--accent2-border);
            color: var(--accent2-text) !important;
            transition: background .2s ease;
        }
        .btn-secondary:hover { background: var(--accent2-hover); }
        .btn-secondary * { color: var(--accent2-text) !important; }

        .btn-danger {
            background: #EF4444;
            color: #FFFFFF !important;
            transition: background .2s ease;
        }
        .btn-danger:hover { background: #DC2626; }

        .input-field {
            background: var(--bg-input);
            border: 1.5px solid var(--border-input);
            color: var(--text-primary);
            transition: border-color .2s ease;
        }
        .input-field:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--focus-ring);
            outline: none;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            transition: background .3s, border-color .2s;
        }
        .card:hover { background: var(--bg-card-hover); border-color: var(--border-card); }

        .menu-backdrop { background: rgba(0,0,0,.45); }

        .menu-panel {
            background: var(--bg-menu);
            border-left: 1px solid var(--border-card);
            transform: translateX(100%);
            transition: transform .25s ease, background .3s;
        }
        .menu-panel.open { transform: translateX(0); }

        .menu-item { transition: background .15s ease; color: var(--text-menu); }
        .menu-item:hover { background: var(--bg-menu-item); }
        .menu-item span { color: var(--text-menu) !important; }

        .hamburger { background: var(--hamburger-bg); }

        .modal-backdrop { background: rgba(0,0,0,.55); }

        /* text helpers */
        .text-slate-900, .text-slate-800 { color: var(--text-primary) !important; }
        .text-slate-700, .text-slate-600 { color: var(--text-secondary) !important; }
        .text-slate-500, .text-slate-400 { color: var(--text-muted) !important; }

        /* dark theme: entradas, selects, textareas nativos */
        body.theme-dark input,
        body.theme-dark select,
        body.theme-dark textarea {
            color-scheme: dark;
            color: var(--text-primary);
            background: var(--bg-input);
            border-color: var(--border-input);
        }
        body.theme-dark .text-slate-900,
        body.theme-dark .text-slate-800,
        body.theme-dark .text-slate-700,
        body.theme-dark .text-slate-600 { color: var(--text-primary) !important; }
        body.theme-dark .text-slate-500,
        body.theme-dark .text-slate-400 { color: var(--text-secondary) !important; }

        /* bg-white en modo oscuro */
        body.theme-dark .bg-white { background: var(--bg-card) !important; color: var(--text-primary); }
        body.theme-dark .bg-slate-50  { background: var(--tw-neu-50)  !important; }
        body.theme-dark .bg-slate-100 { background: var(--tw-neu-100) !important; }
        body.theme-dark .bg-slate-200 { background: var(--tw-neu-200) !important; }

        /* borders en modo oscuro */
        body.theme-dark .border-slate-100,
        body.theme-dark .border-slate-200,
        body.theme-dark .border-slate-300 { border-color: var(--border-card) !important; }
        body.theme-dark .border-white { border-color: var(--border-card) !important; }

        /* texto blanco sobre fondos oscuros */
        body.theme-dark .text-white { color: var(--text-primary) !important; }

        /* tablas */
        body.theme-dark table thead { background: var(--bg-card-hover) !important; }
        body.theme-dark th, body.theme-dark td { color: var(--text-primary) !important; border-color: var(--border-card) !important; }

        /* divisores */
        body.theme-dark hr,
        body.theme-dark .divide-slate-200 > * + * { border-color: var(--border-card) !important; }

        /* botones de selección (metodo pago, etc.) en modo oscuro */
        body.theme-dark button:hover,
        body.theme-dark label:hover { color: var(--text-primary) !important; }
        body.theme-dark .hover\:bg-slate-50:hover { background-color: rgba(255,255,255,.08) !important; }
        body.theme-dark .hover\:bg-terracotta-50:hover,
        body.theme-dark .hover\:bg-terracotta-100:hover,
        body.theme-dark .hover\:bg-purple-50:hover,
        body.theme-dark .hover\:bg-blue-50:hover,
        body.theme-dark .hover\:bg-sage-50:hover,
        body.theme-dark .hover\:bg-cream-50:hover,
        body.theme-dark .hover\:bg-green-50:hover { background-color:var(--bg-menu-item)!important }
        body.theme-dark .hover\:border-terracotta-400:hover,
        body.theme-dark .hover\:border-purple-500:hover,
        body.theme-dark .hover\:border-blue-500:hover { border-color:var(--border-focus)!important }

        /* shadows: ligero en oscuro */
        body.theme-dark .shadow-sm { box-shadow: 0 1px 3px rgba(0,0,0,.4) !important; }
        body.theme-dark .shadow    { box-shadow: 0 2px 6px rgba(0,0,0,.5) !important; }
        body.theme-dark .shadow-lg { box-shadow: 0 4px 16px rgba(0,0,0,.6) !important; }
        body.theme-dark .shadow-xl { box-shadow: 0 8px 24px rgba(0,0,0,.7) !important; }
        body.theme-dark .shadow-2xl{ box-shadow: 0 12px 40px rgba(0,0,0,.8) !important; }

        /* Contenedores de imagen */
        .product-image-container { width:250px; height:250px; position:relative; overflow:hidden; }
        .product-image-container img { width:100%; height:100%; object-fit:cover; }

        .line-clamp-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .aspect-square { aspect-ratio:1/1; }

        /* =====================================================================
           NEUTRALIZADOR GLOBAL DE COLORES TAILWIND
           Mapea cada clase de color a la variable del tema activo.
           Se preserva ROJO (errores críticos).
        ===================================================================== */

        /* bg claros */
        .bg-green-50,.bg-emerald-50,.bg-blue-50,.bg-purple-50,.bg-orange-50,.bg-yellow-50,
        .bg-amber-50,.bg-teal-50,.bg-indigo-50,.bg-pink-50,.bg-cyan-50,.bg-rose-50,.bg-sky-50,.bg-violet-50,.bg-lime-50 { background-color:var(--tw-neu-50)!important }
        .bg-green-100,.bg-blue-100,.bg-purple-100,.bg-orange-100,.bg-yellow-100,
        .bg-amber-100,.bg-teal-100,.bg-indigo-100,.bg-pink-100 { background-color:var(--tw-neu-100)!important }
        .bg-green-200,.bg-blue-200,.bg-purple-200,.bg-orange-200,
        .bg-yellow-200,.bg-amber-200 { background-color:var(--tw-neu-200)!important }
        .bg-green-300,.bg-blue-300,.bg-purple-300,.bg-orange-300,
        .bg-yellow-300,.bg-amber-300 { background-color:var(--tw-neu-300)!important }
        .bg-green-400,.bg-blue-400,.bg-purple-400,.bg-orange-400,
        .bg-yellow-400,.bg-amber-400 { background-color:var(--tw-neu-400)!important }

        /* bg vivos */
        .bg-green-500,.bg-blue-500,.bg-purple-500,.bg-orange-500,.bg-yellow-500,
        .bg-amber-500,.bg-teal-500,.bg-indigo-500,.bg-violet-500,.bg-cyan-500 { background-color:var(--tw-neu-500)!important }
        .bg-green-600,.bg-blue-600,.bg-purple-600,.bg-orange-600,
        .bg-yellow-600,.bg-amber-600,.bg-teal-600,.bg-indigo-600 { background-color:var(--tw-neu-600)!important }
        .bg-green-700,.bg-blue-700,.bg-purple-700,.bg-orange-700,
        .bg-amber-700 { background-color:var(--tw-neu-700)!important }
        .bg-green-800,.bg-blue-800,.bg-purple-800,.bg-orange-800,
        .bg-amber-800 { background-color:var(--tw-neu-800)!important }
        .bg-green-900,.bg-blue-900,.bg-purple-900 { background-color:var(--tw-neu-900)!important }

        /* hover bg */
        .hover\:bg-green-50:hover,.hover\:bg-blue-50:hover,.hover\:bg-purple-50:hover,
        .hover\:bg-amber-50:hover,.hover\:bg-orange-50:hover { background-color:var(--tw-neu-50)!important }
        .hover\:bg-green-100:hover,.hover\:bg-blue-100:hover,
        .hover\:bg-purple-100:hover { background-color:var(--tw-neu-100)!important }
        .hover\:bg-green-500:hover,.hover\:bg-blue-500:hover,.hover\:bg-purple-500:hover,
        .hover\:bg-amber-500:hover,.hover\:bg-yellow-500:hover { background-color:var(--tw-neu-500)!important }
        .hover\:bg-green-600:hover,.hover\:bg-blue-600:hover,.hover\:bg-blue-700:hover,
        .hover\:bg-purple-600:hover,.hover\:bg-purple-700:hover,.hover\:bg-amber-600:hover,
        .hover\:bg-yellow-600:hover,.hover\:bg-orange-500:hover,
        .hover\:bg-orange-600:hover { background-color:var(--tw-neu-600)!important }

        /* text */
        .text-green-400,.text-blue-400,.text-purple-400,.text-orange-400,
        .text-yellow-400,.text-amber-400 { color:var(--tw-neu-400)!important }
        .text-green-500,.text-blue-500,.text-purple-500,.text-orange-500,
        .text-yellow-500,.text-amber-500 { color:var(--tw-neu-500)!important }
        .text-green-600,.text-blue-600,.text-purple-600,.text-orange-600,
        .text-yellow-600,.text-amber-600,.text-teal-600,.text-indigo-600,.text-emerald-600 { color:var(--tw-neu-600)!important }
        .text-green-700,.text-blue-700,.text-purple-700,.text-orange-700,
        .text-yellow-700,.text-amber-700,.text-indigo-700,.text-emerald-700 { color:var(--tw-neu-700)!important }
        .text-green-800,.text-blue-800,.text-purple-800,.text-orange-800,
        .text-yellow-800,.text-amber-800 { color:var(--tw-neu-800)!important }
        .text-green-900,.text-blue-900,.text-purple-900,.text-orange-900,
        .text-yellow-900 { color:var(--tw-neu-900)!important }
        .text-green-50,.text-blue-50,.text-purple-50 { color:var(--tw-neu-50)!important }
        .text-green-100,.text-blue-100,.text-purple-100 { color:var(--tw-neu-100)!important }
        .text-green-200,.text-blue-200,.text-purple-200 { color:var(--tw-neu-200)!important }
        .text-green-300,.text-blue-300,.text-purple-300 { color:var(--tw-neu-300)!important }

        /* hover text */
        .hover\:text-purple-600:hover,.hover\:text-purple-800:hover,
        .hover\:text-blue-600:hover,.hover\:text-blue-800:hover { color:var(--tw-neu-700)!important }

        /* borders */
        .border-green-100,.border-blue-100,.border-purple-100,.border-yellow-100,
        .border-amber-100,.border-orange-100 { border-color:var(--tw-neu-100)!important }
        .border-green-200,.border-blue-200,.border-purple-200,.border-yellow-200,
        .border-amber-200,.border-orange-200 { border-color:var(--tw-neu-200)!important }
        .border-green-300,.border-blue-300,.border-purple-300,.border-yellow-300,
        .border-amber-300 { border-color:var(--tw-neu-300)!important }
        .border-green-400,.border-blue-400,.border-purple-400,.border-orange-400,
        .border-yellow-400,.border-amber-400 { border-color:var(--tw-neu-400)!important }
        .border-green-500,.border-blue-500,.border-purple-500,.border-orange-500,
        .border-yellow-500,.border-amber-500 { border-color:var(--tw-neu-500)!important }
        .border-green-600,.border-blue-600,.border-purple-600 { border-color:var(--tw-neu-600)!important }

        /* gradientes suaves */
        [class~="from-green-50"],[class~="from-green-100"],[class~="from-blue-50"],
        [class~="from-blue-100"],[class~="from-purple-50"],[class~="from-purple-100"],
        [class~="from-orange-50"],[class~="from-yellow-50"],[class~="from-amber-50"],
        [class~="from-amber-100"],[class~="from-terracotta-50"],[class~="from-cream-100"] {
            --tw-gradient-from: var(--tw-neu-50)!important;
            --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, transparent)!important;
        }
        [class~="to-green-50"],[class~="to-green-100"],[class~="to-blue-50"],
        [class~="to-purple-50"],[class~="to-purple-100"],[class~="to-purple-200"],
        [class~="to-orange-50"],[class~="to-yellow-50"],[class~="to-amber-50"],
        [class~="to-amber-100"],[class~="to-sage-50"],[class~="to-cream-100"],
        [class~="to-cream-200"],[class~="to-blue-100"] { --tw-gradient-to: var(--tw-neu-100)!important; }

        /* gradientes vivos */
        [class~="from-green-500"],[class~="from-blue-500"],[class~="from-purple-500"],
        [class~="from-amber-500"],[class~="from-yellow-500"],[class~="from-orange-500"],
        [class~="from-green-600"],[class~="from-blue-600"],[class~="from-purple-600"],
        [class~="from-amber-600"],[class~="from-indigo-500"],[class~="from-teal-500"] {
            --tw-gradient-from: var(--tw-neu-500)!important;
            --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, transparent)!important;
        }
        [class~="to-green-600"],[class~="to-blue-600"],[class~="to-purple-600"],
        [class~="to-purple-700"],[class~="to-amber-600"],[class~="to-green-700"],
        [class~="to-blue-700"],[class~="to-indigo-600"],[class~="to-orange-600"] {
            --tw-gradient-to: var(--tw-neu-600)!important;
        }

        /* focus rings */
        .focus\:ring-blue-500:focus,.focus\:ring-green-500:focus,.focus\:ring-purple-500:focus,
        .focus\:ring-orange-500:focus,.focus\:ring-yellow-500:focus,
        .focus\:ring-amber-500:focus { --tw-ring-color:var(--tw-neu-500)!important }

        /* hover borders */
        .hover\:border-blue-500:hover,.hover\:border-green-500:hover,
        .hover\:border-purple-500:hover { border-color:var(--tw-neu-500)!important }

        /* peer-checked */
        .peer:checked ~ .peer-checked\:border-blue-600 { border-color:var(--tw-neu-600)!important }
        .peer:checked ~ .peer-checked\:bg-blue-50 { background-color:var(--tw-neu-100)!important }

        /* =====================================================================
           COLORES PERSONALIZADOS — cream / sage / terracotta
           Mapeados a las mismas variables del tema activo.
        ===================================================================== */

        /* bg cream */
        .bg-cream-50  { background-color:var(--tw-neu-50)!important }
        .bg-cream-100 { background-color:var(--tw-neu-100)!important }
        .bg-cream-200 { background-color:var(--tw-neu-200)!important }
        .bg-cream-300 { background-color:var(--tw-neu-300)!important }
        .bg-cream-400 { background-color:var(--tw-neu-400)!important }

        /* bg sage */
        .bg-sage-50  { background-color:var(--tw-neu-50)!important }
        .bg-sage-100 { background-color:var(--tw-neu-100)!important }
        .bg-sage-200 { background-color:var(--tw-neu-200)!important }
        .bg-sage-400 { background-color:var(--tw-neu-400)!important }
        .bg-sage-500 { background-color:var(--tw-neu-500)!important }
        .bg-sage-600 { background-color:var(--tw-neu-600)!important }

        /* bg terracotta */
        .bg-terracotta-50  { background-color:var(--tw-neu-50)!important }
        .bg-terracotta-100 { background-color:var(--tw-neu-100)!important }
        .bg-terracotta-200 { background-color:var(--tw-neu-200)!important }
        .bg-terracotta-400 { background-color:var(--tw-neu-400)!important }
        .bg-terracotta-500 { background-color:var(--tw-neu-500)!important }
        .bg-terracotta-600 { background-color:var(--tw-neu-600)!important }

        /* hover bg terracotta/sage/cream — todos los tonos */
        .hover\:bg-terracotta-50:hover,.hover\:bg-terracotta-100:hover { background-color:var(--tw-neu-50)!important }
        .hover\:bg-terracotta-200:hover { background-color:var(--tw-neu-100)!important }
        .hover\:bg-terracotta-500:hover,.hover\:bg-terracotta-600:hover { background-color:var(--tw-neu-500)!important }
        .hover\:bg-sage-50:hover,.hover\:bg-sage-100:hover { background-color:var(--tw-neu-50)!important }
        .hover\:bg-sage-500:hover,.hover\:bg-sage-600:hover { background-color:var(--tw-neu-500)!important }
        .hover\:bg-cream-50:hover,.hover\:bg-cream-100:hover { background-color:var(--tw-neu-50)!important }

        /* text cream */
        .text-cream-50  { color:var(--tw-neu-50)!important }
        .text-cream-100 { color:var(--tw-neu-100)!important }
        .text-cream-200 { color:var(--tw-neu-200)!important }
        .text-cream-400 { color:var(--tw-neu-400)!important }
        .text-cream-600 { color:var(--tw-neu-600)!important }
        .text-cream-700 { color:var(--tw-neu-700)!important }

        /* text sage */
        .text-sage-400  { color:var(--tw-neu-400)!important }
        .text-sage-500  { color:var(--tw-neu-500)!important }
        .text-sage-600  { color:var(--tw-neu-600)!important }
        .text-sage-700  { color:var(--tw-neu-700)!important }

        /* text terracotta */
        .text-terracotta-400  { color:var(--tw-neu-400)!important }
        .text-terracotta-500  { color:var(--tw-neu-500)!important }
        .text-terracotta-600  { color:var(--tw-neu-600)!important }
        .text-terracotta-700  { color:var(--tw-neu-700)!important }

        /* hover text terracotta/sage */
        .hover\:text-terracotta-500:hover,.hover\:text-terracotta-600:hover { color:var(--tw-neu-600)!important }
        .hover\:text-sage-500:hover,.hover\:text-sage-600:hover { color:var(--tw-neu-600)!important }

        /* border cream */
        .border-cream-100 { border-color:var(--tw-neu-100)!important }
        .border-cream-200 { border-color:var(--tw-neu-200)!important }
        .border-cream-300 { border-color:var(--tw-neu-300)!important }
        .border-cream-400 { border-color:var(--tw-neu-400)!important }

        /* border sage */
        .border-sage-200 { border-color:var(--tw-neu-200)!important }
        .border-sage-300 { border-color:var(--tw-neu-300)!important }
        .border-sage-400 { border-color:var(--tw-neu-400)!important }
        .border-sage-500 { border-color:var(--tw-neu-500)!important }

        /* border terracotta */
        .border-terracotta-100 { border-color:var(--tw-neu-100)!important }
        .border-terracotta-200 { border-color:var(--tw-neu-200)!important }
        .border-terracotta-300 { border-color:var(--tw-neu-300)!important }
        .border-terracotta-400 { border-color:var(--tw-neu-400)!important }
        .border-terracotta-500 { border-color:var(--tw-neu-500)!important }
        .border-t-4.border-terracotta-500 { border-color:var(--tw-neu-500)!important }

        /* hover:border terracotta/sage/cream */
        .hover\:border-terracotta-400:hover,.hover\:border-terracotta-500:hover { border-color:var(--tw-neu-400)!important }
        .hover\:border-sage-400:hover,.hover\:border-sage-500:hover { border-color:var(--tw-neu-400)!important }
        .hover\:border-cream-300:hover,.hover\:border-cream-400:hover { border-color:var(--tw-neu-300)!important }

        /* focus:border terracotta */
        .focus\:border-terracotta-500:focus { border-color:var(--border-focus)!important }

        /* gradientes terracotta */
        [class~="from-terracotta-500"],[class~="from-terracotta-400"] {
            --tw-gradient-from: var(--tw-neu-500)!important;
            --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, transparent)!important;
        }
        [class~="to-terracotta-600"],[class~="to-terracotta-500"] { --tw-gradient-to: var(--tw-neu-600)!important; }
        [class~="from-sage-400"],[class~="from-sage-500"] {
            --tw-gradient-from: var(--tw-neu-400)!important;
            --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, transparent)!important;
        }
        [class~="to-sage-500"],[class~="to-sage-600"] { --tw-gradient-to: var(--tw-neu-500)!important; }

        /* =====================================================================
           SELECTOR DE TEMA — dentro del menú
        ===================================================================== */
        .theme-opt-btn {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .5rem .75rem;
            border-radius: .6rem;
            border: 1.5px solid transparent;
            background: transparent;
            cursor: pointer;
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-primary);
            transition: background .15s;
            white-space: nowrap;
            width: 100%;
        }
        .theme-opt-btn:hover { background: var(--bg-menu-item); }
        .theme-opt-btn.active { border-color: var(--accent); background: var(--bg-menu-item); }
        .theme-swatch {
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            flex-shrink: 0;
        }
        #menu-theme-submenu {
            display: none;
            flex-direction: column;
            gap: .2rem;
            padding: .25rem 0 .25rem .5rem;
        }
        #menu-theme-submenu.open { display: flex; }
        #menu-theme-toggle svg {
            transition: transform .2s;
        }
        #menu-theme-toggle.open svg {
            transform: rotate(180deg);
        }
        /* Secciones colapsables del sidebar */
        .sidebar-section {
            max-height: 600px;
            transition: max-height .25s ease, opacity .2s ease;
            opacity: 1;
        }
        .sidebar-section.collapsed {
            max-height: 0 !important;
            opacity: 0;
        }
        .sidebar-section-btn { background: none; border: none; cursor: pointer; }
        .sidebar-section-btn:hover p { color: var(--text-primary, #0f172a); }
        #ico-sec-pedidos,
        #ico-sec-catalogo,
        #ico-sec-usuarios,
        #ico-sec-reportes,
        #ico-sec-config { transition: transform .2s ease; }
        .sec-collapsed-ico { transform: rotate(-90deg); }
    </style>
    <script>
        // Expander BASE_PATH a JavaScript para uso en fetch y rutas
        window.BASE_PATH = '<?= BASE_PATH ?>';

        // ── Secciones colapsables del sidebar ────────────────────────────
        const _SIDEBAR_STORAGE_KEY = 'sidebar_sections';

        function _getSidebarState() {
            try { return JSON.parse(localStorage.getItem(_SIDEBAR_STORAGE_KEY) || '{}'); }
            catch(e) { return {}; }
        }

        function toggleSection(id) {
            const el  = document.getElementById(id);
            const ico = document.getElementById('ico-' + id);
            if (!el) return;
            const wasCollapsed = el.classList.contains('collapsed');
            const state = _getSidebarState();

            // Colapsar todas las demás secciones
            ['sec-pedidos','sec-catalogo','sec-usuarios','sec-reportes','sec-config'].forEach(function(otherId) {
                if (otherId === id) return;
                const other    = document.getElementById(otherId);
                const otherIco = document.getElementById('ico-' + otherId);
                if (other && !other.classList.contains('collapsed')) {
                    other.classList.add('collapsed');
                    if (otherIco) otherIco.classList.add('sec-collapsed-ico');
                    state[otherId] = true;
                }
            });

            // Alternar la sección clickeada
            const collapsed = !wasCollapsed;
            el.classList.toggle('collapsed', collapsed);
            if (ico) ico.classList.toggle('sec-collapsed-ico', collapsed);
            state[id] = collapsed;

            localStorage.setItem(_SIDEBAR_STORAGE_KEY, JSON.stringify(state));
        }

        // Restaurar estado al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const state = _getSidebarState();
            ['sec-pedidos','sec-catalogo','sec-usuarios','sec-reportes','sec-config'].forEach(function(id) {
                if (state[id] === true) {
                    const el  = document.getElementById(id);
                    const ico = document.getElementById('ico-' + id);
                    if (el)  el.classList.add('collapsed');
                    if (ico) ico.classList.add('sec-collapsed-ico');
                }
            });
        });
    </script>
</head>
<body class="antialiased theme-ocean">
<!-- Contenedor global de toasts — Módulo Sistema -->
<div id="_sys-toast-c" aria-live="polite"></div>
<script>
    // Aplicar tema guardado inmediatamente (evita flash)
    (function(){
        var t = localStorage.getItem('botikit-theme') || 'minimal';
        if (t !== 'minimal') document.body.classList.add('theme-' + t);
    })();
</script>

<!-- Menu Hamburguesa -->
<button 
    onclick="toggleMenu()"
    class="hamburger fixed top-4 right-4 z-50 p-3 rounded-xl shadow-lg text-white"
>
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
</button>

<!-- Menu Panel -->
<div id="menuBackdrop" class="menu-backdrop fixed inset-0 z-40 hidden" onclick="toggleMenu()"></div>
<div id="menuPanel" class="menu-panel fixed top-0 right-0 h-full w-72 z-50 shadow-2xl overflow-y-auto">
    
    <div class="p-6">
        <div class="flex justify-between items-center mb-8">
            <img src="<?= asset('assets/images/logo.png') ?>" alt="<?= htmlspecialchars($_nombreTienda) ?>" class="w-16 h-16">
            <button onclick="toggleMenu()" class="text-slate-600 hover:text-terracotta-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <nav class="space-y-2">
            <a href="<?= url('index.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700">
                <span class="text-xl">🏠</span>
                <span class="font-medium">Inicio</span>
            </a>

            <?php if ($isAdminLoggedIn): ?>
                <!-- Menú para Administradores Autenticados -->
                <div class="pt-2">
                    <div class="px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl mb-2">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <?= $_headerEsViewer ? 'Viewer' : 'Administrador' ?>
                        </p>
                        <p class="text-xs text-slate-600 mt-1">👤 <?= htmlspecialchars($adminNombre) ?></p>
                        <?php if ($_headerEsViewer): ?>
                        <p class="text-xs text-amber-600 mt-1 font-semibold">Solo lectura</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- PRINCIPAL -->
                    <a href="<?= url('admin/dashboard.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-900 bg-slate-100 border-l-4 border-slate-900 font-semibold">
                        <span class="text-xl">📊</span>
                        <span class="font-medium">Dashboard</span>
                    </a>

                    <?php if (!$_headerSoloMetricas): ?>

                    <!-- Gestión de Pedidos -->
                    <div class="mt-4">
                        <button type="button" onclick="toggleSection('sec-pedidos')"
                                class="sidebar-section-btn w-full flex items-center justify-between px-4 py-2 text-left group">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider group-hover:text-slate-600 transition-colors">Gestión de Pedidos</p>
                            <svg id="ico-sec-pedidos" class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="sec-pedidos" class="sidebar-section overflow-hidden">
                            <a href="<?= url('admin/kanban.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">📋</span>
                                <span class="font-medium">Pedidos</span>
                            </a>
                            <a href="<?= url('admin/pedidos-historial.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">📜</span>
                                <span class="font-medium">Historial</span>
                            </a>
                            <a href="<?= url('admin/solicitudes-consignacion.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">📦</span>
                                <span class="font-medium">Consignaciones</span>
                            </a>
                        </div>
                    </div>

                    <!-- Catálogo -->
                    <div class="mt-2">
                        <button type="button" onclick="toggleSection('sec-catalogo')"
                                class="sidebar-section-btn w-full flex items-center justify-between px-4 py-2 text-left group">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider group-hover:text-slate-600 transition-colors">Catálogo</p>
                            <svg id="ico-sec-catalogo" class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="sec-catalogo" class="sidebar-section overflow-hidden">
                            <a href="<?= url('admin/productos.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">📦</span>
                                <span class="font-medium">Productos</span>
                            </a>
                            <a href="<?= url('admin/kits.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">🎁</span>
                                <span class="font-medium">Kits</span>
                            </a>
                            <a href="<?= url('admin/cupones.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">🎟️</span>
                                <span class="font-medium">Cupones</span>
                            </a>
                        </div>
                    </div>

                    <!-- Usuarios -->
                    <div class="mt-2">
                        <button type="button" onclick="toggleSection('sec-usuarios')"
                                class="sidebar-section-btn w-full flex items-center justify-between px-4 py-2 text-left group">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider group-hover:text-slate-600 transition-colors">Usuarios</p>
                            <svg id="ico-sec-usuarios" class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="sec-usuarios" class="sidebar-section overflow-hidden">
                            <a href="<?= url('admin/clientes.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">👥</span>
                                <span class="font-medium">Clientes</span>
                            </a>
                            <a href="<?= url('admin/usuarios-sistema.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">🔐</span>
                                <span class="font-medium">Usuarios del Sistema</span>
                            </a>
                        </div>
                    </div>

                    <!-- Reportes -->
                    <div class="mt-2">
                        <button type="button" onclick="toggleSection('sec-reportes')"
                                class="sidebar-section-btn w-full flex items-center justify-between px-4 py-2 text-left group">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider group-hover:text-slate-600 transition-colors">Reportes</p>
                            <svg id="ico-sec-reportes" class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="sec-reportes" class="sidebar-section overflow-hidden">
                            <a href="<?= url('admin/reporte-ventas.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">📊</span>
                                <span class="font-medium">Reporte Ventas</span>
                            </a>
                            <a href="<?= url('admin/reporte-consignacion.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">🧾</span>
                                <span class="font-medium">Reporte Inventario Consignacion</span>
                            </a>
                        </div>
                    </div>

                    <!-- Configuración -->
                    <div class="mt-2">
                        <button type="button" onclick="toggleSection('sec-config')"
                                class="sidebar-section-btn w-full flex items-center justify-between px-4 py-2 text-left group">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider group-hover:text-slate-600 transition-colors">Configuración</p>
                            <svg id="ico-sec-config" class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="sec-config" class="sidebar-section overflow-hidden">
                            <a href="<?= url('admin/metodos-pago.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">💳</span>
                                <span class="font-medium">Métodos de Pago</span>
                            </a>
                            <a href="<?= url('admin/configuracion.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">⚙️</span>
                                <span class="font-medium">Configuración General</span>
                            </a>
                            <a href="<?= url('admin/administrador-tablas.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">🗂️</span>
                                <span class="font-medium">Administrador de Tablas</span>
                            </a>
                        </div>
                    </div>

                    <?php endif; // !$_headerSoloMetricas ?>

                    <?php if ($_headerSoloMetricas): ?>
                    <!-- Reportes -->
                    <div class="mt-2">
                        <button type="button" onclick="toggleSection('sec-reportes')"
                                class="sidebar-section-btn w-full flex items-center justify-between px-4 py-2 text-left group">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider group-hover:text-slate-600 transition-colors">Reportes</p>
                            <svg id="ico-sec-reportes" class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="sec-reportes" class="sidebar-section overflow-hidden">
                            <a href="<?= url('admin/reporte-ventas.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">📊</span>
                                <span class="font-medium">Reporte Ventas</span>
                            </a>
                            <a href="<?= url('admin/reporte-consignacion.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                                <span class="text-xl">🧾</span>
                                <span class="font-medium">Reporte Inventario Consignacion</span>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                <div class="h-px bg-slate-200 my-4"></div>

                <a href="<?= url('admin/mi-perfil.php') ?>"
                   class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700 hover:bg-slate-50">
                    <span class="text-xl">🔑</span>
                    <span class="font-medium">Mi Perfil</span>
                </a>

                <a href="<?= url('logout-admin.php') ?>" 
                   class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 hover:bg-red-50"
                   onclick="event.preventDefault(); showConfirm('\u00bfCerrar sesi\u00f3n de administrador?', () => location.href = this.href, { labelOk:'Cerrar sesi\u00f3n', labelCan:'Cancelar', danger:true })">
                    <span class="text-xl">🚪</span>
                    <span class="font-medium">Cerrar Sesión</span>
                </a>
            
            <?php else: ?>
                <!-- Menú para Usuarios No Autenticados -->
                <div class="pt-2">
                    <p class="px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Cliente</p>
                    
                    <a href="<?= url('mis-datos.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700">
                        <span class="text-xl">👤</span>
                        <span class="font-medium">Mis Datos</span>
                    </a>

                    <a href="<?= url('seguimiento.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-700">
                        <span class="text-xl">🔍</span>
                        <span class="font-medium">Seguimiento</span>
                    </a>
                </div>

                <div class="h-px bg-slate-200 my-4"></div>

                <a href="<?= url('login-admin.php') ?>" class="menu-item flex items-center gap-3 px-4 py-3 rounded-xl text-slate-900 font-semibold hover:bg-slate-50">
                    <span class="text-xl">🔐</span>
                    <span class="font-medium">Acceso personal</span>
                </a>
            <?php endif; ?>

        </nav>
    </div>

</div>

<script>
    /* ---- Tema ---- */
    const THEMES = ['minimal','terracotta','ocean','dark','lavender'];
    const THEME_NAMES = { minimal:'Minimal', terracotta:'Terracota', ocean:'Ocean', dark:'Oscuro', lavender:'Lavanda' };

    function setTheme(theme, save = true) {
        // Limpiar clases de tema previas del body
        THEMES.forEach(t => document.body.classList.remove('theme-' + t));
        document.documentElement.classList.remove(...THEMES.map(t => 'theme-' + t));
        if (theme !== 'minimal') {
            document.body.classList.add('theme-' + theme);
        }
        if (save) localStorage.setItem('botikit-theme', theme);
        // Marcar botón activo
        document.querySelectorAll('.theme-opt-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('onclick') === `setTheme('${theme}')`);
        });
    }

    function toggleMenuTheme() {
        const submenu = document.getElementById('menu-theme-submenu');
        const btn = document.getElementById('menu-theme-toggle');
        submenu.classList.toggle('open');
        btn.classList.toggle('open');
    }

    // Tema fijo: Ocean (personalización de tema deshabilitada)
    document.addEventListener('DOMContentLoaded', function() {
        setTheme('ocean', false);
    });

    /* ---- Menú ---- */
    function toggleMenu() {
        const panel = document.getElementById('menuPanel');
        const backdrop = document.getElementById('menuBackdrop');
        panel.classList.toggle('open');
        backdrop.classList.toggle('hidden');
    }

    function mostrarAlerta(mensaje, tipo = 'success') {
        showToast(mensaje, tipo);
    }

    // ══════════════════════════════════════════════════════
    // showToast — notificaciones globales Módulo Sistema
    // ══════════════════════════════════════════════════════
    function showToast(msg, type, duration) {
        type     = type     || 'success';
        duration = duration || 3500;
        let c = document.getElementById('_sys-toast-c');
        if (!c) return;
        const colors = { success:'#059669', error:'#dc2626', warning:'#d97706', info:'#2563eb' };
        const icons  = { success:'✔', error:'✖', warning:'⚠️', info:'ℹ' };
        const t = document.createElement('div');
        t.style.cssText = [
            'display:flex;align-items:center;gap:10px',
            'background:#fff',
            'border-radius:10px',
            'box-shadow:0 4px 20px rgba(0,0,0,.13)',
            'padding:12px 16px',
            'font-size:13px;font-weight:600',
            'font-family:var(--font-base,sans-serif)',
            'color:#1e293b',
            'border-left:4px solid '+(colors[type]||colors.info),
            'min-width:220px;max-width:380px',
            'cursor:pointer',
            'animation:_toast-in .2s ease',
        ].join(';');
        t.innerHTML = '<span style="font-size:16px">'+(icons[type]||icons.info)+'</span><span style="flex:1">'+msg+'</span>';
        c.appendChild(t);
        const rm = () => { t.style.opacity='0'; t.style.transition='opacity .2s'; setTimeout(()=>t.remove(),220); };
        const timer = setTimeout(rm, duration);
        t.addEventListener('click', () => { clearTimeout(timer); rm(); });
    }

    // ══════════════════════════════════════════════════════
    // showConfirm — dialog de confirmación global Sistema
    // ══════════════════════════════════════════════════════
    function showConfirm(msg, onConfirm, opts) {
        opts = opts || {};
        const labelOk  = opts.labelOk  || 'Confirmar';
        const labelCan = opts.labelCan || 'Cancelar';
        const danger   = opts.danger !== false;
        const ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;padding:20px;animation:_toast-in .15s ease';
        ov.innerHTML = `
            <div style="background:#fff;border-radius:14px;padding:24px;width:100%;max-width:380px;box-shadow:0 8px 40px rgba(0,0,0,.18);font-family:var(--font-base,sans-serif)">
                <p style="font-size:14px;font-weight:600;color:#1e293b;margin:0 0 20px;line-height:1.5">${msg}</p>
                <div style="display:flex;gap:10px;justify-content:flex-end">
                    <button id="_sc-can" style="padding:9px 18px;border-radius:8px;border:1.5px solid #e2e8f0;font-weight:600;font-size:13px;background:#fff;color:#64748b;cursor:pointer">${labelCan}</button>
                    <button id="_sc-ok"  style="padding:9px 18px;border-radius:8px;border:none;font-weight:700;font-size:13px;background:${danger?'#dc2626':'var(--accent)'};color:#fff;cursor:pointer">${labelOk}</button>
                </div>
            </div>`;
        document.body.appendChild(ov);
        const close = () => ov.remove();
        ov.querySelector('#_sc-can').addEventListener('click', close);
        ov.querySelector('#_sc-ok').addEventListener('click', () => { close(); onConfirm(); });
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
    }
</script>
