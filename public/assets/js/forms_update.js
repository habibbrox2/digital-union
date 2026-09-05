document.addEventListener('DOMContentLoaded', function () {
    function populateDropdown(selector, data, selectedValue) {
        selectedValue = selectedValue || null;
        var dropdown = document.querySelector(selector);
        if (!dropdown) return;
        dropdown.innerHTML = '<option value="">-- নির্বাচন করুন --</option>';
        data.forEach(function (item) {
            var isSelected = selectedValue && (selectedValue == item.name_en || selectedValue == item.id);
            var opt = document.createElement('option');
            opt.value = item.name_en;
            opt.setAttribute('data-geo-code', item.geo_code);
            opt.setAttribute('data-name-en', item.name_en);
            opt.setAttribute('data-name-bn', item.name_bn);
            opt.setAttribute('data-geo-id', item.id);
            if (isSelected) opt.selected = true;
            opt.textContent = item.name_bn;
            dropdown.appendChild(opt);
        });
    }

    function fetchUpdateGeoData(geoOrder, parentGeoId, dropdownSelector, nextDropdownSelector, selectedValue, callback) {
        nextDropdownSelector = nextDropdownSelector || null;
        selectedValue = selectedValue || null;
        callback = callback || null;
        var formData = new FormData();
        formData.append('geo_order', geoOrder);
        formData.append('parent_geo_id', parentGeoId);
        fetch('/settings/geo/getdata', { method: 'POST', body: formData })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            populateDropdown(dropdownSelector, data, selectedValue);
            if (nextDropdownSelector) {
                var nextEl = document.querySelector(nextDropdownSelector);
                if (nextEl) nextEl.innerHTML = '<option value="">-- নির্বাচন করুন --</option>';
            }
            if (callback) callback();
        })
        .catch(function(err) { console.error("Error fetching geo data:", err); });
    }

    function populateAddressFields(prefix, addressData) {
        if (!addressData) return;
        var fields = {
            'village_en': 'village_en', 'village_bn': 'village_bn',
            'rbs_en': 'rbs_en', 'rbs_bn': 'rbs_bn',
            'holding_no': 'holding_no', 'ward_no': 'ward_no',
            'postoffice_en': 'postoffice_en', 'postoffice_bn': 'postoffice_bn'
        };
        for (var key in fields) {
            var el = document.getElementById(prefix + '_' + key);
            if (el) el.value = addressData[fields[key]] || '';
        }
        var districtGeoId = addressData.district_geo_id;
        var upazilaGeoId = addressData.upazila_geo_id;
        if (districtGeoId) {
            fetchUpdateGeoData(1, 0, '#' + prefix + '_district_id', null, addressData.district_en, function() {
                var distEl = document.getElementById(prefix + '_district_id');
                if (distEl) distEl.dispatchEvent(new Event('change'));
                fetchUpdateGeoData(2, districtGeoId, '#' + prefix + '_upazila_id', null, addressData.upazila_en, function() {
                    var upaEl = document.getElementById(prefix + '_upazila_id');
                    if (upaEl) upaEl.dispatchEvent(new Event('change'));
                    fetchUpdateGeoData(3, upazilaGeoId, '#' + prefix + '_union_id', null, addressData.union_en, function() {
                        var unionEl = document.getElementById(prefix + '_union_id');
                        if (unionEl) unionEl.dispatchEvent(new Event('change'));
                    });
                });
            });
        }
    }

    function loadApplicationData(applicationId) {
        var certTypeEl = document.getElementById('certificate_type');
        var certificate_type = certTypeEl ? certTypeEl.value : '';
        if (!certificate_type) { console.error("Certificate type is not set."); return; }
        if (!applicationId) return;
        fetch('/applications/' + certificate_type + '/api/' + applicationId)
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            function setVal(id, val) { var el = document.getElementById(id); if (el) el.value = val || ''; }
            setVal('name_en', data.name_en);
            setVal('name_bn', data.name_bn);
            setVal('nid', data.nid);
            setVal('birth_id', data.birth_id);
            setVal('passport_no', data.passport_no);
            if (data.birth_date && data.birth_date !== '0000-00-00') {
                var parts = data.birth_date.split('-');
                if (parts.length === 3) setVal('birth_date', parts[2] + '-' + parts[1] + '-' + parts[0]);
                else setVal('birth_date', data.birth_date);
            }
            setVal('father_name_en', data.father_name_en);
            setVal('father_name_bn', data.father_name_bn);
            setVal('mother_name_en', data.mother_name_en);
            setVal('mother_name_bn', data.mother_name_bn);
            setVal('occupation', data.occupation);
            setVal('educational_qualification', data.educational_qualification);
            setVal('resident', data.resident);
            setVal('religion', data.religion);
            setVal('gender', data.gender);
            var maritalEl = document.getElementById('marital_status');
            if (maritalEl) { maritalEl.value = data.marital_status || ''; maritalEl.dispatchEvent(new Event('change')); }
            if (data.marital_status === 'married') {
                setVal('spouse_name_en', data.spouse_name_en);
                setVal('spouse_name_bn', data.spouse_name_bn);
            }
            if (data.photo_url) {
                var photoPreview = document.getElementById('photo_preview');
                if (photoPreview) { photoPreview.src = data.photo_url; photoPreview.style.display = ''; }
            }
            populateAddressFields('present', data.present_address);
            populateAddressFields('permanent', data.permanent_address);
        })
        .catch(function(err) { console.error("Could not load application data:", err); });
    }

    ['present', 'permanent'].forEach(function(prefix) {
        var distEl = document.getElementById(prefix + '_district_id');
        if (distEl) {
            distEl.addEventListener('change', function () {
                var opt = this.options[this.selectedIndex];
                var nameBn = opt ? opt.getAttribute('data-name-bn') : '';
                var geoId = opt ? opt.getAttribute('data-geo-id') : 0;
                var distBnEl = document.getElementById(prefix + '_district_bn');
                if (distBnEl) distBnEl.value = nameBn || '';
                fetchUpdateGeoData(2, geoId, '#' + prefix + '_upazila_id', '#' + prefix + '_union_id');
            });
        }
        var upaEl = document.getElementById(prefix + '_upazila_id');
        if (upaEl) {
            upaEl.addEventListener('change', function () {
                var opt = this.options[this.selectedIndex];
                var nameBn = opt ? opt.getAttribute('data-name-bn') : '';
                var geoId = opt ? opt.getAttribute('data-geo-id') : 0;
                var upaBnEl = document.getElementById(prefix + '_upazila_bn');
                if (upaBnEl) upaBnEl.value = nameBn || '';
                fetchUpdateGeoData(3, geoId, '#' + prefix + '_union_id');
            });
        }
        var unionEl = document.getElementById(prefix + '_union_id');
        if (unionEl) {
            unionEl.addEventListener('change', function () {
                var opt = this.options[this.selectedIndex];
                var nameBn = opt ? opt.getAttribute('data-name-bn') : '';
                var unionBnEl = document.getElementById(prefix + '_union_bn');
                if (unionBnEl) unionBnEl.value = nameBn || '';
            });
        }
    });

    var maritalStatusEl = document.getElementById('marital_status');
    if (maritalStatusEl) {
        maritalStatusEl.addEventListener('change', function () {
            var spouseNameEl = document.getElementById('spouse_name');
            if (spouseNameEl) spouseNameEl.style.display = this.value === 'married' ? '' : 'none';
        });
    }

    var appIdEl = document.getElementById('application_id');
    var applicationId = appIdEl ? appIdEl.value : '';
    loadApplicationData(applicationId);
});
