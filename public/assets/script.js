document.addEventListener('DOMContentLoaded', function () {

    let isPageLoad = true;

    /* ================= Page Loader ================= */
    const pageLoader = document.getElementById('pageLoader');

    // Hide loader when page is fully loaded
    if (pageLoader) {
        const hidePageLoader = function (delay) {
            setTimeout(function () {
                pageLoader.classList.remove('visible');
            }, delay || 0);
        };

        // Hide immediately if already loaded, otherwise wait for load
        if (document.readyState === 'complete') {
            pageLoader.classList.remove('visible');
        } else {
            // 'load' waits for EVERY subresource (fonts, CDNs, Firebase, chat...).
            // If any of them is slow or hangs, 'load' never fires and the spinner
            // spins forever on an already-usable page. So hide as soon as the DOM
            // is parsed, and keep the 'load' handler only as a re-assertion.
            if (document.readyState === 'interactive') {
                hidePageLoader(350);
            } else {
                document.addEventListener('DOMContentLoaded', function () {
                    hidePageLoader(350);
                });
            }
            window.addEventListener('load', function () {
                hidePageLoader(350);
            });
        }

        // Hard cap: never keep the full-screen loader visible for more than
        // 5 seconds, no matter what is still loading in the background.
        hidePageLoader(5000);

        // Fallback: hide loader when user returns to this tab (handles edge cases
        // where the loader gets stuck, e.g. Ctrl+click, middle-click without
        // page navigation, or interrupted form submissions)
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                pageLoader.classList.remove('visible');
            }
        });

        // Fallback: hide loader when navigating back via browser back/forward cache
        // (bfcache restores the page without firing the 'load' event, so the loader
        // could remain visible if it was showing when the user navigated away)
        window.addEventListener('pageshow', function () {
            pageLoader.classList.remove('visible');
        });
    }

    // Show loader on link clicks (internal navigation)
    document.addEventListener('click', function (e) {
        // Skip if the user intended to open in a new tab (Ctrl/Cmd+click or middle-click)
        if (e.ctrlKey || e.metaKey || e.button === 1) return;

        const link = e.target.closest('a');
        if (link && pageLoader) {
            const href = link.getAttribute('href');
            // Show loader for internal links only (not anchors, JS, email, tel, downloads, or new-tab links)
            if (href &&
                href.indexOf('#') !== 0 &&
                href.indexOf('javascript:') !== 0 &&
                href.indexOf('mailto:') !== 0 &&
                href.indexOf('tel:') !== 0 &&
                !link.hasAttribute('download') &&
                !link.hasAttribute('target') &&
                !link.classList.contains('sidebar-toggle') &&
                !link.closest('.header-dropdown-menu') &&
                !link.closest('.submenu-toggle') &&
                !link.closest('.sidebar-logo-link')) {
                // Defer check: other click handlers (e.g. preview modal, data-preview,
                // AJAX links) may call preventDefault() — in that case no navigation
                // happens, so the full-screen loader must not be shown.
                setTimeout(function () {
                    if (!e.defaultPrevented) {
                        pageLoader.classList.add('visible');
                    }
                }, 0);
            }
        }
    });

    // Show loader on form submissions (only for non-AJAX forms, not when opening in new tab)
    document.addEventListener('submit', function (e) {
        const form = e.target;
        // Skip if the form will open in a new tab (target="_blank" or formtarget="_blank")
        if (form.getAttribute('target') === '_blank' || e.submitter?.getAttribute('formtarget') === '_blank') return;

        if (!form.classList.contains('no-loader') &&
            form.id !== 'searchForm' &&
            !form.closest('.no-loader') &&
            pageLoader) {
            // Defer check to let other submit handlers call preventDefault() first.
            // AJAX forms call preventDefault() — in that case we skip the page loader
            // so it doesn't stay stuck on screen after the AJAX completes.
            setTimeout(function() {
                if (!e.defaultPrevented) {
                    pageLoader.classList.add('visible');
                }
            }, 0);
        }
    });

    /* ================= Sidebar Toggle & Hover ================= */

    const sidebarBtn = document.getElementById('sidebar');
    const sidebarEdgeFab = document.getElementById('sidebarEdgeFab');
    const sidebarNav = document.querySelector('nav.sidebar');
    const SIDEBAR_STATE_KEY = 'sidebar_collapsed';

    /* The sidebar has two modes, exactly as the CSS breakpoints describe:
       >= 769px is a collapsible rail, below that it is an overlay drawer.
       matchMedia() is used (instead of innerWidth) so the JS follows the same
       breakpoint as the stylesheet on every device, zoom level and split view. */
    const sidebarDesktopMQ = window.matchMedia('(min-width: 769px)');
    const prefersReducedMotionMQ = window.matchMedia('(prefers-reduced-motion: reduce)');

    function isDesktop() {
        return sidebarDesktopMQ.matches;
    }

    function isMobile() {
        return !sidebarDesktopMQ.matches;
    }

    /** Animation duration in ms — 0 when the user asked for reduced motion. */
    function motionDuration(ms) {
        return prefersReducedMotionMQ.matches ? 0 : ms;
    }

    /**
     * The trailing chevron of a submenu toggle (never the leading menu icon).
     */
    function getChevron(element) {
        if (!element) return null;
        return element.querySelector('.float-end.fas, .float-end.fa-chevron-down, .float-end.fa-chevron-up');
    }

    /**
     * Remember the desktop sidebar preference so it survives a page reload.
     * @param {boolean} pinned - true when the sidebar is open on desktop
     */
    function saveSidebarState(pinned) {
        try {
            localStorage.setItem(SIDEBAR_STATE_KEY, pinned ? 'pinned' : 'collapsed');
        } catch (e) {
            // localStorage may not be available (private browsing, etc.)
        }
    }

    /**
     * Read the saved desktop preference.
     * @returns {?boolean} true = pinned open, false = collapsed, null = nothing saved yet
     */
    function loadSidebarState() {
        try {
            const saved = localStorage.getItem(SIDEBAR_STATE_KEY);
            if (saved === null) return null;
            if (saved === 'pinned') return true;
            // 'true' is the legacy value written by the previous version (collapsed)
            return false;
        } catch (e) {
            return null;
        }
    }

    /**
     * Set the sidebar to collapsed or expanded state (desktop only).
     * Expanded and pinned are kept in sync here so the toggle button, the edge
     * handle and the tooltips can never disagree about the current state.
     * @param {boolean} collapsed - true to collapse, false to expand
     * @param {boolean} [updateIcon=true] - update the toggle button icon/label
     * @param {boolean} [persist=true] - save the preference to localStorage
     */
    function setSidebarCollapsed(collapsed, updateIcon, persist) {
        if (updateIcon === undefined) updateIcon = true;
        if (persist === undefined) persist = true;
        if (!sidebarNav || !isDesktop()) return;

        sidebarNav.classList.toggle('collapsed', collapsed);
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        document.body.classList.toggle('sidebar-pinned', !collapsed);

        if (persist) saveSidebarState(!collapsed);
        if (updateIcon) updateToggleIcons();
    }

    /**
     * Open/close the sidebar as an overlay drawer (mobile only).
     * @param {boolean} open
     */
    function setSidebarOpen(open) {
        if (!sidebarNav) return;
        sidebarNav.classList.toggle('open', open);
        // Reset any leftover inline transform from an interrupted swipe
        sidebarNav.style.transform = '';
        sidebarNav.style.transition = '';
        // Stop the page behind the drawer from scrolling on touch devices
        document.body.classList.toggle('sidebar-open-mobile', open && isMobile());
        updateToggleIcons();
    }

    /**
     * Toggle the sidebar: pinned open/closed on desktop, drawer on mobile.
     */
    function toggleSidebar() {
        if (!sidebarNav) return;

        if (isDesktop()) {
            setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        } else {
            setSidebarOpen(!sidebarNav.classList.contains('open'));
        }
    }

    /* Update toggle button + edge FAB icons/labels based on current state */
    function updateToggleIcons() {
        if (!sidebarNav) return;

        // Desktop: expanded == pinned. Mobile: the drawer class decides.
        const isOpen = isDesktop()
            ? document.body.classList.contains('sidebar-pinned')
            : sidebarNav.classList.contains('open');

        if (sidebarBtn) {
            const icon = sidebarBtn.querySelector('i');
            if (icon) {
                icon.className = isOpen ? 'fas fa-xmark' : 'fas fa-bars';
            }
            sidebarBtn.title = isOpen ? 'সাইডবার বন্ধ করুন (Ctrl+B)' : 'সাইডবার খুলুন (Ctrl+B)';
            sidebarBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        if (sidebarEdgeFab) {
            const icon = sidebarEdgeFab.querySelector('i');
            if (icon) {
                icon.className = isOpen ? 'fas fa-chevron-left' : 'fas fa-chevron-right';
            }
            sidebarEdgeFab.title = isOpen ? 'সাইডবার বন্ধ করুন' : 'সাইডবার খুলুন';
            sidebarEdgeFab.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    // Sidebar no longer auto-expands on hover — stays collapsed until clicked.
    // On desktop the saved preference is restored (collapsed by default).
    if (sidebarNav) {
        if (isDesktop()) {
            setSidebarCollapsed(!loadSidebarState(), true, false);
        }
        updateToggleIcons();
    }

    if (sidebarBtn) {
        sidebarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    if (sidebarEdgeFab) {
        sidebarEdgeFab.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Rebuild the sidebar state whenever the 769px breakpoint is crossed
    // (window resize, device rotation, browser zoom, split-screen, devtools).
    function handleSidebarBreakpointChange() {
        if (!sidebarNav) return;

        if (isDesktop()) {
            // Leaving drawer mode: drop overlay state, restore the saved preference
            sidebarNav.classList.remove('open');
            sidebarNav.style.transform = '';
            sidebarNav.style.transition = '';
            document.body.classList.remove('sidebar-open-mobile');
            setSidebarCollapsed(!loadSidebarState(), true, false);
        } else {
            // Leaving rail mode: clear every desktop-only class/transform
            sidebarNav.classList.remove('collapsed', 'open');
            sidebarNav.style.transform = '';
            sidebarNav.style.transition = '';
            document.body.classList.remove('sidebar-collapsed', 'sidebar-pinned', 'sidebar-open-mobile');
        }

        updateToggleIcons();
    }

    if (typeof sidebarDesktopMQ.addEventListener === 'function') {
        sidebarDesktopMQ.addEventListener('change', handleSidebarBreakpointChange);
    } else if (typeof sidebarDesktopMQ.addListener === 'function') {
        // Safari < 14
        sidebarDesktopMQ.addListener(handleSidebarBreakpointChange);
    }

    /* ================= Sidebar Search ================= */

    const sidebarSearch = document.getElementById('sidebarSearch');
    const sidebarSearchClear = document.getElementById('sidebarSearchClear');

    /**
     * Open/close a submenu and keep its toggle (class, aria-expanded, chevron)
     * in sync. Always writes an explicit display value so a leftover animated
     * `height: 0` from slideUp() can never hide the menu again.
     */
    function setSubmenuOpen(toggle, submenu, open) {
        if (!submenu) return;
        submenu.style.height = '';
        submenu.classList.toggle('open', open);
        submenu.style.display = open ? 'block' : 'none';

        if (toggle) {
            toggle.classList.toggle('active', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        const chevron = getChevron(toggle);
        if (chevron) {
            chevron.classList.toggle('fa-chevron-up', open);
            chevron.classList.toggle('fa-chevron-down', !open);
        }
    }

    /* Remember which submenus were open before searching, so clearing the
       query restores the real navigation state instead of flattening it. */
    let preSearchSubmenus = null;

    function snapshotSidebarSubmenus() {
        if (!sidebarNav) return;
        preSearchSubmenus = [];
        sidebarNav.querySelectorAll('.submenu').forEach(function (submenu) {
            const ownerLi = submenu.parentElement;
            preSearchSubmenus.push({
                submenu: submenu,
                toggle: ownerLi ? ownerLi.querySelector(':scope > a.submenu-toggle') : null,
                open: submenu.classList.contains('open')
            });
        });
    }

    function restoreSidebarSubmenus() {
        if (!preSearchSubmenus) return;
        preSearchSubmenus.forEach(function (entry) {
            setSubmenuOpen(entry.toggle, entry.submenu, entry.open);
        });
        preSearchSubmenus = null;
    }

    function filterSidebarMenu(rawQuery) {
        const sidebarUl = sidebarNav ? sidebarNav.querySelector('.sidebar-nav') : null;
        if (!sidebarUl) return;

        // Every whitespace separated word must appear somewhere in the entry
        // ("সনদ নাগরিক" then finds the নাগরিক সনদ item).
        const tokens = rawQuery.toLowerCase().split(/\s+/).filter(Boolean);

        if (!tokens.length) {
            sidebarUl.querySelectorAll('.sidebar-hidden').forEach(function (el) {
                el.classList.remove('sidebar-hidden');
            });
            restoreSidebarSubmenus();
            return;
        }

        if (!preSearchSubmenus) snapshotSidebarSubmenus();

        const linkMatches = function (link) {
            const haystack = ((link.getAttribute('data-search') || '') + ' ' + (link.textContent || '')).toLowerCase();
            return tokens.every(function (token) {
                return haystack.indexOf(token) !== -1;
            });
        };

        // 1) Hide every non-matching entry, at every nesting level
        const navItems = sidebarUl.querySelectorAll('li');
        navItems.forEach(function (li) {
            const link = li.querySelector(':scope > a');
            li.dataset.sidebarSearchMatch = (link && linkMatches(link)) ? '1' : '0';
        });

        // 2) Keep visible every parent of a matching entry, so a hit nested
        //    inside a closed submenu is still reachable after auto-opening.
        navItems.forEach(function (li) {
            if (li.dataset.sidebarSearchMatch !== '1') return;
            let parent = li.parentElement;
            while (parent && parent !== sidebarUl) {
                if (parent.tagName === 'LI') parent.dataset.sidebarSearchMatch = '1';
                parent = parent.parentElement;
            }
        });

        navItems.forEach(function (li) {
            li.classList.toggle('sidebar-hidden', li.dataset.sidebarSearchMatch !== '1');
            delete li.dataset.sidebarSearchMatch;
        });

        // Section headings only make sense while browsing the full menu
        sidebarUl.querySelectorAll(':scope > li.sidebar-section-label').forEach(function (label) {
            label.classList.add('sidebar-hidden');
        });

        // 3) Auto-open exactly the submenus that now contain a visible entry
        sidebarUl.querySelectorAll('.submenu').forEach(function (submenu) {
            const hasVisibleEntry = Array.prototype.some.call(submenu.children, function (child) {
                return child.tagName === 'LI' && !child.classList.contains('sidebar-hidden');
            });

            const ownerLi = submenu.parentElement;
            const toggle = ownerLi ? ownerLi.querySelector(':scope > a.submenu-toggle') : null;
            setSubmenuOpen(toggle, submenu, hasVisibleEntry);
        });
    }

    if (sidebarSearch) {
        sidebarSearch.addEventListener('input', function () {
            const query = this.value.trim();

            // Show/hide clear button
            if (sidebarSearchClear) {
                sidebarSearchClear.style.display = query.length > 0 ? 'block' : 'none';
            }

            filterSidebarMenu(query);
        });

        // Focus search on Ctrl+K / Cmd+K
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();

                // The search box lives inside the sidebar, so the sidebar has to
                // be visible first — on mobile that means opening the drawer.
                if (isDesktop()) {
                    setSidebarCollapsed(false);
                } else {
                    setSidebarOpen(true);
                }

                // Wait for the drawer/rail transition before focusing, otherwise
                // the input would be focused while still off-screen.
                setTimeout(function () {
                    sidebarSearch.focus();
                    sidebarSearch.scrollIntoView({ block: 'nearest' });
                }, isDesktop() ? 0 : 320);
            }
        });
    }

    if (sidebarSearchClear) {
        sidebarSearchClear.addEventListener('click', function () {
            if (sidebarSearch) {
                sidebarSearch.value = '';
                sidebarSearch.dispatchEvent(new Event('input'));
                sidebarSearch.focus();
            }
        });
    }


    /* ================= Keyboard Shortcuts ================= */

    document.addEventListener('keydown', function (e) {
        // Ctrl+B / Cmd+B — Toggle sidebar collapse (desktop) or open/close (mobile)
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            toggleSidebar();
            return;
        }

        // Escape — Close mobile sidebar, profile dropdown, or modals
        if (e.key === 'Escape') {
            // Close mobile sidebar overlay
            if (sidebarNav && sidebarNav.classList.contains('open') && isMobile()) {
                closeSidebar();
                e.preventDefault();
                return;
            }

            // Close profile dropdown
            if (profileDropdown && profileMenu && profileMenu.classList.contains('open')) {
                profileDropdown.classList.remove('open');
                profileMenu.classList.remove('open');
                e.preventDefault();
                return;
            }
        }
    });

    /* ================= Sidebar Swipe to Dismiss (Mobile Touch) ================= */

    if (sidebarNav) {
        let touchStartX = 0;
        let touchStartY = 0;
        let swipeDeltaX = 0;
        let isSwiping = false;
        let snapTimer = 0;

        // A finger always wobbles a few pixels on a tap. Without a slop radius
        // that wobble counted as a swipe: preventDefault() on touchmove then
        // ate the click that opens submenus, and a slightly longer wobble
        // dismissed the whole drawer. Only a deliberate drag past SWIPE_SLOP
        // (clearly horizontal, to the left) starts the gesture.
        const SWIPE_SLOP = 12;

        const settleSwipe = function (nav) {
            nav.style.transform = '';
            nav.style.transition = '';
        };

        sidebarNav.addEventListener('touchstart', function (e) {
            if (!isMobile()) return;
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            swipeDeltaX = 0;
            isSwiping = false;
            // A new gesture supersedes any snap-back still animating.
            clearTimeout(snapTimer);
        }, { passive: true });

        sidebarNav.addEventListener('touchmove', function (e) {
            if (!isMobile()) return;
            if (!this.classList.contains('open')) return;

            const dx = e.touches[0].clientX - touchStartX;
            const dy = e.touches[0].clientY - touchStartY;

            if (!isSwiping) {
                // Inside the slop radius: stay a tap, let the click through.
                if (Math.abs(dx) < SWIPE_SLOP && Math.abs(dy) < SWIPE_SLOP) return;
                // Past the slop, only a clear horizontal-left drag starts a
                // swipe; vertical or ambiguous movement stays a scroll/tap.
                if (!(Math.abs(dx) > Math.abs(dy) && dx < -SWIPE_SLOP)) return;
                isSwiping = true;
                this.style.transition = 'none';
            }

            swipeDeltaX = dx;

            // Follow finger with slight resistance for natural feel
            const translateX = Math.max(dx * 0.6, -this.offsetWidth);
            this.style.transform = 'translateX(' + translateX + 'px)';

            e.preventDefault();
        }, { passive: false });

        sidebarNav.addEventListener('touchend', function () {
            if (!isMobile()) return;
            if (!isSwiping) return;

            const nav = this;
            isSwiping = false;
            // Threshold: 80px or 25% of sidebar width
            const threshold = Math.min(80, nav.offsetWidth * 0.25);

            clearTimeout(snapTimer);
            if (swipeDeltaX < -threshold) {
                // Animate to fully closed, then hide
                nav.style.transition = 'transform 0.25s ease';
                nav.style.transform = 'translateX(-100%)';
                snapTimer = setTimeout(function () {
                    closeSidebar();
                    settleSwipe(nav);
                }, 250);
            } else {
                // Snap back to open position
                nav.style.transition = 'transform 0.2s ease';
                nav.style.transform = 'translateX(0)';
                snapTimer = setTimeout(function () {
                    settleSwipe(nav);
                }, 200);
            }
        }, { passive: true });

        // A gesture interrupted by a system dialog, a call or the OS app
        // switcher fires touchcancel and never touchend — without this the
        // sidebar would stay stuck half-way off-screen.
        sidebarNav.addEventListener('touchcancel', function () {
            if (!isMobile()) return;
            if (!isSwiping && !this.style.transform) return;
            const nav = this;
            isSwiping = false;
            clearTimeout(snapTimer);
            nav.style.transition = 'transform 0.2s ease';
            nav.style.transform = 'translateX(0)';
            snapTimer = setTimeout(function () { settleSwipe(nav); }, 200);
        }, { passive: true });
    }

    /* ================= Submenu Toggle ================= */

    document.querySelectorAll('.submenu-toggle').forEach(function (toggle) {

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSubmenu(this);
        });

        // Anchors only fire click on Enter, so keyboard users would never be
        // able to open a submenu with Space.
        toggle.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter') {
                e.preventDefault();
                toggleSubmenu(this);
            }
        });
    });

    function toggleSubmenu(toggle) {
        const ownerLi = toggle.parentElement;
        const clickedMenu = ownerLi ? ownerLi.querySelector(':scope > .submenu') : null;
        if (!clickedMenu) return;

        // A collapsed desktop rail hides every submenu with `display: none
        // !important`, so clicking a category there looked completely dead.
        // Expand the rail first and always reveal the requested submenu.
        const wasCollapsedRail = isDesktop() && document.body.classList.contains('sidebar-collapsed');
        if (wasCollapsedRail) setSidebarCollapsed(false);

        // The `open` class is the single source of truth. While a close
        // animation is still running the computed display is still 'block',
        // so checking computed style here would make a fast second click
        // close the menu again instead of re-opening it.
        const isOpen = !wasCollapsedRail && clickedMenu.classList.contains('open');
        const duration = motionDuration(300);
        const chevron = getChevron(toggle);

        if (isPageLoad) {
            setSubmenuOpen(toggle, clickedMenu, !isOpen);
        } else if (isOpen) {
            slideUp(clickedMenu, duration);
            toggle.classList.remove('active');
            toggle.setAttribute('aria-expanded', 'false');
            clickedMenu.classList.remove('open');
            if (chevron) chevron.classList.replace('fa-chevron-up', 'fa-chevron-down');
        } else {
            slideDown(clickedMenu, duration);
            toggle.classList.add('active');
            toggle.setAttribute('aria-expanded', 'true');
            clickedMenu.classList.add('open');
            if (chevron) chevron.classList.replace('fa-chevron-down', 'fa-chevron-up');
        }

        // Collapse every nested submenu of the one we just toggled
        clickedMenu.querySelectorAll('.submenu').forEach(function (sub) {
            if (isPageLoad) sub.style.display = 'none';
            else slideUp(sub, duration);
        });

        clickedMenu.querySelectorAll('.submenu-toggle').forEach(function (t) {
            t.classList.remove('active');
            t.setAttribute('aria-expanded', 'false');
            const nestedChevron = getChevron(t);
            if (nestedChevron) nestedChevron.classList.replace('fa-chevron-up', 'fa-chevron-down');
        });

        // Only one sibling submenu of the same item stays open
        ownerLi.querySelectorAll(':scope > .submenu').forEach(function (sub) {
            if (sub === clickedMenu) return;
            if (isPageLoad) sub.style.display = 'none';
            else slideUp(sub, duration);
        });
    }

    /* ================= Header Profile Dropdown ================= */

    const profileDropdown = document.getElementById('profileDropdown');
    const profileMenu = document.getElementById('profileDropdownMenu');

    if (profileDropdown && profileMenu) {
        profileDropdown.addEventListener('click', function (e) {
            e.stopPropagation();
            this.classList.toggle('open');
            profileMenu.classList.toggle('open');
        });
    }

    /* ================= Sidebar Backdrop Click ================= */

    const backdrop = document.getElementById('sidebarBackdrop');
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeSidebar();
        });
    }

    /* ================= Outside Click ================= */

    document.addEventListener('click', function (e) {
        if (!e.target.closest('nav.sidebar') && !e.target.closest('#sidebar')) {
            if (isMobile() && sidebarNav && sidebarNav.classList.contains('open')) {
                closeSidebar();
            }
        }

        // Close profile dropdown if clicking outside
        if (profileDropdown && profileMenu &&
            !e.target.closest('#profileDropdown') &&
            !e.target.closest('#profileDropdownMenu')) {
            profileDropdown.classList.remove('open');
            profileMenu.classList.remove('open');
        }
    });

    /**
     * Close the mobile drawer and collapse every sidebar submenu.
     * Scoped to the sidebar itself: `.submenu` is a generic class and other
     * components on the page use it for their own dropdowns.
     */
    function closeSidebar() {
        if (!sidebarNav) return;

        sidebarNav.classList.remove('open');
        // Clear anything left behind by an interrupted swipe
        sidebarNav.style.transform = '';
        sidebarNav.style.transition = '';
        document.body.classList.remove('sidebar-open-mobile');

        if (isMobile()) {
            // Desktop-only classes must not survive in drawer mode, but the
            // saved preference stays untouched so rotating back restores it.
            sidebarNav.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
        }

        const duration = motionDuration(300);

        sidebarNav.querySelectorAll('.submenu').forEach(function (menu) {
            if (isPageLoad) {
                menu.style.display = 'none';
                menu.style.height = '';
            } else {
                slideUp(menu, duration);
            }
            menu.classList.remove('open');
        });

        sidebarNav.querySelectorAll('.submenu-toggle').forEach(function (t) {
            t.classList.remove('active');
            t.setAttribute('aria-expanded', 'false');
            const icon = getChevron(t);
            if (icon) icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
        });

        updateToggleIcons();
    }

    /* ================= Active Menu (Initial Load) ================= */
    // Highlights the current page's sidebar link as active and opens
    // any parent submenus. Uses path normalization and prefix matching
    // so nested routes (e.g. /applications/nagorik-sonod) highlight the
    // parent submenu toggle.

    function activateCurrentSidebarItem() {
        const sidebar = document.querySelector('nav.sidebar');
        if (!sidebar) return;

        let currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
        if (currentPath === '' || currentPath === '/') {
            currentPath = '/dashboard';
        }

        const links = sidebar.querySelectorAll('ul li a[href]');
        let bestMatch = null;

        // Phase 1: Look for an exact match first (highest priority)
        for (const link of links) {
            const href = link.getAttribute('href');
            if (!href || href === '#' || href.indexOf('javascript:') === 0) continue;
            const normalizedHref = href.replace(/\/+$/, '') || '/';
            if (normalizedHref === currentPath) {
                bestMatch = link;
                break;
            }
        }

        // Phase 2: If no exact match, use the longest prefix match
        // e.g. /applications/nagorik-sonod matches the parent toggle /applications
        if (!bestMatch) {
            let bestMatchLength = 0;
            links.forEach(function (link) {
                const href = link.getAttribute('href');
                if (!href || href === '#' || href.indexOf('javascript:') === 0) return;
                const normalizedHref = href.replace(/\/+$/, '') || '/';
                // Current path must start with the link href followed by '/'
                // Avoid treating root '/' as a prefix match for everything
                if (normalizedHref !== '/' && currentPath.indexOf(normalizedHref + '/') === 0) {
                    if (normalizedHref.length > bestMatchLength) {
                        bestMatch = link;
                        bestMatchLength = normalizedHref.length;
                    }
                }
            });
        }

        if (!bestMatch) return;

        // Mark the link as active
        bestMatch.classList.add('active');

        // Open parent submenus up the DOM tree
        let parent = bestMatch.closest('li');
        while (parent) {
            const submenu = parent.querySelector(':scope > ul.submenu');
            const toggle = parent.querySelector(':scope > a.submenu-toggle');
            if (submenu) setSubmenuOpen(toggle, submenu, true);

            parent = parent.parentElement ? parent.parentElement.closest('li') : null;
        }
    }

    activateCurrentSidebarItem();

    /* ================= Scroll To Top ================= */

    const topBtn = document.getElementById('topBtn');

    if (topBtn) {
        window.addEventListener('scroll', () => {
            topBtn.style.display = window.scrollY > 100 ? 'block' : 'none';
        });

        topBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    setTimeout(() => isPageLoad = false, 0);

});

