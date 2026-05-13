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
    let modalItemCounter = 0;      // unique index per item block

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
            const thumb = item.imagen_url
                ? `<div class="fc-card-item-thumb-wrap" data-lightbox="${escAttr(item.imagen_url)}" title="Ver foto">
                     <img class="fc-card-item-thumb" src="${escAttr(item.imagen_url)}" alt="" loading="lazy" />
                   </div>`
                : `<div class="fc-card-item-thumb-empty">&#127800;</div>`;
            const sub = [item.tamano, (item.color && !item.color.startsWith('--')) ? item.color : ''].filter(Boolean).join(' · ');
            const destLine = item.destinatario
                ? `<span class="fc-card-item-dest">Para: ${escHtml(item.destinatario)}${item.destinatario_telefono ? ' · ' + telLink(item.destinatario_telefono) : ''}${item.destinatario_telefono2 ? ' · ' + telLink(item.destinatario_telefono2) : ''}</span>`
                : '';
            const tarjetaLine = item.mensaje_tarjeta
                ? `<span class="fc-card-item-tarjeta">"${escHtml(item.mensaje_tarjeta)}"</span>`
                : '';
            return `
            <div class="fc-card-item">
                ${thumb}
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

    // ── Lightbox ──
    function initLightbox() {
        if (document.getElementById('fc-lightbox')) return; // already created
        const lb = document.createElement('div');
        lb.id = 'fc-lightbox';
        lb.className = 'fc-lightbox';
        lb.innerHTML = `
            <button class="fc-lightbox-close" aria-label="Cerrar">&times;</button>
            <img class="fc-lightbox-img" src="" alt="Arreglo" />
        `;
        document.body.appendChild(lb);

        lb.addEventListener('click', (e) => {
            if (e.target === lb || e.target.matches('.fc-lightbox-close')) {
                lb.classList.remove('open');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') lb.classList.remove('open');
        });
    }

    function openLightbox(src) {
        const lb = document.getElementById('fc-lightbox');
        if (!lb) return;
        lb.querySelector('.fc-lightbox-img').src = src;
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

        // Thumbnail lightbox (items)
        $$('.fc-card-item-thumb-wrap', grid).forEach(wrap => {
            wrap.addEventListener('click', () => openLightbox(wrap.dataset.lightbox));
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

        // Agregar pestaña papelera (solo admins) antes de construir el select
        if (isAdmin && tabContainer) {
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
                const data = await ajax('fc_panel_login', { username, password });
                if (data.success) {
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
            };
        });
    }

    // ── New order modal ──
    function initNewOrderModal() {
        const overlay = $('#fc-modal-overlay');
        const openBtn = $('#fc-btn-new-pedido');
        const closeBtn = $('#fc-modal-close');

        if (!overlay || !openBtn) return;

        openBtn.addEventListener('click', () => {
            overlay.classList.add('open');
            resetNewOrderForm();
        });

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
        if (submitBtn) { submitBtn.style.display = ''; submitBtn.textContent = 'Guardar cambios'; }

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
        currentEditId = null;
        const form = $('#fc-new-pedido-form');
        if (form) form.reset();

        const title = $('#fc-modal-title');
        if (title) title.textContent = 'Nuevo pedido';

        const successBox = $('#fc-pedido-success');
        if (successBox) { successBox.classList.remove('show'); successBox.style.display = 'none'; }

        const submitBtn = $('#fc-submit-pedido');
        if (submitBtn) { submitBtn.style.display = ''; submitBtn.textContent = 'Registrar pedido'; }

        // Clear items container and start with one blank block
        const container = $('#fc-items-container');
        if (container) container.innerHTML = '';
        addItemBlock();

        const horarioSelect = $('#fc-modal-horario');
        if (horarioSelect) horarioSelect.innerHTML = '<option value="">-- Selecciona fecha primero --</option>';

        const canalSel = $('#fc-modal-canal');
        if (canalSel) { canalSel.value = ''; updateCanalField(''); }

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
            items_json:       JSON.stringify(items),
        };

        const action = currentEditId ? 'fc_panel_actualizar_pedido' : 'fc_panel_crear_pedido';
        if (currentEditId) payload.pedido_id = currentEditId;

        try {
            const data = await ajax(action, payload);
            if (data.success) {
                if (currentEditId) {
                    // Modo edición — cerrar modal y recargar
                    showToast('Pedido actualizado', 'success');
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
