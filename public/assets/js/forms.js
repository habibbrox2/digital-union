// File: forms.js

// ======== Position-based Tab navigation (EN fields skipped) ========
// _bn + non-EN fields → grouped by position slot in their row.
// _en fields → SKIPPED entirely during Tab navigation.
// Shift+Tab goes backward. Warish _bn uses its own group; warish extras use default Tab.
function initColumnTabNavigation(formEl) {
    if (!formEl) return;

    function isBn(el) {
        if (el.id && el.id.endsWith('_bn')) return true;
        if (el.name && /_bn(\[\]|$)/.test(el.name)) return true;
        return false;
    }
    function isEn(el) {
        // Skip only name_en, father_name_en, and mother_name_en during Tab
        if (el.id && (el.id === 'name_en' || el.id === 'father_name_en' || el.id === 'mother_name_en')) return true;
        return false;
    }

    function getPositionSlot(el) {
        var row = el.closest('.row');
        if (!row) return -1;
        // Warish rows: only _bn gets a slot; all other warish fields default Tab
        if (row.classList.contains('warish_entry') || row.closest('.warish_entry')) {
            if (isBn(el)) return 'bn';
            return -1;
        }
        var inputs = Array.from(row.querySelectorAll(':scope > div input:not([type="hidden"]):not([disabled]), :scope > div select:not([disabled]), :scope > div textarea:not([disabled])'))
            .filter(function(inp) { return inp.offsetParent !== null; });
        if (inputs.length === 0) {
            inputs = Array.from(row.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])'))
                .filter(function(inp) { return inp.offsetParent !== null; });
        }
        return inputs.indexOf(el);
    }

    // Build groups: warish _bn → 'bn'; ALL other non-EN fields → position slot
    function getColumnGroups() {
        var all = Array.from(formEl.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])'))
            .filter(function(el) { return el.offsetParent !== null; });
        var groups = {};
        all.forEach(function(el) {
            if (isEn(el)) return; // Skip _en fields entirely
            var slot = getPositionSlot(el);
            if (slot === 'bn') {
                if (!groups.bn) groups.bn = [];
                groups.bn.push(el);
            } else if (slot >= 0 && slot !== undefined) {
                if (!groups[slot]) groups[slot] = [];
                groups[slot].push(el);
            }
        });
        return groups;
    }

    // All visible fields in DOM order (for EN skip)
    function allVisibleFields() {
        return Array.from(formEl.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])'))
            .filter(function(el) { return el.offsetParent !== null; });
    }

    formEl.addEventListener('keydown', function(e) {
        if (e.key !== 'Tab') return;
        var target = e.target;
        if (!target || (!target.id && !target.name)) return;

        // If target is an EN field → skip to the next/prev non-EN field in DOM order
        if (isEn(target)) {
            var all = allVisibleFields();
            var cur = all.indexOf(target);
            if (e.shiftKey) {
                for (var i = cur - 1; i >= 0; i--) {
                    if (!isEn(all[i])) { e.preventDefault(); setTimeout(function() { all[i].focus(); }, 0); return; }
                }
            } else {
                for (var i = cur + 1; i < all.length; i++) {
                    if (!isEn(all[i])) { e.preventDefault(); setTimeout(function() { all[i].focus(); }, 0); return; }
                }
            }
            // No non-EN field found → allow default Tab behavior (move out of form)
            return;
        }

        // Determine group for non-EN field
        var slot = getPositionSlot(target);
        var group = null;
        if (slot === 'bn') group = 'bn';
        else if (slot >= 0 && slot !== undefined && slot !== null) group = slot;
        if (group === null || group === undefined) return;

        var groups = getColumnGroups();
        var fields = groups[group];
        if (!fields) return;
        var idx = fields.indexOf(target);
        if (idx === -1) return;

        if (e.shiftKey) {
            if (idx > 0) { e.preventDefault(); setTimeout(function() { fields[idx - 1].focus(); }, 0); }
        } else {
            if (idx < fields.length - 1) { e.preventDefault(); setTimeout(function() { fields[idx + 1].focus(); }, 0); }
        }
    });
}

$(document).ready(function () {
    // Auto-init column-based Tab navigation for any application form
    var appForm = document.getElementById('applicationForm');
    if (appForm) {
        initColumnTabNavigation(appForm);
    }


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
