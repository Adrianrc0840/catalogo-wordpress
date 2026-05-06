(function () {
    var data      = window.fcArreglo || {};
    var tamanos   = data.tamanos   || [];
    var colores   = data.colores   || [];
    var schedules = data.schedules || {};
    var whatsapp  = data.whatsapp  || '';
    var permalink = data.permalink || window.location.href;
    var titulo    = data.titulo    || '';

    var especial          = data.especial || false;
    var tamanoPrincipal   = parseInt( data.tamano_principal ) || 0;
    var selectedTamano    = tamanos.length > 0 ? tamanos[ tamanoPrincipal ] : null;
    var selectedColor  = colores.length  > 0 ? colores[0] : null;
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

    // ── Hora actual en Ensenada, BC (America/Tijuana) ──
    function getNowTijuana() {
        // toLocaleString con la zona horaria devuelve una cadena que new Date() interpreta
        // como hora local, dando un objeto Date "desplazado" al tiempo de Tijuana.
        var str = new Date().toLocaleString('en-US', { timeZone: 'America/Tijuana' });
        return new Date(str);
    }

    // ── Convierte "10:00am" o "1:00pm" a minutos desde medianoche ──
    function parseSlotStartMinutes(slot) {
        var startStr = slot.split('–')[0].trim(); // "10:00am"
        var isPm     = /pm$/i.test(startStr);
        var parts    = startStr.replace(/[apm]/gi, '').split(':');
        var h        = parseInt(parts[0]);
        var m        = parseInt(parts[1]) || 0;
        if (isPm && h !== 12) h += 12;
        if (!isPm && h === 12) h = 0;
        return h * 60 + m;
    }

    var anticipacionEl     = document.getElementById('fc-anticipacion');
    var politicasCb        = document.getElementById('fc-politicas-cb');
    var direccionHint      = document.getElementById('fc-direccion-hint');

    // ── Validación de dirección ──
    function esDireccionValida(val) {
        // Si parece un link, verificar que sea de Google Maps
        if (/https?:\/\//i.test(val)) {
            return /maps\.google\.|goo\.gl\/maps|maps\.app\.goo\.gl|google\.[a-z]+\/maps/i.test(val);
        }
        // Dirección escrita: al menos un número y al menos 2 palabras
        if (!/\d/.test(val)) return false;
        if (val.trim().split(/\s+/).length < 2) return false;
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

    // ── Selector de colores ──
    document.querySelectorAll('.fc-color-btn').forEach(function (btn, i) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.fc-color-btn').forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            selectedColor = colores[i];
            updateDisplay();
        });
    });

    function setImage(url) {
        if (!imgEl || !url) return;
        imgEl.classList.add('fc-fade');
        setTimeout(function () {
            imgEl.src = url;
            imgEl.classList.remove('fc-fade');
        }, 220);
    }

    function updateDisplay() {
        // Precio: siempre del tamaño seleccionado
        if (precioEl && selectedTamano && selectedTamano.precio) {
            var p = parseFloat(selectedTamano.precio);
            precioEl.textContent = '$' + p.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        // Imagen: color tiene prioridad sobre tamaño
        if (selectedColor && selectedColor.imagen_url) {
            setImage(selectedColor.imagen_url);
        } else if (selectedTamano && selectedTamano.imagen_url) {
            setImage(selectedTamano.imagen_url);
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

            // Hora actual en Ensenada BC (usada en ambos modos)
            var ahora    = getNowTijuana();
            var esHoy    = (date.getFullYear() === ahora.getFullYear() &&
                            date.getMonth()    === ahora.getMonth()    &&
                            date.getDate()     === ahora.getDate());
            var ahoraMin = ahora.getHours() * 60 + ahora.getMinutes();

            if (modoTipo === 'envio') {
                // Si es hoy, filtrar bloques cuya hora de inicio ya pasó

                var slotsFiltrados = esHoy
                    ? daySlots.filter(function (s) { return parseSlotStartMinutes(s) > ahoraMin; })
                    : daySlots;

                if (slotsFiltrados.length === 0) {
                    // No quedan horarios disponibles para hoy
                    diaDisponible = false;
                    if (horarioWrap) horarioWrap.classList.remove('visible');
                    if (cerradoEl) {
                        cerradoEl.textContent = 'Ya no hay horarios disponibles para hoy. Por favor elige otra fecha.';
                        cerradoEl.style.display = 'block';
                    }
                    checkFormReady();
                    return;
                }

                horarioEl.innerHTML = '<option value="">-- Selecciona un horario --</option>';
                slotsFiltrados.forEach(function (slot) {
                    var opt = document.createElement('option');
                    opt.value = opt.textContent = slot;
                    horarioEl.appendChild(opt);
                });
                if (horarioWrap) horarioWrap.classList.add('visible');
            } else {
                var rango = horariosRecoleccion[dayOfWeek];
                if (rango && horaRecoleccionEl) {
                    // Si es hoy, el mínimo es la hora actual (si es mayor que la apertura)
                    var minEfectivo = rango.min;
                    if (esHoy) {
                        var ahoraH   = String(ahora.getHours()).padStart(2, '0');
                        var ahoraM   = String(ahora.getMinutes()).padStart(2, '0');
                        var ahoraStr = ahoraH + ':' + ahoraM;
                        if (ahoraStr > rango.min) minEfectivo = ahoraStr;
                    }

                    // Si ya pasó el horario de cierre, mostrar mensaje de cerrado
                    var maxMin = (function () {
                        var p = rango.max.split(':');
                        return parseInt(p[0]) * 60 + parseInt(p[1]);
                    })();

                    if (esHoy && ahoraMin >= maxMin) {
                        diaDisponible = false;
                        if (cerradoEl) {
                            cerradoEl.textContent = 'Ya no hay horarios de recolección disponibles para hoy. Por favor elige otra fecha.';
                            cerradoEl.style.display = 'block';
                        }
                        checkFormReady();
                        return;
                    }

                    horaRecoleccionEl.min = minEfectivo;
                    horaRecoleccionEl.max = rango.max;
                    horaRecoleccionEl.value = '';

                    if (horarioHint) {
                        // Actualizar texto del hint con la hora mínima efectiva si cambió
                        if (esHoy && minEfectivo !== rango.min) {
                            var hh   = parseInt(minEfectivo.split(':')[0]);
                            var mm   = minEfectivo.split(':')[1];
                            var ampm = hh >= 12 ? 'pm' : 'am';
                            var hd   = hh % 12 || 12;
                            horarioHint.textContent = 'Horario disponible: ' + hd + ':' + mm + ampm + ' – ' + (rango.max === '20:00' ? '8:00pm' : '5:00pm');
                        } else {
                            horarioHint.textContent = rango.texto;
                        }
                    }
                }
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
            var colorNombre  = selectedColor  ? selectedColor.nombre  : '';
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
                    (colorNombre ? '*Color:* '    + colorNombre  + '\n' : '') +
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
                    (colorNombre ? '*Color:* '   + colorNombre  + '\n' : '') +
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