// ================= Global: Button Loading Spinner Helper =================
/**
 * Show a loading spinner on a button during AJAX operations.
 * Usage:
 *   const restore = btnLoading(myButton);
 *   // ... do AJAX ...
 *   restore();
 *
 * The button text is replaced with a spinner + loading text.
 * Button is disabled during loading.
 *
 * @param {HTMLElement} btn - The button element
 * @param {string} loadingText - Optional custom text (default: "প্রক্রিয়াকরণ...")
 * @returns {Function} A restore function to call when done
 */
function btnLoading(btn, loadingText) {
    if (!btn || !btn.tagName) return function(){};
    const original = {
        html: btn.innerHTML,
        disabled: btn.disabled
    };
    btn.disabled = true;
    btn.classList.add('btn-loading');
    const text = loadingText || btn.getAttribute('data-loading-text') || 'প্রক্রিয়াকরণ...';
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:0.25rem"></i> ' + text;
    return function btnRestore() {
        btn.disabled = original.disabled;
        btn.classList.remove('btn-loading');
        btn.innerHTML = original.html;
    };
};

/* ================= Old Helpers ================= */

function slideUp(el, duration) {
    // Cancel a pending animation timer, otherwise a quick close-then-open
    // would still be hidden by the stale timer of the first animation.
    clearTimeout(el._slideTimer);
    // No animation (reduced motion): the rAF/timeout pair below would race
    // at 0ms and could leave a stale inline height behind.
    if (!duration) {
        el.style.transition = '';
        el.style.height = '';
        el.style.display = 'none';
        return;
    }
    el.style.transition = `height ${duration}ms`;
    el.style.height = el.scrollHeight + 'px';
    requestAnimationFrame(() => el.style.height = '0');
    el._slideTimer = setTimeout(() => {
        el.style.display = 'none';
        // Drop the animated height: a later `display: block` (menu search,
        // activate-current-page) would otherwise reveal a zero-height menu.
        el.style.height = '';
        el.style.transition = '';
    }, duration);
}

