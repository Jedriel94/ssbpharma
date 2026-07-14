/**
 * ui-toast.js — showToast + showConfirm móvil/tableta
 * Módulo Representantes y Módulo Cliente
 *
 * Uso:
 *   showToast('Mensaje', 'success' | 'error' | 'warning' | 'info', duration?)
 *   showConfirm('¿Seguro?', () => { ... }, { labelOk, labelCan, danger })
 *
 * Requiere en el HTML:
 *   <div id="_mob-toast-c"></div>
 *   <script src="...js/ui-toast.js"></script>
 */

(function () {
    'use strict';

    const COLORS = { success:'#059669', error:'#dc2626', warning:'#d97706', info:'#2563eb' };
    const ICONS  = { success:'✔', error:'✖', warning:'⚠️', info:'ℹ' };

    /* ── CSS inyectado una sola vez ─────────────────────────── */
    (function injectStyles() {
        if (document.getElementById('_mob-toast-styles')) return;
        const s = document.createElement('style');
        s.id = '_mob-toast-styles';
        s.textContent = `
            #_mob-toast-c {
                position: fixed;
                bottom: 80px;          /* encima del bottom-nav */
                left: 50%;
                transform: translateX(-50%);
                z-index: 9999;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                width: min(90vw, 360px);
                pointer-events: none;
            }
            #_mob-toast-c > * { pointer-events: all; }

            @keyframes _mob-toast-in {
                from { opacity: 0; transform: translateY(12px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            @keyframes _mob-confirm-in {
                from { opacity: 0; transform: translateY(30px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(s);
    })();

    /* ── Contenedor auto-creado si no existe ───────────────── */
    function getContainer() {
        let c = document.getElementById('_mob-toast-c');
        if (!c) {
            c = document.createElement('div');
            c.id = '_mob-toast-c';
            document.body.appendChild(c);
        }
        return c;
    }

    /* ── showToast ─────────────────────────────────────────── */
    window.showToast = function (msg, type, duration) {
        type     = type     || 'success';
        duration = duration || 4000;

        const c = getContainer();
        const color = COLORS[type] || COLORS.info;
        const icon  = ICONS[type]  || ICONS.info;

        const t = document.createElement('div');
        t.style.cssText = [
            'display:flex;align-items:center;gap:10px',
            'background:#fff',
            'border-radius:12px',
            'box-shadow:0 4px 24px rgba(0,0,0,.15)',
            'padding:14px 16px',
            'font-size:14px;font-weight:600',
            'color:#1e293b',
            'border-left:4px solid ' + color,
            'width:100%',
            'cursor:pointer',
            'animation:_mob-toast-in .22s ease',
        ].join(';');
        t.innerHTML = '<span style="font-size:18px">' + icon + '</span>'
                    + '<span style="flex:1">' + msg + '</span>';
        c.appendChild(t);

        const rm = () => {
            t.style.opacity = '0';
            t.style.transform = 'translateY(8px)';
            t.style.transition = 'opacity .22s, transform .22s';
            setTimeout(() => t.remove(), 240);
        };
        const timer = setTimeout(rm, duration);
        t.addEventListener('click', () => { clearTimeout(timer); rm(); });
    };

    /* ── showConfirm ───────────────────────────────────────── */
    window.showConfirm = function (msg, onConfirm, opts) {
        opts = opts || {};
        const labelOk  = opts.labelOk  || 'Confirmar';
        const labelCan = opts.labelCan || 'Cancelar';
        const danger   = opts.danger !== false;
        const okColor  = danger ? '#dc2626' : '#059669';

        const ov = document.createElement('div');
        ov.style.cssText = [
            'position:fixed;inset:0;z-index:10000',
            'background:rgba(0,0,0,.45)',
            'display:flex;align-items:flex-end;justify-content:center',
            'padding:16px',
        ].join(';');

        ov.innerHTML = `
            <div style="background:#fff;border-radius:20px 20px 16px 16px;padding:24px;width:100%;max-width:420px;box-shadow:0 -4px 40px rgba(0,0,0,.18);animation:_mob-confirm-in .25s ease">
                <div style="width:40px;height:4px;background:#e2e8f0;border-radius:2px;margin:0 auto 20px"></div>
                <p style="font-size:15px;font-weight:600;color:#1e293b;margin:0 0 20px;line-height:1.5;text-align:center">${msg}</p>
                <div style="display:flex;gap:10px">
                    <button id="_mc-can" style="flex:1;padding:14px;border-radius:12px;border:1.5px solid #e2e8f0;font-weight:700;font-size:14px;background:#fff;color:#64748b;cursor:pointer">${labelCan}</button>
                    <button id="_mc-ok"  style="flex:1;padding:14px;border-radius:12px;border:none;font-weight:700;font-size:14px;background:${okColor};color:#fff;cursor:pointer">${labelOk}</button>
                </div>
            </div>`;

        document.body.appendChild(ov);
        const close = () => ov.remove();
        ov.querySelector('#_mc-can').addEventListener('click', close);
        ov.querySelector('#_mc-ok').addEventListener('click', () => { close(); onConfirm(); });
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
    };

})();
