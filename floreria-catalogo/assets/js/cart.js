/* ── Florería Catálogo — Carrito multi-arreglo ── */
(function () {
    'use strict';

    var CART_KEY = 'fc_cart_v1';

    /* ── Schedule & config data — localized directly to this script ── */
    var cartData = window.fcCartData || {};
    function getSchedules()        { return cartData.schedules        || {}; }
    function getFechasEspeciales() { return cartData.fechasEspeciales || []; }
    function getFechasCerradas()   { return cartData.fechasCerradas   || []; }
    function getWhatsapp()         { return cartData.whatsapp         || ''; }

    /* ── Cart storage ── */
    function getCart()      { try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch (e) { return []; } }
    function saveCart(cart) { try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {} }
    function clearCart()    { localStorage.removeItem(CART_KEY); }
    function uid()          { return Date.now() + '-' + Math.random().toString(36).substr(2, 6); }

    /* ── Collapse state ── */
    var deliveryCollapsed = false;
    var collapsedItems    = {}; // uid → true when collapsed

    /* ── Public: add item ── */
    function addItem(item) {
        var cart = getCart();
        item.uid                   = uid();
        item.destinatario           = item.destinatario           || '';
        item.destinatario_telefono  = item.destinatario_telefono  || '';
        item.destinatario_telefono2 = item.destinatario_telefono2 || '';
        item.mensajeTarjeta         = item.mensajeTarjeta         || '';
        cart.push(item);
        saveCart(cart);
        updateFab();
        showAddedToast(item.titulo);
    }

    /* ── Helpers ── */
    var dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    var meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    function formatFecha(val) {
        if (!val) return '';
        var d = new Date(val + 'T12:00:00');
        return dias[d.getDay()] + ', ' + d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
    }

    function parseSlotStartMinutes(slot) {
        var start = slot.split('–')[0].trim();
        var isPm  = /pm$/i.test(start);
        var parts = start.replace(/[apm]/gi, '').split(':');
        var h = parseInt(parts[0]);
        var m = parseInt(parts[1]) || 0;
        if (isPm && h !== 12) h += 12;
        if (!isPm && h === 12) h = 0;
        return h * 60 + m;
    }

    function getNowTijuana() {
        var str = new Date().toLocaleString('en-US', { timeZone: 'America/Tijuana' });
        return new Date(str);
    }

    /* Cuenta días hábiles desde 'from' (inclusive) hasta 'to' (exclusive).
       El día de inicio cuenta si el negocio aún está abierto (antes de 8pm Tijuana). */
    function countBusinessDays(from, to) {
        var count = 0;
        var d = new Date(from);
        while (d < to) {
            var day = d.getDay();
            if (day !== 0 && day !== 6) count++;
            d.setDate(d.getDate() + 1);
        }
        return count;
    }

    /* Devuelve la fecha de inicio para contar días hábiles según la hora actual en Tijuana.
       Si ya pasaron las 8pm, hoy ya no cuenta → empieza desde mañana. */
    function getHoyParaAnticipacion() {
        var ahora = getNowTijuana();
        var hoy   = new Date(ahora);
        hoy.setHours(0, 0, 0, 0);
        if (ahora.getHours() >= 20) {
            hoy.setDate(hoy.getDate() + 1);
        }
        return hoy;
    }

    function escHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Collapse helpers ── */
    function setCollapsed(bodyEl, arrowEl, collapsed) {
        if (collapsed) {
            bodyEl.classList.add('fc-collapsed');
            arrowEl.classList.add('fc-arrow-up');
        } else {
            bodyEl.classList.remove('fc-collapsed');
            arrowEl.classList.remove('fc-arrow-up');
        }
    }

    /* ── FAB ── */
    var fabEl   = null;
    var badgeEl = null;

    function createFab() {
        fabEl = document.createElement('button');
        fabEl.id        = 'fc-cart-fab';
        fabEl.className = 'fc-cart-fab';
        fabEl.setAttribute('aria-label', 'Ver pedido');
        fabEl.innerHTML =
            '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
                '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>' +
                '<path d="M1 1h4l2.68 13.39a2 2 0 001.99 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/>' +
            '</svg>' +
            '<span class="fc-cart-badge" id="fc-cart-badge">0</span>';
        badgeEl = fabEl.querySelector('.fc-cart-badge');
        document.body.appendChild(fabEl);
        fabEl.addEventListener('click', openDrawer);
    }

    function updateFab() {
        var count = getCart().length;
        if (badgeEl) badgeEl.textContent = count;
        if (fabEl)   fabEl.classList.toggle('fc-cart-fab--empty', count === 0);
    }

    /* ── Toast ── */
    function showAddedToast(titulo) {
        var t = document.createElement('div');
        t.className   = 'fc-cart-toast';
        t.textContent = '✓ ' + titulo + ' agregado al pedido';
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('fc-cart-toast--show'); }, 10);
        setTimeout(function () {
            t.classList.remove('fc-cart-toast--show');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400);
        }, 2600);
    }

    /* ── Drawer ── */
    var drawerEl = null;
    var isOpen   = false;

    function buildDrawer() {
        drawerEl = document.createElement('div');
        drawerEl.id        = 'fc-cart-drawer';
        drawerEl.className = 'fc-cart-drawer';
        drawerEl.innerHTML =
            '<div class="fc-cart-backdrop" id="fc-cart-backdrop"></div>' +
            '<div class="fc-cart-panel" id="fc-cart-panel">' +

                '<div class="fc-cart-header">' +
                    '<h2>Mi pedido (<span id="fc-cart-items-count">0</span>)</h2>' +
                    '<button class="fc-cart-close-btn" id="fc-cart-close" aria-label="Cerrar">&times;</button>' +
                '</div>' +

                '<div class="fc-cart-body" id="fc-cart-body">' +

                    /* ── Delivery section (collapsible) ── */
                    '<div class="fc-cart-delivery" id="fc-cart-delivery">' +
                        '<div class="fc-collapsible-trigger" id="fc-delivery-trigger">' +
                            '<h3 class="fc-cart-section-title">Datos de entrega</h3>' +
                            '<span class="fc-collapse-arrow" id="fc-delivery-arrow">&#9660;</span>' +
                        '</div>' +
                        '<div class="fc-collapsible-body" id="fc-delivery-body">' +
                            '<div class="fc-collapsible-inner">' +
                                '<div class="fc-form-group">' +
                                    '<label>¿Cómo lo recibirás?</label>' +
                                    '<div class="fc-tipo-toggle">' +
                                        '<button type="button" class="fc-tipo-option active" id="fc-cart-tipo-envio">Envío a domicilio</button>' +
                                        '<button type="button" class="fc-tipo-option" id="fc-cart-tipo-recol">Recolección en tienda</button>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="fc-form-group">' +
                                    '<label for="fc-cart-fecha">Fecha de entrega</label>' +
                                    '<input type="date" id="fc-cart-fecha" />' +
                                '</div>' +
                                '<p id="fc-cart-especial-aviso" class="fc-cart-especial-aviso"></p>' +
                                '<div id="fc-cart-envio-fields">' +
                                    '<div class="fc-form-group">' +
                                        '<label for="fc-cart-horario">Horario de entrega</label>' +
                                        '<select id="fc-cart-horario"><option value="">-- Selecciona fecha primero --</option></select>' +
                                    '</div>' +
                                    '<div class="fc-form-group">' +
                                        '<label for="fc-cart-direccion">Dirección de entrega</label>' +
                                        '<input type="text" id="fc-cart-direccion" placeholder="Calle, número, colonia..." />' +
                                    '</div>' +
                                '</div>' +
                                '<div id="fc-cart-recol-fields" style="display:none;">' +
                                    '<div class="fc-form-group">' +
                                        '<label for="fc-cart-hora-recol">Hora de recolección</label>' +
                                        '<input type="time" id="fc-cart-hora-recol" />' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +

                    /* ── Items list ── */
                    '<div id="fc-cart-items-list"></div>' +

                '</div>' +

                '<div class="fc-cart-footer">' +
                    '<div class="fc-cart-total-row">' +
                        '<span>Total estimado:</span>' +
                        '<strong id="fc-cart-total-val"></strong>' +
                    '</div>' +
                    '<button class="fc-btn-whatsapp" id="fc-cart-send-btn">' +
                        '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;fill:currentColor;flex-shrink:0;" aria-hidden="true">' +
                            '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>' +
                        '</svg>' +
                        'Enviar por WhatsApp' +
                    '</button>' +
                    '<button class="fc-btn-outline" id="fc-cart-clear-btn" style="margin-top:8px;width:100%;font-size:13px;">Vaciar pedido</button>' +
                '</div>' +

            '</div>';

        document.body.appendChild(drawerEl);

        /* ── Delivery collapse toggle ── */
        var deliveryTrigger = drawerEl.querySelector('#fc-delivery-trigger');
        var deliveryBody    = drawerEl.querySelector('#fc-delivery-body');
        var deliveryArrow   = drawerEl.querySelector('#fc-delivery-arrow');
        deliveryTrigger.addEventListener('click', function () {
            deliveryCollapsed = !deliveryCollapsed;
            setCollapsed(deliveryBody, deliveryArrow, deliveryCollapsed);
        });

        /* ── Backdrop / close ── */
        drawerEl.querySelector('#fc-cart-backdrop').addEventListener('click', closeDrawer);
        drawerEl.querySelector('#fc-cart-close').addEventListener('click', closeDrawer);
        drawerEl.querySelector('#fc-cart-clear-btn').addEventListener('click', function () {
            if (window.confirm('¿Vaciar el pedido?')) {
                clearCart();
                collapsedItems = {};
                updateFab();
                renderItems();
                closeDrawer();
            }
        });
        drawerEl.querySelector('#fc-cart-send-btn').addEventListener('click', sendWhatsapp);

        /* ── Tipo toggle ── */
        var tipoEnvio = drawerEl.querySelector('#fc-cart-tipo-envio');
        var tipoRecol = drawerEl.querySelector('#fc-cart-tipo-recol');
        var envioFlds = drawerEl.querySelector('#fc-cart-envio-fields');
        var recolFlds = drawerEl.querySelector('#fc-cart-recol-fields');

        tipoEnvio.addEventListener('click', function () {
            tipoEnvio.classList.add('active');
            tipoRecol.classList.remove('active');
            envioFlds.style.display = '';
            recolFlds.style.display = 'none';
        });
        tipoRecol.addEventListener('click', function () {
            tipoRecol.classList.add('active');
            tipoEnvio.classList.remove('active');
            envioFlds.style.display = 'none';
            recolFlds.style.display = '';
        });

        /* ── Fecha → horario ── */
        var fechaInput = drawerEl.querySelector('#fc-cart-fecha');
        var today      = new Date();
        fechaInput.min = today.getFullYear() + '-' +
                         String(today.getMonth() + 1).padStart(2, '0') + '-' +
                         String(today.getDate()).padStart(2, '0');
        fechaInput.addEventListener('change', updateCartHorario);

        /* ── Google Places en dirección ── */
        initPlacesOnInput(document.getElementById('fc-cart-direccion'), { teleport: true });

        /* ── Keyboard close ── */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) closeDrawer();
        });
    }

    /* ── Aviso de anticipación para arreglos especiales ── */
    function checkEspecialAviso(fecha) {
        var avisoEl = document.getElementById('fc-cart-especial-aviso');
        if (!avisoEl) return;
        if (!fecha) { avisoEl.classList.remove('fc-aviso-visible'); return; }

        var cartNow    = getCart();
        var hasEspecial = false;
        for (var ei = 0; ei < cartNow.length; ei++) {
            if (cartNow[ei].especial) { hasEspecial = true; break; }
        }
        if (!hasEspecial) { avisoEl.classList.remove('fc-aviso-visible'); return; }

        var hoyE  = getHoyParaAnticipacion();
        var selE  = new Date(fecha + 'T00:00:00');
        var diasE = countBusinessDays(hoyE, selE);

        if (diasE < 2) {
            avisoEl.textContent = '⚠ Uno o más arreglos son sobre pedido y necesitan al menos 2 días hábiles de anticipación. Sábado y domingo no cuentan.';
            avisoEl.classList.add('fc-aviso-visible');
        } else {
            avisoEl.classList.remove('fc-aviso-visible');
        }

        /* Reposicionar el widget de Google Places (teleportado al body como fixed)
           al inicio y al final de la transición CSS (0.25 s) */
        window.dispatchEvent(new Event('resize'));
        setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 270);
    }

    function updateCartHorario() {
        var fechaInput = document.getElementById('fc-cart-fecha');
        var horarioSel = document.getElementById('fc-cart-horario');
        if (!fechaInput || !horarioSel) return;

        var val = fechaInput.value;
        if (!val) {
            horarioSel.innerHTML = '<option value="">-- Selecciona fecha primero --</option>';
            checkEspecialAviso('');
            return;
        }

        var schedules        = getSchedules();
        var fechasEspeciales = getFechasEspeciales();
        var fechasCerradas   = getFechasCerradas();

        /* Fecha cerrada — no se aceptan pedidos */
        if (fechasCerradas.indexOf(val) !== -1) {
            horarioSel.innerHTML = '<option value="">Lo sentimos, no recibimos pedidos para esta fecha</option>';
            checkEspecialAviso(val);
            return;
        }

        var date             = new Date(val + 'T12:00:00');
        var dayKey           = String(date.getDay());
        var daySlots         = schedules[dayKey] || [];

        /* Sunday — only allow on fechas especiales */
        if (dayKey === '0') {
            var mo   = String(date.getMonth() + 1).padStart(2, '0');
            var dy   = String(date.getDate()).padStart(2, '0');
            var ddmm = dy + '/' + mo;
            if (fechasEspeciales.indexOf(ddmm) === -1) { daySlots = []; }
        }

        if (daySlots.length === 0) {
            horarioSel.innerHTML = '<option value="">No hay horarios disponibles este día</option>';
            checkEspecialAviso(val);
            return;
        }

        var ahora    = getNowTijuana();
        var esHoy    = date.getFullYear() === ahora.getFullYear() &&
                       date.getMonth()    === ahora.getMonth()    &&
                       date.getDate()     === ahora.getDate();
        var ahoraMin = ahora.getHours() * 60 + ahora.getMinutes();
        var slots    = esHoy
            ? daySlots.filter(function (s) { return parseSlotStartMinutes(s) > ahoraMin; })
            : daySlots;

        if (slots.length === 0) {
            horarioSel.innerHTML = '<option value="">Ya no hay horarios disponibles para hoy</option>';
            return;
        }

        horarioSel.innerHTML = '<option value="">-- Selecciona un horario --</option>';
        slots.forEach(function (s) {
            var o = document.createElement('option');
            o.value = o.textContent = s;
            horarioSel.appendChild(o);
        });

        checkEspecialAviso(val);
    }

    function openDrawer() {
        if (!drawerEl) buildDrawer();
        renderItems();
        /* Restore delivery collapse state */
        var deliveryBody  = document.getElementById('fc-delivery-body');
        var deliveryArrow = document.getElementById('fc-delivery-arrow');
        if (deliveryBody && deliveryArrow) setCollapsed(deliveryBody, deliveryArrow, deliveryCollapsed);
        drawerEl.classList.add('fc-cart-drawer--open');
        document.body.style.overflow = 'hidden';
        isOpen = true;
    }

    function closeDrawer() {
        if (!drawerEl) return;
        drawerEl.classList.remove('fc-cart-drawer--open');
        document.body.style.overflow = '';
        isOpen = false;
    }

    /* ── Render item list ── */
    function renderItems() {
        var cart    = getCart();
        var listEl  = document.getElementById('fc-cart-items-list');
        var countEl = document.getElementById('fc-cart-items-count');
        var totalEl = document.getElementById('fc-cart-total-val');
        if (!listEl) return;

        if (countEl) countEl.textContent = cart.length;

        var total = 0;
        cart.forEach(function (item) { total += parseFloat(item.precio) || 0; });
        if (totalEl) {
            totalEl.textContent = total > 0
                ? '$' + total.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
                : '';
        }

        listEl.innerHTML = '';

        if (cart.length === 0) {
            var emptyP = document.createElement('p');
            emptyP.className = 'fc-cart-empty';
            emptyP.innerHTML = 'Tu pedido está vacío.<br>Agrega arreglos desde el catálogo.';
            listEl.appendChild(emptyP);
            return;
        }

        var secTitle = document.createElement('h3');
        secTitle.className   = 'fc-cart-section-title';
        secTitle.textContent = 'Arreglos (' + cart.length + ')';
        listEl.appendChild(secTitle);

        cart.forEach(function (item, i) {
            var isCollapsed = !!collapsedItems[item.uid];
            var precioStr   = item.precio
                ? '$' + parseFloat(item.precio).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
                : '';
            var subStr = escHtml(item.tamano) + (item.color ? ' · ' + escHtml(item.color) : '');

            var div = document.createElement('div');
            div.className = 'fc-cart-item';
            div.innerHTML =
                /* Collapsible trigger row */
                '<div class="fc-collapsible-trigger fc-cart-item-trigger" data-uid="' + escHtml(item.uid) + '">' +
                    '<div class="fc-cart-item-info">' +
                        '<strong class="fc-cart-item-titulo">' + escHtml(item.titulo) + '</strong>' +
                        '<span class="fc-cart-item-sub">' + subStr + '</span>' +
                        (precioStr ? '<span class="fc-cart-item-precio">' + precioStr + '</span>' : '') +
                    '</div>' +
                    '<div class="fc-cart-item-actions">' +
                        '<button class="fc-cart-item-remove" data-uid="' + escHtml(item.uid) + '" aria-label="Eliminar">✕</button>' +
                        '<span class="fc-collapse-arrow' + (isCollapsed ? ' fc-arrow-up' : '') + '">&#9660;</span>' +
                    '</div>' +
                '</div>' +
                /* Collapsible body */
                '<div class="fc-collapsible-body' + (isCollapsed ? ' fc-collapsed' : '') + '">' +
                    '<div class="fc-collapsible-inner">' +
                        '<div class="fc-form-group">' +
                            '<label>Nombre del destinatario <span style="color:#b91c1c;">*</span></label>' +
                            '<input type="text" class="fc-cart-item-dest" data-uid="' + escHtml(item.uid) + '" value="' + escHtml(item.destinatario) + '" placeholder="¿A quién va dirigido?" />' +
                        '</div>' +
                        '<div class="fc-form-group">' +
                            '<label>Teléfono del destinatario <span style="color:#b91c1c;">*</span></label>' +
                            '<input type="tel" class="fc-cart-item-tel" data-uid="' + escHtml(item.uid) + '" value="' + escHtml(item.destinatario_telefono) + '" placeholder="10 dígitos" inputmode="numeric" maxlength="15" />' +
                        '</div>' +
                        '<div class="fc-form-group">' +
                            '<label>Teléfono del destinatario 2 <span style="font-weight:400;color:#94a3b8;">(opcional)</span></label>' +
                            '<input type="tel" class="fc-cart-item-tel2" data-uid="' + escHtml(item.uid) + '" value="' + escHtml(item.destinatario_telefono2 || '') + '" placeholder="Número alternativo" inputmode="numeric" maxlength="15" />' +
                        '</div>' +
                        '<div class="fc-form-group">' +
                            '<label>Mensaje de tarjeta</label>' +
                            '<textarea class="fc-cart-item-tarjeta" data-uid="' + escHtml(item.uid) + '" rows="2" placeholder="Mensaje para incluir en la tarjeta...">' + escHtml(item.mensajeTarjeta) + '</textarea>' +
                        '</div>' +
                        (i > 0 && cart.length > 1
                            ? '<div class="fc-form-group fc-mismos-wrap">' +
                                  '<label class="fc-mismos-label">' +
                                      '<input type="checkbox" class="fc-cart-item-mismos" data-uid="' + escHtml(item.uid) + '" /> ' +
                                      'Mismos datos que el arreglo 1' +
                                  '</label>' +
                              '</div>'
                            : '') +
                    '</div>' +
                '</div>';

            listEl.appendChild(div);
        });

        /* ── Item collapse toggles ── */
        listEl.querySelectorAll('.fc-cart-item-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                /* Don't collapse when clicking the remove button */
                if (e.target.classList.contains('fc-cart-item-remove') ||
                    e.target.closest('.fc-cart-item-remove')) return;

                var id      = trigger.dataset.uid;
                var body    = trigger.nextElementSibling;
                var arrow   = trigger.querySelector('.fc-collapse-arrow');
                var current = !!collapsedItems[id];
                collapsedItems[id] = !current;
                setCollapsed(body, arrow, !current);
            });
        });

        /* ── Remove buttons ── */
        listEl.querySelectorAll('.fc-cart-item-remove').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var id   = this.dataset.uid;
                var cart = getCart();
                delete collapsedItems[id];
                saveCart(cart.filter(function (it) { return it.uid !== id; }));
                updateFab();
                renderItems();
            });
        });

        /* ── Field save on input ── */
        listEl.querySelectorAll('.fc-cart-item-dest').forEach(function (inp) {
            inp.addEventListener('input', function () { patchItem(inp.dataset.uid, 'destinatario', inp.value); });
        });
        listEl.querySelectorAll('.fc-cart-item-tel').forEach(function (inp) {
            inp.addEventListener('input', function () {
                inp.value = inp.value.replace(/\D/g, '');
                patchItem(inp.dataset.uid, 'destinatario_telefono', inp.value);
            });
        });
        listEl.querySelectorAll('.fc-cart-item-tel2').forEach(function (inp) {
            inp.addEventListener('input', function () {
                inp.value = inp.value.replace(/\D/g, '');
                patchItem(inp.dataset.uid, 'destinatario_telefono2', inp.value);
            });
        });
        listEl.querySelectorAll('.fc-cart-item-tarjeta').forEach(function (ta) {
            ta.addEventListener('input', function () { patchItem(ta.dataset.uid, 'mensajeTarjeta', ta.value); });
        });

        /* ── "Mismos datos que el arreglo 1" checkbox ── */
        listEl.querySelectorAll('.fc-cart-item-mismos').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id    = cb.dataset.uid;
                var cart2 = getCart();
                var first = cart2[0];
                if (!first) return;

                var destInp = listEl.querySelector('.fc-cart-item-dest[data-uid="'    + id + '"]');
                var telInp  = listEl.querySelector('.fc-cart-item-tel[data-uid="'     + id + '"]');
                var tel2Inp = listEl.querySelector('.fc-cart-item-tel2[data-uid="'    + id + '"]');
                var tarjTa  = listEl.querySelector('.fc-cart-item-tarjeta[data-uid="' + id + '"]');

                if (cb.checked) {
                    var d  = first.destinatario           || '';
                    var t  = first.destinatario_telefono  || '';
                    var t2 = first.destinatario_telefono2 || '';
                    if (destInp)  { destInp.value  = d;  destInp.disabled  = true; }
                    if (telInp)   { telInp.value   = t;  telInp.disabled   = true; }
                    if (tel2Inp)  { tel2Inp.value  = t2; tel2Inp.disabled  = true; }
                    patchItem(id, 'destinatario',           d);
                    patchItem(id, 'destinatario_telefono',  t);
                    patchItem(id, 'destinatario_telefono2', t2);
                } else {
                    if (destInp)  destInp.disabled  = false;
                    if (telInp)   telInp.disabled   = false;
                    if (tel2Inp)  tel2Inp.disabled  = false;
                }
            });
        });
    }

    function patchItem(id, field, value) {
        var cart = getCart();
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].uid === id) { cart[i][field] = value; break; }
        }
        saveCart(cart);
    }

    /* ── Send WhatsApp ── */
    function sendWhatsapp() {
        var cart = getCart();
        if (cart.length === 0) { alert('Tu pedido está vacío.'); return; }

        var fechaInput = document.getElementById('fc-cart-fecha');
        var horarioSel = document.getElementById('fc-cart-horario');
        var dirInput   = document.getElementById('fc-cart-direccion');
        var horaInput  = document.getElementById('fc-cart-hora-recol');
        var tipoEnvio  = document.getElementById('fc-cart-tipo-envio');
        var isEnvio    = tipoEnvio && tipoEnvio.classList.contains('active');

        var fecha   = fechaInput ? fechaInput.value.trim() : '';
        var horario = horarioSel ? horarioSel.value.trim() : '';
        var dir     = dirInput   ? dirInput.value.trim()   : '';
        var hora    = horaInput  ? horaInput.value.trim()  : '';

        if (!fecha)              { alert('Por favor selecciona la fecha de entrega.');   return; }
        if (isEnvio && !horario) { alert('Por favor selecciona el horario de entrega.'); return; }
        if (isEnvio && !dir)     { alert('Por favor escribe la dirección de entrega.');  return; }
        if (!isEnvio && !hora)   { alert('Por favor escribe la hora de recolección.');   return; }

        /* ── Validar nombre y teléfono del destinatario (obligatorios) ── */
        for (var vi = 0; vi < cart.length; vi++) {
            var label = cart.length > 1 ? ' para el arreglo ' + (vi + 1) : '';
            if (!cart[vi].destinatario || !cart[vi].destinatario.trim()) {
                alert('Por favor ingresa el nombre del destinatario' + label + '.');
                return;
            }
            if (!cart[vi].destinatario_telefono || !cart[vi].destinatario_telefono.trim()) {
                alert('Por favor ingresa el teléfono del destinatario' + label + '.');
                return;
            }
        }

        /* ── Validar anticipación para arreglos especiales (sobre pedido) ── */
        var hoyVal  = getHoyParaAnticipacion();
        var selDate = new Date(fecha + 'T00:00:00');
        for (var si = 0; si < cart.length; si++) {
            if (cart[si].especial) {
                var diasHab = countBusinessDays(hoyVal, selDate);
                if (diasHab < 2) {
                    alert('El arreglo "' + cart[si].titulo + '" es sobre pedido y necesita al menos 2 días hábiles de anticipación. Sábado y domingo no cuentan.\nPor favor elige una fecha posterior.');
                    return;
                }
                break; // todos usan la misma fecha, con uno basta
            }
        }

        var fechaStr = formatFecha(fecha);
        var lines    = ['Hola! Quisiera hacer el siguiente pedido:\n'];

        if (isEnvio) {
            lines.push('*Tipo:* Envío a domicilio');
            lines.push('*Fecha:* '     + fechaStr);
            lines.push('*Horario:* '   + horario);
            lines.push('*Dirección:* ' + dir);
        } else {
            var horaStr = hora;
            if (hora) {
                var pts  = hora.split(':');
                var hh   = parseInt(pts[0]);
                var ampm = hh >= 12 ? 'pm' : 'am';
                hh       = hh % 12 || 12;
                horaStr  = hh + ':' + pts[1] + ampm;
            }
            lines.push('*Tipo:* Recolección en tienda');
            lines.push('*Fecha:* '              + fechaStr);
            lines.push('*Hora de recolección:* ' + horaStr);
        }
        lines.push('');

        cart.forEach(function (item, i) {
            lines.push('*Arreglo ' + (i + 1) + ':* ' + item.titulo);
            lines.push('  Tamaño: ' + item.tamano + (item.color ? ' · ' + item.color : ''));
            if (item.precio) {
                lines.push('  Precio: $' + parseFloat(item.precio).toLocaleString('es-MX', { minimumFractionDigits: 0 }));
            }
            if (item.destinatario)            lines.push('  Para: '           + item.destinatario);
            if (item.destinatario_telefono)  lines.push('  Tel. destino: '   + item.destinatario_telefono);
            if (item.destinatario_telefono2) lines.push('  Tel. destino 2: ' + item.destinatario_telefono2);
            if (item.mensajeTarjeta)        lines.push('  Tarjeta: '      + item.mensajeTarjeta);
            if (item.permalink)             lines.push('  Link: '         + item.permalink);
            lines.push('');
        });

        lines.push('¿Está disponible?');

        var wa  = getWhatsapp();
        var msg = lines.join('\n');

        /* ── Fire-and-forget: registrar como pedido pendiente ── */
        var ajaxurl       = cartData.ajaxurl       || '';
        var whatsappNonce = cartData.whatsappNonce || '';
        if (ajaxurl && whatsappNonce) {
            var itemsPayload = cart.map(function (item) {
                return {
                    arreglo_id:             item.arregloId           || 0,
                    arreglo_nombre:         item.titulo              || '',
                    imagen_url:             '',
                    tamano:                 item.tamano              || '',
                    color:                  item.color               || '',
                    destinatario:           item.destinatario        || '',
                    destinatario_telefono:  item.destinatario_telefono  || '',
                    destinatario_telefono2: item.destinatario_telefono2 || '',
                    mensaje_tarjeta:        item.mensajeTarjeta      || '',
                };
            });
            var body = new URLSearchParams({
                action:           'fc_crear_pedido_whatsapp',
                nonce:            whatsappNonce,
                fecha:            fecha,
                tipo:             isEnvio ? 'envio' : 'recoleccion',
                horario:          isEnvio ? horario : '',
                direccion:        isEnvio ? dir     : '',
                hora_recoleccion: isEnvio ? ''      : hora,
                items_json:       JSON.stringify(itemsPayload),
            });
            fetch(ajaxurl, { method: 'POST', body: body }).catch(function () {});
        }

        window.open('https://wa.me/' + wa + '?text=' + encodeURIComponent(msg), '_blank');
    }

    /* ── Google Places Autocomplete helper ── */
    function initPlacesOnInput(inputEl, opts) {
        if (!inputEl) return;
        if (
            !window.google ||
            !window.google.maps ||
            !window.google.maps.places ||
            typeof window.google.maps.places.PlaceAutocompleteElement === 'undefined'
        ) return;

        opts = opts || {};

        try {
            var pac = new window.google.maps.places.PlaceAutocompleteElement({
                componentRestrictions: { country: 'mx' },
            });

            if (opts.teleport) {
                // Teleportar al body para evitar clipping por overflow/stacking context del carrito
                inputEl.style.opacity = '0';
                pac.style.position   = 'fixed';
                pac.style.zIndex     = '10000';
                pac.style.display    = 'none';
                document.body.appendChild(pac);

                function positionPac() {
                    var r = inputEl.getBoundingClientRect();
                    pac.style.top   = r.top + 'px';
                    pac.style.left  = r.left + 'px';
                    pac.style.width = r.width + 'px';
                }

                // Mostrar/ocultar con el carrito
                var drawer = document.getElementById('fc-cart-drawer');
                if (drawer) {
                    new MutationObserver(function() {
                        var open = drawer.classList.contains('fc-cart-drawer--open');
                        if (open) {
                            // Solo mostrar si la sección de envío está visible
                            var envioFlds2 = document.getElementById('fc-cart-envio-fields');
                            var envioVisible = !envioFlds2 || envioFlds2.style.display !== 'none';
                            if (envioVisible) {
                                pac.style.display = '';
                                setTimeout(positionPac, 250);
                            }
                        } else {
                            pac.style.display = 'none';
                        }
                    }).observe(drawer, { attributes: true, attributeFilter: ['class'] });
                }

                // Mostrar/ocultar según tipo envío/recolección
                var envioFldsEl = document.getElementById('fc-cart-envio-fields');
                if (envioFldsEl) {
                    new MutationObserver(function() {
                        var hidden = envioFldsEl.style.display === 'none';
                        if (hidden) {
                            pac.style.display = 'none';
                        } else {
                            var drawerOpen = drawer && drawer.classList.contains('fc-cart-drawer--open');
                            if (drawerOpen) {
                                pac.style.display = '';
                                positionPac();
                            }
                        }
                    }).observe(envioFldsEl, { attributes: true, attributeFilter: ['style'] });
                }

                window.addEventListener('resize', function() {
                    if (pac.style.display !== 'none') positionPac();
                });

                var cartBody = document.getElementById('fc-cart-body');
                if (cartBody) cartBody.addEventListener('scroll', function() {
                    if (pac.style.display !== 'none') positionPac();
                });

            } else {
                inputEl.parentNode.insertBefore(pac, inputEl);
                inputEl.style.display = 'none';
            }

            pac.addEventListener('gmp-select', function (event) {
                var pred = event.placePrediction;
                if (!pred) return;
                var place = pred.toPlace();
                place.fetchFields({ fields: ['displayName', 'formattedAddress'] }).then(function () {
                    var name = place.displayName || '';
                    var addr = place.formattedAddress || '';
                    inputEl.value = (name && !addr.startsWith(name)) ? name + ', ' + addr : addr;
                    inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });

            var syncSi = function () {
                var si = pac.shadowRoot && pac.shadowRoot.querySelector('input');
                if (si) {
                    si.addEventListener('input', function () {
                        inputEl.value = si.value;
                        inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                    });
                } else {
                    setTimeout(syncSi, 150);
                }
            };
            syncSi();

        } catch (e) {
            inputEl.style.display = '';
            inputEl.style.opacity = '';
        }
    }

    /* ── Init ── */
    document.addEventListener('DOMContentLoaded', function () {
        // No mostrar el carrito en el panel de floristas
        if (document.querySelector('.fc-panel-body')) return;
        createFab();
        updateFab();
    });

    /* ── Public API ── */
    window.fcCart = {
        add:     addItem,
        get:     getCart,
        clear:   clearCart,
        open:    openDrawer,
        close:   closeDrawer,
        refresh: updateFab
    };

})();
