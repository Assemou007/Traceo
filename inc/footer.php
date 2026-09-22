        </main>
    </div>
</div>
<script>
(function() {
    // Theme
    const root = document.documentElement;
    const saved = localStorage.getItem('traceo-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = saved || (prefersDark ? 'dark' : 'light');
    root.setAttribute('data-theme', theme);

    document.getElementById('themeToggle')?.addEventListener('click', function() {
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        localStorage.setItem('traceo-theme', next);
    });

    // Sidebar toggle (mobile)
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });

    document.getElementById('sidebarOverlay')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
    });

    // Auto-hide alerts after 5s
    document.querySelectorAll('.alert').forEach(function(el) {
        setTimeout(function() { el.style.opacity = '0'; el.style.transform = 'translateY(-10px)'; setTimeout(function() { el.remove(); }, 300); }, 5000);
    });

    // Loading state on long actions (ex: import upload)
    document.querySelectorAll('form.js-loading').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = this.querySelector('button[type="submit"]');
            if (btn && !btn.dataset.loading) {
                btn.dataset.loading = '1';
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement en cours...';
            }
        });
    });
})();
</script>
</body>
</html>
