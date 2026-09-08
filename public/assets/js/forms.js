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

document.addEventListener('DOMContentLoaded', function () {
    var appForm = document.getElementById('applicationForm');
    if (appForm) {
        initColumnTabNavigation(appForm);
    }

    // Applicant name selection
    document.querySelectorAll('input[name="applicant_name_option"]').forEach(function(el) {
        el.addEventListener('change', function () {
            var selectedOption = this.value;
            var applicantInput = document.getElementById('applicant_name');
            if (!applicantInput) return;
            if (selectedOption === 'own') {
                var ownerName = document.getElementById('name_bn');
                applicantInput.value = ownerName ? ownerName.value : '';
                applicantInput.readOnly = true;
                applicantInput.style.display = '';
            } else if (selectedOption === 'other') {
                applicantInput.value = '';
                applicantInput.readOnly = false;
                applicantInput.style.display = '';
                applicantInput.focus();
            }
        });
    });

    var nameBnEl = document.getElementById('name_bn');
    if (nameBnEl) {
        nameBnEl.addEventListener('input', function () {
            var ownName = document.getElementById('own_name');
            var applicantInput = document.getElementById('applicant_name');
            if (ownName && ownName.checked && applicantInput) {
                applicantInput.value = this.value;
            }
        });
    }

    var photoEl = document.getElementById('photo');
    if (photoEl) {
        photoEl.addEventListener('change', function (event) {
            var file = event.target.files[0];
            var preview = document.getElementById('photo_preview');
            if (file && file.type.startsWith('image/')) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                preview.src = '';
                preview.style.display = 'none';
            }
        });
    }

    var addDocBtn = document.getElementById('add_document');
    if (addDocBtn) {
        addDocBtn.addEventListener('click', function () {
            var newRow = '<div class="row align-items-center document-row mb-2">' +
                '<div class="col-md-5"><input type="file" name="documents[]" class="form-control file-input"></div>' +
                '<div class="col-md-5 preview-area"></div>' +
                '<div class="col-md-2"><button type="button" class="btn btn-danger remove-file">X</button></div>' +
                '</div>';
            document.getElementById('documents_container').insertAdjacentHTML('beforeend', newRow);
        });
    }

    document.addEventListener('change', function (event) {
        if (!event.target.classList.contains('file-input')) return;
        var row = event.target.closest('.document-row');
        var preview = row ? row.querySelector('.preview-area') : null;
        if (!preview) return;
        preview.innerHTML = '';
        var file = event.target.files[0];
        if (!file) return;
        var fileType = file.type.toLowerCase();
        if (fileType.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function (e) {
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview" class="img-thumbnail" style="max-width: 120px; border: 1px solid #ccc; padding: 5px;">';
            };
            reader.readAsDataURL(file);
        } else if (fileType === 'application/pdf') {
            var fileURL = URL.createObjectURL(file);
            preview.innerHTML = '<iframe src="' + fileURL + '#toolbar=0&navpanes=0&scrollbar=0" style="width: 120px; height: 150px; border: 1px solid #ccc;" class="shadow-sm rounded"></iframe>';
        } else {
            preview.innerHTML = '<span>' + file.name + '</span>';
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-file')) {
            var row = e.target.closest('.document-row');
            if (row) row.remove();
        }
    });

    // Location dropdowns
    var districtDropdown = document.getElementById('district_dropdown');
    var upazilaDropdown = document.getElementById('upazila_dropdown');

    function fetchLocalGeoData(geoOrder, parentGeoId, dropdownId, callback) {
        var dropdown = document.getElementById(dropdownId.replace('#', ''));
        if (!dropdown) return;
        dropdown.innerHTML = '<option value="">লোড হচ্ছে...</option>';
        var formData = new FormData();
        formData.append('geo_order', geoOrder);
        formData.append('parent_geo_id', parentGeoId);
        fetch('/settings/geo/getdata', { method: 'POST', body: formData })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            dropdown.innerHTML = '';
            var placeholder = 'চিহ্নিত করুন';
            if (dropdownId === '#district_dropdown') placeholder = 'জেলা নির্বাচন করুন';
            else if (dropdownId === '#upazila_dropdown') placeholder = 'উপজেলা নির্বাচন করুন';
            else if (dropdownId === '#union_dropdown') placeholder = 'ই঩িয়োন নির্বাচন করুন';
            var defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = placeholder;
            dropdown.appendChild(defaultOpt);
            data.forEach(function(item) {
                var opt = document.createElement('option');
                opt.value = String(item.geo_code);
                opt.textContent = item.name_bn;
                opt.setAttribute('data-geo-code', item.geo_code);
                opt.setAttribute('data-name-en', item.name_en);
                opt.setAttribute('data-name-bn', item.name_bn);
                opt.setAttribute('data-geo-id', item.id);
                dropdown.appendChild(opt);
            });
            if (typeof callback === 'function') setTimeout(callback, 100);
        })
        .catch(function(err) {
            dropdown.innerHTML = '<option value="">ডাটা লোডে সমস্যা হয়েছে</option>';
            console.error('Geo Data Load Error:', err);
        });
    }

    function resetLocalDropdown(selector, placeholder) {
        placeholder = placeholder || 'চিহ্নিত করুন';
        var el = document.querySelector(selector);
        if (el) el.innerHTML = '<option value="">' + placeholder + '</option>';
    }

    function loadLocalUnions(districtNameEn, upazilaNameEn, dropdownId) {
        var dropdown = document.getElementById(dropdownId.replace('#', ''));
        if (!dropdown) return;
        dropdown.innerHTML = '<option value="">লোড হচ্ছে...</option>';
        var formData = new FormData();
        formData.append('district_name_en', districtNameEn);
        formData.append('upazila_name_en', upazilaNameEn);
        fetch('/geo/getUnion', { method: 'POST', body: formData })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            dropdown.innerHTML = '';
            if (data.length === 0) {
                dropdown.innerHTML = '<option value="">কোনো ই঩িয়োন পাওয়া যায়নি</option>';
                return;
            }
            var defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = 'ই঩িয়োন নির্বাচন করুন';
            dropdown.appendChild(defaultOpt);
            data.forEach(function(item) {
                var opt = document.createElement('option');
                opt.value = item.union_code;
                opt.textContent = item.union_name_bn;
                opt.setAttribute('data-name-en', item.union_name_en);
                opt.setAttribute('data-name-bn', item.union_name_bn);
                opt.setAttribute('data-union-id', item.union_id);
                dropdown.appendChild(opt);
            });
        })
        .catch(function(err) {
            dropdown.innerHTML = '<option value="">ডাটডে সমস্যা হয়েছে</option>';
            console.error('Union Load Error:', err);
        });
    }

    fetchLocalGeoData(1, 0, '#district_dropdown', function () {
        setTimeout(function () {
            if (districtDropdown) {
                var opt = districtDropdown.querySelector('option[value="3026"]');
                if (opt) {
                    districtDropdown.value = '3026';
                    districtDropdown.dispatchEvent(new Event('change'));
                }
            }
        }, 200);
    });

    if (districtDropdown) {
        districtDropdown.addEventListener('change', function () {
            var selectedOpt = this.options[this.selectedIndex];
            var parentGeoId = selectedOpt ? selectedOpt.getAttribute('data-geo-id') : 0;
            resetLocalDropdown('#upazila_dropdown', 'উপজেলা নির্বাচন করুন');
            resetLocalDropdown('#union_dropdown', 'ই঩িয়োন নির্বাচন করুন');
            if (parentGeoId) fetchLocalGeoData(2, parentGeoId, '#upazila_dropdown');
        });
    }

    if (upazilaDropdown) {
        upazilaDropdown.addEventListener('change', function () {
            var selectedOpt = this.options[this.selectedIndex];
            var upazilaNameEn = selectedOpt ? selectedOpt.getAttribute('data-name-en') : '';
            var distOpt = districtDropdown ? districtDropdown.options[districtDropdown.selectedIndex] : null;
            var districtNameEn = distOpt ? distOpt.getAttribute('data-name-en') : '';
            resetLocalDropdown('#union_dropdown', 'ই঩িয়োন নির্বাচন করুন');
            if (districtNameEn && upazilaNameEn) loadLocalUnions(districtNameEn, upazilaNameEn, '#union_dropdown');
        });
    }

    function toggleSpouseName() {
        var maritalStatus = document.getElementById('marital_status');
        var spouseName = document.getElementById('spouse_name');
        if (maritalStatus && spouseName) {
            spouseName.style.display = maritalStatus.value === 'married' ? '' : 'none';
        }
    }
    toggleSpouseName();
    var maritalStatusEl = document.getElementById('marital_status');
    if (maritalStatusEl) maritalStatusEl.addEventListener('change', toggleSpouseName);
});
