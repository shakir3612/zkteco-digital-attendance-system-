/**
 * ZKTeco Attendance Dashboard - JavaScript
 * Auto-refresh, AJAX helpers, UI interactions
 */

(function() {
    'use strict';

    // Auto-refresh for cards with data-auto-refresh attribute
    function initAutoRefresh() {
        const cards = document.querySelectorAll('[data-auto-refresh]');
        cards.forEach(card => {
            const interval = parseInt(card.getAttribute('data-auto-refresh')) * 1000;
            if (interval > 0) {
                setInterval(() => {
                    // Reload the full page for simplicity
                    location.reload();
                }, interval);
            }
        });
    }

    // Sidebar toggle
    function initSidebar() {
        const toggle = document.querySelector('.sidebar-toggle');
        if (toggle) {
            toggle.addEventListener('click', () => {
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebar-collapsed', 
                    document.body.classList.contains('sidebar-collapsed'));
            });
        }
        // Restore state
        if (localStorage.getItem('sidebar-collapsed') === 'true') {
            document.body.classList.add('sidebar-collapsed');
        }
    }

    // Confirm dialogs for dangerous actions
    function initConfirmActions() {
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', (e) => {
                if (!confirm(el.getAttribute('data-confirm'))) {
                    e.preventDefault();
                }
            });
        });
    }

    // Time ago updater (updates relative times)
    function updateTimeAgo() {
        document.querySelectorAll('[data-timestamp]').forEach(el => {
            const ts = parseInt(el.getAttribute('data-timestamp'));
            const diff = Math.floor(Date.now() / 1000) - ts;
            if (diff < 60) el.textContent = diff + 's ago';
            else if (diff < 3600) el.textContent = Math.floor(diff/60) + 'm ago';
            else if (diff < 86400) el.textContent = Math.floor(diff/3600) + 'h ago';
            else el.textContent = Math.floor(diff/86400) + 'd ago';
        });
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        initConfirmActions();
        initAutoRefresh();
        setInterval(updateTimeAgo, 10000);
    });
})();
