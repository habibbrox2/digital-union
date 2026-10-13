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

    // ---- আবেদনকারীর নাম: সাজেশন ও সম্পর্ক অনুযায়ী পিতা/স্বামীর নাম প্রিফিল ----
    // • সাজেশন শুধু #applicant_name-এ (অন্য কোনো ফিল্ডে নয়) —
    //   সোর্স: ওয়ারিশ row-গুলোর নাম (পাশে সম্পর্ক) + মৃত ব্যক্তি/নিজের নাম
    // • বাছা নামের সম্পর্ক পুত্র / কন্যা / স্ত্রী হলে আবেদনকারীর পিতা/স্বামী = মৃত ব্যক্তি;
    //   তাই applicant_name_father_bn (ও থাকলে _en) প্রিফিল হয় — অন্য সম্পর্কে কিছুই বসে না
    // • ব্যবহারকারীর নিজে লেখা মান কখনো মোছা হয় না; নোটে সূত্র দেখা যায়, 'মুছুন' দিয়ে বাতিল
    (function () {
        var APPLICANT_ID = 'applicant_name';
        var WARISH_NAME = 'warish_name_bn[]';
        var PARENT_OF = ['পুত্র', 'কন্যা', 'স্ত্রী'];   // এদের পিতা/স্বামী = মৃত ব্যক্তি
        var TARGET_BN = 'applicant_name_father_bn';
        var TARGET_EN = 'applicant_name_father_en';

        var prefill = { bn: null, en: null, relation: '' };
        var noteEl = null;
        var filling = false;

        function trimName(v) { return (v || '').replace(/^\s+|\s+$/g, ''); }
        function byId(id) { return document.getElementById(id); }
        function valueOf(id) { var el = byId(id); return el ? trimName(el.value) : ''; }

        function certificateType() {
            var input = document.querySelector('input[name="certificate_type"]');
            if (input && input.value) return input.value;
            var span = byId('certificate_type_data');
            return span ? (span.getAttribute('data-certificate-type') || '') : '';
        }

        // ওয়ারিশ ফরমে name_bn/name_en = মৃত ব্যক্তির নাম (লেবেলও বদলে যায়)
        function isDeceasedNameForm() {
            if (certificateType() === 'warish') return true;
            var label = document.querySelector('label[for="name_bn"]');
            return !!(label && label.textContent.indexOf('মৃত') !== -1);
        }

        function relationOfRow(input) {
            var row = input.closest ? input.closest('.warish_entry') : null;
            if (!row) return '';
            var sel = row.querySelector('select[name="relation[]"]');
            return sel ? trimName(String(sel.value || '').split('|')[0]) : '';
        }

        // -------- সাজেশনের তালিকা --------
        function collectCandidates() {
            var list = [];
            var seen = {};
            var rows = document.querySelectorAll('input[name="' + WARISH_NAME + '"]');
            for (var i = 0; i < rows.length; i++) {
                var name = trimName(rows[i].value);
                if (!name || seen[name]) continue;
                var relation = relationOfRow(rows[i]);
                seen[name] = true;
                list.push({ value: name, relation: relation, hint: relation || 'ওয়ারিশ' });
            }
            var ownName = valueOf('name_bn');
            if (ownName && !seen[ownName]) {
                list.push({ value: ownName, relation: '', hint: isDeceasedNameForm() ? 'মৃত ব্যক্তি' : 'নিজ' });
            }
            return list;
        }

        // -------- পিতা/স্বামীর নাম প্রিফিল --------
        function hostOf(el) { return el.parentNode; }

        function removeNote() {
            if (noteEl && noteEl.parentNode) noteEl.parentNode.removeChild(noteEl);
            noteEl = null;
            var bn = byId(TARGET_BN), en = byId(TARGET_EN);
            if (bn) bn.classList.remove('is-autofilled');
            if (en) en.classList.remove('is-autofilled');
        }

        function setFieldValue(el, value) {
            filling = true;
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            filling = false;
        }

        function clearPrefill() {
            var bn = byId(TARGET_BN), en = byId(TARGET_EN);
            if (bn && prefill.bn !== null && trimName(bn.value) === prefill.bn) setFieldValue(bn, '');
            if (en && prefill.en !== null && trimName(en.value) === prefill.en) setFieldValue(en, '');
            prefill = { bn: null, en: null, relation: '' };
            removeNote();
        }

        function showNote(target, relation) {
            removeNote();
            if (!target) return;
            var note = document.createElement('div');
            note.className = 'autofill-note';
            note.setAttribute('role', 'status');

            var text = document.createElement('span');
            text.className = 'autofill-text';
            text.textContent = 'পিতা/স্বামী — মৃত ব্যক্তির নাম থেকে বসানো (আবেদনকারীর সম্পর্ক: ' + relation + ')';
            note.appendChild(text);

            var clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'autofill-clear';
            clear.textContent = 'মুছুন';
            clear.addEventListener('click', clearPrefill);
            note.appendChild(clear);

            hostOf(target).appendChild(note);
            noteEl = note;
            target.classList.add('is-autofilled');
        }

        function applyPrefill(entry) {
            var bn = byId(TARGET_BN), en = byId(TARGET_EN);
            // ফিল্ডগুলো ডাইনামিকি (extra-fields API) পরে বসতে পারে → এখানেও ওয়্যার করি
            if (bn) wireTarget(bn);
            if (en) wireTarget(en);
            if (!bn && !en) return;                       // এই ফরমে ফিল্ড নেই
            if (!entry || PARENT_OF.indexOf(entry.relation) === -1) {
                if (prefill.bn !== null || prefill.en !== null) clearPrefill();
                return;
            }
            var deceasedBn = valueOf('name_bn');
            if (!deceasedBn) return;                      // মৃত ব্যক্তির নাম নেই → কিছুই বসি না
            var deceasedEn = valueOf('name_en');

            var takeBn = bn && (trimName(bn.value) === '' || (prefill.bn !== null && trimName(bn.value) === prefill.bn));
            var takeEn = en && deceasedEn !== '' && (trimName(en.value) === '' || (prefill.en !== null && trimName(en.value) === prefill.en));

            prefill = { bn: takeBn ? deceasedBn : null, en: takeEn ? deceasedEn : null, relation: entry.relation };
            if (takeBn) setFieldValue(bn, deceasedBn);
            if (takeEn) setFieldValue(en, deceasedEn);
            if (takeBn || takeEn) showNote(takeBn ? bn : en, entry.relation);
        }

        // মৃত ব্যক্তির নাম বদলালে আমাদের বসানো মানও আপডেট
        function syncFromDeceased() {
            if (prefill.bn === null && prefill.en === null) return;
            var bn = byId(TARGET_BN), en = byId(TARGET_EN);
            var deceasedBn = valueOf('name_bn'), deceasedEn = valueOf('name_en');
            if (prefill.bn !== null && bn && deceasedBn) { prefill.bn = deceasedBn; setFieldValue(bn, deceasedBn); }
            if (prefill.en !== null && en && deceasedEn) { prefill.en = deceasedEn; setFieldValue(en, deceasedEn); }
        }

        function wireTarget(el) {
            if (!el || el.__prefillWired) return;
            el.__prefillWired = true;
            el.addEventListener('input', function () {
                if (filling) return;
                if (prefill.bn === null && prefill.en === null) return;
                prefill = { bn: null, en: null, relation: '' };
                removeNote();
            });
        }

        // -------- #applicant_name-এর ড্রপডাউন --------
        function attach(input) {
            if (input.__nameSuggest) return input.__nameSuggest;

            var wrapper = input.parentNode;
            if (!wrapper || !wrapper.classList.contains('name-suggest-wrapper')) {
                wrapper = document.createElement('div');
                wrapper.className = 'name-suggest-wrapper';
                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
            }

            var panel = document.createElement('ul');
            panel.className = 'name-suggest-dropdown';
            panel.setAttribute('role', 'listbox');
            panel.setAttribute('aria-label', 'নাম সাজেশন');
            wrapper.appendChild(panel);

            var entries = [];
            var activeIndex = -1;
            var muted = false;

            input.setAttribute('autocomplete', 'off');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');

            function closePanel() {
                panel.classList.remove('show');
                panel.innerHTML = '';
                input.setAttribute('aria-expanded', 'false');
                activeIndex = -1;
            }

            function filtered(query) {
                var q = trimName(query).toLowerCase();
                if (!q) return entries;
                var out = [];
                for (var i = 0; i < entries.length; i++) {
                    var v = entries[i].value.toLowerCase();
                    var h = (entries[i].hint || '').toLowerCase();
                    if (v.indexOf(q) !== -1 || h.indexOf(q) !== -1) out.push(entries[i]);
                }
                return out;
            }

            function render(list) {
                panel.innerHTML = '';
                if (!list.length) {
                    closePanel();
                    return;
                }
                var current = trimName(input.value);
                for (var i = 0; i < list.length; i++) {
                    var li = document.createElement('li');
                    li.className = 'name-suggest-item';
                    li.setAttribute('role', 'option');
                    li.setAttribute('data-value', list[i].value);
                    li.setAttribute('aria-selected', list[i].value === current ? 'true' : 'false');

                    var value = document.createElement('span');
                    value.className = 'name-suggest-value';
                    value.textContent = list[i].value;
                    li.appendChild(value);

                    var hint = document.createElement('span');
                    hint.className = 'name-suggest-hint';
                    hint.textContent = list[i].hint || '';
                    li.appendChild(hint);

                    if (list[i].value === current) li.classList.add('is-current');
                    panel.appendChild(li);
                }
                panel.classList.add('show');
                input.setAttribute('aria-expanded', 'true');
                activeIndex = -1;
            }

            function closeOtherPanels() {
                var others = document.querySelectorAll('.name-suggest-dropdown.show');
                for (var i = 0; i < others.length; i++) {
                    if (others[i] === panel) continue;
                    others[i].classList.remove('show');
                    others[i].innerHTML = '';
                    var owner = others[i].parentNode ? others[i].parentNode.querySelector('input') : null;
                    if (owner) owner.setAttribute('aria-expanded', 'false');
                }
            }

            function openPanel() {
                closeOtherPanels();
                entries = collectCandidates();
                render(entries);
            }

            function highlight() {
                var options = panel.querySelectorAll('.name-suggest-item');
                for (var i = 0; i < options.length; i++) {
                    if (i === activeIndex) options[i].classList.add('is-active');
                    else options[i].classList.remove('is-active');
                }
                if (options[activeIndex] && options[activeIndex].scrollIntoView) {
                    options[activeIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            function entryFor(name) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].value === name) return entries[i];
                }
                return null;
            }

            function choose(name) {
                // নিজের নাম নয় — অ্যাপ্লিকেশন ফরমে radio থাকলে 'অন্যান্য' সিলেক্ট করি;
                // ওই radio-র change হ্যান্ডলার ইনপুট খালি করে, তাই মান পরে বসাই
                var otherRadio = byId('other_name');
                if (otherRadio && !otherRadio.checked && valueOf('name_bn') !== name) {
                    otherRadio.checked = true;
                    otherRadio.dispatchEvent(new Event('change', { bubbles: true }));
                }
                var entry = entryFor(name);
                muted = true;
                input.value = name;
                input.readOnly = false;
                input.style.backgroundColor = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                muted = false;
                applyPrefill(entry);
                closePanel();
            }

            input.addEventListener('click', function () {
                if (!panel.classList.contains('show')) openPanel();
            });
            input.addEventListener('input', function () {
                if (muted) return;
                // প্যানেল খোলা না থাকলে (যেমন অন্য কোড ভ্যালু বসালে) টাইপেও খুলব না
                if (!panel.classList.contains('show')) return;
                entries = collectCandidates();
                render(filtered(input.value));
            });
            input.addEventListener('keydown', function (e) {
                if (!panel.classList.contains('show')) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        openPanel();
                    }
                    return;
                }
                var options = panel.querySelectorAll('.name-suggest-item');
                if (!options.length) return;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (e.key === 'ArrowDown') activeIndex = (activeIndex + 1) % options.length;
                    else activeIndex = (activeIndex <= 0) ? options.length - 1 : activeIndex - 1;
                    highlight();
                } else if (e.key === 'Enter') {
                    if (activeIndex >= 0) {
                        e.preventDefault();
                        choose(options[activeIndex].getAttribute('data-value'));
                    } else {
                        closePanel();
                    }
                } else if (e.key === 'Escape' || e.key === 'Tab') {
                    closePanel();
                }
            });
            panel.addEventListener('mousedown', function (e) {
                var item = e.target.closest ? e.target.closest('.name-suggest-item') : null;
                if (!item) return;
                e.preventDefault();
                choose(item.getAttribute('data-value'));
            });
            document.addEventListener('mousedown', function (e) {
                if (!wrapper.contains(e.target)) closePanel();
            });
            input.addEventListener('blur', function () {
                setTimeout(function () {
                    if (!wrapper.contains(document.activeElement)) closePanel();
                }, 100);
            });

            input.__nameSuggest = { open: openPanel, close: closePanel };
            return input.__nameSuggest;
        }

        // শুরু — সাজেশন শুধু #applicant_name-এ, প্রিফিল টার্গেটও ওয়্যার করি
        function start() {
            wireTarget(byId(TARGET_BN));
            wireTarget(byId(TARGET_EN));
            ['name_bn', 'name_en'].forEach(function (id) {
                var el = byId(id);
                if (el) el.addEventListener('input', syncFromDeceased);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }

        // ডাইনামিকি যোগ হওয়া ফিল্ড/সেকশন কাভার করতে focusin-এ delegation
        document.addEventListener('focusin', function (e) {
            var el = e.target;
            if (!el || el.tagName !== 'INPUT' || el.type !== 'text' || el.id !== APPLICANT_ID) return;
            attach(el).open();
        });
    })();

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
