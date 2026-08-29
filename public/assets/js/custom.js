document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // Initialize Bootstrap tooltips
    document.querySelectorAll('.social-network li a, .options_box .color a').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    // Scroll to top button
    var scrollupBtn = document.querySelector('.scrollup');

    window.addEventListener('scroll', function () {
        if (scrollupBtn) {
            if (window.scrollY > 100) {
                scrollupBtn.style.opacity = '1';
                scrollupBtn.style.pointerEvents = 'auto';
            } else {
                scrollupBtn.style.opacity = '0';
                scrollupBtn.style.pointerEvents = 'none';
            }
        }

        // Sticky menu animation
        var mainMenu = document.querySelector('.main-menu-area');
        if (mainMenu) {
            if (window.scrollY > 150) {
                mainMenu.classList.add('animated', 'fadeInDown');
            } else {
                mainMenu.classList.remove('animated', 'fadeInDown');
            }
        }
    });

    // Scroll to top click handler
    if (scrollupBtn) {
        scrollupBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return false;
        });
    }

    // FlexSlider replacements — simple fade carousel for #post-slider and #main-slider
    function initSimpleCarousel(selector) {
        var container = document.querySelector(selector);
        if (!container) return;

        var slides = container.querySelectorAll('li, .slide');
        if (slides.length < 2) return;

        var current = 0;

        // Ensure container is positioned for overlay
        container.style.position = 'relative';
        container.style.overflow = 'hidden';

        // Style all slides
        slides.forEach(function (slide, i) {
            slide.style.position = 'absolute';
            slide.style.top = '0';
            slide.style.left = '0';
            slide.style.width = '100%';
            slide.style.opacity = i === 0 ? '1' : '0';
            slide.style.transition = 'opacity 0.6s ease-in-out';
            slide.style.zIndex = i === 0 ? '1' : '0';
        });

        function nextSlide() {
            slides[current].style.opacity = '0';
            slides[current].style.zIndex = '0';
            current = (current + 1) % slides.length;
            slides[current].style.opacity = '1';
            slides[current].style.zIndex = '1';
        }

        setInterval(nextSlide, 7000);
    }

    initSimpleCarousel('#post-slider');
    initSimpleCarousel('#main-slider');

    // Add animation class to flex-caption elements
    document.querySelectorAll('.flex-caption').forEach(function (el) {
        el.classList.add('animated', 'zoomIn');
    });

    // Simple carousel replacement for slick-based elements
    function initSlickReplacement(selector, options) {
        var container = document.querySelector(selector);
        if (!container) return;

        var slidesToShow = (options && options.slidesToShow) || 3;
        var autoplay = options && options.autoplay !== false;
        var autoplaySpeed = (options && options.autoplaySpeed) || 5000;
        var vertical = options && options.vertical;

        // Get direct slide children
        var slides = Array.from(container.children).filter(function (child) {
            return child.tagName !== 'BUTTON' && !child.classList.contains('slick-arrow');
        });

        // Remove slick arrows if present
        container.querySelectorAll('.slick-arrow, .slick-prev, .slick-next').forEach(function (el) {
            el.remove();
        });

        if (slides.length <= slidesToShow && !vertical) return;

        // Wrap slides in a track
        var track = document.createElement('div');
        track.style.display = 'flex';
        track.style.gap = '15px';
        track.style.transition = 'transform 0.5s ease';
        track.style.overflow = 'hidden';

        if (vertical) {
            track.style.flexDirection = 'column';
            track.style.height = (slidesToShow * 100 / slidesToShow) + '%';
        }

        // Insert track and move slides into it
        container.innerHTML = '';
        container.style.overflow = 'hidden';
        container.appendChild(track);

        slides.forEach(function (slide) {
            slide.style.flex = vertical ? 'none' : '0 0 calc(' + (100 / slidesToShow) + '% - ' + ((slidesToShow - 1) * 15 / slidesToShow) + 'px)';
            slide.style.minWidth = vertical ? '100%' : '0';
            track.appendChild(slide);
        });

        var offset = 0;
        var maxOffset = Math.max(0, slides.length - slidesToShow);

        function slideNext() {
            offset = (offset + 1) > maxOffset ? 0 : offset + 1;
            if (vertical) {
                track.style.transform = 'translateY(-' + (offset * (100 / slidesToShow)) + '%)';
            } else {
                track.style.transform = 'translateX(-' + (offset * (100 / slidesToShow)) + '%)';
            }
        }

        if (autoplay) {
            setInterval(slideNext, autoplaySpeed);
        }
    }

    initSlickReplacement('.projects-highlights', { slidesToShow: 3, autoplay: true, autoplaySpeed: 4000 });
    initSlickReplacement('.event-list', { slidesToShow: 3, autoplay: true, autoplaySpeed: 5000 });
    initSlickReplacement('#organization', { slidesToShow: 4, autoplay: true, autoplaySpeed: 2000 });
    initSlickReplacement('#more-videos', { slidesToShow: 1, autoplay: true, autoplaySpeed: 5000, vertical: true });
    initSlickReplacement('.video-content-boxes', { slidesToShow: 3, autoplay: true, autoplaySpeed: 5000 });
    initSlickReplacement('.latest-news-box', { slidesToShow: 2, autoplay: true, autoplaySpeed: 5000 });

    // Responsive video embedding (fitVids replacement)
    function fitVids(selector) {
        document.querySelectorAll(selector).forEach(function (container) {
            container.querySelectorAll('iframe, object, embed, video').forEach(function (media) {
                // Skip if already wrapped
                if (media.parentNode.classList.contains('fitvids-wrapper')) return;

                var wrapper = document.createElement('div');
                wrapper.className = 'fitvids-wrapper';
                wrapper.style.position = 'relative';
                wrapper.style.paddingBottom = '56.25%';
                wrapper.style.height = '0';
                wrapper.style.overflow = 'hidden';
                wrapper.style.maxWidth = '100%';

                media.parentNode.insertBefore(wrapper, media);
                wrapper.appendChild(media);

                media.style.position = 'absolute';
                media.style.top = '0';
                media.style.left = '0';
                media.style.width = '100%';
                media.style.height = '100%';
            });
        });
    }

    fitVids('#fits-video');
    fitVids('#slider-video');
    fitVids('#featured-videos');
});
