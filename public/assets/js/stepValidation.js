document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.getElementById('applicationForm');
    const steps = document.querySelectorAll('.step');
    const indicators = document.querySelectorAll('.step-circle');
    let currentStep = 0;

    const validationRules = {};
    const validationMessages = {};

    // Build validation rules for required fields
    document.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (field) {
        const fieldId = field.id;
        validationRules[fieldId] = { required: true };
        validationMessages[fieldId] = '⚠️ দয়া করে এই তথ্যটি পূরণ করুন!';

        // Add error message span after the field if not already present
        if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-msg')) {
            field.insertAdjacentHTML('afterend', '<span class="error-msg text-danger"></span>');
        }
    });

    // Marital Status Logic
    const spouseName = document.getElementById('spouse_name');
    const maritalStatus = document.getElementById('marital_status');

    if (spouseName) {
        spouseName.style.display = 'none';
    }

    if (maritalStatus) {
        maritalStatus.addEventListener('change', function () {
            if (this.value === 'Married') {
                if (spouseName) {
                    spouseName.style.display = '';
                    spouseName.style.opacity = '0';
                    requestAnimationFrame(function () {
                        spouseName.style.transition = 'opacity 0.3s';
                        spouseName.style.opacity = '1';
                    });
                    // Clear inputs inside spouse name block
                    spouseName.querySelectorAll('input').forEach(function (input) {
                        input.value = '';
                    });
                }
            } else {
                if (spouseName) {
                    spouseName.style.transition = 'opacity 0.3s';
                    spouseName.style.opacity = '0';
                    setTimeout(function () {
                        spouseName.style.display = 'none';
                    }, 300);
                    // Clear inputs inside spouse name block
                    spouseName.querySelectorAll('input').forEach(function (input) {
                        input.value = '';
                    });
                }
            }
        });
    }

    function showStep(step) {
        steps.forEach(function (el, i) {
            el.classList.toggle('active', i === step);
        });
        indicators.forEach(function (el, i) {
            el.classList.toggle('active-step', i === step);
        });

        var nextBtn = document.querySelector('.next-step');
        var submitBtn = document.querySelector('.submit-button');
        if (nextBtn) nextBtn.style.display = step < steps.length - 1 ? '' : 'none';
        if (submitBtn) submitBtn.style.display = step === steps.length - 1 ? '' : 'none';
    }

    function isFieldVisible(field) {
        if (field.offsetParent === null) return false;
        var style = window.getComputedStyle(field);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    }

    function validateStep() {
        let stepValid = true;
        let firstInvalidField = null;

        var activeStep = document.querySelector('.step.active');
        if (!activeStep) return true;

        activeStep.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (field) {
            var fieldValue = field.value.trim();
            var errorMsg = field.nextElementSibling;

            if (isFieldVisible(field) && !fieldValue) {
                if (errorMsg && errorMsg.classList.contains('error-msg')) {
                    errorMsg.textContent = validationMessages[field.id] || '';
                }
                field.classList.add('has-error');
                field.style.borderColor = 'red';
                stepValid = false;
                if (!firstInvalidField) {
                    firstInvalidField = field;
                }
            } else {
                if (errorMsg && errorMsg.classList.contains('error-msg')) {
                    errorMsg.textContent = '';
                }
                field.classList.remove('has-error');
                field.style.borderColor = '';
            }
        });

        if (!stepValid && firstInvalidField) {
            var rect = firstInvalidField.getBoundingClientRect();
            var top = rect.top + window.pageYOffset - 20;
            window.scrollTo({ top: top, behavior: 'smooth' });
        }

        return stepValid;
    }

    var nextBtn = document.querySelector('.next-step');
    var prevBtn = document.querySelector('.prev-step');

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (validateStep()) {
                currentStep++;
                showStep(currentStep);
            }
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (currentStep > 0) {
                currentStep--;
                showStep(currentStep);
            }
        });
    }

    // Clear error message and border on input/change for required fields
    document.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (field) {
        field.addEventListener('input', function () {
            var errorMsg = this.nextElementSibling;
            if (this.value.trim()) {
                if (errorMsg && errorMsg.classList.contains('error-msg')) {
                    errorMsg.textContent = '';
                }
                this.classList.remove('has-error');
                this.style.borderColor = '';
            }
        });
        field.addEventListener('change', function () {
            var errorMsg = this.nextElementSibling;
            if (this.value.trim()) {
                if (errorMsg && errorMsg.classList.contains('error-msg')) {
                    errorMsg.textContent = '';
                }
                this.classList.remove('has-error');
                this.style.borderColor = '';
            }
        });
    });

    // Prevent Enter key from submitting form, move to next step instead
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
            event.preventDefault();
            if (nextBtn) nextBtn.click();
        }
    });

    showStep(currentStep);
});
