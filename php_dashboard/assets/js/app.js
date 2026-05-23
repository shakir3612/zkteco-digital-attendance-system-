/**
 * ZKTeco Attendance Dashboard - JavaScript
 */

(function() {
    'use strict';

    var MOBILE_BREAKPOINT = 768;

    function isMobile() {
        return window.innerWidth <= MOBILE_BREAKPOINT;
    }

    // =========================================================================
    // SIDEBAR TOGGLE
    // =========================================================================
    function initSidebar() {
        var toggle = document.getElementById('sidebarToggle');
        var overlay = document.getElementById('sidebarOverlay');
        if (!toggle) return;

        if (!isMobile() && localStorage.getItem('sidebar-collapsed') === 'true') {
            document.body.classList.add('sidebar-collapsed');
        }

        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (isMobile()) {
                document.body.classList.toggle('sidebar-open');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebar-collapsed',
                    document.body.classList.contains('sidebar-collapsed') ? 'true' : 'false');
            }
        });

        if (overlay) {
            overlay.addEventListener('click', function() {
                document.body.classList.remove('sidebar-open');
            });
        }

        var navLinks = document.querySelectorAll('.nav-menu a');
        for (var i = 0; i < navLinks.length; i++) {
            navLinks[i].addEventListener('click', function() {
                if (isMobile()) document.body.classList.remove('sidebar-open');
            });
        }
    }

    // =========================================================================
    // SEARCHABLE SELECT DROPDOWN
    // =========================================================================
    function initSearchableSelects() {
        var selects = document.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) {
            var sel = selects[i];
            // Only enhance selects with more than 5 options
            // Skip filter-selects (small inline filters) and no-search marked ones
            if (sel.options.length > 5 && !sel.classList.contains('no-search') && !sel.classList.contains('filter-select')) {
                createSearchableSelect(sel);
            }
        }
    }

    function createSearchableSelect(originalSelect) {
        var wrapper = document.createElement('div');
        wrapper.className = 'ss-wrapper';

        var display = document.createElement('div');
        display.className = 'ss-display';
        display.setAttribute('tabindex', '0');

        var displayText = document.createElement('span');
        displayText.className = 'ss-display-text';
        displayText.textContent = originalSelect.options[originalSelect.selectedIndex]
            ? originalSelect.options[originalSelect.selectedIndex].text
            : 'Select...';

        var arrow = document.createElement('span');
        arrow.className = 'ss-arrow';
        arrow.innerHTML = '&#9662;';

        display.appendChild(displayText);
        display.appendChild(arrow);

        var dropdown = document.createElement('div');
        dropdown.className = 'ss-dropdown';

        var searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'ss-search';
        searchInput.placeholder = 'Type to search...';

        var optionsList = document.createElement('div');
        optionsList.className = 'ss-options';

        dropdown.appendChild(searchInput);
        dropdown.appendChild(optionsList);

        wrapper.appendChild(display);
        wrapper.appendChild(dropdown);

        // Hide original select
        originalSelect.style.display = 'none';
        originalSelect.parentNode.insertBefore(wrapper, originalSelect.nextSibling);

        // Build options
        function buildOptions(filter) {
            optionsList.innerHTML = '';
            var filterLower = (filter || '').toLowerCase();
            var count = 0;
            for (var i = 0; i < originalSelect.options.length; i++) {
                var opt = originalSelect.options[i];
                var text = opt.text;
                if (filterLower && text.toLowerCase().indexOf(filterLower) === -1) continue;
                var item = document.createElement('div');
                item.className = 'ss-option' + (opt.selected ? ' ss-selected' : '');
                item.textContent = text;
                item.setAttribute('data-index', i);
                item.addEventListener('click', (function(index, txt) {
                    return function() {
                        originalSelect.selectedIndex = index;
                        displayText.textContent = txt;
                        closeDropdown();
                        // Trigger change event
                        var event = new Event('change', { bubbles: true });
                        originalSelect.dispatchEvent(event);
                        // If select has onchange, trigger it
                        if (originalSelect.getAttribute('onchange')) {
                            eval(originalSelect.getAttribute('onchange'));
                        }
                    };
                })(i, text));
                optionsList.appendChild(item);
                count++;
            }
            if (count === 0) {
                var noResult = document.createElement('div');
                noResult.className = 'ss-no-result';
                noResult.textContent = 'No matches found';
                optionsList.appendChild(noResult);
            }
        }

        function openDropdown() {
            wrapper.classList.add('ss-open');
            searchInput.value = '';
            buildOptions('');
            setTimeout(function() { searchInput.focus(); }, 50);
        }

        function closeDropdown() {
            wrapper.classList.remove('ss-open');
        }

        // Events
        display.addEventListener('click', function(e) {
            e.stopPropagation();
            if (wrapper.classList.contains('ss-open')) {
                closeDropdown();
            } else {
                // Close all other open dropdowns
                var allOpen = document.querySelectorAll('.ss-wrapper.ss-open');
                for (var j = 0; j < allOpen.length; j++) allOpen[j].classList.remove('ss-open');
                openDropdown();
            }
        });

        display.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDropdown();
            }
        });

        searchInput.addEventListener('input', function() {
            buildOptions(this.value);
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDropdown();
        });

        searchInput.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Close on outside click
        document.addEventListener('click', function() {
            closeDropdown();
        });

        wrapper.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // =========================================================================
    // AUTO-REFRESH
    // =========================================================================
    function initAutoRefresh() {
        var cards = document.querySelectorAll('[data-auto-refresh]');
        for (var i = 0; i < cards.length; i++) {
            var interval = parseInt(cards[i].getAttribute('data-auto-refresh')) * 1000;
            if (interval > 0) {
                setInterval(function() { location.reload(); }, interval);
            }
        }
    }

    // =========================================================================
    // CONFIRM ACTIONS
    // =========================================================================
    function initConfirmActions() {
        var els = document.querySelectorAll('[data-confirm]');
        for (var i = 0; i < els.length; i++) {
            els[i].addEventListener('click', function(e) {
                if (!confirm(this.getAttribute('data-confirm'))) {
                    e.preventDefault();
                }
            });
        }
    }

    // =========================================================================
    // INIT
    // =========================================================================
    document.addEventListener('DOMContentLoaded', function() {
        initSidebar();
        initSearchableSelects();
        initConfirmActions();
        initAutoRefresh();
    });
})();
