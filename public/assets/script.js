document.addEventListener('DOMContentLoaded', function () {

    let isPageLoad = true;

    /* ================= Page Loader ================= */
    const pageLoader = document.getElementById('pageLoader');

    // Hide loader when page is fully loaded
    if (pageLoader) {
        // Hide immediately if already loaded, otherwise wait for load
        if (document.readyState === 'complete') {
            pageLoader.classList.remove('visible');
        } else {
            window.addEventListener('load', function () {
                setTimeout(function () {
                    pageLoader.classList.remove('visible');
                }, 350); // Brief delay for smooth transition
            });
        }

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
                pageLoader.classList.add('visible');
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
    const sidebarNav = document.querySelector('nav.sidebar');
    const SIDEBAR_STATE_KEY = 'sidebar_collapsed';

    /**
     * Save sidebar collapsed state to localStorage.
     */
    function saveSidebarState(collapsed) {
        try {
            if (collapsed) {
                localStorage.setItem(SIDEBAR_STATE_KEY, 'true');
            } else {
                localStorage.removeItem(SIDEBAR_STATE_KEY);
            }
        } catch (e) {
            // localStorage may not be available (private browsing, etc.)
        }
    }

    /**
     * Load sidebar collapsed state from localStorage.
     */
    function loadSidebarState() {
        try {
            return localStorage.getItem(SIDEBAR_STATE_KEY) === 'true';
        } catch (e) {
            return false;
        }
    }

    /**
     * Set the sidebar to collapsed or expanded state (desktop only).
     * @param {boolean} collapsed - true to collapse, false to expand
     * @param {boolean} [updateIcon=true] - whether to update the toggle button icon
     */
    function setSidebarCollapsed(collapsed, updateIcon) {
        if (updateIcon === undefined) updateIcon = true;
        if (!sidebarNav) return;
        const isDesktop = window.innerWidth >= 769;
        if (!isDesktop) return;

        sidebarNav.classList.toggle('collapsed', collapsed);
        document.body.classList.toggle('sidebar-collapsed', collapsed);

        if (updateIcon && sidebarBtn) {
            const icon = sidebarBtn.querySelector('i');
            if (icon) {
                icon.className = collapsed ? 'fas fa-bars' : 'fas fa-xmark';
            }
        }

        saveSidebarState(collapsed);
    }

    /**
     * Toggle the sidebar between collapsed and expanded.
     * On desktop: toggles the pinned state (sidebar stays expanded until unpinned).
     * On mobile: opens/closes as overlay.
     */
    function toggleSidebar() {
        if (!sidebarNav) return;
        const isDesktop = window.innerWidth >= 769;

        if (isDesktop) {
            const isPinned = document.body.classList.contains('sidebar-pinned');
            if (isPinned) {
                // Unpin: collapse back
                document.body.classList.remove('sidebar-pinned');
                setSidebarCollapsed(true);
            } else {
                // Pin: expand and lock
                document.body.classList.add('sidebar-pinned');
                setSidebarCollapsed(false);
            }
        } else {
            sidebarNav.classList.toggle('open');

            const icon = sidebarBtn ? sidebarBtn.querySelector('i') : null;
            if (icon) {
                icon.className = sidebarNav.classList.contains('open') ? 'fas fa-xmark' : 'fas fa-bars';
            }
        }
    }

    /**
     * Desktop hover behaviour — expand on hover, collapse on leave (unless pinned).
     */
    function initSidebarHover() {
        if (!sidebarNav) return;

        let hoverTimer = null;

        sidebarNav.addEventListener('mouseenter', function () {
            if (window.innerWidth <= 768) return;
            clearTimeout(hoverTimer);
            // If not pinned, expand on hover (don't update toggle icon)
            if (!document.body.classList.contains('sidebar-pinned')) {
                setSidebarCollapsed(false, false);
            }
        });

        sidebarNav.addEventListener('mouseleave', function () {
            if (window.innerWidth <= 768) return;
            clearTimeout(hoverTimer);
            // Collapse after a short delay, unless pinned (don't update toggle icon)
            if (!document.body.classList.contains('sidebar-pinned')) {
                hoverTimer = setTimeout(function () {
                    setSidebarCollapsed(true, false);
                }, 300);
            }
        });
    }

    // Default: sidebar starts collapsed on desktop
    if (sidebarNav) {
        const isDesktop = window.innerWidth >= 769;
        if (isDesktop) {
            // Always start collapsed — disregard saved expanded state
            setSidebarCollapsed(true);
        }
        initSidebarHover();
    }

    if (sidebarBtn) {
        sidebarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Re-check sidebar state on resize (e.g. going from desktop to mobile and back)
    let resizeTimeout;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function () {
            if (!sidebarNav) return;
            const isDesktop = window.innerWidth >= 769;
            if (isDesktop) {
                const isPinned = document.body.classList.contains('sidebar-pinned');
                if (isPinned) {
                    // Restore pinned expansion
                    setSidebarCollapsed(false);
                } else {
                    // Collapse back on resize to desktop
                    setSidebarCollapsed(true);
                }
            } else {
                // On mobile, remove collapsed/desktop-only classes
                sidebarNav.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                document.body.classList.remove('sidebar-pinned');
            }
        }, 200);
    });

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
            if (sidebarNav && sidebarNav.classList.contains('open') && window.innerWidth <= 768) {
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

        sidebarNav.addEventListener('touchstart', function (e) {
            if (window.innerWidth > 768) return;
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            swipeDeltaX = 0;
            isSwiping = false;
        }, { passive: true });

        sidebarNav.addEventListener('touchmove', function (e) {
            if (window.innerWidth > 768) return;
            if (!this.classList.contains('open')) return;

            const dx = e.touches[0].clientX - touchStartX;
            const dy = e.touches[0].clientY - touchStartY;

            // Only drag if horizontal movement > vertical (prevent scroll conflicts)
            if (Math.abs(dx) > Math.abs(dy) && dx < 0) {
                isSwiping = true;
                swipeDeltaX = dx;

                // Follow finger with slight resistance for natural feel
                const translateX = Math.max(dx * 0.6, -this.offsetWidth);
                this.style.transition = 'none';
                this.style.transform = 'translateX(' + translateX + 'px)';

                e.preventDefault();
            }
        }, { passive: false });

        sidebarNav.addEventListener('touchend', function (e) {
            if (window.innerWidth > 768) return;
            if (!isSwiping) return;

            // Threshold: 80px or 25% of sidebar width
            const threshold = Math.min(80, this.offsetWidth * 0.25);

            if (swipeDeltaX < -threshold) {
                // Animate to fully closed, then hide
                this.style.transition = 'transform 0.25s ease';
                this.style.transform = 'translateX(-100%)';
                setTimeout(function (nav) {
                    closeSidebar();
                    nav.style.transform = '';
                    nav.style.transition = '';
                }, 250, this);
            } else {
                // Snap back to open position
                this.style.transition = 'transform 0.2s ease';
                this.style.transform = 'translateX(0)';
                setTimeout(function (nav) {
                    nav.style.transform = '';
                    nav.style.transition = '';
                }, 200, this);
            }

            isSwiping = false;
        }, { passive: true });
    }

    /* ================= Submenu Toggle ================= */

    document.querySelectorAll('.submenu-toggle').forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const clickedMenu = this.nextElementSibling;

            if (clickedMenu.style.display === 'block') {

                if (isPageLoad) {
                    clickedMenu.style.display = 'none';
                } else {
                    slideUp(clickedMenu, 300);
                }

                this.classList.remove('active');
                this.querySelector('.fas')
                    .classList.replace('fa-chevron-up', 'fa-chevron-down');

                clickedMenu.querySelectorAll('.submenu').forEach(sub => {
                    isPageLoad ? sub.style.display = 'none' : slideUp(sub, 300);
                });

                clickedMenu.querySelectorAll('.submenu-toggle').forEach(t => {
                    t.classList.remove('active');
                    t.querySelector('.fas')
                        .classList.replace('fa-chevron-up', 'fa-chevron-down');
                });

            } else {

                this.classList.add('active');
                this.querySelector('.fas')
                    .classList.replace('fa-chevron-down', 'fa-chevron-up');

                isPageLoad ? clickedMenu.style.display = 'block' : slideDown(clickedMenu, 300);

                this.parentElement.querySelectorAll(':scope > .submenu').forEach(sub => {
                    if (sub !== clickedMenu) {
                        isPageLoad ? sub.style.display = 'none' : slideUp(sub, 300);
                    }
                });
            }
        });
    });

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
            if (window.innerWidth <= 768) {
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

    function closeSidebar() {
        if (sidebarNav) {
            sidebarNav.classList.remove('open');
            // On mobile, also reset any collapsed/pinned state that may have
            // carried over from desktop resize, but DON'T save to localStorage
            // so that the user's desktop preference is preserved.
            if (window.innerWidth <= 768) {
                sidebarNav.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                document.body.classList.remove('sidebar-pinned');
            }
        }
        if (sidebarBtn) {
            const icon = sidebarBtn.querySelector('i');
            if (icon) icon.className = 'fas fa-bars';
        }

        document.querySelectorAll('.submenu').forEach(menu => {
            isPageLoad ? menu.style.display = 'none' : slideUp(menu, 300);
        });

        document.querySelectorAll('.submenu-toggle').forEach(t => {
            t.classList.remove('active');
            const icon = t.querySelector('.fas');
            if (icon) icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
        });
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
        links.forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href || href === '#' || href.indexOf('javascript:') === 0) return;
            const normalizedHref = href.replace(/\/+$/, '') || '/';
            if (normalizedHref === currentPath) {
                bestMatch = link;
            }
        });

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
            if (submenu) {
                submenu.style.display = 'block';
                submenu.classList.add('open');
            }

            const toggle = parent.querySelector(':scope > a.submenu-toggle');
            if (toggle) {
                toggle.classList.add('active');
                const icon = toggle.querySelector('.fas');
                if (icon) {
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                }
            }

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
    el.style.transition = `height ${duration}ms`;
    el.style.height = el.scrollHeight + 'px';
    requestAnimationFrame(() => el.style.height = '0');
    setTimeout(() => el.style.display = 'none', duration);
}

function slideDown(el, duration) {
    el.style.display = 'block';
    const height = el.scrollHeight;
    el.style.height = '0';
    el.style.transition = `height ${duration}ms`;
    requestAnimationFrame(() => el.style.height = height + 'px');
    setTimeout(() => el.style.height = '', duration);
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

