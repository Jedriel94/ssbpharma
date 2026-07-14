
<!-- ═══════════════════════════════════════════════════
     TOAST SYSTEM — showToast(msg, type, duration)
     type: 'success' | 'error' | 'warning' | 'info'
     Disponible globalmente en todas las páginas admin.
════════════════════════════════════════════════════ -->
<div id="toast-container" aria-live="polite" aria-atomic="false"></div>

<style>
#toast-container {
    position: fixed;
    top: 1.25rem;
    right: 1.25rem;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: .6rem;
    pointer-events: none;
    max-width: 22rem;
    width: calc(100vw - 2.5rem);
}

.toast {
    pointer-events: all;
    display: grid;
    grid-template-columns: 2.25rem 1fr auto;
    align-items: start;
    gap: .75rem;
    padding: .9rem 1rem .85rem;
    border-radius: 14px;
    box-shadow:
        0 4px 6px -1px rgba(0,0,0,.08),
        0 10px 24px -4px rgba(0,0,0,.12),
        0 0 0 1px rgba(0,0,0,.05);
    backdrop-filter: blur(12px);
    background: #fff;
    overflow: hidden;
    position: relative;
    animation: toast-in .32s cubic-bezier(.34,1.56,.64,1) both;
}
.toast.toast-hide {
    animation: toast-out .28s cubic-bezier(.55,.06,.68,.19) forwards;
}

/* Color accents por tipo */
.toast::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 4px;
    border-radius: 14px 0 0 14px;
}
.toast-success::before  { background: #126c6a; }
.toast-error::before    { background: #b42318; }
.toast-warning::before  { background: #d97706; }
.toast-info::before     { background: #2563eb; }

/* Icono */
.toast-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1rem;
    margin-top: .05rem;
}
.toast-success .toast-icon  { background: #d1fae5; color: #065f46; }
.toast-error   .toast-icon  { background: #fee2e2; color: #991b1b; }
.toast-warning .toast-icon  { background: #fef3c7; color: #92400e; }
.toast-info    .toast-icon  { background: #dbeafe; color: #1e40af; }

/* Texto */
.toast-body { display: flex; flex-direction: column; gap: .15rem; min-width: 0; }
.toast-title {
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: #64748b;
    line-height: 1;
}
.toast-msg {
    font-size: .875rem;
    font-weight: 500;
    color: #0f172a;
    line-height: 1.4;
    word-break: break-word;
}

/* Cerrar */
.toast-close {
    background: none;
    border: none;
    cursor: pointer;
    color: #94a3b8;
    padding: .15rem;
    border-radius: 6px;
    line-height: 1;
    font-size: 1rem;
    flex-shrink: 0;
    transition: color .15s, background .15s;
    margin-top: -.1rem;
}
.toast-close:hover { color: #0f172a; background: #f1f5f9; }

/* Barra de progreso */
.toast-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 3px;
    border-radius: 0 0 14px 14px;
    animation: toast-progress linear forwards;
    transform-origin: left center;
}
.toast-success .toast-progress  { background: #126c6a; }
.toast-error   .toast-progress  { background: #b42318; }
.toast-warning .toast-progress  { background: #d97706; }
.toast-info    .toast-progress  { background: #2563eb; }

@keyframes toast-in {
    from { opacity: 0; transform: translateX(110%) scale(.94); }
    to   { opacity: 1; transform: translateX(0)   scale(1); }
}
@keyframes toast-out {
    from { opacity: 1; transform: translateX(0)    scale(1);   max-height: 120px; margin-bottom: 0; }
    to   { opacity: 0; transform: translateX(110%) scale(.94); max-height: 0;     margin-bottom: -.6rem; }
}
@keyframes toast-progress {
    from { width: 100%; }
    to   { width: 0%;   }
}

/* Responsive: en móvil ocupa todo el ancho */
@media (max-width: 480px) {
    #toast-container { right: .75rem; left: .75rem; max-width: none; width: auto; }
}
</style>

<script>
(function () {
    const ICONS = {
        success: '',
        error:   '',
        warning: '',
        info:    'i',
    };
    const LABELS = {
        success: 'Éxito',
        error:   'Error',
        warning: 'Aviso',
        info:    'Info',
    };

    window.showToast = function (msg, type = 'info', duration = 4000) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <span class="toast-icon">${ICONS[type] || ICONS.info}</span>
            <div class="toast-body">
                <span class="toast-title">${LABELS[type] || type}</span>
                <span class="toast-msg">${_escHtml(msg)}</span>
            </div>
            <button class="toast-close" aria-label="Cerrar">&#x2715;</button>
            <div class="toast-progress" style="animation-duration:${duration}ms"></div>
        `;

        const close = () => {
            if (toast._closing) return;
            toast._closing = true;
            toast.classList.add('toast-hide');
            toast.addEventListener('animationend', () => toast.remove(), { once: true });
        };

        toast.querySelector('.toast-close').addEventListener('click', close);
        container.appendChild(toast);

        const timer = setTimeout(close, duration);
        toast.addEventListener('mouseenter', () => {
            clearTimeout(timer);
            toast.querySelector('.toast-progress').style.animationPlayState = 'paused';
        });
        toast.addEventListener('mouseleave', () => {
            toast.querySelector('.toast-progress').style.animationPlayState = 'running';
            setTimeout(close, 800);
        });
    };

    function _escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
</body>
</html>
