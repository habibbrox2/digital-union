$(document).ready(function() {
    // Load divisions when page loads
    loadDivisions('#present_division_en', '#present_division_bn');
    loadDivisions('#permanent_division_en', '#permanent_division_bn');

    // Event listener for Division change (Present Address)
    $('#present_division_en, #present_division_bn').change(function() {
        var divisionId = $(this).val();
        if (divisionId) {
            loadDistricts(divisionId, '#present_district_en', '#present_district_bn');
        }
    });

    // Event listener for District change (Present Address)
    $('#present_district_en, #present_district_bn').change(function() {
        var districtId = $(this).val();
        if (districtId) {
            loadUpazilas(districtId, '#present_upazila_en', '#present_upazila_bn');
        }
    });

    // Event listener for Division change (Permanent Address)
    $('#permanent_division_en, #permanent_division_bn').change(function() {
        var divisionId = $(this).val();
        if (divisionId) {
            loadDistricts(divisionId, '#permanent_district_en', '#permanent_district_bn');
        }
    });

    // Event listener for District change (Permanent Address)
    $('#permanent_district_en, #permanent_district_bn').change(function() {
        var districtId = $(this).val();
        if (districtId) {
            loadUpazilas(districtId, '#permanent_upazila_en', '#permanent_upazila_bn');
        }
    });

    // Function to synchronize _en and _bn select elements
    function syncSelects(selectEn, selectBn) {
        $(selectEn).change(function() {
            var selectedValue = $(this).val();
            $(selectBn).val(selectedValue); // Synchronize Bangla select with English select
        });

        $(selectBn).change(function() {
            var selectedValue = $(this).val();
            $(selectEn).val(selectedValue); // Synchronize English select with Bangla select
        });
    }

    // Sync Divisions, Districts, and Upazilas
    syncSelects('#present_division_en', '#present_division_bn');
    syncSelects('#permanent_division_en', '#permanent_division_bn');
    syncSelects('#present_district_en', '#present_district_bn');
    syncSelects('#permanent_district_en', '#permanent_district_bn');
    syncSelects('#present_upazila_en', '#present_upazila_bn');
    syncSelects('#permanent_upazila_en', '#permanent_upazila_bn');

    // Function to load Divisions
    function loadDivisions(selectEn, selectBn) {
        $.ajax({
            url: '/geo/divisions', // PHP file to fetch divisions
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                $(selectEn).empty().append('<option value="">Select Division</option>');
                $(selectBn).empty().append('<option value="">বিভাগ নির্বাচন করুন</option>');
                $.each(data, function(index, division) {
                    $(selectEn).append('<option value="' + division.division_code + '">' + division.division_name_en + '</option>');
                    $(selectBn).append('<option value="' + division.division_code + '">' + division.division_name_bn + '</option>');
                });
            },
            error: function() {
                SweetAlertUtil.error('ত্রুটি', 'বিভাগ লোড করতে ব্যর্থ হয়েছে।');
            }
        });
    }

    // Function to load Districts based on Division
    function loadDistricts(divisionId, selectEn, selectBn) {
        $.ajax({
            url: '/geo/districts', // PHP file to fetch districts
            method: 'GET',
            data: { division_code: divisionId },
            dataType: 'json',
            success: function(data) {
                $(selectEn).empty().append('<option value="">Select District</option>');
                $(selectBn).empty().append('<option value="">জেলা নির্বাচন করুন</option>');
                $.each(data, function(index, district) {
                    $(selectEn).append('<option value="' + district.district_code + '">' + district.district_name_en + '</option>');
                    $(selectBn).append('<option value="' + district.district_code + '">' + district.district_name_bn + '</option>');
                });
            },
            error: function() {
                SweetAlertUtil.error('ত্রুটি', 'জেলা লোড করতে ব্যর্থ হয়েছে।');
            }
        });
    }

    // Function to load Upazilas based on District
    function loadUpazilas(districtId, selectEn, selectBn) {
        $.ajax({
            url: '/geo/upazilas', // PHP file to fetch upazilas
            method: 'GET',
            data: { district_code: districtId },
            dataType: 'json',
            success: function(data) {
                $(selectEn).empty().append('<option value="">Select Upazila</option>');
                $(selectBn).empty().append('<option value="">উপজেলা নির্বাচন করুন</option>');
                $.each(data, function(index, upazila) {
                    $(selectEn).append('<option value="' + upazila.upazila_code + '">' + upazila.upazila_name_en + '</option>');
                    $(selectBn).append('<option value="' + upazila.upazila_code + '">' + upazila.upazila_name_bn + '</option>');
                });
            },
            error: function() {
                SweetAlertUtil.error('ত্রুটি', 'উপজেলা লোড করতে ব্যর্থ হয়েছে।');
            }
        });
    }

    $('#permanentAddress').hide();

    // Event listener for the nagorik_status checkbox
    $('#nagorik_status').change(function() {
        if ($(this).is(':checked')) {
            $('#permanentAddress').show(); // Show permanent address if checked
        } else {
            $('#permanentAddress').hide(); // Hide permanent address if unchecked
        }
    });

    $('#AddressisSame').change(function() {
        if ($(this).is(':checked')) {
            // Copy the present address to the permanent address fields
            $('#permanent_division_en').val($('#present_division_en').val());
            $('#permanent_district_en').val($('#present_district_en').val());
            $('#permanent_upazila_en').val($('#present_upazila_en').val());
            $('#permanent_post_office_en').val($('#present_post_office_en').val());
            $('#permanent_word_en').val($('#present_word_en').val());
            $('#permanent_village_en').val($('#present_village_en').val());
            $('#permanent_road_area_en').val($('#present_road_area_en').val());
            $('#permanent_holding_house_number_en').val($('#present_holding_house_number_en').val());
    
            $('#permanent_division_bn').val($('#present_division_bn').val());
            $('#permanent_district_bn').val($('#present_district_bn').val());
            $('#permanent_upazila_bn').val($('#present_upazila_bn').val());
            $('#permanent_post_office_bn').val($('#present_post_office_bn').val());
            $('#permanent_word_bn').val($('#present_word_bn').val());
            $('#permanent_village_bn').val($('#present_village_bn').val());
            $('#permanent_road_area_bn').val($('#present_road_area_bn').val());
            $('#permanent_holding_house_number_bn').val($('#present_holding_house_number_bn').val());
    
            // Trigger the change events to update dependent dropdowns
    
            // For English fields
            $('#permanent_division_en').change(function() {
                // Ensure that District and Upazila are updated based on Division
                $('#permanent_district_en').val($('#present_district_en').val()); // Copy district if any
                $('#permanent_upazila_en').val($('#present_upazila_en').val());   // Copy upazila if any
                $('#permanent_district_en').change();  // Trigger change for district to update dependent upazilas
                $('#permanent_upazila_en').change();  // Trigger change for upazila if any further dependencies
            });
    
            // For Bangla fields
            $('#permanent_division_bn').change(function() {
                // Ensure that District and Upazila are updated based on Division
                $('#permanent_district_bn').val($('#present_district_bn').val()); // Copy district if any
                $('#permanent_upazila_bn').val($('#present_upazila_bn').val());   // Copy upazila if any
                $('#permanent_district_bn').change();  // Trigger change for district to update dependent upazilas
                $('#permanent_upazila_bn').change();  // Trigger change for upazila if any further dependencies
            });
    
            // Trigger the initial changes to populate dependent fields
            $('#permanent_division_en').change();
            $('#permanent_division_bn').change();
    
        } else {
            // Clear the permanent address fields when unchecked
            $('#permanent_division_en').val('');
            $('#permanent_district_en').val('');
            $('#permanent_upazila_en').val('');
            $('#permanent_post_office_en').val('');
            $('#permanent_word_en').val('');
            $('#permanent_village_en').val('');
            $('#permanent_road_area_en').val('');
            $('#permanent_holding_house_number_en').val('');
    
            $('#permanent_division_bn').val('');
            $('#permanent_district_bn').val('');
            $('#permanent_upazila_bn').val('');
            $('#permanent_post_office_bn').val('');
            $('#permanent_word_bn').val('');
            $('#permanent_village_bn').val('');
            $('#permanent_road_area_bn').val('');
            $('#permanent_holding_house_number_bn').val('');
        }
    });
    
    
       
});