function slideDown(el, duration) {
    clearTimeout(el._slideTimer);
    if (!duration) {
        el.style.transition = '';
        el.style.height = '';
        el.style.display = 'block';
        return;
    }
    el.style.display = 'block';
    el.style.height = '';
    const height = el.scrollHeight;
    el.style.height = '0';
    el.style.transition = `height ${duration}ms`;
    requestAnimationFrame(() => el.style.height = height + 'px');
    el._slideTimer = setTimeout(() => {
        el.style.height = '';
        el.style.transition = '';
    }, duration);
}



// ----------------- Helper: Enhanced Show Message (SweetAlert2) -----------------
/**
 * @param {string} type - success, error, warning, info
 * @param {string} message - The message to display
 * @param {object} options - Optional: { redirectUrl, reload, timer }
 */
function showMessage(type, message, options = {}) {
    // ডিফল্ট সেটিংস সেট করা
    const config = {
        success: {
            icon: 'success',
            title: 'সফলভাবে সম্পন্ন হয়েছে!',
            confirmButtonColor: '#28a745'
        },
        error: {
            icon: 'error',
            title: 'ত্রুটি ঘটেছে!',
            confirmButtonColor: '#dc3545'
        },
        warning: {
            icon: 'warning',
            title: 'সতর্কবার্তা!',
            confirmButtonColor: '#ffc107'
        },
        info: {
            icon: 'info',
            title: 'তথ্য',
            confirmButtonColor: '#17a2b8'
        }
    };

    const currentType = config[type] || config.info;

    Swal.fire({
        icon: currentType.icon,
        title: currentType.title,
        text: message,
        confirmButtonText: 'ঠিক আছে',
        confirmButtonColor: currentType.confirmButtonColor,
        timer: options.timer || 5000, 
        timerProgressBar: true,
        showClass: {
            popup: 'animate__animated animate__fadeInDown' 
        },
        hideClass: {
            popup: 'animate__animated animate__fadeOutUp'
        }
    }).then((result) => {
 
        if (options.redirectUrl) {
            window.location.href = options.redirectUrl;
        } 

        else if (options.reload) {
            window.location.reload();
        }
    });
}



