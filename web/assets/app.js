// Centralized UI behaviors for sidebar and profile modal
(function () {
    function qs(sel) { return document.querySelector(sel); }
    function qsa(sel) { return Array.from(document.querySelectorAll(sel)); }

    function setAriaOpen(isOpen) {
        const toggle = qs('#mobileMenuToggle');
        const overlay = qs('#sidebarOverlay');
        if (toggle) toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (overlay) overlay.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    window.toggleSidebar = function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (!sidebar || !overlay) return;
        const willOpen = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        setAriaOpen(willOpen);
        if (willOpen) {
            // move focus to first link for accessibility
            const firstLink = sidebar.querySelector('.nav-list a, .nav-item');
            if (firstLink) firstLink.focus();
        }
    };

    window.openProfileModal = function openProfileModal() {
        const modal = document.getElementById('profileModal');
        if (!modal) return;
        modal.style.display = 'flex';
        const input = modal.querySelector('input[type="text"], input[type="email"], input:not([type])');
        if (input) input.focus();
    };

    window.closeProfileModal = function closeProfileModal() {
        const modal = document.getElementById('profileModal');
        if (!modal) return;
        modal.style.display = 'none';
    };

    function init() {
        const toggle = qs('#mobileMenuToggle');
        const overlay = qs('#sidebarOverlay');
        const openProfileBtn = qs('#openProfileBtn');

        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                toggleSidebar();
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function () {
                const sidebar = qs('#sidebar');
                if (sidebar && sidebar.classList.contains('open')) toggleSidebar();
            });
        }

        if (openProfileBtn) {
            openProfileBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openProfileModal();
            });
        }

        // close modal when clicking the modal background
        document.addEventListener('click', function (e) {
            const modal = qs('#profileModal');
            if (!modal) return;
            if (e.target === modal) closeProfileModal();
        });

        // close on Escape
        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const sidebar = qs('#sidebar');
                const overlay = qs('#sidebarOverlay');
                if (overlay && overlay.classList.contains('active')) {
                    toggleSidebar();
                    return;
                }
                const modal = qs('#profileModal');
                if (modal && modal.style.display === 'flex') {
                    closeProfileModal();
                }
            }
        });

        // Ensure clicking a nav link closes mobile sidebar
        document.addEventListener('click', function (e) {
            const link = e.target.closest && e.target.closest('.nav-list a, .nav-item');
            if (link && window.innerWidth <= 768) {
                const sidebar = qs('#sidebar');
                const overlay = qs('#sidebarOverlay');
                if (sidebar && overlay && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
            }
        });

        // Ensure the aria states reflect initial state
        setAriaOpen(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
