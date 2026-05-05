(function () {
    var data      = window.fcArreglo || {};
    var tamanos   = data.tamanos   || [];
    var schedules = data.schedules || {};
    var whatsapp  = data.whatsapp  || '';
    var permalink = data.permalink || window.location.href;
    var titulo    = data.titulo    || '';

    var selectedTamano = tamanos.length > 0 ? tamanos[0] : null;

    var imgEl       = document.getElementById('fc-main-img');
    var precioEl    = document.getElementById('fc-precio-val');
    var fechaEl     = document.getElementById('fc-fecha');
    var horarioWrap = document.getElementById('fc-horario-wrap');
    var horarioEl   = document.getElementById('fc-horario');
    var waBtn       = document.getElementById('fc-wa-btn');
    var cerradoEl   = document.getElementById('fc-cerrado');
    var direccionEl = document.getElementById('fc-direccion');

    // ── Selector de tamaños ──
    document.querySelectorAll('.fc-tamano-btn').forEach(function (btn, i) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.fc-tamano-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            this.classList.add('active');
            selectedTamano = tamanos[i];
            updateDisplay();
        });
    });

    function updateDisplay() {
        if (!selectedTamano) return;

        if (imgEl && selectedTamano.imagen_url) {
            imgEl.classList.add('fc-fade');
            setTimeout(function () {
                imgEl.src = selectedTamano.imagen_url;
                imgEl.classList.remove('fc-fade');
            }, 220);
        }

        if (precioEl && selectedTamano.precio) {
            var p = parseFloat(selectedTamano.precio);
            precioEl.textContent = '$' + p.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }
    }

    // ── Fecha → horarios ──
    if (fechaEl) {
        var today = new Date();
        var yyyy  = today.getFullYear();
        var mm    = String(today.getMonth() + 1).padStart(2, '0');
        var dd    = String(today.getDate()).padStart(2, '0');
        fechaEl.min = yyyy + '-' + mm + '-' + dd;

        fechaEl.addEventListener('change', function () {
            var date      = new Date(this.value + 'T12:00:00');
            var dayOfWeek = String(date.getDay());
            var daySlots  = schedules[dayOfWeek] || [];

            horarioEl.innerHTML = '<option value="">-- Selecciona un horario --</option>';

            if (daySlots.length === 0) {
                horarioWrap.classList.remove('visible');
                if (cerradoEl) cerradoEl.style.display = 'block';
                if (waBtn) { waBtn.style.pointerEvents = 'none'; waBtn.style.opacity = '0.4'; }
            } else {
                if (cerradoEl) cerradoEl.style.display = 'none';
                if (waBtn) { waBtn.style.pointerEvents = ''; waBtn.style.opacity = ''; }
                daySlots.forEach(function (slot) {
                    var opt         = document.createElement('option');
                    opt.value       = slot;
                    opt.textContent = slot;
                    horarioEl.appendChild(opt);
                });
                horarioWrap.classList.add('visible');
            }
        });
    }

    // ── Botón WhatsApp ──
    if (waBtn) {
        waBtn.addEventListener('click', function (e) {
            e.preventDefault();

            var fecha     = fechaEl     ? fechaEl.value.trim()     : '';
            var horario   = horarioEl   ? horarioEl.value.trim()   : '';
            var direccion = direccionEl ? direccionEl.value.trim()  : '';

            if (!fecha) {
                alert('Por favor selecciona una fecha de entrega.');
                return;
            }
            if (!horario) {
                alert('Por favor selecciona un horario de entrega.');
                return;
            }
            if (!direccion) {
                if (direccionEl) direccionEl.focus();
                alert('Por favor escribe tu direccion de entrega.');
                return;
            }

            var tamanoNombre = selectedTamano ? selectedTamano.nombre : '';
            var precio = selectedTamano
                ? '$' + parseFloat(selectedTamano.precio).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
                : '';

            var dateObj  = new Date(fecha + 'T12:00:00');
            var dias     = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
            var meses    = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            var fechaStr = dias[dateObj.getDay()] + ', ' + dateObj.getDate() + ' de ' + meses[dateObj.getMonth()] + ' de ' + dateObj.getFullYear();

            var mensaje =
                'Hola! Me interesa ordenar un arreglo.\n\n' +
                '*Arreglo:* '   + titulo    + '\n' +
                '*Tamano:* '    + tamanoNombre + ' (' + precio + ')\n' +
                '*Fecha:* '     + fechaStr  + '\n' +
                '*Horario:* '   + horario   + '\n' +
                '*Direccion:* ' + direccion + '\n' +
                '*Link:* '      + permalink + '\n\n' +
                'Esta disponible?';

            window.open('https://wa.me/' + whatsapp + '?text=' + encodeURIComponent(mensaje), '_blank');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateDisplay();

        // ── Lightbox ──
        var lightbox     = document.getElementById('fc-lightbox');
        var lightboxImg  = document.getElementById('fc-lightbox-img');
        var lightboxClose = document.getElementById('fc-lightbox-close');
        var imgWrap      = document.querySelector('.fc-detalle-img-wrap');
        var triggerBtn   = document.querySelector('.fc-lightbox-trigger');

        function openLightbox() {
            if (!lightbox || !imgEl) return;
            lightboxImg.src = imgEl.src;
            lightboxImg.alt = imgEl.alt;
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            if (!lightbox) return;
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }

        if (imgWrap)      imgWrap.addEventListener('click', openLightbox);
        if (triggerBtn)   triggerBtn.addEventListener('click', function(e) { e.stopPropagation(); openLightbox(); });
        if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
        if (lightbox)     lightbox.addEventListener('click', function(e) { if (e.target === lightbox) closeLightbox(); });

        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeLightbox(); });
    });
})();
