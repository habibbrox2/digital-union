$(document).ready(function () {
    /**
     * ড্রপডাউন পপুলেট করার জন্য ফাংশন।
     */
    function populateDropdown(selector, data, selectedValue = null) {
        const dropdown = $(selector);
        dropdown.empty().append('<option value="">-- নির্বাচন করুন --</option>');
        $.each(data, function (index, item) {
            // সংশোধনের জন্য `item.name_en` বা `item.id` কে value হিসাবে ব্যবহার করা ভালো
            let isSelected = selectedValue && (selectedValue == item.name_en || selectedValue == item.id) ? 'selected' : '';
            dropdown.append(
                `<option value="${item.name_en}" 
                         data-geo-code="${item.geo_code}" 
                         data-name-en="${item.name_en}" 
                         data-name-bn="${item.name_bn}" 
                         data-geo-id="${item.id}" ${isSelected}>
                    ${item.name_bn}
                </option>`
            );
        });
    }
    
    /**
     * জিওগ্রাফিক্যাল ডেটা ফেচ করার জন্য AJAX ফাংশন।
     */
    function fetchGeoData(geoOrder, parentGeoId, dropdownSelector, nextDropdownSelector = null, selectedValue = null, callback = null) {
        $.ajax({
            url: '/settings/geo/getdata', // আপনার API এন্ডপয়েন্ট
            method: 'POST',
            data: { geo_order: geoOrder, parent_geo_id: parentGeoId },
            dataType: 'json',
            success: function (data) {
                populateDropdown(dropdownSelector, data, selectedValue);
                if (nextDropdownSelector) {
                    $(nextDropdownSelector).empty().append('<option value="">-- নির্বাচন করুন --</option>');
                }
                if (callback) callback();
            },
            error: (err) => console.error("Error fetching geo data:", err)
        });
    }

    /**
     * একটি নির্দিষ্ট ঠিকানার ফিল্ডগুলো ডেটা দিয়ে পূরণ করে।
     * @param {string} prefix - ঠিকানার প্রিফিক্স ('present' or 'permanent')
     * @param {object} addressData - ঠিকানার ডেটা
     */
    function populateAddressFields(prefix, addressData) {
        if (!addressData) return;

        // সাধারণ ইনপুট ফিল্ড পূরণ
        $(`#${prefix}_village_en`).val(addressData.village_en);
        $(`#${prefix}_village_bn`).val(addressData.village_bn);
        $(`#${prefix}_rbs_en`).val(addressData.rbs_en);
        $(`#${prefix}_rbs_bn`).val(addressData.rbs_bn);
        $(`#${prefix}_holding_no`).val(addressData.holding_no);
        $(`#${prefix}_ward_no`).val(addressData.ward_no);
        $(`#${prefix}_postoffice_en`).val(addressData.postoffice_en);
        $(`#${prefix}_postoffice_bn`).val(addressData.postoffice_bn);

        // জেলা, উপজেলা এবং ইউনিয়ন লোড করার চেইন
        const districtGeoId = addressData.district_geo_id;
        const upazilaGeoId = addressData.upazila_geo_id;
        const unionGeoId = addressData.union_geo_id;

        if (districtGeoId) {
            // জেলা লোড করুন এবং নির্বাচিত করুন
            fetchGeoData(1, 0, `#${prefix}_district_id`, null, addressData.district_en, () => {
                $(`#${prefix}_district_id`).trigger('change');
                // উপজেলা লোড করুন এবং নির্বাচিত করুন
                fetchGeoData(2, districtGeoId, `#${prefix}_upazila_id`, null, addressData.upazila_en, () => {
                    $(`#${prefix}_upazila_id`).trigger('change');
                     // ইউনিয়ন লোড করুন এবং নির্বাচিত করুন
                    fetchGeoData(3, upazilaGeoId, `#${prefix}_union_id`, null, addressData.union_en, () => {
                        $(`#${prefix}_union_id`).trigger('change');
                    });
                });
            });
        }
    }

    /**
     * আবেদনকারীর ডেটা লোড করে ফর্ম পূরণ করার মূল ফাংশন।
     */
    function loadApplicationData(applicationId) {

        const certificate_type = $('#certificate_type').val();
        if (!certificate_type) {
            console.error("Certificate type is not set.");
            return;
        }  
        // আবেদন আইডি চেক করুন



        if (!applicationId) return;
        
        // সার্ভার থেকে ডেটা আনার জন্য AJAX কল
        $.ajax({
            url: `/applications/${certificate_type}/api/${applicationId}`, // আপনার ডেটা আনার API
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                // ব্যক্তিগত তথ্য পূরণ
                $('#name_en').val(data.name_en);
                $('#name_bn').val(data.name_bn);
                $('#nid').val(data.nid);
                $('#birth_id').val(data.birth_id);
                $('#passport_no').val(data.passport_no);
                $('#birth_date').val(data.birth_date);
                $('#father_name_en').val(data.father_name_en);
                $('#father_name_bn').val(data.father_name_bn);
                $('#mother_name_en').val(data.mother_name_en);
                $('#mother_name_bn').val(data.mother_name_bn);
                $('#occupation').val(data.occupation);
                $('#educational_qualification').val(data.educational_qualification);
                $('#resident').val(data.resident);
                $('#religion').val(data.religion);
                $('#gender').val(data.gender);
                $('#marital_status').val(data.marital_status).trigger('change');

                // বিবাহিত হলে স্বামী/স্ত্রীর নাম
                if(data.marital_status === 'married') {
                    $('#spouse_name_en').val(data.spouse_name_en);
                    $('#spouse_name_bn').val(data.spouse_name_bn);
                }

                // ছবির প্রিভিউ
                if(data.photo_url) {
                    $('#photo_preview').attr('src', data.photo_url).show();
                }

                // বর্তমান ও স্থায়ী ঠিকানা পূরণ
                populateAddressFields('present', data.present_address);
                populateAddressFields('permanent', data.permanent_address);
            },
            error: (err) => console.error("Could not load application data:", err)
        });
    }

    // --- ইভেন্ট হ্যান্ডলার ---
    
    // ঠিকানা প্রিফিক্স এর জন্য ইভেন্ট বাইন্ডিং
    ['present', 'permanent'].forEach(prefix => {
        $(`#${prefix}_district_id`).change(function () {
            const selected = $(this).find('option:selected');
            $(`#${prefix}_district_bn`).val(selected.data('name-bn'));
            fetchGeoData(2, selected.data('geo-id'), `#${prefix}_upazila_id`, `#${prefix}_union_id`);
        });

        $(`#${prefix}_upazila_id`).change(function () {
            const selected = $(this).find('option:selected');
            $(`#${prefix}_upazila_bn`).val(selected.data('name-bn'));
            fetchGeoData(3, selected.data('geo-id'), `#${prefix}_union_id`);
        });

        $(`#${prefix}_union_id`).change(function () {
            $(`#${prefix}_union_bn`).val($(this).find('option:selected').data('name-bn'));
        });
    });

    // বিবাহিত স্ট্যাটাস পরিবর্তন হলে স্বামী/স্ত্রীর নাম ফিল্ড দেখানো/লুকানো
    $('#marital_status').change(function () {
        $('#spouse_name').toggle($(this).val() === 'married');
    });

    // পেইজ লোডের সময় আবেদনকারীর ডেটা লোড করুন
    const applicationId = $('#application_id').val();
    loadApplicationData(applicationId);

});