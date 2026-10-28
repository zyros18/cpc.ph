document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('menuToggle');
    const backdrop = document.getElementById('sidebarBackdrop');

    function setMenuOpen(open) {
        if (!sidebar || !toggle || !backdrop) return;
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('visible', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }

    if (toggle && sidebar && backdrop) {
        toggle.addEventListener('click', function () {
            setMenuOpen(!sidebar.classList.contains('open'));
        });
        backdrop.addEventListener('click', function () {
            setMenuOpen(false);
        });
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setMenuOpen(false);
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setMenuOpen(false);
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
});
