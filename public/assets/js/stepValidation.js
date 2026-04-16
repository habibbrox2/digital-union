$(document).ready(function () {
    const form = $("#applicationForm");
    const steps = $(".step");
    const indicators = $(".step-circle");
    let currentStep = 0;

    let validationRules = {};
    let validationMessages = {};

    $("input[required], select[required], textarea[required]").each(function () {
        let fieldId = $(this).attr("id");
        validationRules[fieldId] = { required: true };
        validationMessages[fieldId] = "⚠️ দয়া করে এই তথ্যটি পূরণ করুন!";

        if (!$(this).next(".error-msg").length) {
            $(this).after('<span class="error-msg text-danger"></span>');
        }
    });

    // Marital Status Logic
    $("#spouse_name").hide();
    $("#marital_status").change(function () {
        if ($(this).val() === "Married") {
            $("#spouse_name").fadeIn().find("input").val("");  
        } else {
            $("#spouse_name").fadeOut().find("input").val("");  
        }
    });

    function showStep(step) {
        steps.removeClass("active").eq(step).addClass("active");
        indicators.removeClass("active-step").eq(step).addClass("active-step");
        $(".next-step").toggle(step < steps.length - 1);
        $(".submit-button").toggle(step === steps.length - 1);
    }

    function validateStep() {
        let stepValid = true;
        let firstInvalidField = null;

        $(".step.active").find("input[required], select[required], textarea[required]").each(function () {
            const field = $(this);
            const fieldValue = field.val().trim();
            const errorMsg = field.next(".error-msg");

            if (field.is(":visible") && !fieldValue) {
                errorMsg.html(validationMessages[field.attr("id")]); // **⚠️ মেসেজ আপডেট করা হলো**
                field.addClass("has-error").css("border-color", "red");
                stepValid = false;
                if (!firstInvalidField) {
                    firstInvalidField = field;
                }
            } else {
                errorMsg.html(""); // **✅ ইনপুট দিলে সাথে সাথে মেসেজ সরবে**
                field.removeClass("has-error").css("border-color", "");
            }
        });

        if (!stepValid && firstInvalidField) {
            $('html, body').animate({
                scrollTop: firstInvalidField.offset().top - 20
            }, 500);
        }

        return stepValid;
    }

    $(".next-step").click(function () {
        if (validateStep()) {
            currentStep++;
            showStep(currentStep);
        }
    });

    $(".prev-step").click(function () {
        if (currentStep > 0) {
            currentStep--;
            showStep(currentStep);
        }
    });

    // ✅ ইনপুট পরিবর্তন হলে সাথে সাথে মেসেজ এবং বর্ডার সরবে
    $("input[required], select[required], textarea[required]").on("input change", function () {
        const field = $(this);
        const errorMsg = field.next(".error-msg");

        if (field.val().trim()) {
            errorMsg.html(""); // **✅ ইনপুট দিলে সাথে সাথে মেসেজ সরবে**
            field.removeClass("has-error").css("border-color", "");
        }
    });

    // Prevent Enter key from submitting form, move to next step instead
    $(document).on("keydown", function (event) {
        if (event.key === "Enter" && !$(event.target).is("textarea")) {
            event.preventDefault();
            $(".next-step").trigger("click");
        }
    });

    showStep(currentStep);
});
