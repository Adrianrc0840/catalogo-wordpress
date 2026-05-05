(function () {
    // El script carga en el footer — el DOM ya está listo, no necesita DOMContentLoaded

    var filtros       = document.querySelectorAll('.fc-filtro-btn');
    var cards         = document.querySelectorAll('.fc-card');
    var buscador      = document.getElementById('fc-buscador');
    var sinResultados = document.getElementById('fc-sin-resultados');

    var filtroActivo = 'todos';
    var busqueda     = '';

    // ── Construir lista de sugerencias desde el DOM ──
    var sugerencias = [];
    var categoriasVistas = {};

    cards.forEach(function (card) {
        var tituloKey = card.dataset.titulo || '';
        var catKey    = card.dataset.categoria || '';
        var tituloDisplay = card.querySelector('.fc-card-title')
            ? card.querySelector('.fc-card-title').textContent.trim()
            : tituloKey;
        var catDisplay = card.querySelector('.fc-card-cat')
            ? card.querySelector('.fc-card-cat').textContent.trim()
            : catKey;

        if (tituloKey) {
            sugerencias.push({ tipo: 'arreglo', key: tituloKey, display: tituloDisplay });
        }
        if (catKey && !categoriasVistas[catKey]) {
            categoriasVistas[catKey] = true;
            sugerencias.push({ tipo: 'categoria', key: catKey, display: catDisplay });
        }
    });

    // ── Autocomplete dropdown ──
    var dropdown = document.createElement('div');
    dropdown.className = 'fc-autocomplete';
    dropdown.style.display = 'none';
    if (buscador) {
        buscador.parentNode.appendChild(dropdown);
    }

    function mostrarSugerencias(query) {
        if (!query || query.length < 1) {
            dropdown.style.display = 'none';
            return;
        }

        var arreglosMatch   = sugerencias.filter(function (s) { return s.tipo === 'arreglo'   && s.key.includes(query); });
        var categoriasMatch = sugerencias.filter(function (s) { return s.tipo === 'categoria' && s.key.includes(query); });

        if (arreglosMatch.length === 0 && categoriasMatch.length === 0) {
            dropdown.style.display = 'none';
            return;
        }

        var html = '';

        if (categoriasMatch.length > 0) {
            html += '<div class="fc-ac-grupo">Categorías</div>';
            categoriasMatch.forEach(function (s) {
                html += '<div class="fc-ac-item" data-key="' + s.key + '" data-tipo="categoria">&#128194; ' + s.display + '</div>';
            });
        }

        if (arreglosMatch.length > 0) {
            html += '<div class="fc-ac-grupo">Arreglos</div>';
            arreglosMatch.slice(0, 6).forEach(function (s) {
                html += '<div class="fc-ac-item" data-key="' + s.key + '" data-tipo="arreglo">&#127800; ' + s.display + '</div>';
            });
        }

        dropdown.innerHTML = html;
        dropdown.style.display = 'block';

        dropdown.querySelectorAll('.fc-ac-item').forEach(function (item) {
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                var key = this.dataset.key;
                buscador.value = this.textContent.trim().substring(2); // quitar el emoji prefix
                busqueda = key;
                dropdown.style.display = 'none';
                aplicarFiltros();
            });
        });
    }

    // ── Aplicar filtros combinados ──
    function aplicarFiltros() {
        var visibles = 0;

        cards.forEach(function (card) {
            var titulo    = card.dataset.titulo    || '';
            var categoria = card.dataset.categoria || '';

            var coincideBusqueda  = busqueda === '' || titulo.includes(busqueda) || categoria.includes(busqueda);
            var coincideCategoria = filtroActivo === 'todos' || card.classList.contains(filtroActivo);

            var mostrar = coincideBusqueda && coincideCategoria;
            card.classList.toggle('hidden', !mostrar);
            if (mostrar) visibles++;
        });

        if (sinResultados) {
            sinResultados.style.display = visibles === 0 ? 'block' : 'none';
        }
    }

    // ── Filtros por categoría ──
    filtros.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filtros.forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            filtroActivo = this.dataset.categoria;
            aplicarFiltros();
        });
    });

    // ── Buscador ──
    if (buscador) {
        buscador.addEventListener('input', function () {
            busqueda = this.value.toLowerCase().trim();
            mostrarSugerencias(busqueda);
            aplicarFiltros();
        });

        buscador.addEventListener('blur', function () {
            setTimeout(function () { dropdown.style.display = 'none'; }, 150);
        });

        buscador.addEventListener('focus', function () {
            if (this.value.trim().length > 0) {
                mostrarSugerencias(this.value.toLowerCase().trim());
            }
        });
    }

    // Cerrar dropdown al hacer clic fuera
    document.addEventListener('click', function (e) {
        if (buscador && !buscador.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

})();
