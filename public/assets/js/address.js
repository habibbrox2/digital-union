document.addEventListener('DOMContentLoaded', function() {
    function addChangeListener(id, callback) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', callback);
    }

    function populateSelect(selectId, items, valueKey, labelKey, placeholder) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = item[labelKey];
            sel.appendChild(opt);
        });
    }

    function syncSelects(selectEnId, selectBnId) {
        var selEn = document.getElementById(selectEnId);
        var selBn = document.getElementById(selectBnId);
        if (selEn) selEn.addEventListener('change', function() { if (selBn) selBn.value = this.value; });
        if (selBn) selBn.addEventListener('change', function() { if (selEn) selEn.value = this.value; });
    }

    function loadDivisions(selectEnId, selectBnId) {
        fetch('/geo/divisions')
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            populateSelect(selectEnId, data, 'division_code', 'division_name_en', 'Select Division');
            populateSelect(selectBnId, data, 'division_code', 'division_name_bn', '\u09AC\u09BF\u09AD\u09BE\u0997 \u09A8\u09BF\u09B0\u09CD\u09AC\u09BE\u099A\u09A8 \u0995\u09B0\u09C1\u09A8');
        })
        .catch(function() {
            SweetAlertUtil.error('\u09A4\u09CD\u09B0\u09C1\u099F\u09BF', '\u09AC\u09BF\u09AD\u09BE\u0997 \u09B2\u09CB\u09A1 \u0995\u09B0\u09A4\u09C7 \u09AC\u09CD\u09AF\u09B0\u09CD\u09A5 \u09B9\u09AF\u09BC\u09C7\u099B\u09C7\u0964');
        });
    }

    function loadDistricts(divisionId, selectEnId, selectBnId) {
        fetch('/geo/districts?division_code=' + encodeURIComponent(divisionId))
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            populateSelect(selectEnId, data, 'district_code', 'district_name_en', 'Select District');
            populateSelect(selectBnId, data, 'district_code', 'district_name_bn', '\u099C\u09C7\u09B2\u09BE \u09A8\u09BF\u09B0\u09CD\u09AC\u09BE\u099A\u09A8 \u0995\u09B0\u09C1\u09A8');
        })
        .catch(function() {
            SweetAlertUtil.error('\u09A4\u09CD\u09B0\u09C1\u099F\u09BF', '\u099C\u09C7\u09B2\u09BE \u09B2\u09CB\u09A1 \u0995\u09B0\u09A4\u09C7 \u09AC\u09CD\u09AF\u09B0\u09CD\u09A5 \u09B9\u09AF\u09BC\u09C7\u099B\u09C7\u0964');
        });
    }

    function loadUpazilas(districtId, selectEnId, selectBnId) {
        fetch('/geo/upazilas?district_code=' + encodeURIComponent(districtId))
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            populateSelect(selectEnId, data, 'upazila_code', 'upazila_name_en', 'Select Upazila');
            populateSelect(selectBnId, data, 'upazila_code', 'upazila_name_bn', '\u0989\u09AA\u099C\u09C7\u09B2\u09BE \u09A8\u09BF\u09B0\u09CD\u09AC\u09BE\u099A\u09A8 \u0995\u09B0\u09C1\u09A8');
        })
        .catch(function() {
            SweetAlertUtil.error('\u09A4\u09CD\u09B0\u09C1\u099F\u09BF', '\u0989\u09AA\u099C\u09C7\u09B2\u09BE \u09B2\u09CB\u09A1 \u0995\u09B0\u09A4\u09C7 \u09AC\u09CD\u09AF\u09B0\u09CD\u09A5 \u09B9\u09AF\u09BC\u09C7\u099B\u09C7\u0964');
        });
    }

    loadDivisions('present_division_en', 'present_division_bn');
    loadDivisions('permanent_division_en', 'permanent_division_bn');

    addChangeListener('present_division_en', function() {
        var val = this.value;
        if (val) loadDistricts(val, 'present_district_en', 'present_district_bn');
    });
    addChangeListener('present_division_bn', function() {
        var val = this.value;
        if (val) loadDistricts(val, 'present_district_en', 'present_district_bn');
    });

    addChangeListener('present_district_en', function() {
        var val = this.value;
        if (val) loadUpazilas(val, 'present_upazila_en', 'present_upazila_bn');
    });
    addChangeListener('present_district_bn', function() {
        var val = this.value;
        if (val) loadUpazilas(val, 'present_upazila_en', 'present_upazila_bn');
    });

    addChangeListener('permanent_division_en', function() {
        var val = this.value;
        if (val) loadDistricts(val, 'permanent_district_en', 'permanent_district_bn');
    });
    addChangeListener('permanent_division_bn', function() {
        var val = this.value;
        if (val) loadDistricts(val, 'permanent_district_en', 'permanent_district_bn');
    });

    addChangeListener('permanent_district_en', function() {
        var val = this.value;
        if (val) loadUpazilas(val, 'permanent_upazila_en', 'permanent_upazila_bn');
    });
    addChangeListener('permanent_district_bn', function() {
        var val = this.value;
        if (val) loadUpazilas(val, 'permanent_upazila_en', 'permanent_upazila_bn');
    });

    syncSelects('present_division_en', 'present_division_bn');
    syncSelects('permanent_division_en', 'permanent_division_bn');
    syncSelects('present_district_en', 'present_district_bn');
    syncSelects('permanent_district_en', 'permanent_district_bn');
    syncSelects('present_upazila_en', 'present_upazila_bn');
    syncSelects('permanent_upazila_en', 'permanent_upazila_bn');

    var permAddr = document.getElementById('permanentAddress');
    if (permAddr) permAddr.style.display = 'none';

    addChangeListener('nagorik_status', function() {
        if (permAddr) permAddr.style.display = this.checked ? '' : 'none';
    });

    addChangeListener('AddressisSame', function() {
        if (this.checked) {
            var fields = ['division_en', 'district_en', 'upazila_en', 'post_office_en', 'word_en', 'village_en', 'road_area_en', 'holding_house_number_en',
                          'division_bn', 'district_bn', 'upazila_bn', 'post_office_bn', 'word_bn', 'village_bn', 'road_area_bn', 'holding_house_number_bn'];
            fields.forEach(function(f) {
                var pres = document.getElementById('present_' + f);
                var perm = document.getElementById('permanent_' + f);
                if (pres && perm) perm.value = pres.value;
            });

            var permDivEn = document.getElementById('permanent_division_en');
            if (permDivEn) {
                permDivEn.addEventListener('change', function() {
                    var pd = document.getElementById('permanent_district_en');
                    var pu = document.getElementById('permanent_upazila_en');
                    var sd = document.getElementById('present_district_en');
                    var su = document.getElementById('present_upazila_en');
                    if (pd && sd) pd.value = sd.value;
                    if (pu && su) pu.value = su.value;
                    if (pd) pd.dispatchEvent(new Event('change'));
                    if (pu) pu.dispatchEvent(new Event('change'));
                });
            }

            var permDivBn = document.getElementById('permanent_division_bn');
            if (permDivBn) {
                permDivBn.addEventListener('change', function() {
                    var pd = document.getElementById('permanent_district_bn');
                    var pu = document.getElementById('permanent_upazila_bn');
                    var sd = document.getElementById('present_district_bn');
                    var su = document.getElementById('present_upazila_bn');
                    if (pd && sd) pd.value = sd.value;
                    if (pu && su) pu.value = su.value;
                    if (pd) pd.dispatchEvent(new Event('change'));
                    if (pu) pu.dispatchEvent(new Event('change'));
                });
            }

            if (permDivEn) permDivEn.dispatchEvent(new Event('change'));
            if (permDivBn) permDivBn.dispatchEvent(new Event('change'));
        } else {
            var fields = ['division_en', 'district_en', 'upazila_en', 'post_office_en', 'word_en', 'village_en', 'road_area_en', 'holding_house_number_en',
                          'division_bn', 'district_bn', 'upazila_bn', 'post_office_bn', 'word_bn', 'village_bn', 'road_area_bn', 'holding_house_number_bn'];
            fields.forEach(function(f) {
                var perm = document.getElementById('permanent_' + f);
                if (perm) perm.value = '';
            });
        }
    });
});