(function () {
    'use strict';

    let popupEl = null;
    let hideTimer = null;

    const typeMap = {
        success: { bg: '#198754', icon: '✔' },
        error:   { bg: '#dc3545', icon: '✖' },
        warning: { bg: '#ffc107', icon: '⚠' },
        info:    { bg: '#0dcaf0', icon: 'ℹ' }
    };

    function createPopup() {
        popupEl = document.createElement('div');
        popupEl.id = 'global-popup-message';

        popupEl.innerHTML = `
            <div class="popup-box">
                <span class="popup-icon"></span>
                <span class="popup-text"></span>
                <button class="popup-close" aria-label="Close">&times;</button>
            </div>
        `;

        document.body.appendChild(popupEl);

        popupEl.querySelector('.popup-close')
            .addEventListener('click', hidePopup);
    }

    function showPopup(type, message, timeout = 3000) {
        if (!popupEl) createPopup();

        const config = typeMap[type] || typeMap.info;

        const box  = popupEl.querySelector('.popup-box');
        const icon = popupEl.querySelector('.popup-icon');
        const text = popupEl.querySelector('.popup-text');

        icon.textContent = config.icon;
        text.textContent = message;
        box.style.backgroundColor = config.bg;

        popupEl.classList.add('show');

        clearTimeout(hideTimer);
        if (timeout > 0) {
            hideTimer = setTimeout(hidePopup, timeout);
        }
    }

    function hidePopup() {
        popupEl?.classList.remove('show');
    }

    window.displayMessage = function (type, message, timeout) {
        if (!message) return;
        showPopup(type, message, timeout);
    };
})();

