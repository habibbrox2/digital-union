$(document).ready(function () {
    function resetDropdownsitem(startDropdown) {
        let reset = false;
        $('select').each(function () {
            if (reset) {
                $(this).empty().prop('disabled', true).append('<option value="">Select</option>');
            }
            if ($(this).attr('id') === startDropdown) {
                reset = true;
            }
        });
    }

    function syncDropdowns(sourceDropdown, targetDropdown) {
        const value = $(`#${sourceDropdown}`).val();
        $(`#${targetDropdown}`).val(value);
    }

    function loadDivisions() {
        $.get('/geo/divisions', function (data) {
            var divisions = JSON.parse(data);
            $('#division_name_bn, #division_name_en').empty().append('<option value="">Select Division</option>');
            divisions.forEach(function (division) {
                $('#division_name_bn').append('<option value="' + division.division_code + '">' + division.division_name_bn + '</option>');
                $('#division_name_en').append('<option value="' + division.division_code + '">' + division.division_name_en + '</option>');
            });
        }).fail(function () {
            SweetAlertUtil.error('ত্রুটি', 'বিভাগ লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
        });
    }

    // Load divisions on page load
    loadDivisions();

    // Synchronize division selections
    $('#division_name_bn').change(function () {
        syncDropdowns('division_name_bn', 'division_name_en');
    });
    $('#division_name_en').change(function () {
        syncDropdowns('division_name_en', 'division_name_bn');
    });

    // Handle division change
    $('#division_name_bn, #division_name_en').change(function () {
        var divisionCode = $(this).val();
        resetDropdownsitem('division');
        if (divisionCode) {
            $.get('/geo/districts', { division_code: divisionCode }, function (data) {
                var districts = JSON.parse(data);
                $('#district_name_bn, #district_name_en').empty().append('<option value="">Select District</option>');
                districts.forEach(function (district) {
                    $('#district_name_bn').append('<option value="' + district.district_code + '">' + district.district_name_bn + '</option>');
                    $('#district_name_en').append('<option value="' + district.district_code + '">' + district.district_name_en + '</option>');
                });
                $('#district_name_bn, #district_name_en').prop('disabled', false);
            }).fail(function () {
                SweetAlertUtil.error('ত্রুটি', 'জেলা লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    });

    // Synchronize district selections
    $('#district_name_bn').change(function () {
        syncDropdowns('district_name_bn', 'district_name_en');
    });
    $('#district_name_en').change(function () {
        syncDropdowns('district_name_en', 'district_name_bn');
    });

    // Handle district change
    $('#district_name_bn, #district_name_en').change(function () {
        var districtCode = $(this).val();
        resetDropdownsitem('district');
        if (districtCode) {
            $.get('/geo/upazilas', { district_code: districtCode }, function (data) {
                var upazilas = JSON.parse(data);
                $('#upazila_name_bn, #upazila_name_en').empty().append('<option value="">Select Upazila</option>');
                upazilas.forEach(function (upazila) {
                    $('#upazila_name_bn').append('<option value="' + upazila.upazila_code + '">' + upazila.upazila_name_bn + '</option>');
                    $('#upazila_name_en').append('<option value="' + upazila.upazila_code + '">' + upazila.upazila_name_en + '</option>');
                });
                $('#upazila_name_bn, #upazila_name_en').prop('disabled', false);
            }).fail(function () {
                SweetAlertUtil.error('ত্রুটি', 'উপজেলা লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    });

    // Synchronize upazila selections
    $('#upazila_name_bn').change(function () {
        syncDropdowns('upazila_name_bn', 'upazila_name_en');
    });
    $('#upazila_name_en').change(function () {
        syncDropdowns('upazila_name_en', 'upazila_name_bn');
    });

    // Handle upazila change
    $('#upazila_name_bn, #upazila_name_en').change(function () {
        var upazilaCode = $(this).val();
        resetDropdownsitem('upazila');
        $('#rmo_name_bn').prop('disabled', false); // Enable RMO selection

        if (upazilaCode) {
            $.get('/geo/rmo', function (data) {
                var rmos = JSON.parse(data);
                // Filter RMOs based on selected upazila
                rmos.forEach(function (rmo) {
                    $('#rmo_name_bn').append('<option value="' + rmo.rmo_code + '">' + rmo.rmo_name_bn + '</option>');
                    $('#rmo_name_en').append('<option value="' + rmo.rmo_code + '">' + rmo.rmo_name_en + '</option>');
                });
            }).fail(function () {
                SweetAlertUtil.error('ত্রুটি', 'আরএমও লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    });

    // Synchronize RMO selections
    $('#rmo_name_bn').change(function () {
        syncDropdowns('rmo_name_bn', 'rmo_name_en');
    });
    $('#rmo_name_en').change(function () {
        syncDropdowns('rmo_name_en', 'rmo_name_bn');
    });

    // After selecting RMO, fetch unions
    $('#rmo_name_bn, #rmo_name_en').change(function () {
        var rmoCode = $(this).val();
        var upazilaCode = $('#upazila_name_bn').val();

        if (rmoCode && upazilaCode) {
            $.get('/geo/unions', { upazila_code: upazilaCode, rmo_code: rmoCode }, function (data) {
                var unions = JSON.parse(data);
                $('#union_name_bn, #union_name_en').empty().append('<option value="">Select Union</option>');

                if (unions.length > 0) {
                    // Populate the union dropdowns with available options
                    unions.forEach(function (union) {
                        $('#union_name_bn').append('<option value="' + union.union_code + '">' + union.union_name_bn + '</option>');
                        $('#union_name_en').append('<option value="' + union.union_code + '">' + union.union_name_en + '</option>');
                    });
                    $('#union_name_bn, #union_name_en').prop('disabled', false);
                } else {
                    // If no unions, replace selects with text inputs and populate wards
                    $('#union_name_bn').replaceWith('<input type="text" class="form-control" id="union_name_bn" name="union_name_bn" placeholder="গ্রামের ইউনিয়নের নাম লিখুন">');
                    $('#union_name_en').replaceWith('<input type="text" class="form-control" id="union_name_en" name="union_name_en" placeholder="Enter Union Name">');
                
                    // Create ward dropdowns for both Bangla and English
                    let wardOptions_name_bn = '<select class="form-select" id="ward_name_bn" name="ward_code_bn">';
                    wardOptions_name_bn += '<option value="">Select Ward</option>';
                    for (let i = 1; i <= 10; i++) {
                        const wardNumberInBangla = convertEnglishToBanglaDigits(i.toString());
                        wardOptions_name_bn += `<option value="${i}">${wardNumberInBangla}</option>`;
                    }
                    wardOptions_name_bn += '</select>';
                    $('#ward_name_bn').replaceWith(wardOptions_name_bn);
                
                    let wardOptions_name_en = '<select class="form-select" id="ward_name_en" name="ward_code_en">';
                    wardOptions_name_en += '<option value="">Select Ward</option>';
                    for (let i = 1; i <= 10; i++) {
                        wardOptions_name_en += `<option value="${i}">${i}</option>`;
                    }
                    wardOptions_name_en += '</select>';
                    $('#ward_name_en').replaceWith(wardOptions_name_en);
                
                    // Synchronize ward dropdowns
                    $('#ward_name_bn').change(function () {
                        syncDropdowns('ward_name_bn', 'ward_name_en');
                    });
                    $('#ward_name_en').change(function () {
                        syncDropdowns('ward_name_en', 'ward_name_bn');
                    });
                
                }
                
            }).fail(function () {
                SweetAlertUtil.error('ত্রুটি', 'ইউনিয়ন লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    });

    // Synchronize union selections
    $('#union_name_bn').change(function () {
        syncDropdowns('union_name_bn', 'union_name_en');
    });
    $('#union_name_en').change(function () {
        syncDropdowns('union_name_en', 'union_name_bn');
    });

    // Synchronize ward selections
    $('#ward_name_bn').change(function () {
        syncDropdowns('ward_name_bn', 'ward_name_en');
    });
    $('#ward_name_en').change(function () {
        syncDropdowns('ward_name_en', 'ward_name_bn');
    });
});
