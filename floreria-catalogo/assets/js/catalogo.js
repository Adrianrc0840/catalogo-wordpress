(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const filtros = document.querySelectorAll('.fc-filtro-btn');
        const cards   = document.querySelectorAll('.fc-card');

        filtros.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const categoria = this.dataset.categoria;

                filtros.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');

                cards.forEach(function (card) {
                    const mostrar = categoria === 'todos' || card.classList.contains(categoria);
                    card.classList.toggle('hidden', !mostrar);
                });
            });
        });
    });
})();
