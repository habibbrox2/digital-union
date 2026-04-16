$(document).ready(function () {
    const banglaRegex = /^[\u0980-\u09FF\u09E6-\u09EF\s\-,]+$/;


    // বাংলা ইনপুট ফিল্ডের ইভেন্ট হ্যান্ডলার
    $('input[name$="_bn"]').each(function () {
        $(this).addClass('bangla');

        $(this).on('input', function () {
            const inputValue = $(this).val().trim();
            const errorElement = $(this).next('.error-bangla');

            // আগের error message থাকলে রিসেট করবো
            if (!errorElement.length) {
                $(this).after('<span class="error-bangla text-danger" style="font-size: 12px;"></span>');
            }

            if (!banglaRegex.test(inputValue) && inputValue !== '') {
                $(this).next('.error-bangla').text('⚠️ দয়া করে শুধুমাত্র বাংলায় লিখুন।');
                $(this).addClass("has-error").css('border-color', 'red');
            } else {
                $(this).next('.error-bangla').text('');
                $(this).removeClass("has-error").css('border-color', '');
            }
        });
    });
});
