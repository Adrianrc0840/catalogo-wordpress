/* ── Florería PDV — Punto de Venta ── */
(function () {
    'use strict';

    const { ajaxurl, nonce: initialNonce, siteurl, today = '', schedules = {}, fechasEspeciales = [], fechasCerradas = [] } = window.fcPdv || {};
    let nonce = initialNonce;

    // ── DOM helpers ──
    const $  = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

    // ── State ──
    let cart         = [];   // items del ticket
    let catalogo     = [];   // categorías + arreglos (estructura original)
    let allArreglos  = [];   // lista plana de todos los arreglos para el grid
    let selectedCat  = null; // id de categoría activa (null = todas)
    let searchTerm   = '';   // término de búsqueda activo
    let cajaActiva   = null; // objeto de la caja abierta principal
    let cajasData    = { abiertas: [], cerradas: [] };
    let tipoPedido   = 'recoleccion';
    let formaPago    = 'efectivo';

    // ── Helpers ──
    function escHtml(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function fmt(n) {
        return '$' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function fmtDate(d) {
        if (!d) return '';
        const [y, m, day] = d.split('-');
        const meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        return `${parseInt(day)} ${meses[parseInt(m)]} ${y}`;
    }

    function fmtDatetime(ts) {
        if (!ts) return '';
        return ts.replace('T', ' ').substring(0, 16);
    }

    // ── Horarios helpers ──

    /** Hora actual en la zona de Tijuana (America/Tijuana). */
    function getNowTijuana() {
        return new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Tijuana' }));
    }

    /**
     * Extrae los minutos desde medianoche del inicio de un slot.
     * Soporta formato 24h ("10:00 - 12:00") y 12h ("1:00pm – 3:00pm").
     */
    function parseSlotStartMinutes(slot) {
        const m = slot.match(/^(\d{1,2}):(\d{2})\s*(am|pm)?/i);
        if (!m) return 0;
        let h        = parseInt(m[1], 10);
        const min    = parseInt(m[2], 10);
        const ampm   = (m[3] || '').toLowerCase();
        if (ampm === 'pm' && h !== 12) h += 12;
        if (ampm === 'am' && h === 12) h  = 0;
        return h * 60 + min;
    }

    /**
     * Devuelve los slots disponibles para una fecha YYYY-MM-DD.
     *  null  → fecha cerrada (no se aceptan pedidos)
     *  []    → sin horarios definidos (domingo sin excepción, etc.)
     *  [...] → array de strings de slots
     */
    function getSlotsForDate(dateStr) {
        if (!dateStr) return [];
        if (fechasCerradas.includes(dateStr)) return null;

        const [y, mo, d] = dateStr.split('-').map(Number);
        const date       = new Date(y, mo - 1, d);
        const dow        = date.getDay(); // 0=Dom … 6=Sáb

        // Domingo: solo si es fecha especial
        if (dow === 0) {
            const ddmm = String(d).padStart(2, '0') + '/' + String(mo).padStart(2, '0');
            if (!fechasEspeciales.includes(ddmm)) return [];
        }

        const slots = (schedules[String(dow)] || []).slice(); // copia

        // Si es hoy, filtrar slots que ya pasaron (zona Tijuana)
        if (dateStr === today) {
            const now        = getNowTijuana();
            const nowMinutes = now.getHours() * 60 + now.getMinutes();
            return slots.filter(s => parseSlotStartMinutes(s) > nowMinutes);
        }

        return slots;
    }

    /**
     * Actualiza ambos selects de horario (envío y recolección) en el modal de cobro
     * según la fecha y tipo de pedido seleccionados.
     */
    function updateHorariosModal(backdrop) {
        const fecha    = $('#pdv-co-fecha', backdrop)?.value || '';
        const selEnvio = $('#pdv-co-horario', backdrop);
        const warnEl   = $('#pdv-co-fecha-warn', backdrop);

        if (!fecha) {
            if (selEnvio) selEnvio.innerHTML = '<option value="">— Selecciona fecha primero —</option>';
            if (warnEl)   warnEl.style.display = 'none';
            return;
        }

        const slots = getSlotsForDate(fecha);

        // Aviso fecha cerrada (aplica a ambos tipos)
        if (warnEl) warnEl.style.display = slots === null ? '' : 'none';

        // Solo el select de envío usa los rangos del admin
        if (!selEnvio) return;
        if (slots === null) {
            selEnvio.innerHTML = '<option value="">— Fecha cerrada —</option>';
        } else if (!slots.length) {
            selEnvio.innerHTML = '<option value="">— Sin horarios disponibles —</option>';
        } else {
            selEnvio.innerHTML = '<option value="">— Selecciona horario —</option>'
                + slots.map(s => `<option value="${escHtml(s)}">${escHtml(s)}</option>`).join('');
        }
    }

    async function ajax(action, data = {}) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', nonce);
        for (const [k, v] of Object.entries(data)) body.append(k, v);
        const res = await fetch(ajaxurl, { method: 'POST', body });
        return res.json();
    }

    let toastTimer = null;
    function showToast(msg, type = 'info') {
        let t = $('.fc-pdv-toast');
        if (!t) {
            t = document.createElement('div');
            t.className = 'fc-pdv-toast';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.className   = `fc-pdv-toast ${type} show`;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
    }

    // ── CART ──
    function cartTotal() {
        return cart.reduce((s, it) => s + (it.precio || 0), 0);
    }

    function renderTicket() {
        const container = $('#fc-pdv-ticket-items');
        if (!container) return;

        if (!cart.length) {
            container.innerHTML = `
                <div class="fc-pdv-ticket-empty">
                    <div class="fc-pdv-ticket-empty-icon">🛒</div>
                    <span>Ticket vacío</span>
                </div>`;
        } else {
            container.innerHTML = cart.map((it, i) => `
                <div class="fc-pdv-ticket-item" data-idx="${i}">
                    <div class="fc-pdv-ticket-item-click" title="Editar">
                        <div class="fc-pdv-ticket-item-info">
                            <div class="fc-pdv-ticket-item-nombre">${escHtml(it.arreglo_nombre)}</div>
                            <div class="fc-pdv-ticket-item-sub">${[it.tamano, it.color].filter(Boolean).join(' · ')}</div>
                        </div>
                        <span class="fc-pdv-ticket-item-precio">${fmt(it.precio)}</span>
                    </div>
                    <button class="fc-pdv-ticket-item-remove" data-idx="${i}" title="Quitar">×</button>
                </div>`).join('');

            // Botón quitar
            $$('.fc-pdv-ticket-item-remove', container).forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    cart.splice(parseInt(btn.dataset.idx), 1);
                    renderTicket();
                });
            });

            // Click en el área del ítem → editar
            $$('.fc-pdv-ticket-item-click', container).forEach(clickArea => {
                clickArea.addEventListener('click', () => {
                    const row = clickArea.closest('[data-idx]');
                    const idx = parseInt(row.dataset.idx);
                    const cartItem = cart[idx];
                    if (!cartItem) return;
                    // Ir a vista PDV si no está
                    switchView('pdv');
                    const arreglo = cartItem.arreglo_id
                        ? (allArreglos.find(a => a.id === cartItem.arreglo_id) || null)
                        : null;
                    showDetailPanel(
                        arreglo || { id: 0, nombre: cartItem.arreglo_nombre, descripcion: '', thumb: cartItem.imagen_url || '', tamanos: [] },
                        idx
                    );
                });
            });
        }

        const totalEl = $('#fc-pdv-total-amount');
        if (totalEl) totalEl.textContent = fmt(cartTotal());

        const cobrarBtn = $('#fc-pdv-btn-cobrar');
        if (cobrarBtn) cobrarBtn.disabled = !cart.length;
    }

    // Cambiar entre vistas PDV / Caja / Informes
    function switchView(view) {
        $$('.fc-pdv-nav-btn').forEach(b => b.classList.remove('active'));
        $$('.fc-pdv-view').forEach(v => v.classList.remove('active'));
        const btn = $(`.fc-pdv-nav-btn[data-view="${view}"]`);
        if (btn) btn.classList.add('active');
        const panel = $(`#fc-pdv-view-${view}`);
        if (panel) panel.classList.add('active');
    }

    // ── CATALOG ──
    function renderCatalog() {
        const container = $('#fc-pdv-catalog-content');
        if (!container) return;
        container.classList.add('is-grid'); // padding + scroll para el grid

        // Aplicar filtros de categoría y búsqueda
        let arr = allArreglos;
        if (selectedCat !== null) arr = arr.filter(a => Array.isArray(a.cat_ids) && a.cat_ids.includes(selectedCat));
        if (searchTerm) arr = arr.filter(a => a.nombre.toLowerCase().includes(searchTerm));

        // Dropdown de categoría (solo si hay más de una)
        const catLabel = selectedCat === null
            ? 'Todas las categorías'
            : (catalogo.find(c => c.id === selectedCat)?.nombre || 'Todas las categorías');
        const pillsHtml = catalogo.length > 1 ? `
            <div class="fc-pdv-cat-filter">
                <button class="fc-pdv-cat-toggle" id="pdv-cat-toggle">
                    <span id="pdv-cat-label">${escHtml(catLabel)}</span>
                    <span class="fc-pdv-cat-chevron">▼</span>
                </button>
                <div class="fc-pdv-cat-panel" id="pdv-cat-panel" style="display:none">
                    <button class="fc-pdv-cat-btn${selectedCat === null ? ' active' : ''}" data-cat="all">Todas</button>
                    ${catalogo.map(c => `<button class="fc-pdv-cat-btn${selectedCat === c.id ? ' active' : ''}" data-cat="${c.id}">${escHtml(c.nombre)}</button>`).join('')}
                </div>
            </div>` : '';

        // Grid de tarjetas
        const capturedArr = arr; // closure para eventos
        const cardsHtml = arr.length
            ? `<div class="fc-pdv-catalog-grid">
                ${arr.map((a, i) => {
                    const precios = a.tamanos.map(t => t.precio).filter(p => p > 0);
                    const pMin = precios.length ? Math.min(...precios) : 0;
                    const pMax = precios.length ? Math.max(...precios) : 0;
                    const precioStr = pMin && pMax && pMin !== pMax
                        ? `${fmt(pMin)} – ${fmt(pMax)}`
                        : pMin ? fmt(pMin) : 'Sin precio';
                    return `
                        <div class="fc-pdv-catalog-card${a.agotado ? ' agotado' : ''}" data-idx="${i}">
                            <div class="fc-pdv-card-img-wrap">
                                ${a.thumb
                                    ? `<img src="${escHtml(a.thumb)}" alt="${escHtml(a.nombre)}" loading="lazy" />`
                                    : `<div class="fc-pdv-card-img-empty">🌸</div>`}
                                ${a.agotado ? `<span class="fc-pdv-card-agotado-badge">Agotado</span>` : ''}
                            </div>
                            <div class="fc-pdv-card-body">
                                <div class="fc-pdv-card-nombre">${escHtml(a.nombre)}</div>
                                <div class="fc-pdv-card-precio">${precioStr}</div>
                            </div>
                        </div>`;
                }).join('')}
               </div>`
            : `<p style="color:#94a3b8;font-size:14px;text-align:center;padding:32px 16px;">
                   ${searchTerm ? `Sin resultados para "<strong>${escHtml(searchTerm)}</strong>"` : 'No hay arreglos disponibles.'}
               </p>`;

        container.innerHTML = pillsHtml + cardsHtml;

        // Dropdown → toggle panel
        const catToggleEl = $('#pdv-cat-toggle', container);
        const catPanelEl  = $('#pdv-cat-panel', container);
        if (catToggleEl && catPanelEl) {
            catToggleEl.addEventListener('click', e => {
                e.stopPropagation();
                const isOpen = catPanelEl.style.display !== 'none';
                catPanelEl.style.display = isOpen ? 'none' : '';
                catToggleEl.classList.toggle('open', !isOpen);
            });

            // Cerrar al clic fuera
            const closeCatPanel = e => {
                if (!catToggleEl.contains(e.target) && !catPanelEl.contains(e.target)) {
                    catPanelEl.style.display = 'none';
                    catToggleEl.classList.remove('open');
                    document.removeEventListener('click', closeCatPanel);
                }
            };
            catToggleEl.addEventListener('click', () => {
                if (catPanelEl.style.display !== 'none') {
                    setTimeout(() => document.addEventListener('click', closeCatPanel), 0);
                }
            });
        }

        // Botones de categoría dentro del panel
        $$('.fc-pdv-cat-btn', container).forEach(btn => {
            btn.addEventListener('click', () => {
                const val = btn.dataset.cat;
                selectedCat = val === 'all' ? null : parseInt(val);
                renderCatalog();
            });
        });

        // Tarjeta → abrir detalle
        $$('.fc-pdv-catalog-card', container).forEach(card => {
            card.addEventListener('click', () => {
                showDetailPanel(capturedArr[parseInt(card.dataset.idx)]);
            });
        });
    }

    // ── DETAIL PANEL — reemplaza el grid en el panel izquierdo ──
    function showDetailPanel(arreglo, cartIdx = -1) {
        const container = $('#fc-pdv-catalog-content');
        if (!container) return;
        container.classList.remove('is-grid'); // sin padding para el detalle 2-col

        const isPersonalizado = !arreglo.id;
        const tamanos = arreglo.tamanos || [];
        const editItem = cartIdx >= 0 ? cart[cartIdx] : null;

        // Pre-seleccionar tamaño si estamos editando
        let selTamIdx = 0;
        if (editItem?.tamano && tamanos.length > 0) {
            const found = tamanos.findIndex(t => t.label === editItem.tamano);
            if (found >= 0) selTamIdx = found;
        }
        let selTamano = tamanos[selTamIdx] || null;

        // Pre-seleccionar color si estamos editando
        let selColor = selTamano?.colores?.[0] || null;
        if (editItem?.color && selTamano?.colores) {
            const colorName = editItem.color.split(' · ')[0];
            const found = selTamano.colores.find(c => c.nombre === colorName);
            if (found) selColor = found;
        }

        // Notas previas — prefiere el campo 'notas' separado; fallback: extraer del color "Color · notas"
        let editNotas = editItem?.notas || '';
        if (!editNotas && editItem?.color) {
            const parts = editItem.color.split(' · ');
            editNotas = parts.length > 1 ? parts.slice(1).join(' · ') : '';
        }

        function getMainPhoto() {
            if (selColor?.imagen_url) return selColor.imagen_url;
            if (selTamano?.imagen_url) return selTamano.imagen_url;
            return arreglo.thumb || '';
        }

        function render() {
            const photo   = getMainPhoto();
            const colores = selTamano?.colores || [];
            const precio  = editItem ? editItem.precio : (selTamano?.precio || 0);

            container.innerHTML = `
                <div class="fc-pdv-detail-panel">
                  <div class="fc-pdv-detail-inner">

                    ${/* ── Columna izquierda: foto ── */ ''}
                    ${!isPersonalizado && photo
                        ? `<div class="fc-pdv-detail-photo-col">
                               <div class="fc-pdv-img-wrap" id="pdv-img-wrap">
                                   <img id="pdv-detail-img" src="${escHtml(photo)}" alt="${escHtml(arreglo.nombre)}" />
                                   <button class="fc-pdv-lightbox-trigger" title="Ver imagen completa">⛶</button>
                                   <span class="fc-pdv-img-hint">🔍 Toca para ver completa</span>
                               </div>
                           </div>`
                        : `<div class="fc-pdv-detail-photo-empty-col">🌸</div>`}

                    ${/* ── Columna derecha: info ── */ ''}
                    <div class="fc-pdv-detail-info-col">

                        <button class="fc-pdv-detail-back">
                            ← Volver${editItem ? '<span class="fc-pdv-detail-edit-badge">Editando</span>' : ''}
                        </button>

                        ${arreglo.agotado ? `
                            <div class="fc-pdv-agotado-aviso">
                                🚫 Este arreglo está marcado como <strong>agotado</strong>.
                            </div>` : ''}

                        ${arreglo.especial ? `
                            <div class="fc-pdv-especial-aviso">
                                🕐 Este arreglo requiere <strong>al menos 2 días hábiles de anticipación</strong>. Sábado y domingo no cuentan.
                            </div>` : ''}

                        ${isPersonalizado ? `
                            <div class="fc-pdv-form-group">
                                <label>Nombre del arreglo <span style="color:#ef4444">*</span></label>
                                <input type="text" id="pdv-item-nombre"
                                       placeholder="Ej: Girasoles personalizados"
                                       value="${escHtml(editItem?.arreglo_nombre || '')}" />
                            </div>` : `
                            <h2 class="fc-pdv-detail-nombre">${escHtml(arreglo.nombre)}</h2>`}

                        <div class="fc-pdv-precio-display" id="pdv-precio-display">${fmt(precio)}</div>

                        ${arreglo.descripcion
                            ? `<p class="fc-pdv-detail-desc">${escHtml(arreglo.descripcion)}</p>`
                            : ''}

                        ${!isPersonalizado && tamanos.length > 1 ? `
                            <div class="fc-pdv-form-group">
                                <label class="fc-pdv-label-sm">Tamaño</label>
                                <div class="fc-pdv-tamano-chips">
                                    ${tamanos.map((t, i) => `
                                        <div class="fc-pdv-tamano-chip${i === selTamIdx ? ' active' : ''}" data-idx="${i}">
                                            ${escHtml(t.label)}
                                        </div>`).join('')}
                                </div>
                            </div>` : ''}

                        ${colores.length > 0 ? `
                            <div class="fc-pdv-form-group">
                                <label class="fc-pdv-label-sm">Color:
                                    <strong id="pdv-color-label">${escHtml(selColor?.nombre || '')}</strong>
                                </label>
                                <div class="fc-pdv-colores">
                                    ${colores.map((c, ci) => `
                                        <div class="fc-pdv-color-swatch${selColor?.nombre === c.nombre ? ' active' : ''}"
                                             data-cidx="${ci}" title="${escHtml(c.nombre)}"
                                             style="background:${escHtml(c.hex || '#c8185a')}"></div>`).join('')}
                                </div>
                            </div>` : ''}

                        <div class="fc-pdv-form-group">
                            <label class="fc-pdv-label-sm">Modificaciones</label>
                            <textarea id="pdv-item-notas" rows="3"
                                      placeholder="Sin cenizo, con papel morado, listón rosita… (opcional)"
                                      style="resize:vertical">${escHtml(editNotas)}</textarea>
                        </div>
                        <div class="fc-pdv-form-group">
                            <label class="fc-pdv-label-sm">Precio <span style="color:#ef4444">*</span></label>
                            <input type="number" id="pdv-item-precio" min="0" step="0.01"
                                   placeholder="0.00" value="${precio || ''}" />
                        </div>

                        <button class="fc-pdv-btn-agregar" id="pdv-item-confirm">
                            ${editItem ? '✓ Actualizar en ticket' : '+ Agregar al ticket'}
                        </button>
                    </div>

                  </div>
                </div>`;

            bindPanelEvents();
        }

        function bindPanelEvents() {
            // Volver al grid
            $('.fc-pdv-detail-back', container)?.addEventListener('click', renderCatalog);

            // Lightbox al hacer click en la foto o en el botón ⛶
            const imgWrap = $('#pdv-img-wrap', container);
            if (imgWrap) {
                const openLightbox = () => {
                    const img = $('#pdv-detail-img', container);
                    if (!img) return;
                    const lb = document.createElement('div');
                    lb.className = 'fc-pdv-lightbox';
                    lb.innerHTML = `
                        <button class="fc-pdv-lightbox-close">×</button>
                        <img src="${escHtml(img.src)}" alt="${escHtml(arreglo.nombre)}" />`;
                    document.body.appendChild(lb);
                    const close = () => lb.remove();
                    lb.addEventListener('click', e => { if (!e.target.closest('img')) close(); });
                };
                imgWrap.addEventListener('click', openLightbox);
            }

            // Chips de tamaño
            $$('.fc-pdv-tamano-chip', container).forEach(chip => {
                chip.addEventListener('click', () => {
                    const prevColor = selColor?.nombre;
                    selTamIdx = parseInt(chip.dataset.idx);
                    selTamano = tamanos[selTamIdx];
                    selColor  = selTamano?.colores?.find(c => c.nombre === prevColor)
                             || selTamano?.colores?.[0] || null;

                    // Actualizar chip activo
                    $$('.fc-pdv-tamano-chip', container).forEach(c => c.classList.remove('active'));
                    chip.classList.add('active');

                    // Actualizar precio
                    const pd = $('#pdv-precio-display', container);
                    const pi = $('#pdv-item-precio', container);
                    if (pd) pd.textContent = fmt(selTamano?.precio || 0);
                    if (pi && selTamano?.precio) pi.value = selTamano.precio;

                    // Actualizar foto
                    const img = $('#pdv-detail-img', container);
                    if (img) { const url = getMainPhoto(); if (url) img.src = url; }

                    // Actualizar colores
                    const coloresWrap = $('.fc-pdv-colores', container);
                    if (coloresWrap) {
                        coloresWrap.innerHTML = (selTamano?.colores || []).map((c, ci) => `
                            <div class="fc-pdv-color-swatch${selColor?.nombre === c.nombre ? ' active' : ''}"
                                 data-cidx="${ci}" title="${escHtml(c.nombre)}"
                                 style="background:${escHtml(c.hex || '#c8185a')}"></div>`).join('');
                        bindColorSwatches();
                    }
                    const labelEl = $('#pdv-color-label', container);
                    if (labelEl) labelEl.textContent = selColor?.nombre || '';
                });
            });

            bindColorSwatches();

            // Bloquear scroll del mouse en el campo precio
            $('#pdv-item-precio', container)?.addEventListener('wheel', e => e.preventDefault(), { passive: false });

            // Confirmar
            $('#pdv-item-confirm', container)?.addEventListener('click', () => {
                const precioInput = $('#pdv-item-precio', container);
                const precio = parseFloat(precioInput?.value || 0);
                if (!precio || precio <= 0) {
                    if (precioInput) { precioInput.focus(); precioInput.style.borderColor = '#ef4444'; }
                    return;
                }
                const nombre = isPersonalizado
                    ? ($('#pdv-item-nombre', container)?.value || '').trim()
                    : arreglo.nombre;
                if (!nombre) { $('#pdv-item-nombre', container)?.focus(); return; }

                const notas       = ($('#pdv-item-notas', container)?.value || '').trim();
                const colorNombre = selColor?.nombre || '';
                const colorDisplay = colorNombre && notas
                    ? `${colorNombre} · ${notas}` : (colorNombre || notas);

                const item = {
                    arreglo_id:            arreglo.id || 0,
                    arreglo_nombre:        nombre,
                    imagen_url:            getMainPhoto(),
                    tamano:                selTamano?.label || '',
                    color:                 colorDisplay,
                    notas:                 notas,
                    precio,
                    destinatario:          editItem?.destinatario          || '',
                    destinatario_telefono: editItem?.destinatario_telefono  || '',
                    destinatario_telefono2:editItem?.destinatario_telefono2 || '',
                    mensaje_tarjeta:       editItem?.mensaje_tarjeta        || '',
                };

                if (cartIdx >= 0) {
                    cart[cartIdx] = item;
                    showToast('Ticket actualizado', 'success');
                } else {
                    cart.push(item);
                    showToast('Agregado al ticket', 'success');
                }

                renderTicket();
                renderCatalog(); // regresa al grid
            });
        }

        function bindColorSwatches() {
            $$('.fc-pdv-color-swatch', container).forEach(sw => {
                sw.addEventListener('click', () => {
                    const ci = parseInt(sw.dataset.cidx);
                    selColor = (selTamano?.colores || [])[ci] || null;
                    $$('.fc-pdv-color-swatch', container).forEach(s => s.classList.remove('active'));
                    sw.classList.add('active');
                    const labelEl = $('#pdv-color-label', container);
                    if (labelEl) labelEl.textContent = selColor?.nombre || '';
                    const img = $('#pdv-detail-img', container);
                    if (img) { const url = getMainPhoto(); if (url) img.src = url; }
                });
            });
        }

        render();
    }

    // ── CHECKOUT MODAL ──
    function showCheckoutModal() {
        if (!cart.length) return;

        const backdrop = document.createElement('div');
        backdrop.className = 'fc-pdv-modal-backdrop';

        const req = tipoPedido === 'envio';
        const itemsCheckoutHtml = cart.map((it, i) => `
            <div class="fc-pdv-checkout-item">
                <div class="fc-pdv-checkout-item-title">${escHtml(it.arreglo_nombre)}${it.tamano ? ' — ' + escHtml(it.tamano) : ''} · ${fmt(it.precio)}</div>
                <div class="fc-pdv-form-group">
                    <label>Nombre del destinatario <span class="pdv-envio-req" style="color:#ef4444;${req ? '' : 'display:none'}">*</span></label>
                    <input type="text" class="pdv-co-dest" data-idx="${i}"
                           placeholder="${req ? 'Nombre de quien recibe' : 'Nombre de quien recibe (opcional)'}"
                           value="${escHtml(it.destinatario || '')}" />
                </div>
                <div class="fc-pdv-form-group">
                    <label>Teléfono del destinatario <span class="pdv-envio-req" style="color:#ef4444;${req ? '' : 'display:none'}">*</span></label>
                    <input type="tel" class="pdv-co-dest-tel" data-idx="${i}"
                           inputmode="numeric" placeholder="10 dígitos"
                           value="${escHtml(it.destinatario_telefono || '')}" />
                </div>
                <div class="fc-pdv-form-group">
                    <label>Teléfono 2 <small style="color:var(--pdv-muted)">(opcional)</small></label>
                    <input type="tel" class="pdv-co-dest-tel2" data-idx="${i}"
                           inputmode="numeric" placeholder="Número alternativo"
                           value="${escHtml(it.destinatario_telefono2 || '')}" />
                </div>
                <div class="fc-pdv-form-group">
                    <label>Mensaje de tarjeta</label>
                    <textarea class="pdv-co-tarjeta" data-idx="${i}" rows="2"
                              placeholder="Mensaje para incluir en la tarjeta...">${escHtml(it.mensaje_tarjeta || '')}</textarea>
                </div>
            </div>`).join('');

        backdrop.innerHTML = `
            <div class="fc-pdv-modal">
                <div class="fc-pdv-modal-header">
                    <h3>Cobrar — ${fmt(cartTotal())}</h3>
                    <button class="fc-pdv-modal-close">×</button>
                </div>
                <div class="fc-pdv-modal-body">
                    <div class="fc-pdv-section-title">Tipo de entrega</div>
                    <div class="fc-pdv-tipo-btns">
                        <button class="fc-pdv-tipo-btn ${tipoPedido === 'recoleccion' ? 'active' : ''}" data-tipo="recoleccion">🏪 Recolección</button>
                        <button class="fc-pdv-tipo-btn ${tipoPedido === 'envio' ? 'active' : ''}" data-tipo="envio">🚗 Envío a domicilio</button>
                    </div>

                    <div class="fc-pdv-form-group">
                        <label>Fecha de entrega <span style="color:#ef4444">*</span></label>
                        <input type="date" id="pdv-co-fecha" value="${today}" min="${today}" />
                        <div id="pdv-co-fecha-warn" class="fc-pdv-fecha-warn" style="display:none">⚠️ Esta fecha está cerrada — no se aceptan pedidos.</div>
                    </div>

                    <div id="pdv-co-horario-wrap" class="fc-pdv-form-group" style="${tipoPedido === 'recoleccion' ? '' : 'display:none'}">
                        <label>Hora de recolección</label>
                        <input type="time" id="pdv-co-hora-recoleccion" />
                    </div>
                    <div id="pdv-co-envio-wrap" style="${tipoPedido === 'envio' ? '' : 'display:none'}">
                        <div class="fc-pdv-form-group">
                            <label>Horario de entrega</label>
                            <select id="pdv-co-horario">
                                <option value="">— Selecciona fecha primero —</option>
                            </select>
                        </div>
                        <div class="fc-pdv-form-group">
                            <label>Dirección de entrega <span style="color:#ef4444">*</span></label>
                            <input type="text" id="pdv-co-direccion" placeholder="Busca la dirección…" autocomplete="off" />
                        </div>
                        <div class="fc-pdv-form-group">
                            <label>Costo de envío <small style="color:var(--pdv-muted)">(opcional)</small></label>
                            <input type="number" id="pdv-co-costo-envio" min="0" step="0.01" placeholder="0.00" />
                        </div>
                    </div>

                    <div class="fc-pdv-form-group">
                        <label>Referencias de entrega <small style="color:var(--pdv-muted)">(opcional)</small></label>
                        <textarea id="pdv-co-referencias" rows="2"
                                  placeholder="Casa azul con portón negro, frente al OXXO…"
                                  style="resize:vertical"></textarea>
                    </div>

                    <div class="fc-pdv-section-title">Detalles por arreglo</div>
                    ${itemsCheckoutHtml}

                    <div class="fc-pdv-section-title">Forma de pago</div>
                    <div class="fc-pdv-pago-btns">
                        <button class="fc-pdv-pago-btn efectivo ${formaPago === 'efectivo' ? 'active' : ''}" data-pago="efectivo">💵 Efectivo</button>
                        <button class="fc-pdv-pago-btn tarjeta ${formaPago === 'tarjeta' ? 'active' : ''}" data-pago="tarjeta">💳 Tarjeta</button>
                        <button class="fc-pdv-pago-btn otro ${formaPago === 'otro' ? 'active' : ''}" data-pago="otro">🔄 Otro</button>
                    </div>
                    <div id="pdv-co-efectivo-wrap" style="${formaPago === 'efectivo' ? '' : 'display:none'}">
                        <div class="fc-pdv-form-group">
                            <label>Monto recibido</label>
                            <input type="number" id="pdv-co-monto-recibido" min="0" step="0.01" placeholder="${cartTotal().toFixed(2)}" />
                        </div>
                        <div class="fc-pdv-cambio-row" id="pdv-co-cambio-row" style="display:none">
                            <span>Cambio</span>
                            <span id="pdv-co-cambio-val">$0.00</span>
                        </div>
                    </div>
                </div>
                <div class="fc-pdv-modal-footer">
                    <button class="fc-pdv-modal-cancel">Cancelar</button>
                    <button class="fc-pdv-btn-primary" id="pdv-co-confirm">Confirmar venta</button>
                </div>
            </div>`;

        document.body.appendChild(backdrop);

        // Populate horarios on open (fecha defaults to today)
        updateHorariosModal(backdrop);

        // Fecha change → rebuild slots (input cubre teclado; change cubre el picker)
        const fechaInputEl = $('#pdv-co-fecha', backdrop);
        fechaInputEl?.addEventListener('change', () => updateHorariosModal(backdrop));
        fechaInputEl?.addEventListener('input',  () => updateHorariosModal(backdrop));

        // Tipo toggle
        $$('.fc-pdv-tipo-btn', backdrop).forEach(btn => {
            btn.addEventListener('click', () => {
                $$('.fc-pdv-tipo-btn', backdrop).forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                tipoPedido = btn.dataset.tipo;
                const isEnvio = tipoPedido === 'envio';
                $('#pdv-co-horario-wrap', backdrop).style.display = isEnvio ? 'none' : '';
                $('#pdv-co-envio-wrap',   backdrop).style.display = isEnvio ? '' : 'none';
                // Mostrar/ocultar marcadores de obligatorio
                $$('.pdv-envio-req', backdrop).forEach(el => el.style.display = isEnvio ? '' : 'none');
                // Actualizar placeholders
                $$('.pdv-co-dest', backdrop).forEach(inp => {
                    inp.placeholder = isEnvio ? 'Nombre de quien recibe' : 'Nombre de quien recibe (opcional)';
                });
                $$('.pdv-co-dest-tel', backdrop).forEach(inp => { inp.style.borderColor = ''; });
                // Rebuild horarios for new tipo
                updateHorariosModal(backdrop);
            });
        });

        // Pago toggle
        $$('.fc-pdv-pago-btn', backdrop).forEach(btn => {
            btn.addEventListener('click', () => {
                $$('.fc-pdv-pago-btn', backdrop).forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                formaPago = btn.dataset.pago;
                $('#pdv-co-efectivo-wrap', backdrop).style.display = formaPago === 'efectivo' ? '' : 'none';
            });
        });

        // Teléfonos: solo números
        $$('.pdv-co-dest-tel, .pdv-co-dest-tel2', backdrop).forEach(inp => {
            inp.addEventListener('input', () => {
                inp.value = inp.value.replace(/\D/g, '');
                inp.style.borderColor = '';
            });
        });
        $$('.pdv-co-dest', backdrop).forEach(inp => {
            inp.addEventListener('input', () => { inp.style.borderColor = ''; });
        });

        // Google Places — PlaceAutocompleteElement (nuevo API, igual que detalle.js)
        if (
            window.google && window.google.maps && window.google.maps.places &&
            typeof window.google.maps.places.PlaceAutocompleteElement !== 'undefined'
        ) {
            const dirInput = $('#pdv-co-direccion', backdrop);
            if (dirInput) {
                try {
                    const pac = new window.google.maps.places.PlaceAutocompleteElement({
                        componentRestrictions: { country: 'mx' },
                    });
                    // Estilos para que tome el espacio del input original
                    pac.style.display = 'block';
                    dirInput.parentNode.insertBefore(pac, dirInput);
                    dirInput.style.display = 'none';

                    pac.addEventListener('gmp-select', e => {
                        const pred = e.placePrediction;
                        if (!pred) return;
                        const place = pred.toPlace();
                        place.fetchFields({ fields: ['displayName', 'formattedAddress'] }).then(() => {
                            const name = place.displayName || '';
                            const addr = place.formattedAddress || '';
                            dirInput.value = (name && !addr.startsWith(name)) ? name + ', ' + addr : addr;
                            dirInput.style.borderColor = '';
                        });
                    });

                    // Sincronizar texto mientras escribe (dentro del shadow DOM)
                    const syncShadow = () => {
                        const si = pac.shadowRoot && pac.shadowRoot.querySelector('input');
                        if (si) {
                            si.addEventListener('input', () => { dirInput.value = si.value; });
                        } else {
                            setTimeout(syncShadow, 150);
                        }
                    };
                    syncShadow();
                } catch (e) {
                    $('#pdv-co-direccion', backdrop).style.display = '';
                }
            }
        }

        // Cambio calculator
        const montoInput = $('#pdv-co-monto-recibido', backdrop);
        if (montoInput) {
            montoInput.addEventListener('wheel', e => e.preventDefault(), { passive: false });
            montoInput.addEventListener('input', () => {
                const recibido   = parseFloat(montoInput.value || 0);
                const costoEnvio = parseFloat($('#pdv-co-costo-envio', backdrop)?.value || 0);
                const cambio     = recibido - (cartTotal() + costoEnvio);
                const row        = $('#pdv-co-cambio-row', backdrop);
                const val        = $('#pdv-co-cambio-val', backdrop);
                if (recibido > 0 && row && val) {
                    row.style.display = '';
                    val.textContent   = fmt(Math.max(0, cambio));
                }
            });
        }

        // Costo de envío — bloquear scroll y actualizar total en header
        const costoEnvioInput = $('#pdv-co-costo-envio', backdrop);
        if (costoEnvioInput) {
            costoEnvioInput.addEventListener('wheel', e => e.preventDefault(), { passive: false });
            costoEnvioInput.addEventListener('input', () => {
                const costo  = parseFloat(costoEnvioInput.value || 0);
                const total  = cartTotal() + costo;
                // Actualizar título del modal
                const h3 = $('.fc-pdv-modal-header h3', backdrop);
                if (h3) h3.textContent = `Cobrar — ${fmt(total)}`;
                // Actualizar placeholder de monto recibido
                const mr = $('#pdv-co-monto-recibido', backdrop);
                if (mr) mr.placeholder = total.toFixed(2);
                // Recalcular cambio si ya hay monto ingresado
                const recibido = parseFloat(mr?.value || 0);
                const row = $('#pdv-co-cambio-row', backdrop);
                const val = $('#pdv-co-cambio-val', backdrop);
                if (recibido > 0 && row && val) {
                    row.style.display = '';
                    val.textContent   = fmt(Math.max(0, recibido - total));
                }
            });
        }

        // Close
        const close = () => backdrop.remove();
        $('.fc-pdv-modal-close', backdrop).addEventListener('click', close);
        $('.fc-pdv-modal-cancel', backdrop).addEventListener('click', close);
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });

        // Confirm
        const confirmBtn = $('#pdv-co-confirm', backdrop);
        confirmBtn.addEventListener('click', async () => {
            const fecha    = $('#pdv-co-fecha', backdrop)?.value || '';
            const isEnvio  = tipoPedido === 'envio';
            if (!fecha) { showToast('Selecciona la fecha de entrega', 'error'); return; }

            if (isEnvio) {
                // Dirección obligatoria
                const dirInput = $('#pdv-co-direccion', backdrop);
                if (!(dirInput?.value || '').trim()) {
                    showToast('Ingresa la dirección de entrega', 'error');
                    dirInput?.focus(); dirInput && (dirInput.style.borderColor = '#ef4444');
                    return;
                }
                // Destinatario + teléfono obligatorios por arreglo
                const emptyDest = $$('.pdv-co-dest', backdrop).find(inp => !inp.value.trim());
                if (emptyDest) {
                    showToast('Ingresa el nombre del destinatario de cada arreglo', 'error');
                    emptyDest.focus(); emptyDest.style.borderColor = '#ef4444';
                    return;
                }
                const emptyTel = $$('.pdv-co-dest-tel', backdrop).find(inp => !inp.value.trim());
                if (emptyTel) {
                    showToast('Ingresa el teléfono del destinatario', 'error');
                    emptyTel.focus(); emptyTel.style.borderColor = '#ef4444';
                    return;
                }
            }

            // Collect item details from modal
            const itemsFinal = cart.map((it, i) => ({
                ...it,
                destinatario:           ($('.pdv-co-dest[data-idx="'     + i + '"]', backdrop)?.value || '').trim(),
                destinatario_telefono:  ($('.pdv-co-dest-tel[data-idx="' + i + '"]', backdrop)?.value || '').replace(/\D/g, ''),
                destinatario_telefono2: ($('.pdv-co-dest-tel2[data-idx="'+ i + '"]', backdrop)?.value || '').replace(/\D/g, ''),
                mensaje_tarjeta:        ($('.pdv-co-tarjeta[data-idx="'  + i + '"]', backdrop)?.value || '').trim(),
            }));

            const costoEnvio = parseFloat($('#pdv-co-costo-envio', backdrop)?.value || 0);
            const montoTotal = cartTotal() + costoEnvio;
            const recibido   = parseFloat($('#pdv-co-monto-recibido', backdrop)?.value || 0);
            const cambio     = formaPago === 'efectivo' && recibido > 0 ? Math.max(0, recibido - montoTotal) : 0;

            confirmBtn.textContent = 'Procesando...';
            confirmBtn.disabled    = true;

            try {
                const data = await ajax('fc_pdv_crear_venta', {
                    tipo:            tipoPedido,
                    fecha,
                    horario:         $('#pdv-co-horario', backdrop)?.value          || '',
                    hora_recoleccion:$('#pdv-co-hora-recoleccion', backdrop)?.value || '',
                    direccion:       $('#pdv-co-direccion', backdrop)?.value         || '',
                    forma_pago:      formaPago,
                    costo_envio:     costoEnvio,
                    referencias:     ($('#pdv-co-referencias', backdrop)?.value || '').trim(),
                    monto_total:     montoTotal,
                    items_json:      JSON.stringify(itemsFinal),
                });

                if (data.success) {
                    const { numero, client_url } = data.data;
                    cart = [];
                    renderTicket();
                    loadCajas();
                    showVentaOk(backdrop, numero, client_url, cambio);
                } else {
                    showToast(data.data?.message || 'Error al procesar la venta', 'error');
                    confirmBtn.textContent = 'Confirmar venta';
                    confirmBtn.disabled    = false;
                }
            } catch {
                showToast('Error de conexión', 'error');
                confirmBtn.textContent = 'Confirmar venta';
                confirmBtn.disabled    = false;
            }
        });
    }

    function showVentaOk(backdrop, numero, clientUrl, cambio) {
        const body = $('.fc-pdv-modal-body', backdrop);
        const footer = $('.fc-pdv-modal-footer', backdrop);
        const header = $('.fc-pdv-modal-header h3', backdrop);
        if (header) header.textContent = 'Venta registrada';
        if (footer) footer.innerHTML = `<button class="fc-pdv-btn-primary" id="pdv-ok-cerrar">Cerrar</button>`;
        if (body) {
            body.innerHTML = `
                <div class="fc-pdv-venta-ok">
                    <div class="fc-pdv-venta-ok-icon">✅</div>
                    <h3>¡Venta registrada!</h3>
                    <div class="fc-pdv-numero">${escHtml(numero)}</div>
                    ${cambio > 0 ? `<div class="fc-pdv-cambio-ok">Cambio: ${fmt(cambio)}</div>` : ''}
                    <p>Link de rastreo para el cliente:</p>
                    <div class="fc-pdv-link-box" id="pdv-ok-link" title="Clic para copiar">${escHtml(clientUrl)}</div>
                    <p style="font-size:12px;color:#94a3b8;">Clic en el link para copiar</p>
                </div>`;
        }
        $('#pdv-ok-link', backdrop)?.addEventListener('click', () => {
            navigator.clipboard?.writeText(clientUrl).then(() => showToast('Link copiado', 'success'));
        });
        $('#pdv-ok-cerrar', backdrop)?.addEventListener('click', () => backdrop.remove());
    }

    // ── CAJA ──
    async function loadCajas() {
        const data = await ajax('fc_pdv_get_cajas');
        if (!data.success) return;
        cajasData  = data.data;
        cajaActiva = cajasData.abiertas[0] || null;
        updateCajaBadge();
        renderCajas();
    }

    function updateCajaBadge() {
        const badge = $('#fc-pdv-caja-badge');
        if (!badge) return;
        if (cajaActiva) {
            badge.className   = 'fc-pdv-caja-badge abierta';
            badge.textContent = 'Caja: ' + fmt(cajaActiva.saldo_actual);
        } else {
            badge.className   = 'fc-pdv-caja-badge cerrada';
            badge.textContent = 'Sin caja abierta';
        }
    }

    function renderCajas() {
        const wrap = $('#fc-pdv-caja-wrap');
        if (!wrap) return;

        let html = '';

        // Cajas abiertas (puede haber más de una)
        if (!cajasData.abiertas.length) {
            html += `
                <div class="fc-pdv-no-caja">
                    <p>No hay caja abierta.</p>
                    <button class="fc-pdv-btn-sm success" id="fc-pdv-btn-abrir-caja">+ Abrir caja</button>
                </div>`;
        } else {
            cajasData.abiertas.forEach(caja => {
                html += renderCajaCard(caja, true);
            });
            html += `<button class="fc-pdv-btn-sm outline" id="fc-pdv-btn-abrir-caja" style="align-self:flex-start">+ Abrir otra caja</button>`;
        }

        // Historial cajas cerradas
        if (cajasData.cerradas.length) {
            html += `<div class="fc-pdv-cajas-anteriores-title">Cajas anteriores</div>`;
            cajasData.cerradas.forEach(c => { html += renderCajaCard(c, false); });
        }

        wrap.innerHTML = html;

        $('#fc-pdv-btn-abrir-caja', wrap)?.addEventListener('click', () => showAbrirCajaModal());

        // Botones por caja abierta
        cajasData.abiertas.forEach(caja => {
            $(`#fc-pdv-btn-entrada-${caja.id}`, wrap)?.addEventListener('click', () => showMovimientoModal(caja.id, 'entrada'));
            $(`#fc-pdv-btn-salida-${caja.id}`,  wrap)?.addEventListener('click', () => showMovimientoModal(caja.id, 'salida'));
            $(`#fc-pdv-btn-cerrar-${caja.id}`,  wrap)?.addEventListener('click', () => showCerrarCajaModal(caja));
        });

        // Toggle expandir cajas cerradas
        $$('.fc-pdv-caja-toggle', wrap).forEach(btn => {
            btn.addEventListener('click', () => {
                const body = btn.closest('.fc-pdv-caja-card').querySelector('.fc-pdv-caja-expandable');
                if (!body) return;
                const open = body.style.display !== 'none';
                body.style.display = open ? 'none' : 'block';
                btn.textContent = open ? '▼ Ver detalles' : '▲ Ocultar';
            });
        });
    }

    function renderMovimientos(movs) {
        if (!movs || !movs.length) return '<p style="font-size:13px;color:#94a3b8;margin:6px 0;">Sin movimientos manuales.</p>';
        return `<div class="fc-pdv-movs-list">
            ${movs.map(m => `
                <div class="fc-pdv-mov-row ${m.tipo}">
                    <span class="fc-pdv-mov-tipo">${m.tipo === 'entrada' ? '▲ Entrada' : '▼ Salida'}</span>
                    <span class="fc-pdv-mov-desc">${escHtml(m.descripcion)}</span>
                    <span class="fc-pdv-mov-monto">${fmt(m.monto)}</span>
                    <span class="fc-pdv-mov-ts">${fmtDatetime(m.timestamp)}</span>
                </div>`).join('')}
        </div>`;
    }

    function renderCajaCard(caja, isAbierta) {
        const movHtml = renderMovimientos(caja.movimientos || []);
        const expandible = !isAbierta;
        return `
            <div class="fc-pdv-caja-card ${isAbierta ? 'abierta' : 'cerrada'}">
                <h4>${isAbierta ? '🟢 Caja abierta' : '🔴 Caja cerrada'} · ${fmtDatetime(caja.fecha_apertura)}</h4>
                <div class="fc-pdv-caja-saldo">${fmt(isAbierta ? caja.saldo_actual : caja.saldo_final)}</div>
                <div class="fc-pdv-caja-desglose">
                    Inicial: <strong>${fmt(caja.saldo_inicial)}</strong> &nbsp;
                    Ventas: <strong>${fmt(caja.total_ventas)}</strong> (${caja.count_ventas})<br>
                    Efectivo: <strong>${fmt(caja.total_efectivo)}</strong> &nbsp;
                    Tarjeta: <strong>${fmt(caja.total_tarjeta)}</strong> &nbsp;
                    Otro: <strong>${fmt(caja.total_otro)}</strong><br>
                    Entradas: <strong>${fmt(caja.total_entradas)}</strong> &nbsp;
                    Salidas: <strong>${fmt(caja.total_salidas)}</strong>
                </div>
                ${isAbierta ? `
                <div class="fc-pdv-caja-actions">
                    <button class="fc-pdv-btn-sm success" id="fc-pdv-btn-entrada-${caja.id}">+ Entrada</button>
                    <button class="fc-pdv-btn-sm danger"  id="fc-pdv-btn-salida-${caja.id}">− Salida</button>
                    <button class="fc-pdv-btn-sm outline" id="fc-pdv-btn-cerrar-${caja.id}">Cerrar caja</button>
                </div>` : ''}
                ${expandible
                    ? `<button class="fc-pdv-caja-toggle fc-pdv-btn-sm outline" style="margin-top:8px;align-self:flex-start">▼ Ver detalles</button>
                       <div class="fc-pdv-caja-expandable" style="display:none">${movHtml}</div>`
                    : `<div class="fc-pdv-caja-expandable">${movHtml}</div>`}
            </div>`;
    }

    function showAbrirCajaModal() {
        const backdrop = document.createElement('div');
        backdrop.className = 'fc-pdv-modal-backdrop';
        backdrop.innerHTML = `
            <div class="fc-pdv-modal">
                <div class="fc-pdv-modal-header">
                    <h3>Abrir caja</h3>
                    <button class="fc-pdv-modal-close">×</button>
                </div>
                <div class="fc-pdv-modal-body">
                    <div class="fc-pdv-form-group">
                        <label>Saldo inicial en caja ($)</label>
                        <input type="number" id="pdv-caja-saldo" min="0" step="0.01" placeholder="0.00" value="0" />
                    </div>
                </div>
                <div class="fc-pdv-modal-footer">
                    <button class="fc-pdv-modal-cancel">Cancelar</button>
                    <button class="fc-pdv-btn-primary" id="pdv-caja-confirm">Abrir caja</button>
                </div>
            </div>`;
        document.body.appendChild(backdrop);

        const close = () => backdrop.remove();
        $('.fc-pdv-modal-close',  backdrop).addEventListener('click', close);
        $('.fc-pdv-modal-cancel', backdrop).addEventListener('click', close);
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });

        const confirmBtn = $('#pdv-caja-confirm', backdrop);
        confirmBtn.addEventListener('click', async () => {
            const saldo = parseFloat($('#pdv-caja-saldo', backdrop).value || 0);
            confirmBtn.disabled = true;
            const data = await ajax('fc_pdv_abrir_caja', { saldo_inicial: saldo });
            if (data.success) {
                close();
                await loadCajas();
                showToast('Caja abierta', 'success');
            } else {
                showToast(data.data?.message || 'Error', 'error');
                confirmBtn.disabled = false;
            }
        });

        setTimeout(() => $('#pdv-caja-saldo', backdrop)?.focus(), 60);
    }

    function showMovimientoModal(cajaId, tipo) {
        const esEntrada = tipo === 'entrada';
        const backdrop  = document.createElement('div');
        backdrop.className = 'fc-pdv-modal-backdrop';
        backdrop.innerHTML = `
            <div class="fc-pdv-modal">
                <div class="fc-pdv-modal-header">
                    <h3>${esEntrada ? '+ Entrada de efectivo' : '− Salida de efectivo'}</h3>
                    <button class="fc-pdv-modal-close">×</button>
                </div>
                <div class="fc-pdv-modal-body">
                    <div class="fc-pdv-form-group">
                        <label>Monto ($) <span style="color:#ef4444">*</span></label>
                        <input type="number" id="pdv-mov-monto" min="0.01" step="0.01" placeholder="0.00" />
                    </div>
                    <div class="fc-pdv-form-group">
                        <label>Descripción <span style="color:#ef4444">*</span></label>
                        <input type="text" id="pdv-mov-desc" placeholder="${esEntrada ? 'Ej: Fondo extra' : 'Ej: Pago de proveedor'}" />
                    </div>
                </div>
                <div class="fc-pdv-modal-footer">
                    <button class="fc-pdv-modal-cancel">Cancelar</button>
                    <button class="fc-pdv-btn-primary" id="pdv-mov-confirm"
                        style="background:${esEntrada ? 'var(--pdv-accent)' : 'var(--pdv-danger)'}">
                        Registrar ${esEntrada ? 'entrada' : 'salida'}
                    </button>
                </div>
            </div>`;
        document.body.appendChild(backdrop);

        const close = () => backdrop.remove();
        $('.fc-pdv-modal-close',  backdrop).addEventListener('click', close);
        $('.fc-pdv-modal-cancel', backdrop).addEventListener('click', close);
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });

        const confirmBtn = $('#pdv-mov-confirm', backdrop);
        confirmBtn.addEventListener('click', async () => {
            const monto = parseFloat($('#pdv-mov-monto', backdrop).value || 0);
            const desc  = ($('#pdv-mov-desc', backdrop).value || '').trim();
            if (monto <= 0 || !desc) {
                showToast('Completa los campos requeridos', 'error');
                return;
            }
            confirmBtn.disabled = true;
            const data = await ajax('fc_pdv_movimiento_caja', { caja_id: cajaId, tipo, monto, descripcion: desc });
            if (data.success) {
                close();
                await loadCajas();
                showToast(esEntrada ? 'Entrada registrada' : 'Salida registrada', 'success');
            } else {
                showToast(data.data?.message || 'Error', 'error');
                confirmBtn.disabled = false;
            }
        });

        setTimeout(() => $('#pdv-mov-monto', backdrop)?.focus(), 60);
    }

    function showCerrarCajaModal(caja) {
        const backdrop = document.createElement('div');
        backdrop.className = 'fc-pdv-modal-backdrop';
        backdrop.innerHTML = `
            <div class="fc-pdv-modal">
                <div class="fc-pdv-modal-header">
                    <h3>Cerrar caja</h3>
                    <button class="fc-pdv-modal-close">×</button>
                </div>
                <div class="fc-pdv-modal-body">
                    <p style="font-size:14px;color:#64748b;margin:0 0 16px;">
                        Saldo calculado: <strong>${fmt(caja.saldo_actual)}</strong>
                    </p>
                    <div class="fc-pdv-form-group">
                        <label>Saldo real al cerrar ($)</label>
                        <input type="number" id="pdv-cierre-saldo" min="0" step="0.01"
                            placeholder="${caja.saldo_actual.toFixed(2)}" value="${caja.saldo_actual.toFixed(2)}" />
                    </div>
                </div>
                <div class="fc-pdv-modal-footer">
                    <button class="fc-pdv-modal-cancel">Cancelar</button>
                    <button class="fc-pdv-btn-primary" id="pdv-cierre-confirm" style="background:var(--pdv-danger)">Cerrar caja</button>
                </div>
            </div>`;
        document.body.appendChild(backdrop);

        const close = () => backdrop.remove();
        $('.fc-pdv-modal-close',  backdrop).addEventListener('click', close);
        $('.fc-pdv-modal-cancel', backdrop).addEventListener('click', close);
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });

        const confirmBtn = $('#pdv-cierre-confirm', backdrop);
        confirmBtn.addEventListener('click', async () => {
            const saldoFinal = parseFloat($('#pdv-cierre-saldo', backdrop).value || 0);
            confirmBtn.disabled = true;
            const data = await ajax('fc_pdv_cerrar_caja', { caja_id: caja.id, saldo_final: saldoFinal });
            if (data.success) {
                close();
                await loadCajas();
                showToast('Caja cerrada', 'success');
            } else {
                showToast(data.data?.message || 'Error', 'error');
                confirmBtn.disabled = false;
            }
        });
    }

    // ── TRANSACCIONES ──
    const pagoLabel = { efectivo: '💵 Efectivo', tarjeta: '💳 Tarjeta', otro: '🔄 Otro' };
    const statusLabel = { aceptado: 'Aceptado', en_proceso: 'En proceso', listo: 'Listo', entregado: 'Entregado', cancelado: 'Cancelado' };
    const statusColor = { aceptado: '#3b82f6', en_proceso: '#f59e0b', listo: '#10b981', entregado: '#64748b', cancelado: '#ef4444' };

    async function loadTransacciones() {
        const desde    = $('#fc-pdv-tx-desde')?.value || '';
        const hasta    = $('#fc-pdv-tx-hasta')?.value || '';
        const buscarBtn = $('#fc-pdv-tx-buscar');
        const wrap      = $('#fc-pdv-transacciones-result');
        const resumenEl = $('#fc-pdv-tx-resumen');

        // Estado de carga
        if (buscarBtn) { buscarBtn.disabled = true; buscarBtn.textContent = 'Buscando…'; }
        if (wrap) wrap.innerHTML = '<p style="color:#94a3b8;font-size:13px;padding:20px;">Cargando…</p>';

        let data;
        try {
            data = await ajax('fc_pdv_get_transacciones', { desde, hasta });
        } catch {
            showToast('Error de conexión', 'error');
            if (buscarBtn) { buscarBtn.disabled = false; buscarBtn.textContent = 'Buscar'; }
            return;
        } finally {
            if (buscarBtn) { buscarBtn.disabled = false; buscarBtn.textContent = 'Buscar'; }
        }

        if (!data.success) {
            showToast(data.data?.message || 'Error al cargar transacciones', 'error');
            if (wrap) wrap.innerHTML = '';
            return;
        }

        if (!wrap) return;
        const { dias } = data.data;

        // ── Resumen del período ──
        if (resumenEl) {
            if (!dias.length) {
                resumenEl.style.display = 'none';
            } else {
                let totalCount = 0, totalMonto = 0, totalEnvio = 0, totalRecol = 0;
                dias.forEach(d => d.transacciones.forEach(tx => {
                    totalCount++;
                    totalMonto += tx.monto || 0;
                    if (tx.tipo === 'envio') totalEnvio++; else totalRecol++;
                }));
                resumenEl.style.display = '';
                resumenEl.innerHTML = `
                    <div class="fc-pdv-tx-res-chip">
                        <span>Ventas</span>${totalCount}
                    </div>
                    <div class="fc-pdv-tx-res-chip total">
                        <span>Total</span>${fmt(totalMonto)}
                    </div>
                    <div class="fc-pdv-tx-res-chip envios">
                        <span>🚗 Envíos</span>${totalEnvio}
                    </div>
                    <div class="fc-pdv-tx-res-chip recol">
                        <span>🏪 Recolección</span>${totalRecol}
                    </div>`;
            }
        }

        if (!dias.length) {
            wrap.innerHTML = '<p style="color:#94a3b8;font-size:14px;padding:20px 0;">Sin ventas en el período seleccionado.</p>';
            return;
        }

        wrap.innerHTML = dias.map(grupo => `
            <div class="fc-pdv-tx-grupo">
                <div class="fc-pdv-tx-fecha-header">${fmtDate(grupo.fecha)}</div>
                <div class="fc-pdv-tx-cards">
                    ${grupo.transacciones.map(tx => renderTxCard(tx)).join('')}
                </div>
            </div>`).join('');
    }

    function renderTxCard(tx) {
        const itemsHtml = (tx.items || []).map(it => {
            const tel  = it.destinatario_telefono  ? `<a href="tel:${escHtml(it.destinatario_telefono)}"  class="fc-pdv-tx-link">📞 ${escHtml(it.destinatario_telefono)}</a>`  : '';
            const tel2 = it.destinatario_telefono2 ? `<a href="tel:${escHtml(it.destinatario_telefono2)}" class="fc-pdv-tx-link">📞 ${escHtml(it.destinatario_telefono2)}</a>` : '';
            return `
                <div class="fc-pdv-tx-item">
                    <div class="fc-pdv-tx-item-nombre">${escHtml(it.arreglo_nombre)}${it.tamano ? ' <span style="font-weight:400;color:var(--pdv-muted)">· ' + escHtml(it.tamano) + '</span>' : ''}</div>
                    ${it.color ? `<div class="fc-pdv-tx-item-sub">Color: ${escHtml(it.color)}</div>` : ''}
                    ${it.destinatario ? `<div class="fc-pdv-tx-item-sub">Para: <strong>${escHtml(it.destinatario)}</strong></div>` : ''}
                    ${tel ? `<div class="fc-pdv-tx-item-sub">${tel}${tel2 ? ' · ' + tel2 : ''}</div>` : ''}
                    ${it.mensaje_tarjeta ? `<div class="fc-pdv-tx-item-sub" style="font-style:italic">"${escHtml(it.mensaje_tarjeta)}"</div>` : ''}
                </div>`;
        }).join('');

        const dirLink = tx.direccion
            ? `<a href="https://maps.google.com/?q=${encodeURIComponent(tx.direccion)}" target="_blank" rel="noopener" class="fc-pdv-tx-link">📍 ${escHtml(tx.direccion)}</a>`
            : '';
        const tipoStr = tx.tipo === 'envio' ? '🚗 Envío' : '🏪 Recolección';
        const st      = tx.status || 'aceptado';

        return `
            <div class="fc-pdv-tx-card">
                <div class="fc-pdv-tx-card-header">
                    <span class="fc-pdv-tx-numero">${escHtml(tx.numero)}</span>
                    <span class="fc-pdv-tx-status" style="background:${statusColor[st] || '#64748b'}">${statusLabel[st] || st}</span>
                    <span class="fc-pdv-tx-hora">${fmtDatetime(tx.fecha_venta).split(' ')[1] || ''}</span>
                </div>
                <div class="fc-pdv-tx-meta">
                    ${tipoStr} · Entrega: ${fmtDate(tx.fecha_entrega)}
                    ${tx.tipo === 'envio'
                        ? (tx.horario          ? ' · ' + escHtml(tx.horario)          : '')
                        : (tx.hora_recoleccion ? ' · ' + escHtml(tx.hora_recoleccion) : '')}
                </div>
                ${dirLink ? `<div class="fc-pdv-tx-meta">${dirLink}</div>` : ''}
                <div class="fc-pdv-tx-items">${itemsHtml}</div>
                <div class="fc-pdv-tx-footer">
                    <span>${pagoLabel[tx.forma_pago] || tx.forma_pago}</span>
                    <span class="fc-pdv-tx-monto">${fmt(tx.monto)}</span>
                </div>
            </div>`;
    }

    // ── INFORMES ──
    async function loadInformes() {
        const desde = $('#fc-pdv-inf-desde')?.value || '';
        const hasta = $('#fc-pdv-inf-hasta')?.value || '';
        const data  = await ajax('fc_pdv_get_informes', { desde, hasta });
        if (!data.success) return;

        const { dias, totales } = data.data;
        const wrap = $('#fc-pdv-informes-result');
        if (!wrap) return;

        wrap.innerHTML = `
            <div class="fc-pdv-informe-totales">
                <div class="fc-pdv-total-chip total"><span>Total ventas</span>${fmt(totales.total)} <small style="font-size:11px;color:#94a3b8">(${totales.count})</small></div>
                <div class="fc-pdv-total-chip efectivo"><span>Efectivo</span>${fmt(totales.efectivo)}</div>
                <div class="fc-pdv-total-chip tarjeta"><span>Tarjeta</span>${fmt(totales.tarjeta)}</div>
                <div class="fc-pdv-total-chip otro"><span>Otro</span>${fmt(totales.otro)}</div>
            </div>
            ${dias.length ? `
            <table class="fc-pdv-informe-table">
                <thead>
                    <tr><th>Fecha</th><th>Ventas</th><th>Efectivo</th><th>Tarjeta</th><th>Otro</th><th>Total</th></tr>
                </thead>
                <tbody>
                    ${dias.map(d => `
                    <tr>
                        <td>${fmtDate(d.fecha)}</td>
                        <td>${d.count}</td>
                        <td>${fmt(d.efectivo)}</td>
                        <td>${fmt(d.tarjeta)}</td>
                        <td>${fmt(d.otro)}</td>
                        <td>${fmt(d.total)}</td>
                    </tr>`).join('')}
                </tbody>
            </table>` : '<p style="color:#94a3b8;font-size:14px;">Sin ventas en el período seleccionado.</p>'}`;
    }

    // ── AUTH / LOGIN ──
    async function checkAuth() {
        try {
            const data = await ajax('fc_pdv_check_auth');
            return data.success && data.data.is_admin;
        } catch { return false; }
    }

    function initLoginForm() {
        const form = $('#fc-pdv-login-form');
        if (!form) return;

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const username = $('#fc-pdv-user').value;
            const password = $('#fc-pdv-pass').value;
            const errEl    = $('#fc-pdv-login-error');
            const submitBtn = form.querySelector('button[type="submit"]');

            submitBtn.disabled    = true;
            submitBtn.textContent = 'Verificando...';
            if (errEl) errEl.textContent = '';

            const data = await ajax('fc_pdv_login', { username, password });
            if (data.success) {
                if (data.data.nonce) nonce = data.data.nonce;
                window.location.reload();
            } else {
                if (errEl) errEl.textContent = data.data?.message || 'Error al iniciar sesión';
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Entrar';
            }
        });
    }

    // ── MAIN INIT ──
    async function initPDV() {
        initLoginForm();

        const mainPanel = $('#fc-pdv-main');
        if (!mainPanel) return; // not logged in, login form is shown

        // Nav: PDV | Caja | Informes
        $$('.fc-pdv-nav-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const view = btn.dataset.view;
                switchView(view);
                if (view === 'caja')          loadCajas();
                if (view === 'transacciones') loadTransacciones();
                if (view === 'informes')      loadInformes();
                if (view === 'pdv')           renderCatalog();
            });
        });

        // Logout
        $('#fc-pdv-btn-logout')?.addEventListener('click', async () => {
            await ajax('fc_pdv_logout');
            window.location.reload();
        });

        // Cobrar button
        $('#fc-pdv-btn-cobrar')?.addEventListener('click', showCheckoutModal);

        // Personalizado button
        $('#fc-pdv-btn-personalizado')?.addEventListener('click', () => {
            showDetailPanel({ id: 0, nombre: '', descripcion: '', thumb: '', tamanos: [] });
        });

        // Search
        const searchInput = $('#fc-pdv-search');
        if (searchInput) {
            let searchTimer = null;
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    searchTerm = searchInput.value.toLowerCase().trim();
                    renderCatalog();
                }, 250);
            });
        }

        // Transacciones date filter
        $('#fc-pdv-tx-buscar')?.addEventListener('click', loadTransacciones);

        // Informes date filter
        $('#fc-pdv-inf-buscar')?.addEventListener('click', loadInformes);

        // Set default date range for informes (current month)
        const now  = new Date();
        const y    = now.getFullYear();
        const m    = String(now.getMonth() + 1).padStart(2, '0');
        const d    = String(now.getDate()).padStart(2, '0');
        const inf_desde = $('#fc-pdv-inf-desde');
        const inf_hasta = $('#fc-pdv-inf-hasta');
        if (inf_desde && !inf_desde.value) inf_desde.value = `${y}-${m}-01`;
        if (inf_hasta && !inf_hasta.value) inf_hasta.value = `${y}-${m}-${d}`;

        const tx_desde = $('#fc-pdv-tx-desde');
        const tx_hasta = $('#fc-pdv-tx-hasta');
        if (tx_desde && !tx_desde.value) tx_desde.value = `${y}-${m}-${d}`;
        if (tx_hasta && !tx_hasta.value) tx_hasta.value = `${y}-${m}-${d}`;

        // Load catalog
        renderTicket();
        const catData = await ajax('fc_pdv_get_catalogo');
        if (catData.success) {
            catalogo    = catData.data.categorias || [];
            allArreglos = catData.data.arreglos   || [];
            renderCatalog();
        }

        // Load cajas
        await loadCajas();
    }

    document.addEventListener('DOMContentLoaded', initPDV);
})();
