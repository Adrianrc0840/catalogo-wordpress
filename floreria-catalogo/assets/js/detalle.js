(function () {
    var data      = window.fcArreglo || {};
    var tamanos   = data.tamanos   || [];
    var schedules = data.schedules || {};
    var whatsapp  = data.whatsapp  || '';
    var permalink = data.permalink || window.location.href;
    var titulo    = data.titulo    || '';

    var especial       = data.especial || false;
    var selectedTamano = tamanos.length > 0 ? tamanos[0] : null;
    var diaDisponible  = true;
    var fechaValida    = true; // false cuando especial y no hay 2 días hábiles
    var modoTipo       = 'envio'; // 'envio' | 'recoleccion'

    var dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    var meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    // Horario de atención para recolección por día de la semana
    var horariosRecoleccion = {
        '1': { min: '10:00', max: '20:00', texto: 'Horario de atención: 10:00am – 8:00pm' },
        '2': { min: '10:00', max: '20:00', texto: 'Horario de atención: 10:00am – 8:00pm' },
        '3': { min: '10:00', max: '20:00', texto: 'Horario de atención: 10:00am – 8:00pm' },
        '4': { min: '10:00', max: '20:00', texto: 'Horario de atención: 10:00am – 8:00pm' },
        '5': { min: '10:00', max: '20:00', texto: 'Horario de atención: 10:00am – 8:00pm' },
        '6': { min: '10:00', max: '17:00', texto: 'Horario de atención: 10:00am – 5:00pm' },
    };

    var anticipacionEl     = document.getElementById('fc-anticipacion');
    var politicasCb        = document.getElementById('fc-politicas-cb');
    var direccionHint      = document.getElementById('fc-direccion-hint');

    // ── Validación de dirección ──
    function esDireccionValida(val) {
        if (val.length < 15)          return false; // mínimo 15 caracteres
        if (!/\d/.test(val))          return false; // al menos un número
        if (val.trim().split(/\s+/).length < 2) return false; // al menos 2 palabras
        return true;
    }

    var direccionTocada = false; // solo mostrar hint si el usuario ya escribió algo

    // Cuenta días hábiles estrictamente entre dos fechas (sin incluir ninguna de las dos)
    function countBusinessDays(from, to) {
        var count = 0;
        var d = new Date(from);
        d.setDate(d.getDate() + 1);
        while (d < to) {
            var day = d.getDay();
            if (day !== 0 && day !== 6) count++;
            d.setDate(d.getDate() + 1);
        }
        return count;
    }

    var imgEl              = document.getElementById('fc-main-img');
    var precioEl           = document.getElementById('fc-precio-val');
    var fechaEl            = document.getElementById('fc-fecha');
    var fechaWrap          = document.getElementById('fc-fecha-wrap');
    var fechaDisplayEl     = document.getElementById('fc-fecha-display');
    var horarioWrap        = document.getElementById('fc-horario-wrap');
    var horarioEl          = document.getElementById('fc-horario');
    var waBtn              = document.getElementById('fc-wa-btn');
    var cerradoEl          = document.getElementById('fc-cerrado');
    var direccionEl        = document.getElementById('fc-direccion');
    var envioSection       = document.getElementById('fc-envio-section');
    var recoleccionSection = document.getElementById('fc-recoleccion-section');
    var horaRecoleccionEl  = document.getElementById('fc-hora-recoleccion');
    var horarioHint        = document.getElementById('fc-horario-hint');
    var tipoBtns           = document.querySelectorAll('.fc-tipo-btn');

    // ── Toggle envío / recolección ──
    tipoBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tipoBtns.forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            modoTipo = this.dataset.tipo;

            if (modoTipo === 'envio') {
                if (envioSection)       envioSection.style.display = '';
                if (recoleccionSection) recoleccionSection.style.display = 'none';
            } else {
                if (envioSection)       envioSection.style.display = 'none';
                if (recoleccionSection) recoleccionSection.style.display = '';
            }

            if (fechaEl && fechaEl.value) onFechaChange();
            checkFormReady();
        });
    });

    // ── Abrir calendario / reloj al picar en cualquier parte del wrapper ──
    if (fechaWrap && fechaEl) {
        fechaWrap.addEventListener('click', function () {
            try { fechaEl.showPicker(); } catch (e) { fechaEl.focus(); }
        });
    }

    var horaWrap = document.getElementById('fc-hora-wrap');
    if (horaWrap && horaRecoleccionEl) {
        horaWrap.addEventListener('click', function () {
            try { horaRecoleccionEl.showPicker(); } catch (e) { horaRecoleccionEl.focus(); }
        });
    }

    // ── Verifica si todos los campos están llenos ──
    function checkFormReady() {
        var fecha      = fechaEl      ? fechaEl.value.trim()       : '';
        var politicas  = politicasCb  ? politicasCb.checked        : true;
        var listo      = false;

        if (modoTipo === 'envio') {
            var horario   = horarioEl   ? horarioEl.value.trim()  : '';
            var direccion = direccionEl ? direccionEl.value.trim() : '';
            var dirValida = esDireccionValida(direccion);

            // Mostrar/ocultar hint y borde de error solo si el usuario ya escribió algo
            if (direccionTocada && direccion !== '') {
                if (direccionHint) direccionHint.style.display = !dirValida ? 'block' : 'none';
                if (direccionEl)   direccionEl.classList.toggle('fc-input-error', !dirValida);
            } else {
                if (direccionHint) direccionHint.style.display = 'none';
                if (direccionEl)   direccionEl.classList.remove('fc-input-error');
            }

            listo = fecha !== '' && horario !== '' && dirValida && diaDisponible && fechaValida && politicas;
        } else {
            var hora = horaRecoleccionEl ? horaRecoleccionEl.value.trim() : '';
            listo = fecha !== '' && hora !== '' && diaDisponible && fechaValida && politicas;
        }

        if (waBtn) waBtn.classList.toggle('fc-btn-disabled', !listo);
    }

    // ── Selector de tamaños ──
    document.querySelectorAll('.fc-tamano-btn').forEach(function (btn, i) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.fc-tamano-btn').forEach(function (b) { b.classList.remove('active'); });
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

    // ── Fecha → actualizar UI según modo ──
    function onFechaChange() {
        var val = fechaEl.value;
        if (!val) return;

        var date      = new Date(val + 'T12:00:00');
        var dayOfWeek = String(date.getDay());
        var daySlots  = schedules[dayOfWeek] || [];

        if (fechaDisplayEl) {
            fechaDisplayEl.textContent = dias[date.getDay()] + ', ' + date.getDate() + ' de ' + meses[date.getMonth()] + ' de ' + date.getFullYear();
        }

        // Validación de anticipación para arreglos especiales
        if (especial) {
            var hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            var seleccionada = new Date(val + 'T00:00:00');
            var diasHabiles = countBusinessDays(hoy, seleccionada);
            if (diasHabiles < 2) {
                fechaValida = false;
                if (anticipacionEl) {
                    anticipacionEl.textContent = 'Este arreglo necesita al menos 2 días hábiles de anticipación. Por favor elige una fecha posterior.';
                    anticipacionEl.style.display = 'block';
                }
            } else {
                fechaValida = true;
                if (anticipacionEl) anticipacionEl.style.display = 'none';
            }
        }

        if (daySlots.length === 0) {
            diaDisponible = false;
            if (horarioWrap) horarioWrap.classList.remove('visible');
            if (cerradoEl) {
                cerradoEl.textContent = date.getDay() === 0
                    ? 'Lo sentimos, no laboramos los domingos.'
                    : 'Lo sentimos, ese día no realizamos entregas.';
                cerradoEl.style.display = 'block';
            }
        } else {
            diaDisponible = true;
            if (cerradoEl) cerradoEl.style.display = 'none';

            if (modoTipo === 'envio') {
                horarioEl.innerHTML = '<option value="">-- Selecciona un horario --</option>';
                daySlots.forEach(function (slot) {
                    var opt = document.createElement('option');
                    opt.value = opt.textContent = slot;
                    horarioEl.appendChild(opt);
                });
                if (horarioWrap) horarioWrap.classList.add('visible');
            } else {
                var rango = horariosRecoleccion[dayOfWeek];
                if (rango && horaRecoleccionEl) {
                    horaRecoleccionEl.min = rango.min;
                    horaRecoleccionEl.max = rango.max;
                    horaRecoleccionEl.value = '';
                }
                if (horarioHint) horarioHint.textContent = rango ? rango.texto : '';
            }
        }

        checkFormReady();
    }

    if (fechaEl) {
        var today = new Date();
        var yyyy  = today.getFullYear();
        var mm    = String(today.getMonth() + 1).padStart(2, '0');
        var dd    = String(today.getDate()).padStart(2, '0');
        fechaEl.min = yyyy + '-' + mm + '-' + dd;

        fechaEl.addEventListener('change', onFechaChange);
        fechaEl.addEventListener('input',  onFechaChange);
    }

    if (horarioEl)   horarioEl.addEventListener('change',  checkFormReady);
    if (direccionEl) direccionEl.addEventListener('input', function () {
        direccionTocada = true;
        checkFormReady();
    });
    if (politicasCb) politicasCb.addEventListener('change',  checkFormReady);

    function onHoraRecoleccionChange() {
        if (!horaRecoleccionEl || !horaRecoleccionEl.value) { checkFormReady(); return; }

        var min  = horaRecoleccionEl.min || '10:00';
        var max  = horaRecoleccionEl.max || '20:00';
        var val  = horaRecoleccionEl.value;

        // Comparar como strings HH:MM es suficiente (mismo formato)
        if (val < min || val > max) {
            horaRecoleccionEl.value = '';
            if (horarioHint) {
                var textoOriginal = horarioHint.textContent;
                horarioHint.style.color = '#c44';
                horarioHint.textContent = 'Elige una hora dentro del horario de atención.';
                setTimeout(function () {
                    horarioHint.style.color = '';
                    horarioHint.textContent = textoOriginal;
                }, 2500);
            }
        }

        checkFormReady();
    }

    if (horaRecoleccionEl) horaRecoleccionEl.addEventListener('change', onHoraRecoleccionChange);
    if (horaRecoleccionEl) horaRecoleccionEl.addEventListener('input',  onHoraRecoleccionChange);

    // ── Botón WhatsApp ──
    if (waBtn) {
        waBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (this.classList.contains('fc-btn-disabled')) return;

            var fecha = fechaEl ? fechaEl.value.trim() : '';
            if (!fecha) return;

            var tamanoNombre = selectedTamano ? selectedTamano.nombre : '';
            var precio = selectedTamano
                ? '$' + parseFloat(selectedTamano.precio).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
                : '';

            var dateObj  = new Date(fecha + 'T12:00:00');
            var fechaStr = dias[dateObj.getDay()] + ', ' + dateObj.getDate() + ' de ' + meses[dateObj.getMonth()] + ' de ' + dateObj.getFullYear();

            var mensaje;

            if (modoTipo === 'envio') {
                var horario   = horarioEl   ? horarioEl.value.trim()  : '';
                var direccion = direccionEl ? direccionEl.value.trim() : '';
                mensaje =
                    'Hola! Me interesa ordenar un arreglo.\n\n' +
                    '*Arreglo:* '   + titulo       + '\n' +
                    '*Tamano:* '    + tamanoNombre + ' (' + precio + ')\n' +
                    '*Tipo:* Envio a domicilio\n' +
                    '*Fecha:* '     + fechaStr     + '\n' +
                    '*Horario:* '   + horario      + '\n' +
                    '*Direccion:* ' + direccion    + '\n' +
                    '*Link:* '      + permalink    + '\n\n' +
                    'Esta disponible?';
            } else {
                var horaRaw = horaRecoleccionEl ? horaRecoleccionEl.value : '';
                var horaStr = horaRaw;
                if (horaRaw) {
                    var parts = horaRaw.split(':');
                    var h     = parseInt(parts[0]);
                    var ampm  = h >= 12 ? 'pm' : 'am';
                    h         = h % 12 || 12;
                    horaStr   = h + ':' + parts[1] + ampm;
                }
                mensaje =
                    'Hola! Me interesa ordenar un arreglo.\n\n' +
                    '*Arreglo:* '  + titulo       + '\n' +
                    '*Tamano:* '   + tamanoNombre + ' (' + precio + ')\n' +
                    '*Tipo:* Recoleccion en tienda\n' +
                    '*Fecha:* '    + fechaStr     + '\n' +
                    '*Hora:* '     + horaStr      + '\n' +
                    '*Link:* '     + permalink    + '\n\n' +
                    'Esta disponible?';
            }

            window.open('https://wa.me/' + whatsapp + '?text=' + encodeURIComponent(mensaje), '_blank');
        });
    }

    // ── Lightbox ──
    document.addEventListener('DOMContentLoaded', function () {
        updateDisplay();

        var lightbox      = document.getElementById('fc-lightbox');
        var lightboxImg   = document.getElementById('fc-lightbox-img');
        var lightboxClose = document.getElementById('fc-lightbox-close');
        var imgWrap       = document.querySelector('.fc-detalle-img-wrap');
        var triggerBtn    = document.querySelector('.fc-lightbox-trigger');

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

        if (imgWrap)       imgWrap.addEventListener('click', openLightbox);
        if (triggerBtn)    triggerBtn.addEventListener('click', function (e) { e.stopPropagation(); openLightbox(); });
        if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
        if (lightbox)      lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });

        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLightbox(); });
    });
})();
