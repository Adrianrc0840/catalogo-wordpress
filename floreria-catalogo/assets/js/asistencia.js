(function () {
    'use strict';

    var ajax      = fcAsist.ajaxurl;
    var nonce     = fcAsist.nonce;

    // ── Login ──
    var loginForm = document.getElementById('fc-asist-login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn    = loginForm.querySelector('button[type="submit"]');
            var errEl  = document.getElementById('fc-asist-login-error');
            var user   = document.getElementById('fc-asist-user').value;
            var pass   = document.getElementById('fc-asist-pass').value;
            btn.disabled    = true;
            btn.textContent = 'Entrando…';
            errEl.textContent = '';

            var body = new URLSearchParams({ action: 'fc_asistencia_login', username: user, password: pass });
            fetch(ajax, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        errEl.textContent   = res.data?.message || 'Error al iniciar sesión.';
                        btn.disabled        = false;
                        btn.textContent     = 'Entrar';
                        return;
                    }
                    // Autologin: aplica cookie y redirige al kiosco
                    window.location.href = ajax + '?action=fc_asistencia_autologin&token=' + encodeURIComponent(res.data.token);
                })
                .catch(function () {
                    errEl.textContent = 'Error de conexión.';
                    btn.disabled      = false;
                    btn.textContent   = 'Entrar';
                });
        });
        return; // No inicializar el kiosco si estamos en el login
    }

    // ── Logout ──
    var logoutBtn = document.getElementById('fc-asist-logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            if (!confirm('¿Cerrar sesión del kiosco?')) return;
            fetch(ajax, { method: 'POST', body: new URLSearchParams({ action: 'fc_asistencia_logout' }) })
                .then(function () { window.location.reload(); });
        });
    }
    var pin       = '';
    var empActual = null;
    var timerAnim = null;

    // ── Referencias DOM ──
    var stepPad  = document.getElementById('fc-step-pad');
    var stepEmp  = document.getElementById('fc-step-emp');
    var stepOk   = document.getElementById('fc-step-ok');
    var pinLabel = document.getElementById('fc-pin-label');
    var dots     = [0, 1, 2, 3].map(function (i) { return document.getElementById('fc-dot-' + i); });

    // ── Actualizar display de dígitos ──
    function actualizarDisplay() {
        dots.forEach(function (dot, i) {
            dot.classList.toggle('filled', i < pin.length);
        });
    }

    // ── Shake de error ──
    function shakeError(msg) {
        var display = document.getElementById('fc-pin-display');
        display.classList.add('shake');
        pinLabel.textContent = msg;
        pinLabel.classList.add('error');
        setTimeout(function () {
            display.classList.remove('shake');
            pinLabel.textContent = 'Ingresa tu número de empleada';
            pinLabel.classList.remove('error');
            pin = '';
            actualizarDisplay();
        }, 1400);
    }

    // ── Buscar empleada ──
    function buscarEmpleada() {
        pinLabel.textContent = 'Buscando…';
        var body = new URLSearchParams({ action: 'fc_asistencia_buscar', nonce: nonce, numero: pin });
        fetch(ajax, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    shakeError('Número no encontrado. Intenta de nuevo.');
                    return;
                }
                empActual = res.data;
                mostrarTarjeta();
            })
            .catch(function () {
                shakeError('Error de conexión. Intenta de nuevo.');
            });
    }

    // ── Mostrar tarjeta de empleada ──
    function mostrarTarjeta() {
        var fotoEl   = document.getElementById('fc-emp-foto');
        var avatarEl = document.getElementById('fc-emp-avatar');

        document.getElementById('fc-emp-nombre').textContent   = empActual.nombre;
        document.getElementById('fc-emp-posicion').textContent = empActual.posicion || '';

        if (empActual.foto_url) {
            fotoEl.src              = empActual.foto_url;
            fotoEl.style.display    = '';
            avatarEl.style.display  = 'none';
        } else {
            fotoEl.style.display   = 'none';
            avatarEl.style.display = '';
        }

        stepPad.style.display = 'none';
        stepEmp.style.display = '';
    }

    // ── Fichar ──
    function fichar(tipo) {
        document.getElementById('fc-btn-entrada').disabled = true;
        document.getElementById('fc-btn-salida').disabled  = true;

        var body = new URLSearchParams({
            action:      'fc_asistencia_fichar',
            nonce:       nonce,
            empleado_id: empActual.id,
            tipo:        tipo,
        });

        fetch(ajax, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    alert('Error al registrar. Intenta de nuevo.');
                    resetearKiosco();
                    return;
                }
                mostrarConfirmacion(tipo, res.data.hora);
            })
            .catch(function () {
                alert('Error de conexión.');
                resetearKiosco();
            });
    }

    // ── Mostrar confirmación ──
    function mostrarConfirmacion(tipo, hora) {
        var iconEl  = document.getElementById('fc-confirm-icon');
        var tipoEl  = document.getElementById('fc-confirm-tipo');
        var horaEl  = document.getElementById('fc-confirm-hora');
        var nomEl   = document.getElementById('fc-confirm-nombre');
        var timerEl = document.getElementById('fc-timer-bar');

        nomEl.textContent = '¡Gracias, ' + empActual.nombre.split(' ')[0] + '!';

        if (tipo === 'entrada') {
            tipoEl.textContent = '✅ Entrada registrada';
            tipoEl.className   = 'fc-confirm-tipo entrada';
            iconEl.textContent = '✓';
            iconEl.className   = 'fc-confirm-check entrada';
        } else {
            tipoEl.textContent = '👋 Salida registrada';
            tipoEl.className   = 'fc-confirm-tipo salida';
            iconEl.textContent = '✓';
            iconEl.className   = 'fc-confirm-check salida';
        }

        horaEl.textContent = hora;

        stepEmp.style.display = 'none';
        stepOk.style.display  = '';

        // Barra de cuenta regresiva (5 segundos)
        timerEl.style.transition = 'none';
        timerEl.style.width      = '100%';
        setTimeout(function () {
            timerEl.style.transition = 'width 5s linear';
            timerEl.style.width      = '0%';
        }, 50);

        timerAnim = setTimeout(resetearKiosco, 5000);
    }

    // ── Resetear al estado inicial ──
    function resetearKiosco() {
        clearTimeout(timerAnim);
        pin       = '';
        empActual = null;
        actualizarDisplay();
        pinLabel.textContent = 'Ingresa tu número de empleada';
        pinLabel.classList.remove('error');
        document.getElementById('fc-btn-entrada').disabled = false;
        document.getElementById('fc-btn-salida').disabled  = false;
        stepOk.style.display  = 'none';
        stepEmp.style.display = 'none';
        stepPad.style.display = '';
    }

    // ── Teclado numérico ──
    document.querySelectorAll('.fc-num-btn[data-n]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (pin.length >= 4) return;
            pin += btn.dataset.n;
            actualizarDisplay();
            if (pin.length === 4) {
                setTimeout(buscarEmpleada, 120); // pequeña pausa visual
            }
        });
    });

    document.getElementById('fc-del-btn').addEventListener('click', function () {
        if (pin.length > 0) {
            pin = pin.slice(0, -1);
            actualizarDisplay();
        }
    });

    // Soporte teclado físico
    document.addEventListener('keydown', function (e) {
        if (stepPad.style.display === 'none') return;
        if (e.key >= '0' && e.key <= '9' && pin.length < 4) {
            pin += e.key;
            actualizarDisplay();
            if (pin.length === 4) setTimeout(buscarEmpleada, 120);
        } else if (e.key === 'Backspace' && pin.length > 0) {
            pin = pin.slice(0, -1);
            actualizarDisplay();
        }
    });

    // ── Botones entrada / salida / cancelar ──
    document.getElementById('fc-btn-entrada').addEventListener('click', function () { fichar('entrada'); });
    document.getElementById('fc-btn-salida').addEventListener('click',  function () { fichar('salida'); });
    document.getElementById('fc-btn-cancelar').addEventListener('click', resetearKiosco);

})();
