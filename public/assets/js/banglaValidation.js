document.addEventListener('DOMContentLoaded', function () {
    var banglaRegex = /^[\u0980-\u09FF\u09E6-\u09EF\s\-,.]+$/;

    // বাংলা ইনপুট ফিল্ডের ইভেন্ট হ্যান্ডলার
    document.querySelectorAll('input[name$="_bn"]').forEach(function (el) {
        el.classList.add('bangla');

        el.addEventListener('input', function () {
            var inputValue = this.value.trim();
            var errorElement = this.nextElementSibling;

            // আগের error message থাকলে রিসেট করবো
            if (!errorElement || !errorElement.classList.contains('error-bangla')) {
                var span = document.createElement('span');
                span.className = 'error-bangla text-danger';
                span.style.fontSize = '12px';
                this.parentNode.insertBefore(span, this.nextSibling);
                errorElement = span;
            }

            if (!banglaRegex.test(inputValue) && inputValue !== '') {
                errorElement.textContent = '⚠️ দয়া করে শুধুমাত্র বাংলায় লিখুন।';
                this.classList.add('has-error');
                this.style.borderColor = 'red';
            } else {
                errorElement.textContent = '';
                this.classList.remove('has-error');
                this.style.borderColor = '';
            }
        });
    });
});
