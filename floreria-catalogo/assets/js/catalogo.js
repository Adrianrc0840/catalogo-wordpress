(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var filtros      = document.querySelectorAll('.fc-filtro-btn');
        var cards        = document.querySelectorAll('.fc-card');
        var buscador     = document.getElementById('fc-buscador');
        var sinResultados = document.getElementById('fc-sin-resultados');

        var filtroActivo = 'todos';
        var busqueda     = '';

        function aplicarFiltros() {
            var visibles = 0;

            cards.forEach(function (card) {
                var titulo    = card.dataset.titulo    || '';
                var categoria = card.dataset.categoria || '';
                var clases    = card.className;

                var coincideBusqueda = busqueda === '' ||
                    titulo.includes(busqueda) ||
                    categoria.includes(busqueda);

                var coincideCategoria = filtroActivo === 'todos' ||
                    card.classList.contains(filtroActivo);

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

        // ── Buscador en tiempo real ──
        if (buscador) {
            buscador.addEventListener('input', function () {
                busqueda = this.value.toLowerCase().trim();
                aplicarFiltros();
            });
        }
    });
})();
