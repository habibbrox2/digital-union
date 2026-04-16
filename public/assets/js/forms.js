// File: forms.js

$(document).ready(function () {
    // ======== আবেদনের নাম নির্বাচন (শুধু ইউজার ইন্টারঅ্যাকশনের জন্য) ========
    $('input[name="applicant_name_option"]').on('change', function () {
        const selectedOption = $(this).val();
        const applicantInput = $('#applicant_name');
        if (selectedOption === 'own') {
            const ownerName = $('#name_bn').val();
            applicantInput.val(ownerName).prop('readonly', true).show();
        } else if (selectedOption === 'other') {
            applicantInput.val('').prop('readonly', false).show().focus();
        }
    });

    // আবেদনকারীর নাম পরিবর্তনের সাথে সাথে কপি (শুধু "নিজ" সিলেক্টেড থাকলে)
    $('#name_bn').on('input', function () {
        if ($('#own_name').is(':checked')) {
            $('#applicant_name').val($(this).val());
        }
    });
      
    // ======== আবেদনকারীর ছবি প্রিভিউ ========
    $('#photo').on('change', function (event) {
        const file = event.target.files[0];
        const preview = $('#photo_preview');

        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function (e) {
                preview.attr('src', e.target.result).css('display', 'block');
            };
            reader.readAsDataURL(file);
        } else {
            preview.attr('src', '').css('display', 'none');
        }
    });

    // ======== ডকুমেন্ট ইনপুট যুক্ত ========
    $('#add_document').on('click', function () {
        const newRow = `
            <div class="row align-items-center document-row mb-2">
                <div class="col-md-5">
                    <input type="file" name="documents[]" class="form-control file-input">
                </div>
                <div class="col-md-5 preview-area"></div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger remove-file">X</button>
                </div>
            </div>
        `;
        $('#documents_container').append(newRow);
    });

    // ======== ডকুমেন্ট প্রিভিউ ========
    $(document).on('change', '.file-input', function (event) {
        const $preview = $(this).closest('.document-row').find('.preview-area');
        $preview.empty();

        const file = event.target.files[0];
        if (!file) return;

        const fileType = file.type.toLowerCase();

        if (fileType.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function (e) {
                $preview.html(`
                    <img src="${e.target.result}" alt="📸 ছবি প্রিভিউ" class="img-thumbnail" style="max-width: 120px; border: 1px solid #ccc; padding: 5px;">
                `);
            };
            reader.readAsDataURL(file);
        } else if (fileType === 'application/pdf') {
            const fileURL = URL.createObjectURL(file);
            $preview.html(`
                <iframe src="${fileURL}#toolbar=0&navpanes=0&scrollbar=0" style="width: 120px; height: 150px; border: 1px solid #ccc;" class="shadow-sm rounded"></iframe>
            `);
        } else {
            $preview.html(`<span>📝 ${file.name}</span>`);
        }
    });

    // ======== ডকুমেন্ট রিমুভ ========
    $(document).on('click', '.remove-file', function () {
        $(this).closest('.document-row').remove();
    });
});
$(document).ready(function () {

    // ======== লোকেশন ড্রপডাউন লোড ========
    fetchGeoData(1, 0, '#district_dropdown', function () {
        const defaultDistrictValue = '3026';

        // Delay দিয়ে DOM অ্যাপেন্ড নিশ্চিত করে তারপর ভ্যালু সেট
        setTimeout(function () {
            const $districtDropdown = $('#district_dropdown');

            if ($districtDropdown.find(`option[value="${defaultDistrictValue}"]`).length > 0) {
                $districtDropdown.val(defaultDistrictValue).trigger('change');
            } else {
                console.warn('ডিফল্ট জেলা (ঢাকা) অপশন পাওয়া যায়নি।');
            }
        }, 200); // 200ms delay
    });

    // ======== জেলা নির্বাচন হলে উপজেলা লোড ========
    $('#district_dropdown').on('change', function () {
        const parentGeoId = $(this).find('option:selected').data('geo-id');
        resetDropdown('#upazila_dropdown', 'উপজেলা নির্বাচন করুন');
        resetDropdown('#union_dropdown', 'ইউনিয়ন নির্বাচন করুন');

        if (parentGeoId) {
            fetchGeoData(2, parentGeoId, '#upazila_dropdown');
        }
    });

    // ======== উপজেলা নির্বাচন হলে ইউনিয়ন লোড ========
    $('#upazila_dropdown').on('change', function () {
        const upazilaNameEn = $(this).find('option:selected').data('name-en');
        const districtNameEn = $('#district_dropdown').find('option:selected').data('name-en');

        resetDropdown('#union_dropdown', 'ইউনিয়ন নির্বাচন করুন');

        if (districtNameEn && upazilaNameEn) {
            loadUnions(districtNameEn, upazilaNameEn, '#union_dropdown');
        }
    });

    // ======== Geo Data ফেচ (জেলা / উপজেলা) ========
    function fetchGeoData(geoOrder, parentGeoId, dropdownSelector, callback) {
        const $dropdown = $(dropdownSelector);
        $dropdown.empty().append('<option value="">লোড হচ্ছে...</option>');

        $.ajax({
            url: '/settings/geo/getdata',
            type: 'POST',
            dataType: 'json',
            data: {
                geo_order: geoOrder,
                parent_geo_id: parentGeoId
            },
            success: function (data) {
                $dropdown.empty();

                let placeholder = 'চিহ্নিত করুন';
                if (dropdownSelector === '#district_dropdown') placeholder = 'জেলা নির্বাচন করুন';
                else if (dropdownSelector === '#upazila_dropdown') placeholder = 'উপজেলা নির্বাচন করুন';
                else if (dropdownSelector === '#union_dropdown') placeholder = 'ইউনিয়ন নির্বাচন করুন';

                $dropdown.append(`<option value="">${placeholder}</option>`);

                $.each(data, function (index, item) {
                    $dropdown.append(
                        $('<option></option>')
                            .val(String(item.geo_code)) // Ensure string value
                            .text(item.name_bn)
                            .attr('data-geo-code', item.geo_code)
                            .attr('data-name-en', item.name_en)
                            .attr('data-name-bn', item.name_bn)
                            .attr('data-geo-id', item.id)
                    );
                });

                if (typeof callback === 'function') {
                    // ছোট delay দিয়ে নির্বাচন চালান
                    setTimeout(callback, 100);
                }
            },
            error: function (xhr, status, error) {
                $dropdown.empty().append('<option value="">ডাটা লোডে সমস্যা হয়েছে</option>');
                console.error('Geo Data Load Error:', error);
            }
        });
    }

    // ======== ইউনিয়ন ডেটা লোড ========
    function loadUnions(districtNameEn, upazilaNameEn, dropdownSelector) {
        const $dropdown = $(dropdownSelector);
        $dropdown.empty().append('<option value="">লোড হচ্ছে...</option>');

        $.ajax({
            url: '/geo/getUnion',
            type: 'POST',
            dataType: 'json',
            data: {
                district_name_en: districtNameEn,
                upazila_name_en: upazilaNameEn
            },
            success: function (data) {
                $dropdown.empty();

                if (data.length === 0) {
                    $dropdown.append('<option value="">কোনো ইউনিয়ন পাওয়া যায়নি</option>');
                    return;
                }

                $dropdown.append('<option value="">ইউনিয়ন নির্বাচন করুন</option>');

                $.each(data, function (index, item) {
                    $dropdown.append(
                        $('<option></option>')
                            .val(item.union_code)
                            .text(item.union_name_bn)
                            .attr('data-name-en', item.union_name_en)
                            .attr('data-name-bn', item.union_name_bn)
                            .attr('data-union-id', item.union_id)
                    );
                });
            },
            error: function (xhr, status, error) {
                $dropdown.empty().append('<option value="">ডাটা লোডে সমস্যা হয়েছে</option>');
                console.error('Union Load Error:', error);
            }
        });
    }

    // ======== ড্রপডাউন রিসেট ========
    function resetDropdown(selector, placeholder = 'চিহ্নিত করুন') {
        $(selector).empty().append(`<option value="">${placeholder}</option>`);
    }

        function toggleSpouseName() {
        if ($('#marital_status').val() === 'married') {
            $('#spouse_name').show();
        } else {
            $('#spouse_name').hide();
        }
    }

    // Initial check on page load
    toggleSpouseName();

    // Listen for changes
    $('#marital_status').change(function() {
        toggleSpouseName();
    });

});
