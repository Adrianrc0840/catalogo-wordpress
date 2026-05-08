/* ── Florería Panel Florista — Vanilla JS ── */
(function () {
    'use strict';

    const { ajaxurl, nonce, siteurl, schedules = {}, isAdmin = false, today = '' } = window.fcPanel || {};

    // ── Status labels ──
    const STATUS_LABELS = {
        recibido:          'Recibido',
        en_preparacion:    'En preparación',
        en_camino:         'En camino',
        listo_recoleccion: 'Listo para recolección',
        entregado:         'Entregado',
    };

    // ── State ──
    let currentFilter    = 'all';
    let allArreglos      = [];
    let selectedArreglo  = null;
    let currentEditId    = null;   // null = crear, número = editar
    let pedidoDataMap    = {};     // id → datos completos del pedido
    let isPapeleraView   = false;

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
        const d = new Date(ts.replace(' ', 'T'));
        return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' })
            + ' ' + d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
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

        const tipoLabel = p.tipo === 'envio' ? 'Envío a domicilio' : 'Recolección en tienda';
        const horarioLabel = p.tipo === 'envio' ? p.horario : p.hora_recoleccion;

        const thumbHtml = p.arreglo_thumb
            ? `<div class="fc-card-thumb-wrap" data-lightbox="${escAttr(p.arreglo_thumb)}" title="Ver foto">
                <img class="fc-card-thumb" src="${escAttr(p.arreglo_thumb)}" alt="Foto del arreglo" loading="lazy" />
               </div>`
            : '';

        const isMobile = window.innerWidth <= 640;

        return `
<div class="fc-order-card${isMobile ? ' collapsed' : ''}" data-status="${escAttr(p.status)}" data-id="${p.id}">
    <div class="fc-card-header">
        <span class="fc-order-num">${escHtml(p.numero)}</span>
        <div style="display:flex;align-items:center;gap:8px;">
            ${renderBadge(p.status)}
            <button class="fc-card-collapse-btn" aria-label="Colapsar">&#9662;</button>
        </div>
    </div>

    <div class="fc-card-collapsible">
        <div class="fc-card-info">
            ${thumbHtml}
            <div class="fc-card-row">
                <span class="fc-label">Arreglo</span>
                <span class="fc-value">${escHtml(p.arreglo_nombre)} — ${escHtml(p.tamano)}${(p.color && !p.color.startsWith('--')) ? ' / ' + escHtml(p.color) : ''}</span>
            </div>
            <div class="fc-card-row">
                <span class="fc-label">Cliente</span>
                <span class="fc-value">${escHtml(p.cliente_nombre)} · ${escHtml(p.cliente_telefono)}</span>
            </div>
            <div class="fc-card-row">
                <span class="fc-label">Entrega</span>
                <span class="fc-value">${escHtml(tipoLabel)} · ${escHtml(p.fecha)}${horarioLabel ? ' · ' + escHtml(horarioLabel) : ''}</span>
            </div>
            ${p.tipo === 'envio' && p.direccion ? `<div class="fc-card-row"><span class="fc-label">Dirección</span><span class="fc-value">${escHtml(p.direccion)}</span></div>` : ''}
            ${p.destinatario ? `<div class="fc-card-row"><span class="fc-label">Destinatario</span><span class="fc-value">${escHtml(p.destinatario)}</span></div>` : ''}
            ${p.mensaje_tarjeta ? `<div class="fc-card-row"><span class="fc-label">Tarjeta</span><span class="fc-value">"${escHtml(p.mensaje_tarjeta)}"</span></div>` : ''}
            ${p.nota ? `<div class="fc-card-row"><span class="fc-label">Nota</span><span class="fc-value">${escHtml(p.nota)}</span></div>` : ''}
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

        // Thumbnail lightbox
        $$('.fc-card-thumb-wrap', grid).forEach(wrap => {
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
                const envioSection      = $('#fc-modal-envio-section');
                const recoleccionSection = $('#fc-modal-recoleccion-section');
                if (tipo === 'envio') {
                    if (envioSection)      envioSection.style.display      = '';
                    if (recoleccionSection) recoleccionSection.style.display = 'none';
                } else {
                    if (envioSection)      envioSection.style.display      = 'none';
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

        // Solo números en teléfono
        const telInput = $('#fc-modal-cliente-telefono');
        if (telInput) {
            telInput.addEventListener('input', () => {
                telInput.value = telInput.value.replace(/\D/g, '');
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

    function updateHorarioSelect(fechaVal) {
        const horarioEl = $('#fc-modal-horario');
        if (!horarioEl) return;

        if (!fechaVal) {
            horarioEl.innerHTML = '<option value="">-- Selecciona fecha primero --</option>';
            return;
        }

        const date    = new Date(fechaVal + 'T12:00:00');
        const dayKey  = String(date.getDay()); // 0=Dom, 1=Lun...
        const slots   = schedules[dayKey] || [];

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

        // Arreglo fields
        const searchInput = $('#fc-arreglo-search');
        const idInput     = $('#fc-arreglo-id');
        const nameInput   = $('#fc-arreglo-nombre');
        if (searchInput) searchInput.value = pedido.arreglo_nombre || '';
        if (idInput)     idInput.value     = pedido.arreglo_id    || '';
        if (nameInput)   nameInput.value   = pedido.arreglo_nombre || '';

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

        // Campos simples
        const simpleFields = {
            '#fc-modal-direccion':        pedido.direccion,
            '#fc-modal-hora-recoleccion': pedido.hora_recoleccion,
            '#fc-modal-cliente-nombre':   pedido.cliente_nombre,
            '#fc-modal-cliente-telefono': pedido.cliente_telefono,
            '#fc-modal-destinatario':     pedido.destinatario,
            '#fc-modal-mensaje-tarjeta':  pedido.mensaje_tarjeta,
            '#fc-modal-nota':             pedido.nota,
        };
        for (const [sel, val] of Object.entries(simpleFields)) {
            const el = $(sel);
            if (el) el.value = val || '';
        }

        // Tamaño y color — obtener datos del arreglo
        const tamSelect = $('#fc-tamano-select');
        const colGroup  = $('#fc-color-group');
        if (tamSelect) tamSelect.innerHTML = '<option value="">-- Selecciona tamaño --</option>';
        if (colGroup)  colGroup.style.display = 'none';

        if (pedido.arreglo_id) {
            try {
                const res = await ajax('fc_panel_get_arreglo', { arreglo_id: pedido.arreglo_id });
                if (res.success) {
                    selectedArreglo = res.data;
                    populateTamanos(res.data.tamanos || []);

                    // Pre-seleccionar tamaño por texto
                    if (tamSelect && pedido.tamano) {
                        for (let i = 1; i < tamSelect.options.length; i++) {
                            if (tamSelect.options[i].text === pedido.tamano) {
                                tamSelect.value = tamSelect.options[i].value;
                                const idx     = parseInt(tamSelect.options[i].value, 10);
                                const colores = res.data.tamanos[idx]?.colores || [];
                                populateColores(colores);

                                // Pre-seleccionar color por texto
                                if (pedido.color) {
                                    const colSelect = $('#fc-color-select');
                                    if (colSelect) {
                                        for (let j = 1; j < colSelect.options.length; j++) {
                                            if (colSelect.options[j].text === pedido.color) {
                                                colSelect.value = colSelect.options[j].value;
                                                break;
                                            }
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

    function resetNewOrderForm() {
        currentEditId = null;
        const form = $('#fc-new-pedido-form');
        if (form) form.reset();

        const title = $('#fc-modal-title');
        if (title) title.textContent = 'Nuevo pedido';

        const successBox = $('#fc-pedido-success');
        if (successBox) successBox.classList.remove('show');

        const submitBtn = $('#fc-submit-pedido');
        if (submitBtn) {
            submitBtn.style.display = '';
            submitBtn.textContent = 'Registrar pedido';
        }

        selectedArreglo = null;
        const searchInput = $('#fc-arreglo-search');
        if (searchInput) searchInput.value = '';

        const horarioSelect = $('#fc-modal-horario');
        if (horarioSelect) horarioSelect.innerHTML = '<option value="">-- Selecciona fecha primero --</option>';

        const tamSelect = $('#fc-tamano-select');
        if (tamSelect) tamSelect.innerHTML = '<option value="">-- Selecciona tamaño --</option>';

        const colSelect = $('#fc-color-select');
        if (colSelect) colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';
        const colGroup = $('#fc-color-group');
        if (colGroup) colGroup.style.display = 'none';

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
        btn.textContent = currentEditId ? 'Guardando...' : 'Registrando...';
        btn.disabled    = true;

        const tipo = ($('.fc-tipo-option.active') || {}).dataset?.tipo || 'envio';

        const arregloId     = $('#fc-arreglo-id')?.value || '';
        const arregloNombre = $('#fc-arreglo-nombre')?.value || '';
        const tamanoEl      = $('#fc-tamano-select');
        const tamanoNombre  = tamanoEl?.options[tamanoEl.selectedIndex]?.text || '';
        const colorEl       = $('#fc-color-select');
        const colorNombre   = (colorEl?.value) ? colorEl.options[colorEl.selectedIndex]?.text || '' : '';

        const payload = {
            tipo,
            fecha:            $('#fc-modal-fecha')?.value || '',
            horario:          $('#fc-modal-horario')?.value || '',
            hora_recoleccion: $('#fc-modal-hora-recoleccion')?.value || '',
            direccion:        $('#fc-modal-direccion')?.value || '',
            cliente_nombre:   $('#fc-modal-cliente-nombre')?.value || '',
            cliente_telefono: $('#fc-modal-cliente-telefono')?.value || '',
            destinatario:     $('#fc-modal-destinatario')?.value || '',
            mensaje_tarjeta:  $('#fc-modal-mensaje-tarjeta')?.value || '',
            nota:             $('#fc-modal-nota')?.value || '',
            arreglo_id:       arregloId,
            arreglo_nombre:   arregloNombre,
            tamano:           tamanoNombre,
            color:            colorNombre,
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
                    if (numEl)      numEl.textContent = data.data.numero;
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

    // ── Arreglo autocomplete ──
    let debounceTimer = null;

    function initArregloSearch() {
        const input    = $('#fc-arreglo-search');
        const dropdown = $('#fc-arreglo-dropdown');
        const idInput  = $('#fc-arreglo-id');
        const nameInput = $('#fc-arreglo-nombre');

        if (!input || !dropdown) return;

        input.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            const term = input.value.trim();
            if (term.length < 2) {
                dropdown.classList.remove('open');
                dropdown.innerHTML = '';
                return;
            }
            debounceTimer = setTimeout(async () => {
                try {
                    const data = await ajax('fc_panel_buscar_arreglos', { term });
                    if (data.success && data.data.arreglos.length) {
                        allArreglos = data.data.arreglos;
                        dropdown.innerHTML = data.data.arreglos.map(a =>
                            `<div class="fc-autocomplete-item" data-id="${a.id}">${escHtml(a.title)}</div>`
                        ).join('');
                        dropdown.classList.add('open');
                    } else {
                        dropdown.innerHTML = '<div class="fc-autocomplete-item">Sin resultados</div>';
                        dropdown.classList.add('open');
                    }
                } catch {
                    dropdown.classList.remove('open');
                }
            }, 350);
        });

        dropdown.addEventListener('click', (e) => {
            const item = e.target.closest('.fc-autocomplete-item');
            if (!item || !item.dataset.id) return;

            const arregloId = parseInt(item.dataset.id, 10);
            selectedArreglo = allArreglos.find(a => a.id === arregloId);

            input.value = selectedArreglo?.title || '';
            if (idInput)   idInput.value   = arregloId;
            if (nameInput) nameInput.value = selectedArreglo?.title || '';
            dropdown.classList.remove('open');

            populateTamanos(selectedArreglo?.tamanos || []);
        });

        // Close dropdown on outside click
        document.addEventListener('click', (e) => {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
    }

    function populateTamanos(tamanos) {
        const tamSelect = $('#fc-tamano-select');
        const colSelect = $('#fc-color-select');
        const colGroup  = $('#fc-color-group');
        if (!tamSelect) return;

        tamSelect.innerHTML = '<option value="">-- Selecciona tamaño --</option>';
        if (colSelect) colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';
        if (colGroup)  colGroup.style.display = 'none';

        tamanos.forEach((t, i) => {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = t.nombre + (t.precio ? ` ($${Number(t.precio).toLocaleString('es-MX')})` : '');
            tamSelect.appendChild(opt);
        });

        tamSelect.addEventListener('change', () => {
            const idx = parseInt(tamSelect.value, 10);
            if (!isNaN(idx) && tamanos[idx]) {
                populateColores(tamanos[idx].colores || []);
            } else {
                if (colSelect) colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';
            }
        });
    }

    function populateColores(colores) {
        const colSelect  = $('#fc-color-select');
        const colGroup   = $('#fc-color-group');
        if (!colSelect) return;

        colSelect.innerHTML = '<option value="">-- Selecciona color --</option>';

        if (!colores || colores.length === 0) {
            // Sin variantes de color: ocultar el campo
            if (colGroup) colGroup.style.display = 'none';
            return;
        }

        // Con variantes: mostrar y poblar
        if (colGroup) colGroup.style.display = '';
        colores.forEach((c, i) => {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = c.nombre;
            colSelect.appendChild(opt);
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
            <div class="fc-card-row"><span class="fc-label">Cliente</span><span class="fc-value">${escHtml(p.cliente_nombre)}${p.cliente_telefono ? ' · ' + escHtml(p.cliente_telefono) : ''}</span></div>
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

    // ── Init ──
    function init() {
        // Modal, arreglo search y copy link funcionan tanto en el panel
        // como en la página de admin (donde no existe .fc-panel-body)
        initNewOrderModal();
        initArregloSearch();
        initCopySuccessLink();

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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
