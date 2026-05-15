/* ── Florería Panel Florista — Vanilla JS ── */
(function () {
    'use strict';

    const { ajaxurl, nonce, siteurl, schedules = {}, isAdmin = false, today = '' } = window.fcPanel || {};

    // ── Status labels ──
    const STATUS_LABELS = {
        aceptado:          'Aceptado',
        en_preparacion:    'En preparación',
        en_camino:         'En camino',
        listo_recoleccion: 'Listo para recolección',
        entregado:         'Entregado',
    };

    // ── State ──
    let currentFilter    = 'all';
    let currentEditId    = null;   // null = crear, número = editar
    let pedidoDataMap    = {};     // id → datos completos del pedido
    let isPapeleraView   = false;
    let isPendienteMode  = false;
    let modalItemCounter = 0;      // unique index per item block

    // Lightbox carousel state
    let lbPhotos = [];
    let lbIndex  = 0;

    // ── DOM helpers ──
    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

    // ── Toast ──
    function showToast(msg, type = 'success') {
        let toast = $('#fc-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'fc-toast';
            toast.className = 'fc-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.className = `fc-toast ${type}`;
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }

    // ── AJAX helper ──
    async function ajax(action, data = {}) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', nonce);
        for (const [k, v] of Object.entries(data)) {
            body.append(k, v);
        }
        const res = await fetch(ajaxurl, { method: 'POST', body });
        return res.json();
    }

    // ── Copy to clipboard ──
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => showToast('Link copiado al portapapeles'));
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('Link copiado al portapapeles');
        }
    }

    // ── Format datetime ──
    function fmtDatetime(ts) {
        if (!ts) return '';
        // El timestamp viene en UTC desde WordPress; añadir 'Z' para que JS lo interprete correctamente
        const d = new Date(ts.replace(' ', 'T') + 'Z');
        const tz = { timeZone: 'America/Tijuana' };
        return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', ...tz })
            + ' ' + d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', ...tz });
    }

    // ── Render badge ──
    function renderBadge(status) {
        const label = STATUS_LABELS[status] || status;
        return `<span class="fc-status-badge ${status}">${label}</span>`;
    }

    // ── Render status options ──
    // Omit incompatible statuses depending on delivery type
    function statusOptions(current, tipo) {
        return Object.entries(STATUS_LABELS)
            .filter(([k]) => {
                if (tipo === 'envio'        && k === 'listo_recoleccion') return false;
                if (tipo === 'recoleccion'  && k === 'en_camino')         return false;
                return true;
            })
            .map(([k, v]) =>
                `<option value="${k}" ${k === current ? 'selected' : ''}>${v}</option>`
            ).join('');
    }

    // ── Render order card ──
    function renderCard(p) {
        const clientUrl = `${siteurl}/pedido/${p.numero}`;
        const last = p.last_change;
        const lastInfo = last
            ? `Último cambio: ${fmtDatetime(last.timestamp)} por ${escHtml(last.user_name)}`
            : '';

        const tipoLabel    = p.tipo === 'envio' ? 'Envío a domicilio' : 'Recolección en tienda';
        const horarioLabel = p.tipo === 'envio' ? p.horario : p.hora_recoleccion;

        const CANAL_LABELS = { whatsapp: 'WhatsApp', instagram: 'Instagram', facebook: 'Facebook', otro: 'Otro' };
        const canalLabel = p.canal ? CANAL_LABELS[p.canal] || p.canal : '';
        const canalContactoHtml = p.canal === 'whatsapp' && p.canal_contacto
            ? telLink(p.canal_contacto)
            : escHtml(p.canal_contacto || '');
        const canalInfoParts = [p.canal_nombre ? escHtml(p.canal_nombre) : '', canalContactoHtml].filter(Boolean);
        const canalHtml  = canalLabel
            ? `<div class="fc-card-row"><span class="fc-label">Canal</span><span class="fc-value">${escHtml(canalLabel)}${canalInfoParts.length ? ' · ' + canalInfoParts.join(' · ') : ''}</span></div>`
            : '';

        // ── Items (multi-arreglo) ──
        const items = p.items || [];
        const itemsHtml = items.map((item, i) => {
            const allPhotos = [item.imagen_url, ...(item.fotos_extra || [])].filter(Boolean);
            const hasExtra  = allPhotos.length > 1;
            const photosAttr = escAttr(JSON.stringify(allPhotos));

            let thumb;
            if (allPhotos.length > 0) {
                thumb = `<div class="fc-card-item-thumb-wrap${hasExtra ? ' fc-photo-stack' : ''}"
                              data-photos="${photosAttr}" title="${hasExtra ? 'Ver ' + allPhotos.length + ' fotos' : 'Ver foto'}">
                           <img class="fc-card-item-thumb" src="${escAttr(allPhotos[0])}" alt="" loading="lazy"
                                onerror="this.closest('.fc-card-item-thumb-wrap').classList.add('fc-photo-error');this.style.display='none'" />
                           ${hasExtra ? `<span class="fc-photo-stack-count">${allPhotos.length}</span>` : ''}
                         </div>`;
            } else {
                thumb = `<div class="fc-card-item-thumb-empty">&#127800;</div>`;
            }

            const sub = [item.tamano, (item.color && !item.color.startsWith('--')) ? item.color : ''].filter(Boolean).join(' · ');
            const destLine = item.destinatario
                ? `<span class="fc-card-item-dest">Para: ${escHtml(item.destinatario)}${item.destinatario_telefono ? ' · ' + telLink(item.destinatario_telefono) : ''}${item.destinatario_telefono2 ? ' · ' + telLink(item.destinatario_telefono2) : ''}</span>`
                : '';
            const tarjetaLine = item.mensaje_tarjeta
                ? `<span class="fc-card-item-tarjeta">"${escHtml(item.mensaje_tarjeta)}"</span>`
                : '';
            return `
            <div class="fc-card-item">
                <div class="fc-card-item-media">
                    ${thumb}
                    <button class="fc-btn-add-foto" type="button"
                            data-pedido-id="${p.id}" data-item-idx="${i}" title="Añadir foto">
                        📷 Añadir foto
                    </button>
                </div>
                <div class="fc-card-item-info">
                    <strong class="fc-card-item-nombre">${escHtml(item.arreglo_nombre)}</strong>
                    ${sub ? `<span class="fc-card-item-sub">${escHtml(sub)}</span>` : ''}
                    ${destLine}
                    ${tarjetaLine}
                </div>
            </div>`;
        }).join('');

        const isMobile = window.innerWidth <= 640;

        return `
<div class="fc-order-card${isMobile ? ' collapsed' : ''}" data-status="${escAttr(p.status)}" data-id="${p.id}">
    <div class="fc-card-header">
        <div style="display:flex;flex-direction:column;gap:2px;">
            <span class="fc-order-num">${escHtml(p.numero)}</span>
            ${items.length > 1 ? `<span class="fc-card-item-count">${items.length} arreglos</span>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            ${renderBadge(p.status)}
            <button class="fc-card-collapse-btn" aria-label="Colapsar">&#9662;</button>
        </div>
    </div>

    <div class="fc-card-collapsible">
        <div class="fc-card-info">

            <!-- Datos de entrega y cliente -->
            ${canalHtml}
            <div class="fc-card-row">
                <span class="fc-label">Entrega</span>
                <span class="fc-value">${escHtml(tipoLabel)} · ${escHtml(p.fecha)}${horarioLabel ? ' · ' + escHtml(horarioLabel) : ''}</span>
            </div>
            ${p.tipo === 'envio' && p.direccion ? `<div class="fc-card-row"><span class="fc-label">Dirección</span><span class="fc-value"><a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(p.direccion)}" target="_blank" rel="noopener" class="fc-maps-link">${escHtml(p.direccion)}</a></span></div>` : ''}
            ${p.nota ? `<div class="fc-card-row"><span class="fc-label">Nota</span><span class="fc-value">${escHtml(p.nota)}</span></div>` : ''}

            <!-- Arreglos -->
            ${items.length ? `<div class="fc-card-items-list">${itemsHtml}</div>` : ''}

        </div>

        <hr class="fc-card-divider" />

        <div class="fc-card-actions">
            <button class="fc-btn-link fc-btn-ver-link" data-url="${escAttr(clientUrl)}">
                Ver pedido ↗
            </button>

            <div class="fc-status-row">
                <select class="fc-select-status">${statusOptions(p.status, p.tipo)}</select>
                <button class="fc-btn-update fc-btn-actualizar-status">Actualizar</button>
            </div>
            ${lastInfo ? `<p class="fc-last-change">${lastInfo}</p>` : ''}

            <div class="fc-nota-row">
                <label>Nota para el cliente (visible en su página):</label>
                <textarea class="fc-nota-floreria-input" rows="2" placeholder="Escribe una nota para el cliente...">${escHtml(p.nota_floreria || '')}</textarea>
                <div class="fc-nota-row-actions">
                    <button class="fc-btn-sm fc-btn-guardar-nota">Guardar nota</button>
                </div>
            </div>

            <div class="fc-card-extra-actions">
                <button class="fc-btn-sm fc-btn-imprimir" data-id="${p.id}" style="background:#2d6a4f;">&#128424; Imprimir</button>
                ${p.pdf_url ? `<a class="fc-btn-sm fc-btn-ver-pdf" href="${escAttr(p.pdf_url)}" target="_blank" rel="noopener" style="background:#7c3aed;text-decoration:none;">&#128196; Ver PDF</a>` : ''}
                <button class="fc-btn-sm fc-btn-editar-pedido" style="background:#4a5568;">&#9998; Editar</button>
                ${isAdmin ? `<button class="fc-btn-sm fc-btn-eliminar-pedido" style="background:#ef4444;">&#10005; Eliminar</button>` : ''}
            </div>
        </div>
    </div>
</div>`;
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escAttr(str) {
        return escHtml(str);
    }

    function telLink(num) {
        if (!num) return '';
        var digits = String(num).replace(/\D/g, '');
        return `<a href="tel:${digits}" class="fc-tel-link">${escHtml(num)}</a>`;
    }

    // ── Load & render orders ──
    async function loadPedidos(status = 'all', fecha = '') {
        const grid = $('#fc-orders-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="fc-loading">Cargando pedidos...</div>';

        try {
            const data = await ajax('fc_panel_get_pedidos', { status, fecha });
            if (!data.success) {
                grid.innerHTML = '<div class="fc-no-pedidos">Error al cargar pedidos.</div>';
                return;
            }
            const pedidos = data.data.pedidos;
            if (!pedidos.length) {
                grid.innerHTML = '<div class="fc-no-pedidos">No hay pedidos en este estado.</div>';
                return;
            }
            pedidoDataMap = {};
            pedidos.forEach(p => { pedidoDataMap[p.id] = p; });
            grid.innerHTML = pedidos.map(renderCard).join('');
            attachCardEvents(grid);
        } catch (e) {
            grid.innerHTML = '<div class="fc-no-pedidos">Error de conexión.</div>';
        }
    }

    // ── Lightbox (carousel) ──
    function initLightbox() {
        if (document.getElementById('fc-lightbox')) return;
        const lb = document.createElement('div');
        lb.id = 'fc-lightbox';
        lb.className = 'fc-lightbox';
        lb.innerHTML = `
            <button class="fc-lightbox-close" aria-label="Cerrar">&times;</button>
            <button class="fc-lightbox-prev" aria-label="Anterior">&#8249;</button>
            <img class="fc-lightbox-img" src="" alt="Foto" />
            <button class="fc-lightbox-next" aria-label="Siguiente">&#8250;</button>
            <div class="fc-lightbox-counter"></div>
        `;
        document.body.appendChild(lb);

        lb.addEventListener('click', (e) => {
            if (e.target === lb || e.target.matches('.fc-lightbox-close')) lb.classList.remove('open');
            if (e.target.matches('.fc-lightbox-prev')) lbStep(-1);
            if (e.target.matches('.fc-lightbox-next')) lbStep(1);
        });
        document.addEventListener('keydown', (e) => {
            if (!lb.classList.contains('open')) return;
            if (e.key === 'Escape')      lb.classList.remove('open');
            if (e.key === 'ArrowLeft')   lbStep(-1);
            if (e.key === 'ArrowRight')  lbStep(1);
        });
    }

    function lbStep(dir) {
        if (lbPhotos.length <= 1) return;
        lbIndex = (lbIndex + dir + lbPhotos.length) % lbPhotos.length;
        lbRender();
    }

    function lbRender() {
        const lb = document.getElementById('fc-lightbox');
        if (!lb) return;
        lb.querySelector('.fc-lightbox-img').src = lbPhotos[lbIndex] || '';
        const counter  = lb.querySelector('.fc-lightbox-counter');
        const showNav  = lbPhotos.length > 1;
        lb.querySelector('.fc-lightbox-prev').style.display = showNav ? '' : 'none';
        lb.querySelector('.fc-lightbox-next').style.display = showNav ? '' : 'none';
        if (counter) counter.textContent = showNav ? `${lbIndex + 1} / ${lbPhotos.length}` : '';
    }

    function openLightbox(photos, startIndex = 0) {
        const lb = document.getElementById('fc-lightbox');
        if (!lb) return;
        lbPhotos = Array.isArray(photos) ? photos.filter(Boolean) : [photos].filter(Boolean);
        lbIndex  = Math.max(0, Math.min(startIndex, lbPhotos.length - 1));
        lbRender();
        lb.classList.add('open');
    }

    // ── Attach card events ──
    function attachCardEvents(grid) {
        // Editar pedido
        $$('.fc-btn-editar-pedido', grid).forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const cardId = parseInt(btn.closest('.fc-order-card').dataset.id, 10);
                const pedido = pedidoDataMap[cardId];
                if (pedido) openEditModal(pedido);
            });
        });

        // Eliminar pedido
        $$('.fc-btn-eliminar-pedido', grid).forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const card   = btn.closest('.fc-order-card');
                const cardId = parseInt(card.dataset.id, 10);
                const pedido = pedidoDataMap[cardId];
                if (!window.confirm(`¿Eliminar el pedido ${pedido?.numero || cardId}?\nEsta acción no se puede deshacer.`)) return;
                btn.textContent = '...';
                btn.disabled    = true;
                try {
                    const data = await ajax('fc_panel_eliminar_pedido', { pedido_id: cardId });
                    if (data.success) {
                        card.remove();
                        delete pedidoDataMap[cardId];
                        showToast('Pedido movido a la papelera', 'success');
                    } else {
                        showToast(data.data?.message || 'Error al eliminar', 'error');
                        btn.textContent = '✕ Eliminar';
                        btn.disabled    = false;
                    }
                } catch {
                    showToast('Error de conexión', 'error');
                    btn.textContent = '✕ Eliminar';
                    btn.disabled    = false;
                }
            });
        });

        // Imprimir pedido
        $$('.fc-btn-imprimir', grid).forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const pedidoId = btn.dataset.id;
                window.open(`${siteurl}/?fc_print_pedido=${pedidoId}`, '_blank', 'noopener');
            });
        });

        // Collapse toggle
        $$('.fc-card-collapse-btn', grid).forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                btn.closest('.fc-order-card').classList.toggle('collapsed');
            });
        });

        // Thumbnail lightbox carousel (items)
        $$('.fc-card-item-thumb-wrap', grid).forEach(wrap => {
            wrap.addEventListener('click', () => {
                try {
                    const photos = JSON.parse(wrap.dataset.photos || '[]');
                    openLightbox(photos, 0);
                } catch { openLightbox([wrap.dataset.photos], 0); }
            });
        });

        // Añadir foto
        if (!document.getElementById('fc-foto-file-input')) {
            const fi = document.createElement('input');
            fi.type = 'file';
            fi.accept = 'image/*';
            fi.id = 'fc-foto-file-input';
            fi.style.display = 'none';
            document.body.appendChild(fi);

            fi.addEventListener('change', async () => {
                if (!fi.files[0] || !fi._activeBtn) return;
                const btn      = fi._activeBtn;
                const pedidoId = parseInt(btn.dataset.pedidoId, 10);
                const itemIdx  = parseInt(btn.dataset.itemIdx,  10);
                const origText = btn.textContent;
                btn.textContent = '⏳';
                btn.disabled    = true;

                const fd = new FormData();
                fd.append('action', 'fc_panel_upload_foto');
                fd.append('nonce',     nonce);
                fd.append('pedido_id', pedidoId);
                fd.append('item_idx',  itemIdx);
                fd.append('foto', fi.files[0]);

                try {
                    const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        const url = data.data.url;
                        // Update in-memory map
                        const pedido = pedidoDataMap[pedidoId];
                        if (pedido && pedido.items[itemIdx]) {
                            if (!pedido.items[itemIdx].fotos_extra) pedido.items[itemIdx].fotos_extra = [];
                            pedido.items[itemIdx].fotos_extra.push(url);
                            // Refresh media area
                            refreshCardItemMedia(btn.closest('.fc-order-card'), itemIdx, pedido.items[itemIdx], pedidoId);
                        }
                        showToast('Foto añadida', 'success');
                    } else {
                        showToast(data.data?.message || 'Error al subir', 'error');
                    }
                } catch { showToast('Error de conexión', 'error'); }
                finally {
                    btn.textContent = origText;
                    btn.disabled    = false;
                    fi.value        = '';
                    fi._activeBtn   = null;
                }
            });
        }

        $$('.fc-btn-add-foto', grid).forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const fi = document.getElementById('fc-foto-file-input');
                fi._activeBtn = btn;
                fi.click();
            });
        });

        // Ver link — abre en nueva pestaña
        $$('.fc-btn-ver-link', grid).forEach(btn => {
            btn.addEventListener('click', () => {
                window.open(btn.dataset.url, '_blank', 'noopener');
            });
        });

        // Actualizar status
        $$('.fc-btn-actualizar-status', grid).forEach(btn => {
            btn.addEventListener('click', async () => {
                const card     = btn.closest('.fc-order-card');
                const pedidoId = card.dataset.id;
                const select   = $('.fc-select-status', card);
                const status   = select.value;

                btn.textContent = '...';
                btn.disabled    = true;

                try {
                    const data = await ajax('fc_panel_actualizar_status', { pedido_id: pedidoId, status });
                    if (data.success) {
                        showToast('Estado actualizado', 'success');
                        card.dataset.status = status;
                        const badge = $('.fc-status-badge', card);
                        if (badge) {
                            badge.className = `fc-status-badge ${status}`;
                            badge.textContent = STATUS_LABELS[status] || status;
                        }
                        const lastChange = data.data.last_change;
                        let lc = $('.fc-last-change', card);
                        if (!lc) {
                            lc = document.createElement('p');
                            lc.className = 'fc-last-change';
                            btn.closest('.fc-status-row').insertAdjacentElement('afterend', lc);
                        }
                        lc.textContent = `Último cambio: ${fmtDatetime(lastChange.timestamp)} por ${lastChange.user_name}`;
                    } else {
                        showToast(data.data?.message || 'Error al actualizar', 'error');
                    }
                } catch {
                    showToast('Error de conexión', 'error');
                } finally {
                    btn.textContent = 'Actualizar';
                    btn.disabled    = false;
                }
            });
        });

        // Guardar nota floreria
        $$('.fc-btn-guardar-nota', grid).forEach(btn => {
            btn.addEventListener('click', async () => {
                const card     = btn.closest('.fc-order-card');
                const pedidoId = card.dataset.id;
                const nota     = $('.fc-nota-floreria-input', card).value;

                btn.textContent = '...';
                btn.disabled    = true;

                try {
                    const data = await ajax('fc_panel_actualizar_nota', { pedido_id: pedidoId, nota });
                    if (data.success) {
                        showToast('Nota guardada', 'success');
                    } else {
                        showToast(data.data?.message || 'Error al guardar', 'error');
                    }
                } catch {
                    showToast('Error de conexión', 'error');
                } finally {
                    btn.textContent = 'Guardar nota';
                    btn.disabled    = false;
                }
            });
        });
    }

    // ── Refresh media area of a single card item after photo upload ──
    function refreshCardItemMedia(cardEl, itemIdx, item, pedidoId) {
        const itemEls = $$('.fc-card-item', cardEl);
        const itemEl  = itemEls[itemIdx];
        if (!itemEl) return;
        const mediaEl = itemEl.querySelector('.fc-card-item-media');
        if (!mediaEl) return;

        const allPhotos = [item.imagen_url, ...(item.fotos_extra || [])].filter(Boolean);
        const hasExtra  = allPhotos.length > 1;
        const photosAttr = escAttr(JSON.stringify(allPhotos));

        let thumbHtml;
        if (allPhotos.length > 0) {
            thumbHtml = `<div class="fc-card-item-thumb-wrap${hasExtra ? ' fc-photo-stack' : ''}"
                              data-photos="${photosAttr}" title="${hasExtra ? 'Ver ' + allPhotos.length + ' fotos' : 'Ver foto'}">
                           <img class="fc-card-item-thumb" src="${escAttr(allPhotos[0])}" alt="" loading="lazy"
                                onerror="this.closest('.fc-card-item-thumb-wrap').classList.add('fc-photo-error');this.style.display='none'" />
                           ${hasExtra ? `<span class="fc-photo-stack-count">${allPhotos.length}</span>` : ''}
                         </div>`;
        } else {
            thumbHtml = `<div class="fc-card-item-thumb-empty">&#127800;</div>`;
        }

        mediaEl.innerHTML = thumbHtml +
            `<button class="fc-btn-add-foto" type="button"
                     data-pedido-id="${pedidoId}" data-item-idx="${itemIdx}" title="Añadir foto">
                 📷 Añadir foto
             </button>`;

        mediaEl.querySelector('.fc-card-item-thumb-wrap')?.addEventListener('click', () => {
            openLightbox(allPhotos, 0);
        });
        mediaEl.querySelector('.fc-btn-add-foto')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const fi = document.getElementById('fc-foto-file-input');
            fi._activeBtn = mediaEl.querySelector('.fc-btn-add-foto');
            fi.click();
        });
    }

    // ── Get current fecha filter value ──
    function getCurrentFecha() {
        return $('#fc-fecha-filter')?.value || '';
    }

    // ── Helpers: entrar / salir del modo papelera ──
    function enterTrashMode() {
        isPapeleraView = true;
        const df = $('.fc-date-filter');
        const sb = $('.fc-search-bar');
        if (df) df.style.display = 'none';
        if (sb) sb.style.display = 'none';
    }

    function exitTrashMode() {
        isPapeleraView = false;
        const df = $('.fc-date-filter');
        const sb = $('.fc-search-bar');
        if (df) df.style.display = '';
        if (sb) sb.style.display = '';
    }

    // ── Filter tabs ──
    function initFilterTabs() {
        const tabContainer = $('.fc-filter-tabs');

        // Agregar pestañas exclusivas de admin antes de construir el select
        if (isAdmin && tabContainer) {
            const pendienteBtn = document.createElement('button');
            pendienteBtn.className = 'fc-filter-tab fc-filter-tab-pendiente';
            pendienteBtn.dataset.status = 'pendiente';
            pendienteBtn.textContent = '⏳ Pendientes';
            tabContainer.appendChild(pendienteBtn);

            const trashBtn = document.createElement('button');
            trashBtn.className = 'fc-filter-tab fc-filter-tab-trash';
            trashBtn.dataset.status = '__trash__';
            trashBtn.textContent = '🗑 Papelera';
            tabContainer.appendChild(trashBtn);
        }

        const tabs = $$('.fc-filter-tab');

        // Build mobile <select> from all tabs (incluye papelera si se agregó)
        if (tabContainer) {
            const mSel = document.createElement('select');
            mSel.className = 'fc-filter-select-mobile';
            tabs.forEach(tab => {
                const opt = document.createElement('option');
                opt.value = tab.dataset.status;
                opt.textContent = tab.textContent.trim();
                if (tab.classList.contains('active')) opt.selected = true;
                mSel.appendChild(opt);
            });
            tabContainer.insertAdjacentElement('beforebegin', mSel);

            mSel.addEventListener('change', () => {
                tabs.forEach(t => t.classList.remove('active'));
                const match = tabs.find(t => t.dataset.status === mSel.value);
                if (match) match.classList.add('active');
                if (mSel.value === '__trash__') {
                    enterTrashMode();
                    loadPapelera();
                } else {
                    exitTrashMode();
                    currentFilter = mSel.value;
                    loadPedidos(currentFilter, getCurrentFecha());
                }
            });
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const mSel = $('.fc-filter-select-mobile');
                if (mSel) mSel.value = tab.dataset.status;

                if (tab.dataset.status === '__trash__') {
                    enterTrashMode();
                    loadPapelera();
                } else {
                    exitTrashMode();
                    currentFilter = tab.dataset.status;
                    loadPedidos(currentFilter, getCurrentFecha());
                }
            });
        });
    }

    // ── Search ──
    function initSearch() {
        const input    = $('#fc-search-input');
        const clearBtn = $('#fc-search-clear');
        if (!input) return;

        let timer = null;

        function restoreNormalView() {
            exitTrashMode();
            // Si la pestaña activa era papelera, volver a "Todos"
            const activeTab = $('.fc-filter-tab.active');
            if (activeTab?.dataset?.status === '__trash__') {
                $$('.fc-filter-tab').forEach(t => t.classList.remove('active'));
                const allTab = $('.fc-filter-tab[data-status="all"]');
                if (allTab) allTab.classList.add('active');
                currentFilter = 'all';
                const mSel = $('.fc-filter-select-mobile');
                if (mSel) mSel.value = 'all';
            }
            loadPedidos(currentFilter, getCurrentFecha());
        }

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const term = input.value.trim();

            // Show / hide clear button
            if (clearBtn) clearBtn.style.display = term ? '' : 'none';

            if (!term) {
                restoreNormalView();
                return;
            }
            if (term.length < 2) return;

            timer = setTimeout(async () => {
                const grid = $('#fc-orders-grid');
                if (grid) grid.innerHTML = '<div class="fc-loading">Buscando...</div>';

                try {
                    const data = await ajax('fc_panel_search_pedidos', { term });
                    if (!data.success) {
                        grid.innerHTML = '<div class="fc-no-pedidos">Error al buscar.</div>';
                        return;
                    }
                    const pedidos = data.data.pedidos;
                    pedidoDataMap = {};
                    pedidos.forEach(p => { pedidoDataMap[p.id] = p; });
                    if (!pedidos.length) {
                        grid.innerHTML = `<div class="fc-no-pedidos">Sin resultados para "<strong>${escHtml(term)}</strong>".</div>`;
                    } else {
                        grid.innerHTML = pedidos.map(renderCard).join('');
                        attachCardEvents(grid);
                    }
                } catch {
                    const grid2 = $('#fc-orders-grid');
                    if (grid2) grid2.innerHTML = '<div class="fc-no-pedidos">Error de conexión.</div>';
                }
            }, 380);
        });

        clearBtn && clearBtn.addEventListener('click', () => {
            input.value = '';
            clearBtn.style.display = 'none';
            restoreNormalView();
            input.focus();
        });
    }

    // ── Date filter ──
    function initDateFilter() {
        const fechaInput = $('#fc-fecha-filter');
        const clearBtn   = $('#fc-clear-fecha');
        if (!fechaInput) return;

        fechaInput.addEventListener('change', () => {
            loadPedidos(currentFilter, fechaInput.value);
        });

        clearBtn && clearBtn.addEventListener('click', () => {
            fechaInput.value = '';
            loadPedidos(currentFilter, '');
        });
    }

    // ── Login form ──
    function initLoginForm() {
        const form = $('#fc-login-form');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = $('#fc-login-username').value.trim();
            const password = $('#fc-login-password').value;
            const errEl    = $('#fc-login-error');
            const btn      = form.querySelector('button[type="submit"]');

            errEl.textContent = '';
            btn.textContent   = 'Entrando...';
            btn.disabled      = true;

            try {
                const body = new FormData();
                body.append('action', 'fc_panel_login');
                body.append('username', username);
                body.append('password', password);
                const res  = await fetch(ajaxurl, { method: 'POST', body });
                const data = await res.json();

                if (data.success && data.data?.token) {
                    // POST a /panel-florista/ con el token firmado.
                    // Un POST nunca es cacheado → el servidor siempre ejecuta PHP,
                    // establece la cookie de sesión y redirige al GET del panel.
                    const f   = document.createElement('form');
                    f.method  = 'POST';
                    f.action  = siteurl + '/panel-florista/';
                    const inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = 'fc_al_token';
                    inp.value = data.data.token;
                    f.appendChild(inp);
                    document.body.appendChild(f);
                    f.submit();
                } else if (data.success) {
                    window.location.reload();
                } else {
                    errEl.textContent = data.data?.message || 'Error al iniciar sesión.';
                }
            } catch {
                errEl.textContent = 'Error de conexión.';
            } finally {
                btn.textContent = 'Entrar';
                btn.disabled    = false;
            }
        });
    }

    // ── Logout ──
    function initLogout() {
        const btn = $('#fc-logout-btn');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            btn.textContent = '...';
            btn.disabled    = true;
            try {
                await ajax('fc_panel_logout');
                window.location.reload();
            } catch {
                window.location.reload();
            }
        });
    }

    // ── Canal de contacto ──
    const CANAL_CONFIG = {
        whatsapp:  { label: 'Número de WhatsApp',  placeholder: 'Ej. 6641234567',    inputmode: 'numeric' },
        instagram: { label: 'Usuario de Instagram', placeholder: '@usuario',           inputmode: 'text'    },
        facebook:  { label: 'Nombre en Facebook',   placeholder: 'Nombre del perfil', inputmode: 'text'    },
        otro:      { label: 'Detalle',              placeholder: '¿Cómo contactó?',   inputmode: 'text'    },
    };

    function updateCanalField(canal) {
        const group      = $('#fc-canal-contacto-group');
        const label      = $('#fc-canal-contacto-label');
        const input      = $('#fc-modal-canal-contacto');
        const nombreGrp  = $('#fc-canal-nombre-group');
        const nombreInp  = $('#fc-modal-canal-nombre');
        if (!group || !label || !input) return;

        if (!canal) {
            group.style.display      = 'none';
            if (nombreGrp) nombreGrp.style.display = 'none';
            input.value = '';
            if (nombreInp) nombreInp.value = '';
            return;
        }

        const cfg = CANAL_CONFIG[canal] || { label: 'Contacto', placeholder: '', inputmode: 'text' };
        label.textContent   = cfg.label;
        input.placeholder   = cfg.placeholder;
        input.inputMode     = cfg.inputmode;
        input.type          = canal === 'whatsapp' ? 'tel' : 'text';
        group.style.display = '';

        // Campo de nombre: solo para WhatsApp
        if (nombreGrp) {
            nombreGrp.style.display = canal === 'whatsapp' ? '' : 'none';
            if (canal !== 'whatsapp' && nombreInp) nombreInp.value = '';
        }

        (canal === 'whatsapp' ? nombreInp : input)?.focus();
    }

    // ── Item block: build HTML ──
    function buildItemBlockHTML(idx, prefill = {}) {
        const num = idx + 1;
        return `
        <div class="fc-item-block" data-item-idx="${idx}">
            <div class="fc-item-block-header">
                <span class="fc-item-block-label">Arreglo ${num}</span>
                <button type="button" class="fc-item-remove-btn" title="Quitar arreglo">&#10005;</button>
            </div>
            <div class="fc-form-group">
                <label>Arreglo</label>
                <div class="fc-autocomplete-wrap">
                    <input type="text" class="fc-item-arreglo-search" placeholder="Buscar por nombre..." autocomplete="off" value="${escHtml(prefill.arreglo_nombre || '')}" />
                    <input type="hidden" class="fc-item-arreglo-id"     value="${escHtml(String(prefill.arreglo_id || ''))}" />
                    <input type="hidden" class="fc-item-arreglo-nombre" value="${escHtml(prefill.arreglo_nombre || '')}" />
                    <input type="hidden" class="fc-item-imagen-url"     value="${escHtml(prefill.imagen_url || '')}" />
                    <div class="fc-autocomplete-dropdown fc-item-arreglo-dropdown"></div>
                </div>
            </div>
            <div class="fc-form-group">
                <label>Tamaño</label>
                <select class="fc-item-tamano">
                    <option value="">-- Selecciona tamaño --</option>
                </select>
            </div>
            <div class="fc-form-group fc-item-color-group" style="display:none;">
                <label>Color</label>
                <select class="fc-item-color">
                    <option value="">-- Selecciona color --</option>
                </select>
            </div>
            <div class="fc-form-group">
                <label>Nombre del destinatario</label>
                <input type="text" class="fc-item-destinatario" placeholder="¿A quién va dirigido?" value="${escHtml(prefill.destinatario || '')}" />
            </div>
            <div class="fc-form-group">
                <label>Teléfono del destinatario</label>
                <input type="tel" class="fc-item-dest-tel" placeholder="10 dígitos" inputmode="numeric" maxlength="15" value="${escHtml(prefill.destinatario_telefono || '')}" />
            </div>
            <div class="fc-form-group">
                <label>Teléfono del destinatario 2 <span style="font-weight:400;color:#94a3b8;">(opcional)</span></label>
                <input type="tel" class="fc-item-dest-tel2" placeholder="Número alternativo" inputmode="numeric" maxlength="15" value="${escHtml(prefill.destinatario_telefono2 || '')}" />
            </div>
            <div class="fc-form-group">
                <label>Mensaje de tarjeta</label>
                <textarea class="fc-item-tarjeta" rows="2" placeholder="Mensaje para incluir en la tarjeta...">${escHtml(prefill.mensaje_tarjeta || '')}</textarea>
            </div>
            <div class="fc-form-group fc-item-fotos-section">
                <label>Fotos adicionales</label>
                <input type="hidden" class="fc-item-fotos-json" value="" />
                <div class="fc-item-fotos-gallery"></div>
                <button type="button" class="fc-item-upload-foto-btn">📷 Añadir foto</button>
            </div>
        </div>`;
    }

    // ── Item block: wire events ──
    async function wireItemBlock(block, prefill = {}) {
        const searchInput = block.querySelector('.fc-item-arreglo-search');
        const dropdown    = block.querySelector('.fc-item-arreglo-dropdown');
        const idInput     = block.querySelector('.fc-item-arreglo-id');
        const nameInput   = block.querySelector('.fc-item-arreglo-nombre');
        const imgInput    = block.querySelector('.fc-item-imagen-url');
        const tamSelect   = block.querySelector('.fc-item-tamano');
        const colSelect   = block.querySelector('.fc-item-color');
        const colGroup    = block.querySelector('.fc-item-color-group');
        const removeBtn   = block.querySelector('.fc-item-remove-btn');

        // Solo números en teléfonos del destinatario
        const telInput = block.querySelector('.fc-item-dest-tel');
        if (telInput) {
            telInput.addEventListener('input', () => {
                telInput.value = telInput.value.replace(/\D/g, '');
            });
        }
        const tel2Input = block.querySelector('.fc-item-dest-tel2');
        if (tel2Input) {
            tel2Input.addEventListener('input', () => {
                tel2Input.value = tel2Input.value.replace(/\D/g, '');
            });
        }

        // Remove button
        removeBtn.addEventListener('click', () => {
            block.remove();
            renumberItemBlocks();
        });

        // Autocomplete search
        let dbt = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(dbt);
            const term = searchInput.value.trim();
            if (term.length < 2) { dropdown.classList.remove('open'); return; }
            dbt = setTimeout(async () => {
                try {
                    const data = await ajax('fc_panel_buscar_arreglos', { term });
                    if (data.success && data.data.arreglos.length) {
                        dropdown.innerHTML = data.data.arreglos.map(a =>
                            `<div class="fc-autocomplete-item" data-id="${a.id}" data-title="${escAttr(a.title)}">${escHtml(a.title)}</div>`
                        ).join('');
                        // Store tamanos data on items
                        dropdown._arreglosData = data.data.arreglos;
                        dropdown.classList.add('open');
                    } else {
                        dropdown.innerHTML = '<div class="fc-autocomplete-item">Sin resultados</div>';
                        dropdown.classList.add('open');
                    }
                } catch { dropdown.classList.remove('open'); }
            }, 350);
        });

        dropdown.addEventListener('click', (e) => {
            const item = e.target.closest('.fc-autocomplete-item');
            if (!item || !item.dataset.id) return;
            const id      = parseInt(item.dataset.id, 10);
            const arreglo = (dropdown._arreglosData || []).find(a => a.id === id);
            searchInput.value = arreglo?.title || '';
            if (idInput)   idInput.value   = id;
            if (nameInput) nameInput.value = arreglo?.title || '';
            dropdown.classList.remove('open');
            itemPopulateTamanos(tamSelect, colSelect, colGroup, imgInput, arreglo?.tamanos || []);
        });

        document.addEventListener('click', (e) => {
            if (!block.contains(e.target)) dropdown.classList.remove('open');
        });

        // Tamaño → colores
        tamSelect.addEventListener('change', () => {
            const tamanos = tamSelect._tamanos || [];
            const idx     = parseInt(tamSelect.value, 10);
            if (!isNaN(idx) && tamanos[idx]) {
                itemPopulateColores(colSelect, colGroup, tamanos[idx].colores || []);
                // Update imagen_url from tamaño
                const tamImg = tamanos[idx].imagen_url || '';
                if (imgInput) imgInput.value = tamImg;
            } else {
                colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';
                if (colGroup) colGroup.style.display = 'none';
            }
        });

        colSelect.addEventListener('change', () => {
            const tamanos = tamSelect._tamanos || [];
            const tIdx    = parseInt(tamSelect.value, 10);
            const cIdx    = parseInt(colSelect.value, 10);
            if (!isNaN(tIdx) && !isNaN(cIdx) && tamanos[tIdx]?.colores?.[cIdx]) {
                const colImg = tamanos[tIdx].colores[cIdx].imagen_url || '';
                if (imgInput && colImg) imgInput.value = colImg;
            }
        });

        // Pre-fill tamaño/color if editing
        if (prefill.arreglo_id) {
            try {
                const res = await ajax('fc_panel_get_arreglo', { arreglo_id: prefill.arreglo_id });
                if (res.success) {
                    itemPopulateTamanos(tamSelect, colSelect, colGroup, imgInput, res.data.tamanos || []);
                    // Pre-select tamaño by name
                    if (prefill.tamano) {
                        for (let i = 1; i < tamSelect.options.length; i++) {
                            const opt = tamSelect.options[i];
                            if ((opt.dataset.nombre || opt.text) === prefill.tamano) {
                                tamSelect.value = opt.value;
                                const idx = parseInt(opt.value, 10);
                                itemPopulateColores(colSelect, colGroup, res.data.tamanos[idx]?.colores || []);
                                // Pre-select color by name
                                if (prefill.color) {
                                    for (let j = 1; j < colSelect.options.length; j++) {
                                        if (colSelect.options[j].text === prefill.color) {
                                            colSelect.value = colSelect.options[j].value;
                                            // Update image
                                            const cIdx = parseInt(colSelect.value, 10);
                                            const colImg = res.data.tamanos[idx]?.colores?.[cIdx]?.imagen_url || '';
                                            if (imgInput && colImg) imgInput.value = colImg;
                                            break;
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }
                }
            } catch { /* falla silenciosa */ }
        }

        // Fotos adicionales en modal
        const fotosJsonInput = block.querySelector('.fc-item-fotos-json');
        const fotosGallery   = block.querySelector('.fc-item-fotos-gallery');
        const uploadFotoBtn  = block.querySelector('.fc-item-upload-foto-btn');

        // Assign fotos JSON via JS (not via HTML attribute) to avoid HTML-entity encoding issues
        if (fotosJsonInput) {
            fotosJsonInput.value = JSON.stringify(Array.isArray(prefill.fotos_extra) ? prefill.fotos_extra : []);
        }

        function renderModalFotos() {
            let urls = [];
            try { urls = JSON.parse(fotosJsonInput.value || '[]'); } catch { urls = []; }
            fotosGallery.innerHTML = urls.map((url, fi) =>
                `<div class="fc-item-foto-thumb" style="position:relative;display:inline-block;margin:3px;">
                    <img src="${escAttr(url)}" style="width:54px;height:54px;object-fit:cover;border-radius:6px;border:1.5px solid #f0c0d0;"
                         onerror="this.style.opacity='.3';this.style.border='2px dashed #f87171'" />
                    <button type="button" class="fc-item-foto-remove" data-idx="${fi}"
                            style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;border:none;border-radius:50%;width:16px;height:16px;font-size:10px;cursor:pointer;line-height:1;padding:0;">✕</button>
                </div>`
            ).join('');
            fotosGallery.querySelectorAll('.fc-item-foto-remove').forEach(rb => {
                rb.addEventListener('click', () => {
                    let urls2 = [];
                    try { urls2 = JSON.parse(fotosJsonInput.value || '[]'); } catch { urls2 = []; }
                    urls2.splice(parseInt(rb.dataset.idx, 10), 1);
                    fotosJsonInput.value = JSON.stringify(urls2);
                    renderModalFotos();
                });
            });
        }
        renderModalFotos();

        if (uploadFotoBtn) {
            uploadFotoBtn.addEventListener('click', () => {
                const fi = document.createElement('input');
                fi.type = 'file';
                fi.accept = 'image/*';
                fi.style.display = 'none';
                document.body.appendChild(fi);
                fi.addEventListener('change', async () => {
                    if (!fi.files[0]) { fi.remove(); return; }
                    uploadFotoBtn.textContent = '⏳ Subiendo...';
                    uploadFotoBtn.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'fc_panel_upload_foto');
                    fd.append('nonce', nonce);
                    fd.append('pedido_id', '0'); // temp upload, no pedido yet
                    fd.append('item_idx', '0');
                    fd.append('foto', fi.files[0]);
                    try {
                        const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            const urls = JSON.parse(fotosJsonInput.value || '[]');
                            urls.push(data.data.url);
                            fotosJsonInput.value = JSON.stringify(urls);
                            renderModalFotos();
                        } else { showToast(data.data?.message || 'Error al subir', 'error'); }
                    } catch { showToast('Error de conexión', 'error'); }
                    finally {
                        uploadFotoBtn.textContent = '📷 Añadir foto';
                        uploadFotoBtn.disabled = false;
                        fi.remove();
                    }
                });
                fi.click();
            });
        }
    }

    function itemPopulateTamanos(tamSelect, colSelect, colGroup, imgInput, tamanos) {
        tamSelect.innerHTML = '<option value="">-- Selecciona tamaño --</option>';
        tamSelect._tamanos  = tamanos;
        colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';
        if (colGroup) colGroup.style.display = 'none';

        tamanos.forEach((t, i) => {
            const opt = document.createElement('option');
            opt.value        = i;
            opt.dataset.nombre = t.nombre;
            opt.textContent  = t.nombre + (t.precio ? ` ($${Number(t.precio).toLocaleString('es-MX')})` : '');
            tamSelect.appendChild(opt);
        });
    }

    function itemPopulateColores(colSelect, colGroup, colores) {
        colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';
        if (!colores || colores.length === 0) {
            if (colGroup) colGroup.style.display = 'none';
            return;
        }
        if (colGroup) colGroup.style.display = '';
        colores.forEach((c, i) => {
            const opt = document.createElement('option');
            opt.value       = i;
            opt.textContent = c.nombre;
            colSelect.appendChild(opt);
        });
    }

    function renumberItemBlocks() {
        $$('.fc-item-block').forEach((block, i) => {
            block.dataset.itemIdx = i;
            const label = block.querySelector('.fc-item-block-label');
            if (label) label.textContent = `Arreglo ${i + 1}`;
        });
    }

    // ── Collect items from modal ──
    function collectItems() {
        return $$('.fc-item-block').map(block => {
            const tamSelect = block.querySelector('.fc-item-tamano');
            const colSelect = block.querySelector('.fc-item-color');
            const tamNombre = tamSelect?.selectedIndex > 0
                ? (tamSelect.options[tamSelect.selectedIndex].dataset.nombre || tamSelect.options[tamSelect.selectedIndex].text)
                : '';
            const colNombre = colSelect?.selectedIndex > 0
                ? colSelect.options[colSelect.selectedIndex].text
                : '';
            return {
                arreglo_id:            block.querySelector('.fc-item-arreglo-id')?.value     || '',
                arreglo_nombre:        block.querySelector('.fc-item-arreglo-nombre')?.value || '',
                imagen_url:            block.querySelector('.fc-item-imagen-url')?.value     || '',
                tamano:                tamNombre,
                color:                 colNombre,
                destinatario:           block.querySelector('.fc-item-destinatario')?.value  || '',
                destinatario_telefono:  block.querySelector('.fc-item-dest-tel')?.value      || '',
                destinatario_telefono2: block.querySelector('.fc-item-dest-tel2')?.value     || '',
                mensaje_tarjeta:        block.querySelector('.fc-item-tarjeta')?.value       || '',
                fotos_extra:            (() => { try { return JSON.parse(block.querySelector('.fc-item-fotos-json')?.value || '[]'); } catch { return []; } })(),
            };
        });
    }

    // ── New order modal ──
    function initNewOrderModal() {
        const overlay = $('#fc-modal-overlay');
        const openBtn = $('#fc-btn-new-pedido');
        const closeBtn = $('#fc-modal-close');

        if (!overlay) return;

        if (openBtn) {
            openBtn.addEventListener('click', () => {
                overlay.classList.add('open');
                resetNewOrderForm();
            });
        }

        const pendienteBtn = $('#fc-btn-new-pendiente');
        if (pendienteBtn) {
            pendienteBtn.addEventListener('click', () => {
                overlay.classList.add('open');
                resetNewOrderForm();           // resetea isPendienteMode a false
                isPendienteMode = true;        // luego lo activamos
                const title = $('#fc-modal-title');
                if (title) title.textContent = 'Nuevo pedido pendiente';
                const submitBtn2 = $('#fc-submit-pedido');
                if (submitBtn2) { submitBtn2.style.display = ''; submitBtn2.textContent = 'Guardar como pendiente'; }
            });
        }

        closeBtn && closeBtn.addEventListener('click', () => {
            overlay.classList.remove('open');
        });

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.classList.remove('open');
        });

        // Tipo toggle
        $$('.fc-tipo-option').forEach(opt => {
            opt.addEventListener('click', () => {
                $$('.fc-tipo-option').forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
                const tipo = opt.dataset.tipo;
                const envioSection       = $('#fc-modal-envio-section');
                const recoleccionSection = $('#fc-modal-recoleccion-section');
                if (tipo === 'envio') {
                    if (envioSection)       envioSection.style.display      = '';
                    if (recoleccionSection) recoleccionSection.style.display = 'none';
                } else {
                    if (envioSection)       envioSection.style.display      = 'none';
                    if (recoleccionSection) recoleccionSection.style.display = '';
                }
            });
        });

        // Fecha → poblar horarios
        const fechaInput = $('#fc-modal-fecha');
        if (fechaInput) {
            fechaInput.addEventListener('change', function () {
                updateHorarioSelect(this.value);
            });
        }

        // Canal de contacto
        const canalSelect = $('#fc-modal-canal');
        if (canalSelect) {
            canalSelect.addEventListener('change', () => updateCanalField(canalSelect.value));
        }

        // Add item button
        const addItemBtn = $('#fc-add-item-btn');
        if (addItemBtn) {
            addItemBtn.addEventListener('click', () => addItemBlock());
        }

        // PDF upload button — usa wp.media si está disponible (admin), si no sube directo
        const pdfUploadBtn = $('#fc-modal-upload-pdf-btn');
        if (pdfUploadBtn) {
            let pdfMediaFrame = null;

            function setPdfResult(url, fname) {
                const pdfInput  = $('#fc-modal-pdf-url');
                const pdfStatus = $('#fc-modal-pdf-status');
                const pdfLink   = $('#fc-modal-pdf-link');
                const pdfName   = $('#fc-modal-pdf-name');
                if (pdfInput)  pdfInput.value          = url;
                if (pdfName)   pdfName.textContent     = decodeURIComponent(fname);
                if (pdfLink)   pdfLink.href            = url;
                if (pdfStatus) pdfStatus.style.display = '';
                pdfUploadBtn.style.display = 'none';
            }

            pdfUploadBtn.addEventListener('click', () => {
                // Si wp.media está disponible (contexto admin), úsarlo
                if (typeof window.wp !== 'undefined' && window.wp.media) {
                    if (!pdfMediaFrame) {
                        pdfMediaFrame = window.wp.media({
                            title:    'Seleccionar PDF del pedido',
                            button:   { text: 'Usar este PDF' },
                            multiple: false,
                            library:  { type: 'application/pdf' },
                        });
                        pdfMediaFrame.on('select', () => {
                            const att   = pdfMediaFrame.state().get('selection').first().toJSON();
                            const url   = att.url;
                            const fname = att.filename || url.split('/').pop().split('?')[0];
                            setPdfResult(url, fname);
                        });
                    }
                    pdfMediaFrame.open();
                    return;
                }

                // Fallback: file input + subida directa por AJAX
                const fi = document.createElement('input');
                fi.type   = 'file';
                fi.accept = 'application/pdf';
                fi.style.display = 'none';
                document.body.appendChild(fi);
                fi.addEventListener('change', async () => {
                    if (!fi.files[0]) { fi.remove(); return; }
                    const origText           = pdfUploadBtn.textContent;
                    pdfUploadBtn.textContent = '⏳ Subiendo...';
                    pdfUploadBtn.disabled    = true;
                    const fd = new FormData();
                    fd.append('action', 'fc_panel_upload_pdf');
                    fd.append('nonce', nonce);
                    fd.append('pdf', fi.files[0]);
                    try {
                        const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            const url   = data.data.url;
                            const fname = url.split('/').pop().split('?')[0];
                            setPdfResult(url, fname);
                            showToast('PDF subido correctamente', 'success');
                        } else {
                            showToast(data.data?.message || 'Error al subir PDF', 'error');
                        }
                    } catch { showToast('Error de conexión', 'error'); }
                    finally {
                        pdfUploadBtn.textContent = origText;
                        pdfUploadBtn.disabled    = false;
                        fi.remove();
                    }
                });
                fi.click();
            });
        }

        // PDF quitar button
        const pdfQuitarBtn = $('#fc-modal-pdf-quitar');
        if (pdfQuitarBtn) {
            pdfQuitarBtn.addEventListener('click', () => {
                const pdfInput  = $('#fc-modal-pdf-url');
                const pdfStatus = $('#fc-modal-pdf-status');
                const pdfAddBtn = $('#fc-modal-upload-pdf-btn');
                if (pdfInput)  pdfInput.value          = '';
                if (pdfStatus) pdfStatus.style.display  = 'none';
                if (pdfAddBtn) pdfAddBtn.style.display  = '';
            });
        }

        // Submit
        const form = $('#fc-new-pedido-form');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await submitNewPedido();
            });
        }
    }

    async function addItemBlock(prefill = {}) {
        const container = $('#fc-items-container');
        if (!container) return;
        const idx = $$('.fc-item-block').length;
        const temp = document.createElement('div');
        temp.innerHTML = buildItemBlockHTML(idx, prefill);
        const block = temp.firstElementChild;
        container.appendChild(block);
        await wireItemBlock(block, prefill);
    }

    function updateHorarioSelect(fechaVal) {
        const horarioEl = $('#fc-modal-horario');
        if (!horarioEl) return;

        if (!fechaVal) {
            horarioEl.innerHTML = '<option value="">-- Selecciona fecha primero --</option>';
            return;
        }

        const date   = new Date(fechaVal + 'T12:00:00');
        const dayKey = String(date.getDay()); // 0=Dom, 1=Lun...

        // Domingo: solo permitir si es fecha especial
        if (dayKey === '0') {
            const mm   = String(date.getMonth() + 1).padStart(2, '0');
            const dd   = String(date.getDate()).padStart(2, '0');
            const ddmm = `${dd}/${mm}`;
            const fechasEspeciales = (window.fcPanel || {}).fechasEspeciales || [];
            if (!fechasEspeciales.includes(ddmm)) {
                horarioEl.innerHTML = '<option value="">No hay horarios para este día</option>';
                return;
            }
        }

        const slots = schedules[dayKey] || [];

        if (slots.length === 0) {
            horarioEl.innerHTML = '<option value="">No hay horarios para este día</option>';
            return;
        }

        horarioEl.innerHTML = '<option value="">-- Selecciona horario --</option>' +
            slots.map(s => `<option value="${escAttr(s)}">${escHtml(s)}</option>`).join('');
    }

    // ── Open modal in EDIT mode ──
    async function openEditModal(pedido) {
        currentEditId = pedido.id;

        const overlay = $('#fc-modal-overlay');
        if (overlay) overlay.classList.add('open');

        const title = $('#fc-modal-title');
        if (title) title.textContent = `Editar pedido ${pedido.numero}`;

        const submitBtn = $('#fc-submit-pedido');
        if (submitBtn) { submitBtn.style.display = ''; submitBtn.textContent = 'Guardar cambios'; submitBtn.disabled = false; }

        const successBox = $('#fc-pedido-success');
        if (successBox) { successBox.classList.remove('show'); successBox.style.display = 'none'; }

        // Tipo
        $$('.fc-tipo-option').forEach(o => o.classList.remove('active'));
        const tipoBtn = $(`.fc-tipo-option[data-tipo="${pedido.tipo || 'envio'}"]`);
        if (tipoBtn) tipoBtn.classList.add('active');
        const envioSec = $('#fc-modal-envio-section');
        const recSec   = $('#fc-modal-recoleccion-section');
        if (pedido.tipo === 'recoleccion') {
            if (envioSec) envioSec.style.display = 'none';
            if (recSec)   recSec.style.display   = '';
        } else {
            if (envioSec) envioSec.style.display = '';
            if (recSec)   recSec.style.display   = 'none';
        }

        // Fecha y horario
        const fechaInput = $('#fc-modal-fecha');
        if (fechaInput) { fechaInput.value = pedido.fecha || ''; updateHorarioSelect(pedido.fecha || ''); }
        requestAnimationFrame(() => {
            const horarioEl = $('#fc-modal-horario');
            if (horarioEl && pedido.horario) horarioEl.value = pedido.horario;
        });

        // Canal de contacto
        const canalEl = $('#fc-modal-canal');
        if (canalEl) {
            canalEl.value = pedido.canal || '';
            updateCanalField(pedido.canal || '');
            requestAnimationFrame(() => {
                const contactoEl = $('#fc-modal-canal-contacto');
                if (contactoEl) contactoEl.value = pedido.canal_contacto || '';
                const nombreEl = $('#fc-modal-canal-nombre');
                if (nombreEl) nombreEl.value = pedido.canal_nombre || '';
            });
        }

        // Campos simples
        const dirVal = pedido.direccion || '';
        if (window._fcSetDireccionPac) {
            window._fcSetDireccionPac(dirVal);
        } else {
            const dirEl = $('#fc-modal-direccion'); if (dirEl) dirEl.value = dirVal;
        }
        const horaEl   = $('#fc-modal-hora-recoleccion'); if (horaEl) horaEl.value   = pedido.hora_recoleccion || '';
        const notaEl   = $('#fc-modal-nota');           if (notaEl)   notaEl.value   = pedido.nota            || '';

        // PDF
        const pdfUrl    = pedido.pdf_url || '';
        const pdfStatus = $('#fc-modal-pdf-status');
        const pdfLink   = $('#fc-modal-pdf-link');
        const pdfName   = $('#fc-modal-pdf-name');
        const pdfInput  = $('#fc-modal-pdf-url');
        const pdfAddBtn = $('#fc-modal-upload-pdf-btn');
        if (pdfInput) pdfInput.value = pdfUrl;
        if (pdfUrl) {
            const fname = pdfUrl.split('/').pop().split('?')[0];
            if (pdfName)   pdfName.textContent = decodeURIComponent(fname);
            if (pdfLink)   pdfLink.href        = pdfUrl;
            if (pdfStatus) pdfStatus.style.display = '';
            if (pdfAddBtn) pdfAddBtn.style.display  = 'none';
        } else {
            if (pdfStatus) pdfStatus.style.display = 'none';
            if (pdfAddBtn) pdfAddBtn.style.display  = '';
        }

        // Items — clear container and populate from pedido.items
        const container = $('#fc-items-container');
        if (container) container.innerHTML = '';
        const items = (pedido.items && pedido.items.length) ? pedido.items : [];
        if (items.length) {
            for (const item of items) {
                await addItemBlock(item);
            }
        } else {
            await addItemBlock();
        }
    }

    function resetNewOrderForm() {
        isPendienteMode = false;
        currentEditId = null;
        const form = $('#fc-new-pedido-form');
        if (form) form.reset();

        const title = $('#fc-modal-title');
        if (title) title.textContent = 'Nuevo pedido';

        const successBox = $('#fc-pedido-success');
        if (successBox) { successBox.classList.remove('show'); successBox.style.display = 'none'; }

        const submitBtn = $('#fc-submit-pedido');
        if (submitBtn) { submitBtn.style.display = ''; submitBtn.textContent = 'Registrar pedido'; submitBtn.disabled = false; }

        // Clear items container and start with one blank block
        const container = $('#fc-items-container');
        if (container) container.innerHTML = '';
        addItemBlock();

        const horarioSelect = $('#fc-modal-horario');
        if (horarioSelect) horarioSelect.innerHTML = '<option value="">-- Selecciona fecha primero --</option>';

        const canalSel = $('#fc-modal-canal');
        if (canalSel) { canalSel.value = ''; updateCanalField(''); }

        // Reset PDF
        const pdfInput2  = $('#fc-modal-pdf-url');
        const pdfStatus2 = $('#fc-modal-pdf-status');
        const pdfAddBtn2 = $('#fc-modal-upload-pdf-btn');
        if (pdfInput2)  pdfInput2.value         = '';
        if (pdfStatus2) pdfStatus2.style.display = 'none';
        if (pdfAddBtn2) pdfAddBtn2.style.display = '';

        // Reset tipo
        $$('.fc-tipo-option').forEach(o => o.classList.remove('active'));
        const firstTipo = $('.fc-tipo-option[data-tipo="envio"]');
        if (firstTipo) firstTipo.classList.add('active');

        const envioSection = $('#fc-modal-envio-section');
        const recSection   = $('#fc-modal-recoleccion-section');
        if (envioSection)  envioSection.style.display  = '';
        if (recSection)    recSection.style.display    = 'none';
    }

    async function submitNewPedido() {
        const btn = $('#fc-submit-pedido');

        // Validar canal obligatorio
        const canalVal = $('#fc-modal-canal')?.value || '';
        if (!canalVal) {
            showToast('Selecciona el canal de contacto', 'error');
            $('#fc-modal-canal')?.focus();
            return;
        }

        // Validar al menos un arreglo con nombre
        const items = collectItems();
        if (!items.length || !items[0].arreglo_nombre) {
            showToast('Agrega al menos un arreglo al pedido', 'error');
            return;
        }

        btn.textContent = currentEditId ? 'Guardando...' : 'Registrando...';
        btn.disabled    = true;

        const tipo = ($('.fc-tipo-option.active') || {}).dataset?.tipo || 'envio';

        const payload = {
            tipo,
            fecha:            $('#fc-modal-fecha')?.value            || '',
            horario:          $('#fc-modal-horario')?.value          || '',
            hora_recoleccion: $('#fc-modal-hora-recoleccion')?.value || '',
            direccion:        $('#fc-modal-direccion')?.value        || '',
            canal:            canalVal,
            canal_nombre:     $('#fc-modal-canal-nombre')?.value     || '',
            canal_contacto:   $('#fc-modal-canal-contacto')?.value   || '',
            nota:             $('#fc-modal-nota')?.value             || '',
            pdf_url:          $('#fc-modal-pdf-url')?.value          || '',
            items_json:       JSON.stringify(items),
        };

        if (isPendienteMode) payload.modo = 'pendiente';

        const action = currentEditId ? 'fc_panel_actualizar_pedido' : 'fc_panel_crear_pedido';
        if (currentEditId) payload.pedido_id = currentEditId;

        try {
            const data = await ajax(action, payload);
            if (data.success) {
                if (isPendienteMode) {
                    showToast('Pedido guardado como pendiente', 'success');
                    const overlay2 = $('#fc-modal-overlay');
                    if (overlay2) overlay2.classList.remove('open');
                    isPendienteMode = false;
                    btn.textContent = 'Guardar como pendiente';
                    btn.disabled    = false;
                    if (isAdmin) {
                        setTimeout(() => {
                            const url = new URL(window.location.href);
                            url.searchParams.set('view', 'pendiente');
                            url.searchParams.set('pendiente_created', '1');
                            window.location.href = url.toString();
                        }, 800);
                    } else {
                        setTimeout(() => loadPedidos(currentFilter, getCurrentFecha()), 600);
                    }
                } else if (currentEditId) {
                    // Modo edición — cerrar modal y recargar
                    showToast('Pedido actualizado', 'success');
                    btn.textContent = 'Guardar cambios';
                    btn.disabled    = false;
                    const overlay = $('#fc-modal-overlay');
                    if (overlay) overlay.classList.remove('open');
                    setTimeout(() => loadPedidos(currentFilter, getCurrentFecha()), 600);
                } else {
                    // Modo creación — mostrar success box
                    const successBox = $('#fc-pedido-success');
                    const linkEl     = $('#fc-pedido-link');
                    const numEl      = $('#fc-pedido-num-result');
                    if (numEl)      numEl.textContent  = data.data.numero;
                    if (linkEl)     linkEl.textContent = data.data.client_url;
                    if (successBox) {
                        successBox.classList.add('show');
                        successBox.style.display = '';
                        successBox.dataset.url   = data.data.client_url;
                    }
                    btn.style.display = 'none';
                    btn.disabled      = false;
                    showToast('Pedido registrado', 'success');
                    setTimeout(() => loadPedidos(currentFilter, getCurrentFecha()), 1500);
                }
            } else {
                showToast(data.data?.message || 'Error', 'error');
                btn.textContent = currentEditId ? 'Guardar cambios' : 'Registrar pedido';
                btn.disabled    = false;
            }
        } catch {
            showToast('Error de conexión', 'error');
            btn.textContent = currentEditId ? 'Guardar cambios' : 'Registrar pedido';
            btn.disabled    = false;
        }
    }

    // ── Copy link from success box ──
    function initCopySuccessLink() {
        document.addEventListener('click', (e) => {
            if (e.target.matches('#fc-copy-link-btn')) {
                const box = e.target.closest('.fc-success-box');
                const url = box?.dataset.url || '';
                if (url) copyToClipboard(url);
            }
        });
    }

    // ── Papelera ──
    async function loadPapelera() {
        const grid = $('#fc-orders-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="fc-loading">Cargando papelera...</div>';

        try {
            const data = await ajax('fc_panel_get_papelera');
            if (!data.success) {
                grid.innerHTML = '<div class="fc-no-pedidos">Error al cargar la papelera.</div>';
                return;
            }
            const pedidos = data.data.pedidos;
            pedidoDataMap = {};
            pedidos.forEach(p => { pedidoDataMap[p.id] = p; });
            if (!pedidos.length) {
                grid.innerHTML = '<div class="fc-no-pedidos">🗑 La papelera está vacía.</div>';
            } else {
                grid.innerHTML = pedidos.map(renderTrashCard).join('');
                attachTrashCardEvents(grid);
            }
        } catch {
            grid.innerHTML = '<div class="fc-no-pedidos">Error de conexión.</div>';
        }
    }

    function renderTrashCard(p) {
        const isMobile = window.innerWidth <= 640;
        const tipoLabel = p.tipo === 'envio' ? 'Envío' : 'Recolección';

        return `
<div class="fc-order-card fc-order-card-trash${isMobile ? ' collapsed' : ''}" data-id="${p.id}">
    <div class="fc-card-header">
        <span class="fc-order-num">${escHtml(p.numero)}</span>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:11px;color:#a0aec0;font-style:italic;">En papelera</span>
            <button class="fc-card-collapse-btn" aria-label="Colapsar">&#9662;</button>
        </div>
    </div>
    <div class="fc-card-collapsible">
        <div class="fc-card-info">
            <div class="fc-card-row"><span class="fc-label">Cliente</span><span class="fc-value">${escHtml(p.cliente_nombre)}${p.cliente_telefono ? ' · ' + telLink(p.cliente_telefono) : ''}</span></div>
            <div class="fc-card-row"><span class="fc-label">Arreglo</span><span class="fc-value">${escHtml(p.arreglo_nombre)}${p.tamano ? ' — ' + escHtml(p.tamano) : ''}</span></div>
            <div class="fc-card-row"><span class="fc-label">Fecha</span><span class="fc-value">${escHtml(p.fecha)} · ${escHtml(tipoLabel)}</span></div>
            ${p.destinatario ? `<div class="fc-card-row"><span class="fc-label">Para</span><span class="fc-value">${escHtml(p.destinatario)}</span></div>` : ''}
        </div>
        <hr class="fc-card-divider" />
        <div class="fc-card-extra-actions">
            <button class="fc-btn-sm fc-btn-restaurar-pedido" style="background:#059669;">&#8635; Restaurar</button>
            <button class="fc-btn-sm fc-btn-eliminar-permanente" style="background:#dc2626;">&#10005; Eliminar permanentemente</button>
        </div>
    </div>
</div>`;
    }

    function attachTrashCardEvents(grid) {
        // Collapse
        $$('.fc-card-collapse-btn', grid).forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                btn.closest('.fc-order-card').classList.toggle('collapsed');
            });
        });

        // Restaurar
        $$('.fc-btn-restaurar-pedido', grid).forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const card   = btn.closest('.fc-order-card');
                const cardId = parseInt(card.dataset.id, 10);
                if (!confirm('¿Restaurar este pedido?')) return;
                btn.textContent = '...';
                btn.disabled    = true;
                try {
                    const data = await ajax('fc_panel_restaurar_pedido', { pedido_id: cardId });
                    if (data.success) {
                        card.remove();
                        delete pedidoDataMap[cardId];
                        showToast('Pedido restaurado', 'success');
                        if (!$('#fc-orders-grid .fc-order-card')) {
                            $('#fc-orders-grid').innerHTML = '<div class="fc-no-pedidos">🗑 La papelera está vacía.</div>';
                        }
                    } else {
                        showToast(data.data?.message || 'Error', 'error');
                        btn.textContent = '↺ Restaurar';
                        btn.disabled    = false;
                    }
                } catch {
                    showToast('Error de conexión', 'error');
                    btn.textContent = '↺ Restaurar';
                    btn.disabled    = false;
                }
            });
        });

        // Eliminar permanentemente
        $$('.fc-btn-eliminar-permanente', grid).forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const card   = btn.closest('.fc-order-card');
                const cardId = parseInt(card.dataset.id, 10);
                const num    = pedidoDataMap[cardId]?.numero || cardId;
                if (!confirm(`¿Eliminar permanentemente el pedido ${num}?\nEsta acción NO se puede deshacer.`)) return;
                btn.textContent = '...';
                btn.disabled    = true;
                try {
                    const data = await ajax('fc_panel_eliminar_permanente', { pedido_id: cardId });
                    if (data.success) {
                        card.remove();
                        delete pedidoDataMap[cardId];
                        showToast('Pedido eliminado permanentemente', 'success');
                        if (!$('#fc-orders-grid .fc-order-card')) {
                            $('#fc-orders-grid').innerHTML = '<div class="fc-no-pedidos">🗑 La papelera está vacía.</div>';
                        }
                    } else {
                        showToast(data.data?.message || 'Error', 'error');
                        btn.textContent = '✕ Eliminar permanentemente';
                        btn.disabled    = false;
                    }
                } catch {
                    showToast('Error de conexión', 'error');
                    btn.textContent = '✕ Eliminar permanentemente';
                    btn.disabled    = false;
                }
            });
        });
    }

    // ── Google Places Autocomplete en campo de dirección ──
    // ── Google Places Autocomplete (PlaceAutocompleteElement — nueva API) ──
    function initDireccionAutocomplete() {
        if (
            !window.google ||
            !window.google.maps ||
            !window.google.maps.places ||
            typeof window.google.maps.places.PlaceAutocompleteElement === 'undefined'
        ) return;

        const inputEl = document.getElementById('fc-modal-direccion');
        if (!inputEl) return;

        try {
            const pac = new google.maps.places.PlaceAutocompleteElement({
                componentRestrictions: { country: 'mx' },
            });
            pac.id = 'fc-direccion-pac';

            // Insertar el elemento antes del input original y ocultar el original
            inputEl.parentNode.insertBefore(pac, inputEl);
            inputEl.style.display = 'none';

            // Al seleccionar una sugerencia, llenar el input oculto
            pac.addEventListener('gmp-select', function(event) {
                const pred = event.placePrediction;
                if (!pred) return;
                const place = pred.toPlace();
                place.fetchFields({ fields: ['displayName', 'formattedAddress'] }).then(function() {
                    const name = place.displayName || '';
                    const addr = place.formattedAddress || '';
                    inputEl.value = (name && !addr.startsWith(name)) ? name + ', ' + addr : addr;
                });
            });

            // Sincronizar texto escrito manualmente al input oculto
            const syncShadowInput = function() {
                const si = pac.shadowRoot && pac.shadowRoot.querySelector('input');
                if (si) {
                    si.addEventListener('input', function() { inputEl.value = si.value; });
                    if (inputEl.value) si.value = inputEl.value;
                } else {
                    setTimeout(syncShadowInput, 150);
                }
            };
            syncShadowInput();

            // Exponer función para pre-llenar desde modal de edición
            window._fcSetDireccionPac = function(val) {
                inputEl.value = val || '';
                const si = pac.shadowRoot && pac.shadowRoot.querySelector('input');
                if (si) si.value = val || '';
            };

        } catch (e) {
            // Fallback: mostrar input original si Places falla
            inputEl.style.display = '';
            console.warn('Google Places no disponible:', e);
        }
    }

    // ── Limpiar parámetro nc= (cache-buster) de la URL sin recargar ──
    (function cleanNcParam() {
        const url = new URL(window.location.href);
        if (url.searchParams.has('nc')) {
            url.searchParams.delete('nc');
            window.history.replaceState(null, '', url.pathname + (url.search === '?' ? '' : url.search));
        }
    })();

    // ── Init ──
    function init() {
        // Modal y copy link funcionan tanto en el panel como en admin
        initNewOrderModal();
        initCopySuccessLink();
        initDireccionAutocomplete();

        const isPanel = document.body.classList.contains('fc-panel-body') ||
            document.querySelector('.fc-panel-body');

        if (!isPanel) return;

        initLoginForm();
        initLogout();
        initSearch();
        initFilterTabs();
        initDateFilter();
        initLightbox();

        // Load orders if logged in (panel header present)
        if ($('#fc-panel-header')) {
            // Pre-set date filter to today (Tijuana) and load only today's orders
            const fechaInput = $('#fc-fecha-filter');
            if (fechaInput && today) fechaInput.value = today;
            loadPedidos('all', today);
        }
    }

    // Expose openEditModal for admin page use
    window._fcOpenEditModal = openEditModal;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
