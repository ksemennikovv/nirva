    </div><!-- .adm-content -->
</main><!-- .adm-main -->

<script>
(function() {
    var burger  = document.getElementById('adm-burger');
    var sidebar = document.getElementById('adm-sidebar');
    var overlay = document.getElementById('adm-overlay');
    if (!burger || !sidebar) return;

    function open() {
        sidebar.classList.add('open');
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    burger.addEventListener('click', function() {
        sidebar.classList.contains('open') ? close() : open();
    });
    overlay.addEventListener('click', close);

    // Закрываем при клике на ссылку в меню (на мобиле)
    sidebar.querySelectorAll('a').forEach(function(a) {
        a.addEventListener('click', function() {
            if (window.innerWidth <= 768) close();
        });
    });
})();
</script>
</body>
</html>
