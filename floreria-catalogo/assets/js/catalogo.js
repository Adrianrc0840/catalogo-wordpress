(function () {
    var filtros       = document.querySelectorAll('.fc-filtro-btn');
    var cards         = document.querySelectorAll('.fc-card');
    var buscador      = document.getElementById('fc-buscador');
    var sinResultados = document.getElementById('fc-sin-resultados');

    var filtroActivo = 'todos';
    var busqueda     = '';

    // ── Sugerencias: categorías de los botones + títulos de las tarjetas ──
    var sugerencias = [];

    filtros.forEach(function (btn) {
        var slug = btn.dataset.categoria;
        var name = btn.textContent.trim();
        if (slug && slug !== 'todos') {
            sugerencias.push({ tipo: 'categoria', display: name, slug: slug, key: name.toLowerCase() });
        }
    });

    cards.forEach(function (card) {
        var key     = card.dataset.titulo || '';
        var titleEl = card.querySelector('.fc-card-title');
        var display = titleEl ? titleEl.textContent.trim() : key;
        if (key) {
            sugerencias.push({ tipo: 'arreglo', display: display, key: key, url: card.href });
        }
    });

    // ── Dropdown ──
    var dropdown = document.createElement('div');
    dropdown.className = 'fc-autocomplete';
    dropdown.style.display = 'none';
    if (buscador) buscador.parentNode.appendChild(dropdown);

    function cerrarDropdown() {
        dropdown.style.display = 'none';
    }

    function mostrarSugerencias(query) {
        if (!query) { cerrarDropdown(); return; }

        var cats     = sugerencias.filter(function (s) { return s.tipo === 'categoria' && s.key.includes(query); });
        var arreglos = sugerencias.filter(function (s) { return s.tipo === 'arreglo'   && s.key.includes(query); });

        if (cats.length === 0 && arreglos.length === 0) { cerrarDropdown(); return; }

        var html = '';

        if (cats.length > 0) {
            html += '<div class="fc-ac-grupo">Categorias</div>';
            cats.forEach(function (s) {
                html += '<div class="fc-ac-item" data-tipo="categoria" data-slug="' + s.slug + '" data-display="' + s.display + '">' +
                        '<span class="fc-ac-icon">&#128194;</span>' + s.display + '</div>';
            });
        }

        if (arreglos.length > 0) {
            html += '<div class="fc-ac-grupo">Arreglos</div>';
            arreglos.slice(0, 6).forEach(function (s) {
                html += '<div class="fc-ac-item" data-tipo="arreglo" data-key="' + s.key + '" data-url="' + (s.url || '') + '" data-display="' + s.display + '">' +
                        '<span class="fc-ac-icon">&#127800;</span>' + s.display + '</div>';
            });
        }

        dropdown.innerHTML = html;
        dropdown.style.display = 'block';
    }

    // Click en sugerencia — usa click, no mousedown, para evitar conflicto con blur
    dropdown.addEventListener('click', function (e) {
        var item = e.target.closest('.fc-ac-item');
        if (!item) return;

        if (item.dataset.tipo === 'categoria') {
            // Activa el botón de filtro correspondiente
            var slug = item.dataset.slug;
            var btn  = document.querySelector('.fc-filtro-btn[data-categoria="' + slug + '"]');
            if (btn) {
                buscador.value = '';
                busqueda = '';
                btn.click();
            }
        } else {
            // Filtra por nombre del arreglo
            var key = item.dataset.key;
            buscador.value = item.dataset.display || key;
            busqueda = key;
            aplicarFiltros();
        }

        cerrarDropdown();
        buscador.blur();
    });

    // ── Filtros por categoría ──
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

        // Pequeño delay en blur para que el click del dropdown alcance a disparar
        buscador.addEventListener('blur', function () {
            setTimeout(cerrarDropdown, 200);
        });

        buscador.addEventListener('focus', function () {
            if (this.value.trim()) mostrarSugerencias(this.value.toLowerCase().trim());
        });
    }

    document.addEventListener('click', function (e) {
        if (buscador && !buscador.parentNode.contains(e.target)) {
            cerrarDropdown();
        }
    });

    // ── Activar filtro desde hash (#cat=slug) ──
    var hash = window.location.hash;
    if (hash && hash.indexOf('#cat=') === 0) {
        var slug = hash.replace('#cat=', '');
        var btnHash = document.querySelector('.fc-filtro-btn[data-categoria="' + slug + '"]');
        if (btnHash) btnHash.click();
    }

})();
