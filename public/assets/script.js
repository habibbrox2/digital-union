document.addEventListener('DOMContentLoaded', function () {

    let isPageLoad = true;

    /* ================= Sidebar Toggle ================= */

    const sidebarBtn = document.getElementById('sidebar');
    const sidebarNav = document.querySelector('nav.sidebar');

    sidebarBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        sidebarNav.classList.toggle('open');

        const icon = this.querySelector('i');
        icon.classList.toggle('fas');
        icon.classList.toggle('fa-bars');
        icon.classList.toggle('fa-solid');
        icon.classList.toggle('fa-xmark');
    });

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

    /* ================= Outside Click ================= */

    document.addEventListener('click', function (e) {
        if (!e.target.closest('nav.sidebar') && !e.target.closest('#sidebar')) {

            sidebarNav.classList.remove('open');
            const icon = sidebarBtn.querySelector('i');
            icon.className = 'fas fa-bars';

            document.querySelectorAll('.submenu').forEach(menu => {
                isPageLoad ? menu.style.display = 'none' : slideUp(menu, 300);
            });

            document.querySelectorAll('.submenu-toggle').forEach(t => {
                t.classList.remove('active');
                t.querySelector('.fas')
                    .classList.replace('fa-chevron-up', 'fa-chevron-down');
            });
        }
    });

    /* ================= Active Menu (Initial Load) ================= */

    const currentPath = window.location.pathname;

    document.querySelectorAll('nav.sidebar ul li a').forEach(a => {
        if (a.getAttribute('href') === currentPath) {
            a.classList.add('active');

            let li = a.closest('li');
            while (li) {
                li.classList.add('selected');
                const submenu = li.querySelector(':scope > ul.submenu');
                if (submenu) submenu.style.display = 'block';

                const toggle = li.querySelector(':scope > a.submenu-toggle');
                if (toggle) {
                    toggle.classList.add('active');
                    toggle.querySelector('.fas')
                        .classList.replace('fa-chevron-down', 'fa-chevron-up');
                }
                li = li.parentElement.closest('li');
            }
        }
    });

    /* ================= Scroll To Top ================= */

    const topBtn = document.getElementById('topBtn');

    window.addEventListener('scroll', () => {
        topBtn.style.display = window.scrollY > 100 ? 'block' : 'none';
    });

    topBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    setTimeout(() => isPageLoad = false, 0);

    /* ================= Helpers ================= */

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

});

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

