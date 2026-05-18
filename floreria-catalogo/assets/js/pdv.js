/* ── Florería PDV — Punto de Venta ── */
(function () {
    'use strict';

    const { ajaxurl, nonce: initialNonce, siteurl, today = '', schedules = {} } = window.fcPdv || {};
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
        if (selectedCat !== null) arr = arr.filter(a => a.cat_id === selectedCat);
        if (searchTerm) arr = arr.filter(a => a.nombre.toLowerCase().includes(searchTerm));

        // Pills de categoría (solo si hay más de una)
        const pillsHtml = catalogo.length > 1 ? `
            <div class="fc-pdv-cat-pills">
                <button class="fc-pdv-cat-pill${selectedCat === null ? ' active' : ''}" data-cat="all">Todos</button>
                ${catalogo.map(c => `
                    <button class="fc-pdv-cat-pill${selectedCat === c.id ? ' active' : ''}" data-cat="${c.id}">${escHtml(c.nombre)}</button>
                `).join('')}
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
                        <div class="fc-pdv-catalog-card" data-idx="${i}">
                            <div class="fc-pdv-card-img-wrap">
                                ${a.thumb
                                    ? `<img src="${escHtml(a.thumb)}" alt="${escHtml(a.nombre)}" loading="lazy" />`
                                    : `<div class="fc-pdv-card-img-empty">🌸</div>`}
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

        // Pills → filtrar por categoría
        $$('.fc-pdv-cat-pill', container).forEach(pill => {
            pill.addEventListener('click', () => {
                const val = pill.dataset.cat;
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

        // Notas previas (sin el prefijo del color)
        let editNotas = '';
        if (editItem?.color) {
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

                    ${/* ── Columna izquierda: foto ── */ ''}
                    ${!isPersonalizado && photo
                        ? `<div class="fc-pdv-detail-photo-col" id="pdv-photo-col">
                               <img id="pdv-detail-img" src="${escHtml(photo)}" alt="${escHtml(arreglo.nombre)}" />
                               <div class="fc-pdv-photo-zoom-hint">🔍 Toca para ver completa</div>
                           </div>`
                        : `<div class="fc-pdv-detail-photo-empty-col">🌸</div>`}

                    ${/* ── Columna derecha: info ── */ ''}
                    <div class="fc-pdv-detail-info-col">

                        <button class="fc-pdv-detail-back">
                            ← Volver${editItem ? '<span class="fc-pdv-detail-edit-badge">Editando</span>' : ''}
                        </button>

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
                            <label class="fc-pdv-label-sm">Notas adicionales</label>
                            <input type="text" id="pdv-item-notas"
                                   placeholder="Detalles, personalización, etc. (opcional)"
                                   value="${escHtml(editNotas)}" />
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

                </div>`;

            bindPanelEvents();
        }

        function bindPanelEvents() {
            // Volver al grid
            $('.fc-pdv-detail-back', container)?.addEventListener('click', renderCatalog);

            // Lightbox al hacer click en la foto
            const photoCol = $('#pdv-photo-col', container);
            if (photoCol) {
                photoCol.addEventListener('click', () => {
                    const img = $('#pdv-detail-img', container);
                    if (!img) return;
                    const lb = document.createElement('div');
                    lb.className = 'fc-pdv-lightbox';
                    lb.innerHTML = `
                        <button class="fc-pdv-lightbox-close">×</button>
                        <img src="${escHtml(img.src)}" alt="${escHtml(arreglo.nombre)}" />`;
                    document.body.appendChild(lb);
                    const close = () => lb.remove();
                    lb.addEventListener('click', e => {
                        if (!e.target.closest('img')) close();
                    });
                });
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

        const itemsCheckoutHtml = cart.map((it, i) => `
            <div class="fc-pdv-checkout-item">
                <div class="fc-pdv-checkout-item-title">${escHtml(it.arreglo_nombre)}${it.tamano ? ' — ' + escHtml(it.tamano) : ''} · ${fmt(it.precio)}</div>
                <div class="fc-pdv-form-group">
                    <label>Para (destinatario)</label>
                    <input type="text" class="pdv-co-dest" data-idx="${i}" placeholder="Nombre de quien recibe (opcional)" value="${escHtml(it.destinatario)}" />
                </div>
                <div class="fc-pdv-form-group">
                    <label>Mensaje de tarjeta</label>
                    <input type="text" class="pdv-co-tarjeta" data-idx="${i}" placeholder="Opcional" value="${escHtml(it.mensaje_tarjeta)}" />
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
                    </div>

                    <div id="pdv-co-horario-wrap" class="fc-pdv-form-group" style="${tipoPedido === 'recoleccion' ? '' : 'display:none'}">
                        <label>Hora de recolección</label>
                        <input type="time" id="pdv-co-hora-recoleccion" />
                    </div>
                    <div id="pdv-co-envio-wrap" style="${tipoPedido === 'envio' ? '' : 'display:none'}">
                        <div class="fc-pdv-form-group">
                            <label>Horario de entrega</label>
                            <input type="text" id="pdv-co-horario" placeholder="Ej: 12:00 - 14:00" />
                        </div>
                        <div class="fc-pdv-form-group">
                            <label>Dirección de entrega <span style="color:#ef4444">*</span></label>
                            <input type="text" id="pdv-co-direccion" placeholder="Calle, número, colonia" />
                        </div>
                    </div>

                    <div class="fc-pdv-section-title">Detalles por arreglo</div>
                    ${itemsCheckoutHtml}

                    <div class="fc-pdv-section-title">Contacto (opcional)</div>
                    <div class="fc-pdv-form-group">
                        <label>Nombre del cliente</label>
                        <input type="text" id="pdv-co-canal-nombre" placeholder="Nombre de quien compra" />
                    </div>
                    <div class="fc-pdv-form-group">
                        <label>Teléfono</label>
                        <input type="tel" id="pdv-co-canal-contacto" placeholder="10 dígitos" inputmode="numeric" />
                    </div>
                    <div class="fc-pdv-form-group">
                        <label>Nota del pedido</label>
                        <textarea id="pdv-co-nota" rows="2" placeholder="Indicaciones especiales..."></textarea>
                    </div>

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

        // Tipo toggle
        $$('.fc-pdv-tipo-btn', backdrop).forEach(btn => {
            btn.addEventListener('click', () => {
                $$('.fc-pdv-tipo-btn', backdrop).forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                tipoPedido = btn.dataset.tipo;
                $('#pdv-co-horario-wrap', backdrop).style.display = tipoPedido === 'recoleccion' ? '' : 'none';
                $('#pdv-co-envio-wrap',   backdrop).style.display = tipoPedido === 'envio'       ? '' : 'none';
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

        // Cambio calculator
        const montoInput = $('#pdv-co-monto-recibido', backdrop);
        if (montoInput) {
            montoInput.addEventListener('input', () => {
                const recibido = parseFloat(montoInput.value || 0);
                const cambio   = recibido - cartTotal();
                const row      = $('#pdv-co-cambio-row', backdrop);
                const val      = $('#pdv-co-cambio-val', backdrop);
                if (recibido > 0 && row && val) {
                    row.style.display = '';
                    val.textContent   = fmt(Math.max(0, cambio));
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
            const fecha = $('#pdv-co-fecha', backdrop)?.value || '';
            if (!fecha) { showToast('Selecciona la fecha de entrega', 'error'); return; }
            if (tipoPedido === 'envio' && !($('#pdv-co-direccion', backdrop)?.value || '').trim()) {
                showToast('Ingresa la dirección de entrega', 'error');
                $('#pdv-co-direccion', backdrop)?.focus();
                return;
            }

            // Collect item details from modal
            const itemsFinal = cart.map((it, i) => ({
                ...it,
                destinatario:    ($('.pdv-co-dest[data-idx="' + i + '"]', backdrop)?.value    || '').trim(),
                mensaje_tarjeta: ($('.pdv-co-tarjeta[data-idx="' + i + '"]', backdrop)?.value || '').trim(),
            }));

            const montoTotal = cartTotal();
            const recibido   = parseFloat($('#pdv-co-monto-recibido', backdrop)?.value || 0);
            const cambio     = formaPago === 'efectivo' && recibido > 0 ? Math.max(0, recibido - montoTotal) : 0;

            confirmBtn.textContent = 'Procesando...';
            confirmBtn.disabled    = true;

            try {
                const data = await ajax('fc_pdv_crear_venta', {
                    tipo:            tipoPedido,
                    fecha,
                    horario:         $('#pdv-co-horario', backdrop)?.value           || '',
                    hora_recoleccion:$('#pdv-co-hora-recoleccion', backdrop)?.value  || '',
                    direccion:       $('#pdv-co-direccion', backdrop)?.value          || '',
                    canal_nombre:    $('#pdv-co-canal-nombre', backdrop)?.value       || '',
                    canal_contacto:  $('#pdv-co-canal-contacto', backdrop)?.value     || '',
                    nota:            $('#pdv-co-nota', backdrop)?.value               || '',
                    forma_pago:      formaPago,
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
            html += `
                <div style="width:100%;margin-top:4px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:8px;">Cajas anteriores</div>
                    <div class="fc-pdv-historial-list">
                        ${cajasData.cerradas.map(c => `
                            <div class="fc-pdv-hist-item">
                                <span class="fc-pdv-hist-fecha">${fmtDatetime(c.fecha_apertura)} → ${fmtDatetime(c.fecha_cierre)}</span>
                                <span>E:${fmt(c.total_efectivo)} T:${fmt(c.total_tarjeta)} O:${fmt(c.total_otro)}</span>
                                <span class="fc-pdv-hist-total">${fmt(c.total_ventas)}</span>
                            </div>`).join('')}
                    </div>
                </div>`;
        }

        wrap.innerHTML = html;

        $('#fc-pdv-btn-abrir-caja', wrap)?.addEventListener('click', () => showAbrirCajaModal());

        // Botones por caja
        cajasData.abiertas.forEach(caja => {
            $(`#fc-pdv-btn-entrada-${caja.id}`, wrap)?.addEventListener('click', () => showMovimientoModal(caja.id, 'entrada'));
            $(`#fc-pdv-btn-salida-${caja.id}`,  wrap)?.addEventListener('click', () => showMovimientoModal(caja.id, 'salida'));
            $(`#fc-pdv-btn-cerrar-${caja.id}`,  wrap)?.addEventListener('click', () => showCerrarCajaModal(caja));
        });
    }

    function renderCajaCard(caja, isAbierta) {
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
                    Entradas manuales: <strong>${fmt(caja.total_entradas)}</strong> &nbsp;
                    Salidas: <strong>${fmt(caja.total_salidas)}</strong>
                </div>
                ${isAbierta ? `
                <div class="fc-pdv-caja-actions">
                    <button class="fc-pdv-btn-sm success" id="fc-pdv-btn-entrada-${caja.id}">+ Entrada</button>
                    <button class="fc-pdv-btn-sm danger"  id="fc-pdv-btn-salida-${caja.id}">− Salida</button>
                    <button class="fc-pdv-btn-sm outline" id="fc-pdv-btn-cerrar-${caja.id}">Cerrar caja</button>
                </div>` : ''}
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
                if (view === 'caja')     loadCajas();
                if (view === 'informes') loadInformes();
                if (view === 'pdv')      renderCatalog(); // asegurar que el grid esté visible
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

        // Load catalog
        renderTicket();
        const catData = await ajax('fc_pdv_get_catalogo');
        if (catData.success) {
            catalogo    = catData.data.categorias || [];
            allArreglos = catalogo.flatMap(c => c.arreglos.map(a => ({ ...a, cat_id: c.id })));
            renderCatalog();
        }

        // Load cajas
        await loadCajas();
    }

    document.addEventListener('DOMContentLoaded', initPDV);
})();
