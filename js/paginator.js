/**
 * Paginator — paginador client-side reutilizable
 *
 * Uso mínimo:
 *   const pag = new Paginator({
 *     rows:     () => document.querySelectorAll('#myTbody .my-row'),
 *     bar:      '#my-pag-bar',
 *     info:     '#my-pag-info',
 *     ctrl:     '#my-pag-ctrl',
 *     sizeEl:   '#my-pag-size',
 *     noResult: '#my-no-results',   // opcional
 *   });
 *   pag.apply(filteredRows);        // llama tras cada filtrado
 *
 * También se puede usar con filter integrado:
 *   pag.filter(filterFn);           // filtra + pagina en un paso
 */
class Paginator {
  /**
   * @param {object} opts
   * @param {function():NodeList|Array} opts.rows       — todos los elementos paginables
   * @param {string}  opts.bar        — selector del contenedor del paginador
   * @param {string}  opts.info       — selector del span de info "1–25 de 87"
   * @param {string}  opts.ctrl       — selector del contenedor de botones
   * @param {string}  opts.sizeEl     — selector del <select> de tamaño de página
   * @param {string}  [opts.noResult] — selector del elemento "sin resultados"
   * @param {number}  [opts.defaultSize=25] — filas por página inicial
   * @param {string}  [opts.unit='elemento'] — unidad para el texto de info
   */
  constructor(opts) {
    this._opts    = opts;
    this._filtered = [];
    this._page    = 1;

    const sizeEl = document.querySelector(opts.sizeEl);
    if (sizeEl) sizeEl.addEventListener('change', () => this.paginate(1));
  }

  /** Devuelve el tamaño de página seleccionado actualmente */
  _pageSize() {
    const el = document.querySelector(this._opts.sizeEl);
    return parseInt(el?.value ?? this._opts.defaultSize ?? 25);
  }

  /**
   * Filtra todos los rows con filterFn(row) → bool, luego pagina.
   * @param {function(Element):boolean} filterFn
   */
  filter(filterFn) {
    const allRows = typeof this._opts.rows === 'function'
      ? Array.from(this._opts.rows())
      : Array.from(this._opts.rows);

    const filtered = allRows.filter(filterFn);
    this.apply(filtered, allRows);
  }

  /**
   * Aplica la paginación sobre un conjunto ya filtrado.
   * @param {Array<Element>} filtered — filas que deben mostrarse
   * @param {Array<Element>} [all]    — todas las filas (para ocultar el resto); si se omite se obtienen de opts.rows
   */
  apply(filtered, all) {
    this._filtered = filtered;
    this.paginate(1, all);
  }

  /**
   * Muestra la página indicada del conjunto filtrado actual.
   * @param {number}         page
   * @param {Array<Element>} [all] — todas las filas; si se omite se obtienen de opts.rows
   */
  paginate(page, all) {
    const allRows = all ?? (typeof this._opts.rows === 'function'
      ? Array.from(this._opts.rows())
      : Array.from(this._opts.rows));

    const ps     = this._pageSize();
    const total  = this._filtered.length;
    const pages  = Math.max(1, Math.ceil(total / ps));
    this._page   = Math.min(Math.max(1, page), pages);
    const start  = (this._page - 1) * ps;
    const end    = start + ps;

    // Ocultar todo, luego mostrar solo la ventana
    const visibleSet = new Set(this._filtered.slice(start, end));
    allRows.forEach(r => { r.style.display = visibleSet.has(r) ? '' : 'none'; });

    // No-results
    const noRes = this._opts.noResult ? document.querySelector(this._opts.noResult) : null;
    if (noRes) noRes.style.display = total === 0 ? '' : 'none';

    // Barra
    const bar = document.querySelector(this._opts.bar);
    if (bar) bar.style.display = total === 0 ? 'none' : '';

    // Info
    const infoEl = document.querySelector(this._opts.info);
    if (infoEl) {
      const unit = this._opts.unit ?? 'elemento';
      const units = this._opts.units ?? (unit + 's');
      const from = total === 0 ? 0 : start + 1;
      const to   = Math.min(end, total);
      infoEl.textContent = `${from}–${to} de ${total} ${total === 1 ? unit : units}`;
    }

    // Controles
    const ctrl = document.querySelector(this._opts.ctrl);
    if (!ctrl) return;
    ctrl.innerHTML = '';
    const mkBtn = (label, pg, active, disabled) => {
      const b = document.createElement('button');
      b.className = 'pag-btn' + (active ? ' pag-active' : '');
      b.textContent = label;
      b.disabled = disabled;
      if (!disabled) b.addEventListener('click', () => this.paginate(pg));
      return b;
    };
    const mkEllipsis = () => {
      const s = document.createElement('span');
      s.className = 'pag-btn';
      s.style.cursor = 'default';
      s.textContent = '…';
      return s;
    };

    ctrl.appendChild(mkBtn('‹', this._page - 1, false, this._page === 1));

    let lo = Math.max(1, this._page - 2);
    let hi = Math.min(pages, this._page + 2);

    if (lo > 1) {
      ctrl.appendChild(mkBtn('1', 1, false, false));
      if (lo > 2) ctrl.appendChild(mkEllipsis());
    }
    for (let p = lo; p <= hi; p++) {
      ctrl.appendChild(mkBtn(p, p, p === this._page, false));
    }
    if (hi < pages) {
      if (hi < pages - 1) ctrl.appendChild(mkEllipsis());
      ctrl.appendChild(mkBtn(pages, pages, false, false));
    }

    ctrl.appendChild(mkBtn('›', this._page + 1, false, this._page === pages));
  }

  /** Vuelve a paginar la página actual (útil tras DOM changes) */
  refresh() {
    this.paginate(this._page);
  }

  /** Número de página actual */
  get currentPage() { return this._page; }

  /** Total de filas filtradas */
  get total() { return this._filtered.length; }
}
