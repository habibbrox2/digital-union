// slider.js
let currentSlide = 0;
const slides = document.querySelectorAll(".slider-item");
const totalSlides = slides.length;

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
    if (index !== 0) {
        slide.style.display = "none";
    } else {
        slide.style.display = "block"; // Show the first slide
    }
});

// Change slides every 5 seconds
setInterval(showNextSlide, 5000); // 5000 milliseconds = 5 seconds
