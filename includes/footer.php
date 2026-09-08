        </div><!-- /.content-area -->
    </div><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<?php if (isset($extraJS)): foreach ($extraJS as $js): ?>
<script src="<?= $js ?>"></script>
<?php endforeach; endif; ?>

<script>
/* Global password toggle */
function togglePassword(fieldId, btn) {
    const input = typeof fieldId === 'string' ? document.getElementById(fieldId) : btn.previousElementSibling;
    if (!input) return;
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

/* Preserve sidebar scroll position across page navigations */
(function() {
    const sidebar = document.querySelector('.sidebar-nav');
    if (!sidebar) return;
    // Restore saved scroll position
    const saved = sessionStorage.getItem('sidebarScrollTop');
    if (saved !== null) sidebar.scrollTop = parseInt(saved, 10);
    // Save scroll position before navigating away
    sidebar.addEventListener('scroll', function() {
        sessionStorage.setItem('sidebarScrollTop', sidebar.scrollTop);
    });
    // Also save on any nav link click
    sidebar.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', function() {
            sessionStorage.setItem('sidebarScrollTop', sidebar.scrollTop);
        });
    });
})();
</script>
<script src="<?= ASSETS_URL ?>/js/app.js"></script>
</body>
</html>
