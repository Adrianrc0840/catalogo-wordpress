(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var tabs   = document.querySelectorAll('.fc-pol-tab');
        var panels = document.querySelectorAll('.fc-pol-panel');
        if (!tabs.length) return;

        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = this.dataset.tab;
                tabs.forEach(function (b) {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                panels.forEach(function (p) { p.classList.remove('active'); });
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');
                var panel = document.querySelector('.fc-pol-panel[data-panel="' + idx + '"]');
                if (panel) panel.classList.add('active');
            });
        });
    });
})();
