/**
 * ubicaciones.js — Helper compartido para selectores Estado / Municipio
 *
 * Uso:
 *   initUbicaciones({
 *     selectEstado:   '#id-del-select-estado',
 *     selectMunicipio:'#id-del-select-municipio',  // o inputMunicipio para input text
 *     inputMunicipio: null,   // si no usas select sino input texto
 *     valorEstado:    'Jalisco',   // valor pre-seleccionado (opcional)
 *     valorMunicipio: 'Guadalajara', // valor pre-seleccionado (opcional)
 *     basePath:       '',          // prefijo URL, ej: '../' para subcarpetas
 *     required:       false,       // agrega required al select municipio
 *   });
 */
async function initUbicaciones(opts = {}) {
    const {
        selectEstado    = '#estado',
        selectMunicipio = '#ciudad',
        inputMunicipio  = null,
        valorEstado     = '',
        valorMunicipio  = '',
        basePath        = '',
        required        = false,
    } = opts;

    const selEstado = document.querySelector(selectEstado);
    if (!selEstado) return;

    // ── 1. Cargar estados ────────────────────────────────────────────────────
    try {
        const res  = await fetch(`${basePath}api/ubicaciones.php?action=estados`);
        const json = await res.json();
        if (!json.success) return;

        // Vaciar y re-poblar (preserva la option vacía inicial si existe)
        const placeholder = selEstado.querySelector('option[value=""]');
        selEstado.innerHTML = '';
        if (placeholder) selEstado.appendChild(placeholder);

        json.data.forEach(e => {
            const opt = document.createElement('option');
            opt.value       = e.nombre;
            opt.textContent = e.nombre;
            if (e.nombre === valorEstado) opt.selected = true;
            selEstado.appendChild(opt);
        });
    } catch (_) { return; }

    // ── 2. Función para poblar municipios ────────────────────────────────────
    async function cargarMunicipios(estado, seleccionado = '') {
        if (!estado) {
            resetMunicipio();
            return;
        }
        try {
            const res  = await fetch(`${basePath}api/ubicaciones.php?action=municipios&estado=${encodeURIComponent(estado)}`);
            const json = await res.json();
            if (!json.success) return;

            if (inputMunicipio) {
                // Modo input libre: solo mostramos datalist si existe
                const inp = document.querySelector(inputMunicipio);
                if (!inp) return;
                let dl = document.getElementById('_mun_datalist_');
                if (!dl) {
                    dl = document.createElement('datalist');
                    dl.id = '_mun_datalist_';
                    document.body.appendChild(dl);
                    inp.setAttribute('list', '_mun_datalist_');
                }
                dl.innerHTML = json.data.map(m => `<option value="${m}">`).join('');
                return;
            }

            const sel = document.querySelector(selectMunicipio);
            if (!sel) return;

            const prevVal = seleccionado || sel.value;
            sel.innerHTML = '<option value="">Selecciona municipio / alcaldía</option>';
            json.data.forEach(m => {
                const opt = document.createElement('option');
                opt.value       = m;
                opt.textContent = m;
                if (m === prevVal) opt.selected = true;
                sel.appendChild(opt);
            });
            if (required) sel.required = true;
            sel.disabled = false;
        } catch (_) {}
    }

    function resetMunicipio() {
        if (inputMunicipio) return;
        const sel = document.querySelector(selectMunicipio);
        if (!sel) return;
        sel.innerHTML = '<option value="">— Primero selecciona un estado —</option>';
        sel.disabled  = true;
    }

    // ── 3. Listener onchange estado (evitar duplicados al llamar initUbicaciones varias veces) ──
    if (selEstado._ubicListener) {
        selEstado.removeEventListener('change', selEstado._ubicListener);
    }
    selEstado._ubicListener = () => cargarMunicipios(selEstado.value);
    selEstado.addEventListener('change', selEstado._ubicListener);

    // ── 4. Cargar municipios inicial si hay estado pre-seleccionado ──────────
    resetMunicipio();
    if (valorEstado) {
        await cargarMunicipios(valorEstado, valorMunicipio);
    }
}
