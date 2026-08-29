// slider.js
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        let currentSlide = 0;
        const slides = document.querySelectorAll(".slider-item");
        const totalSlides = slides.length;

        if (totalSlides === 0) return;

        function showNextSlide() {
            // Hide the current slide
            slides[currentSlide].style.display = "none";
            
            // Move to the next slide
            currentSlide = (currentSlide + 1) % totalSlides;
            
            // Show the next slide
            slides[currentSlide].style.display = "block";
        }

        // Initially hide all slides except the first
        slides.forEach((slide, index) => {
            slide.style.display = index === 0 ? "block" : "none";
        });

        // Change slides every 5 seconds
        setInterval(showNextSlide, 5000);
    });
})();
