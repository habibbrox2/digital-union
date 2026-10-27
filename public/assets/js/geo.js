document.addEventListener('DOMContentLoaded', function () {
    function resetDropdownsitem(startDropdown) {
        var reset = false;
        document.querySelectorAll('select').forEach(function (sel) {
            if (reset) {
                sel.innerHTML = '<option value="">Select</option>';
                sel.disabled = true;
            }
            if (sel.id === startDropdown) {
                reset = true;
            }
        });
    }

    function syncDropdowns(sourceDropdown, targetDropdown) {
        var source = document.getElementById(sourceDropdown);
        var target = document.getElementById(targetDropdown);
        if (source && target) target.value = source.value;
    }

    function populateSelect(selectId, items, valueKey, labelKey) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        sel.innerHTML = '<option value="">Select</option>';
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = item[labelKey];
            sel.appendChild(opt);
        });
    }

    function loadDivisions() {
        fetch('/geo/divisions')
        .then(function (resp) { return resp.text(); })
        .then(function (data) {
            var divisions = JSON.parse(data);
            populateSelect('division_name_bn', divisions, 'division_code', 'division_name_bn');
            populateSelect('division_name_en', divisions, 'division_code', 'division_name_en');
        })
        .catch(function () {
            SweetAlertUtil.error('ত্রুটি', 'বিভাগ লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
        });
    }

    loadDivisions();

    function addChangeListener(id, callback) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', callback);
    }

    addChangeListener('division_name_bn', function () {
        syncDropdowns('division_name_bn', 'division_name_en');
    });
    addChangeListener('division_name_en', function () {
        syncDropdowns('division_name_en', 'division_name_bn');
    });

    var divisionHandler = function () {
        var divisionCode = this.value;
        resetDropdownsitem('division');
        if (divisionCode) {
            fetch('/geo/districts?division_code=' + encodeURIComponent(divisionCode))
            .then(function (resp) { return resp.text(); })
            .then(function (data) {
                var districts = JSON.parse(data);
                populateSelect('district_name_bn', districts, 'district_code', 'district_name_bn');
                populateSelect('district_name_en', districts, 'district_code', 'district_name_en');
                var bn = document.getElementById('district_name_bn');
                var en = document.getElementById('district_name_en');
                if (bn) bn.disabled = false;
                if (en) en.disabled = false;
            })
            .catch(function () {
                SweetAlertUtil.error('ত্রুটি', 'জেলা লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    };

    addChangeListener('division_name_bn', divisionHandler);
    addChangeListener('division_name_en', divisionHandler);

    addChangeListener('district_name_bn', function () {
        syncDropdowns('district_name_bn', 'district_name_en');
    });
    addChangeListener('district_name_en', function () {
        syncDropdowns('district_name_en', 'district_name_bn');
    });

    var districtHandler = function () {
        var districtCode = this.value;
        resetDropdownsitem('district');
        if (districtCode) {
            fetch('/geo/upazilas?district_code=' + encodeURIComponent(districtCode))
            .then(function (resp) { return resp.text(); })
            .then(function (data) {
                var upazilas = JSON.parse(data);
                populateSelect('upazila_name_bn', upazilas, 'upazila_code', 'upazila_name_bn');
                populateSelect('upazila_name_en', upazilas, 'upazila_code', 'upazila_name_en');
                var bn = document.getElementById('upazila_name_bn');
                var en = document.getElementById('upazila_name_en');
                if (bn) bn.disabled = false;
                if (en) en.disabled = false;
            })
            .catch(function () {
                SweetAlertUtil.error('ত্রুটি', 'উপজেলা লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    };

    addChangeListener('district_name_bn', districtHandler);
    addChangeListener('district_name_en', districtHandler);

    addChangeListener('upazila_name_bn', function () {
        syncDropdowns('upazila_name_bn', 'upazila_name_en');
    });
    addChangeListener('upazila_name_en', function () {
        syncDropdowns('upazila_name_en', 'upazila_name_bn');
    });

    var upazilaHandler = function () {
        var upazilaCode = this.value;
        resetDropdownsitem('upazila');
        var rmoBn = document.getElementById('rmo_name_bn');
        if (rmoBn) rmoBn.disabled = false;

        if (upazilaCode) {
            fetch('/geo/rmo')
            .then(function (resp) { return resp.text(); })
            .then(function (data) {
                var rmos = JSON.parse(data);
                rmos.forEach(function (rmo) {
                    var optBn = document.createElement('option');
                    optBn.value = rmo.rmo_code;
                    optBn.textContent = rmo.rmo_name_bn;
                    if (rmoBn) rmoBn.appendChild(optBn);

                    var rmoEn = document.getElementById('rmo_name_en');
                    if (rmoEn) {
                        var optEn = document.createElement('option');
                        optEn.value = rmo.rmo_code;
                        optEn.textContent = rmo.rmo_name_en;
                        rmoEn.appendChild(optEn);
                    }
                });
            })
            .catch(function () {
                SweetAlertUtil.error('ত্রুটি', 'আরএমও লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    };

    addChangeListener('upazila_name_bn', upazilaHandler);
    addChangeListener('upazila_name_en', upazilaHandler);

    addChangeListener('rmo_name_bn', function () {
        syncDropdowns('rmo_name_bn', 'rmo_name_en');
    });
    addChangeListener('rmo_name_en', function () {
        syncDropdowns('rmo_name_en', 'rmo_name_bn');
    });

    var rmoHandler = function () {
        var rmoCode = this.value;
        var upazilaBn = document.getElementById('upazila_name_bn');
        var upazilaCode = upazilaBn ? upazilaBn.value : '';

        if (rmoCode && upazilaCode) {
            fetch('/geo/unions?upazila_code=' + encodeURIComponent(upazilaCode) + '&rmo_code=' + encodeURIComponent(rmoCode))
            .then(function (resp) { return resp.text(); })
            .then(function (data) {
                var unions = JSON.parse(data);
                if (unions.length > 0) {
                    populateSelect('union_name_bn', unions, 'union_code', 'union_name_bn');
                    populateSelect('union_name_en', unions, 'union_code', 'union_name_en');
                    var bn = document.getElementById('union_name_bn');
                    var en = document.getElementById('union_name_en');
                    if (bn) bn.disabled = false;
                    if (en) en.disabled = false;
                } else {
                    var unionBn = document.getElementById('union_name_bn');
                    if (unionBn) {
                        var newInput = document.createElement('input');
                        newInput.type = 'text';
                        newInput.className = 'form-control';
                        newInput.id = 'union_name_bn';
                        newInput.name = 'union_name_bn';
                        newInput.placeholder = '\u0997\u09CD\u09B0\u09BE\u09AE\u09C7\u09B0 \u0987\u09A9\u09BF\u09AF\u09BC\u09CB\u09A8\u09C7\u09B0 \u09A8\u09BE\u09AE \u09B2\u09BF\u0996\u09C1\u09A8';
                        unionBn.parentNode.replaceChild(newInput, unionBn);
                    }
                    var unionEn = document.getElementById('union_name_en');
                    if (unionEn) {
                        var newInputEn = document.createElement('input');
                        newInputEn.type = 'text';
                        newInputEn.className = 'form-control';
                        newInputEn.id = 'union_name_en';
                        newInputEn.name = 'union_name_en';
                        newInputEn.placeholder = 'Enter Union Name';
                        unionEn.parentNode.replaceChild(newInputEn, unionEn);
                    }

                    var wardBn = document.getElementById('ward_name_bn');
                    if (wardBn) {
                        var wardSel = document.createElement('select');
                        wardSel.className = 'form-select';
                        wardSel.id = 'ward_name_bn';
                        wardSel.name = 'ward_code_bn';
                        wardSel.innerHTML = '<option value="">Select Ward</option>';
                        for (var i = 1; i <= 10; i++) {
                            var wardOpt = document.createElement('option');
                            wardOpt.value = i;
                            wardOpt.textContent = convertEnglishToBanglaDigits(i.toString());
                            wardSel.appendChild(wardOpt);
                        }
                        wardBn.parentNode.replaceChild(wardSel, wardBn);
                    }

                    var wardEn = document.getElementById('ward_name_en');
                    if (wardEn) {
                        var wardSelEn = document.createElement('select');
                        wardSelEn.className = 'form-select';
                        wardSelEn.id = 'ward_name_en';
                        wardSelEn.name = 'ward_code_en';
                        wardSelEn.innerHTML = '<option value="">Select Ward</option>';
                        for (var j = 1; j <= 10; j++) {
                            var wardOptEn = document.createElement('option');
                            wardOptEn.value = j;
                            wardOptEn.textContent = j;
                            wardSelEn.appendChild(wardOptEn);
                        }
                        wardEn.parentNode.replaceChild(wardSelEn, wardEn);
                    }

                    addChangeListener('ward_name_bn', function () {
                        syncDropdowns('ward_name_bn', 'ward_name_en');
                    });
                    addChangeListener('ward_name_en', function () {
                        syncDropdowns('ward_name_en', 'ward_name_bn');
                    });
                }
            })
            .catch(function () {
                SweetAlertUtil.error('ত্রুটি', 'ইউনিয়ন লোড করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
            });
        }
    };

    addChangeListener('rmo_name_bn', rmoHandler);
    addChangeListener('rmo_name_en', rmoHandler);

    addChangeListener('union_name_bn', function () {
        syncDropdowns('union_name_bn', 'union_name_en');
    });
    addChangeListener('union_name_en', function () {
        syncDropdowns('union_name_en', 'union_name_bn');
    });

    addChangeListener('ward_name_bn', function () {
        syncDropdowns('ward_name_bn', 'ward_name_en');
    });
    addChangeListener('ward_name_en', function () {
        syncDropdowns('ward_name_en', 'ward_name_bn');
    });
});
