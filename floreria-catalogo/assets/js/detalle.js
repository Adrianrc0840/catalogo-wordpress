(function () {
    var data      = window.fcArreglo || {};
    var tamanos   = data.tamanos      || [];
    var schedules = data.schedules    || {};
    var whatsapp  = data.whatsapp     || '';
    var permalink = data.permalink    || window.location.href;
    var titulo    = data.titulo       || '';

    var selectedTamano    = tamanos.length > 0 ? tamanos[0] : null;
    var selectedDireccion = '';   // dirección validada por Google Places

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
            } else {
                if (cerradoEl) cerradoEl.style.display = 'none';
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

    // ── Google Places Autocomplete ──
    // Llamado como callback cuando la API de Google carga (ver floreria-catalogo.php)
    window.fcInitAutocomplete = function () {
        if (!direccionEl) return;

        var autocomplete = new google.maps.places.Autocomplete(direccionEl, {
            types:                 ['address'],
            componentRestrictions: { country: 'mx' },
            fields:                ['formatted_address'],
        });

        // Cuando el usuario elige una sugerencia
        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            if (place && place.formatted_address) {
                selectedDireccion = place.formatted_address;
                direccionEl.value = place.formatted_address;
                direccionEl.classList.remove('fc-direccion-error');
                direccionEl.classList.add('fc-direccion-ok');
            }
        });

        // Si el usuario edita el campo manualmente, la dirección ya no está validada
        direccionEl.addEventListener('input', function () {
            selectedDireccion = '';
            direccionEl.classList.remove('fc-direccion-ok');
        });
    };

    // ── Botón WhatsApp ──
    if (waBtn) {
        waBtn.addEventListener('click', function (e) {
            e.preventDefault();

            var fecha   = fechaEl   ? fechaEl.value   : '';
            var horario = horarioEl ? horarioEl.value : '';

            if (!fecha) {
                alert('Por favor selecciona una fecha de entrega.');
                return;
            }
            if (!horario) {
                alert('Por favor selecciona un horario de entrega.');
                return;
            }
            if (!selectedDireccion) {
                if (direccionEl) {
                    direccionEl.classList.add('fc-direccion-error');
                    direccionEl.focus();
                }
                alert('Por favor selecciona tu dirección de las sugerencias que aparecen al escribir.');
                return;
            }

            var tamanoNombre = selectedTamano ? selectedTamano.nombre : '';
            var precio = selectedTamano
                ? '$' + parseFloat(selectedTamano.precio).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
                : '';

            var dateObj  = new Date(fecha + 'T12:00:00');
            var dias     = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
            var meses    = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            var fechaStr = dias[dateObj.getDay()] + ', ' + dateObj.getDate() + ' de ' + meses[dateObj.getMonth()] + ' de ' + dateObj.getFullYear();

            var mensaje =
                '¡Hola! Me interesa ordenar un arreglo 🌸\n\n' +
                '🌺 *Arreglo:* '    + titulo           + '\n' +
                '📦 *Tamaño:* '     + tamanoNombre     + ' (' + precio + ')\n' +
                '📅 *Fecha:* '      + fechaStr         + '\n' +
                '🕐 *Horario:* '    + horario          + '\n' +
                '📍 *Dirección:* '  + selectedDireccion + '\n' +
                '🔗 '               + permalink        + '\n\n' +
                '¿Está disponible?';

            window.open('https://wa.me/' + whatsapp + '?text=' + encodeURIComponent(mensaje), '_blank');
        });
    }

    // ── Inicializar ──
    document.addEventListener('DOMContentLoaded', function () {
        updateDisplay();

        // Si Google Maps no está configurado, el campo de dirección funciona como texto libre
        if (!data.gmapsEnabled && direccionEl) {
            direccionEl.addEventListener('input', function () {
                selectedDireccion = this.value.trim();
            });
        }
    });
})();
