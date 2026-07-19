/* ================================================================
   Smart Union Parishad - Search Form JavaScript
   Bengali Search Form | Vanilla JS (ES2025) | Modular
   ================================================================ */

(function () {
  'use strict';

  // ================================================================
  // STATE
  // ================================================================
  const state = {
    districts: [],
    upazilas: [],
    unions: [],
    selectedDistrict: '',
    selectedUpazila: '',
    selectedUnion: '',
    isSearching: false,
    isUploading: false,
  };

  // ================================================================
  // DOM CACHE
  // ================================================================
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  const dom = {
    district: $('#district'),
    upazila: $('#upazila'),
    union: $('#union'),
    searchInput: $('#searchInput'),
    searchBtn: $('#searchBtn'),
    searchForm: $('#searchForm'),
    resultsContainer: $('#resultsContainer'),
    uploadCard: $('#uploadCard'),
    uploadInput: $('#uploadInput'),
    uploadBtn: $('#uploadBtn'),
    uploadPlaceholder: $('#uploadPlaceholder'),
    uploadPreview: $('#uploadPreview'),
    uploadOverlay: $('#uploadOverlay'),
    uploadBadge: $('#uploadBadge'),
    uploadSizeInfo: $('#uploadSizeInfo'),
    validationMessage: $('#validationMessage'),
    districtMsg: $('#districtMsg'),
    upazilaMsg: $('#upazilaMsg'),
    unionMsg: $('#unionMsg'),
  };

  // ================================================================
  // API ENDPOINTS (relative paths - configure for production)
  // ================================================================
  const API = {
    districts: '/api/v2/geo/districts',
    upazilas: '/api/v2/geo/upazilas/',
    unions: '/api/v2/geo/unions/',
    search: '/api/v2/applications/search',
  };

  // ================================================================
  // UTILITY FUNCTIONS
  // ================================================================

  /** Fetch with timeout and error handling */
  async function fetchJSON(url, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const res = await fetch(url, {
        ...options,
        signal: controller.signal,
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...options.headers,
        },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      return await res.json();
    } catch (err) {
      if (err.name === 'AbortError') throw new Error('অনুরোধটি সময় শেষ হয়েছে। আবার চেষ্টা করুন।');
      throw err;
    } finally {
      clearTimeout(timeout);
    }
  }

  /** Create an option element */
  function createOption(value, text, selected = false) {
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = text;
    if (selected) opt.selected = true;
    return opt;
  }

  /** Show validation message on a select/input */
  function showError(el, msgEl, message) {
    el.classList.add('error');
    if (msgEl) {
      msgEl.textContent = message;
      msgEl.classList.add('visible');
    }
  }

  /** Clear validation error */
  function clearError(el, msgEl) {
    el.classList.remove('error');
    if (msgEl) {
      msgEl.textContent = '';
      msgEl.classList.remove('visible');
    }
  }

  /** Debounce utility */
  function debounce(fn, delay = 300) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  /** Format file size */
  function formatFileSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
  }

  // ================================================================
  // GEO CASCADING DROPDOWNS
  // ================================================================

  /** Load districts on page load */
  async function loadDistricts() {
    try {
      dom.district.disabled = true;
      dom.district.innerHTML = '';
      dom.district.appendChild(createOption('', 'জেলা নির্বাচন করুন'));

      const data = await fetchJSON(API.districts);
      state.districts = Array.isArray(data) ? data : (data.data || []);

      state.districts.forEach(d => {
        const name = d.name_bn || d.name || d.district_name_bn || '';
        const value = d.id || d.district_id || d.code || name;
        dom.district.appendChild(createOption(value, name));
      });
    } catch (err) {
      console.error('Failed to load districts:', err);
      dom.district.appendChild(createOption('', 'ডাটা লোড করতে ব্যর্থ'));
      showError(dom.district, dom.districtMsg, 'জেলা তালিকা লোড করা যায়নি');
    } finally {
      dom.district.disabled = false;
    }
  }

  /** Load upazilas for a district */
  async function loadUpazilas(districtId) {
    try {
      dom.upazila.disabled = true;
      dom.upazila.innerHTML = '';
      dom.upazila.appendChild(createOption('', 'উপজেলা নির্বাচন করুন'));
      dom.union.innerHTML = '';
      dom.union.appendChild(createOption('', 'ইউনিয়ন নির্বাচন করুন'));
      dom.union.disabled = true;

      if (!districtId) return;

      const data = await fetchJSON(`${API.upazilas}${districtId}`);
      state.upazilas = Array.isArray(data) ? data : (data.data || []);

      state.upazilas.forEach(u => {
        const name = u.name_bn || u.name || u.upazila_name_bn || '';
        const value = u.id || u.upazila_id || u.code || name;
        dom.upazila.appendChild(createOption(value, name));
      });

      dom.upazila.disabled = false;
    } catch (err) {
      console.error('Failed to load upazilas:', err);
      showError(dom.upazila, dom.upazilaMsg, 'উপজেলা তালিকা লোড করা যায়নি');
    }
  }

  /** Load unions for an upazila */
  async function loadUnions(upazilaId) {
    try {
      dom.union.disabled = true;
      dom.union.innerHTML = '';
      dom.union.appendChild(createOption('', 'ইউনিয়ন নির্বাচন করুন'));

      if (!upazilaId) return;

      const data = await fetchJSON(`${API.unions}${upazilaId}`);
      state.unions = Array.isArray(data) ? data : (data.data || []);

      state.unions.forEach(u => {
        const name = u.name_bn || u.name || u.union_name_bn || '';
        const value = u.id || u.union_id || u.code || u.union_code || name;
        dom.union.appendChild(createOption(value, name));
      });

      dom.union.disabled = false;
    } catch (err) {
      console.error('Failed to load unions:', err);
      dom.union.disabled = true;
    }
  }

  // ================================================================
  // UPLOAD CARD - DRAG & DROP, PREVIEW, VALIDATION
  // ================================================================

  const UPLOAD_CONFIG = {
    maxSize: 2 * 1024 * 1024, // 2MB
    preferredWidth: 300,
    preferredHeight: 300,
    accept: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
  };

  /** Handle file selection */
  function handleFile(file) {
    if (!file) return;

    // Validate type
    if (!UPLOAD_CONFIG.accept.includes(file.type)) {
      showUploadError('শুধুমাত্র ছবি (JPEG, PNG, WebP) আপলোড করুন');
      return;
    }

    // Validate size
    if (file.size > UPLOAD_CONFIG.maxSize) {
      showUploadError(`ছবির আকার ২MB এর কম হতে হবে। (বর্তমান: ${formatFileSize(file.size)})`);
      return;
    }

    // Show preview and validate dimensions
    const reader = new FileReader();
    reader.onload = function (e) {
      const img = new Image();
      img.onload = function () {
        // Dimension check
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        const isPreferred = w === UPLOAD_CONFIG.preferredWidth && h === UPLOAD_CONFIG.preferredHeight;

        dom.uploadPreview.src = e.target.result;
        dom.uploadPreview.classList.add('visible');
        dom.uploadPlaceholder.style.display = 'none';
        dom.uploadOverlay.querySelector('.overlay-text').textContent = 'পরিবর্তন করুন';

        // Show size info
        dom.uploadSizeInfo.textContent = `${w} × ${h} px`;
        dom.uploadSizeInfo.classList.add('visible');

        // Show badge
        if (isPreferred) {
          dom.uploadBadge.textContent = '✓ ৩০০×৩০০';
          dom.uploadBadge.className = 'upload-badge success';
        } else {
          const msg = `আদর্শ: ৩০০×৩০০ px (বর্তমান: ${w}×${h} px)`;
          dom.uploadBadge.textContent = '⚠ আকার ajust';
          dom.uploadBadge.className = 'upload-badge warning';
          console.warn(msg);
        }

        state.isUploading = true;
      };
      img.onerror = function () {
        showUploadError('ছবিটি লোড করা যায়নি। ভিন্ন ছবি নির্বাচন করুন।');
      };
      img.src = e.target.result;
    };
    reader.onerror = function () {
      showUploadError('ফাইল পড়তে ব্যর্থ। আবার চেষ্টা করুন।');
    };
    reader.readAsDataURL(file);
  }

  /** Show upload error */
  function showUploadError(message) {
    dom.uploadBadge.textContent = '✗ ত্রুটি';
    dom.uploadBadge.className = 'upload-badge error';

    // Reset preview
    dom.uploadPreview.classList.remove('visible');
    dom.uploadPlaceholder.style.display = 'flex';
    dom.uploadSizeInfo.classList.remove('visible');

    // Reset input
    dom.uploadInput.value = '';

    // Show alert
    alert(message);

    state.isUploading = false;
  }

  /** Reset upload to initial state */
  function resetUpload() {
    dom.uploadPreview.classList.remove('visible');
    dom.uploadPreview.src = '';
    dom.uploadPlaceholder.style.display = 'flex';
    dom.uploadOverlay.querySelector('.overlay-text').textContent = 'ক্লিক করুন';
    dom.uploadBadge.className = 'upload-badge';
    dom.uploadSizeInfo.classList.remove('visible');
    dom.uploadInput.value = '';
    state.isUploading = false;
  }

  /** Initialize upload card events */
  function initUpload() {
    // Click card to trigger file input
    dom.uploadCard.addEventListener('click', function (e) {
      // Don't trigger if clicking button (button has its own handler)
      if (e.target.closest('.upload-btn')) return;
      dom.uploadInput.click();
    });

    // Upload button click
    dom.uploadBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      dom.uploadInput.click();
    });

    // File input change
    dom.uploadInput.addEventListener('change', function () {
      if (this.files && this.files.length > 0) {
        handleFile(this.files[0]);
      }
    });

    // Drag & Drop
    let dragCounter = 0;

    dom.uploadCard.addEventListener('dragenter', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dragCounter++;
      this.classList.add('drag-over');
    });

    dom.uploadCard.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.stopPropagation();
    });

    dom.uploadCard.addEventListener('dragleave', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dragCounter--;
      if (dragCounter === 0) {
        this.classList.remove('drag-over');
      }
    });

    dom.uploadCard.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dragCounter = 0;
      this.classList.remove('drag-over');

      const files = e.dataTransfer.files;
      if (files && files.length > 0) {
        dom.uploadInput.files = files;
        handleFile(files[0]);
      }
    });

    // Keyboard support
    dom.uploadCard.setAttribute('tabindex', '0');
    dom.uploadCard.setAttribute('role', 'button');
    dom.uploadCard.setAttribute('aria-label', 'আবেদনকারীর ছবি আপলোড করুন');

    dom.uploadCard.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        dom.uploadInput.click();
      }
      // Delete/Backspace to remove
      if ((e.key === 'Delete' || e.key === 'Backspace') && state.isUploading) {
        e.preventDefault();
        resetUpload();
      }
    });
  }

  // ================================================================
  // SEARCH FUNCTIONALITY
  // ================================================================

  /** Validate form before search */
  function validateForm() {
    let isValid = true;

    // District
    if (!dom.district.value) {
      showError(dom.district, dom.districtMsg, 'অনুগ্রহ করে জেলা নির্বাচন করুন');
      isValid = false;
    } else {
      clearError(dom.district, dom.districtMsg);
    }

    // Upazila
    if (!dom.upazila.value) {
      showError(dom.upazila, dom.upazilaMsg, 'অনুগ্রহ করে উপজেলা নির্বাচন করুন');
      isValid = false;
    } else {
      clearError(dom.upazila, dom.upazilaMsg);
    }

    // Union
    if (!dom.union.value) {
      showError(dom.union, dom.unionMsg, 'অনুগ্রহ করে ইউনিয়ন নির্বাচন করুন');
      isValid = false;
    } else {
      clearError(dom.union, dom.unionMsg);
    }

    // Search query
    if (!dom.searchInput.value.trim()) {
      showError(dom.searchInput, dom.validationMessage, 'অনুগ্রহ করে সার্চ আইডি দিন');
      isValid = false;
    } else {
      clearError(dom.searchInput, dom.validationMessage);
    }

    if (!isValid) {
      dom.searchForm.classList.add('shake');
      setTimeout(() => dom.searchForm.classList.remove('shake'), 300);
    }

    return isValid;
  }

  /** Perform search */
  async function performSearch() {
    if (!validateForm()) return;
    if (state.isSearching) return;

    state.isSearching = true;
    dom.searchBtn.classList.add('loading');
    dom.resultsContainer.innerHTML = '';

    const params = new URLSearchParams({
      district: dom.district.value,
      upazila: dom.upazila.value,
      union: dom.union.value,
      query: dom.searchInput.value.trim(),
    });

    try {
      const data = await fetchJSON(`${API.search}?${params.toString()}`);
      renderResults(data);
    } catch (err) {
      console.error('Search failed:', err);
      dom.resultsContainer.innerHTML = `
        <div class="results-empty fade-in">
          <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p>সার্চ ব্যর্থ হয়েছে। আবার চেষ্টা করুন।</p>
          <p style="font-size:14px;color:#9ca3af;">${err.message}</p>
        </div>
      `.trim();
    } finally {
      state.isSearching = false;
      dom.searchBtn.classList.remove('loading');
    }
  }

  /** Render search results */
  function renderResults(data) {
    const items = Array.isArray(data) ? data : (data.data || []);
    const container = dom.resultsContainer;

    if (items.length === 0) {
      container.innerHTML = `
        <div class="results-empty fade-in">
          <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <p>কোনো তথ্য পাওয়া যায়নি।</p>
          <p style="font-size:14px;color:#9ca3af;">অনুগ্রহ করে সঠিক তথ্য দিয়ে পুনরায় সার্চ করুন।</p>
        </div>
      `.trim();
      return;
    }

    let html = `
      <h2 class="results-title">সার্চ ফলাফল (${items.length})</h2>
      <div class="table-responsive">
        <table class="results-table">
          <thead>
            <tr>
              <th>ক্রমিক</th>
              <th>নাম</th>
              <th>পিতা/স্বামীর নাম</th>
              <th>সনদ নম্বর</th>
              <th>ধরন</th>
              <th>স্ট্যাটাস</th>
            </tr>
          </thead>
          <tbody>
    `.trim();

    items.forEach((item, index) => {
      const name = item.name_bn || item.applicant_name || '—';
      const father = item.father_name_bn || item.father_name || '—';
      const sonodNo = item.sonod_number || item.certificate_no || '—';
      const type = item.certificate_type_bn || item.certificate_type || '—';
      const status = item.status || 'pending';

      let statusClass = 'pending';
      let statusText = 'বিচারাধীন';
      if (status === 'approved' || status === 'delivered') {
        statusClass = 'approved';
        statusText = 'অনুমোদিত';
      } else if (status === 'rejected') {
        statusClass = 'rejected';
        statusText = 'বাতিল';
      }

      html += `
        <tr class="fade-in" style="animation-delay: ${index * 0.05}s">
          <td>${index + 1}</td>
          <td>${name}</td>
          <td>${father}</td>
          <td>${sonodNo}</td>
          <td>${type}</td>
          <td><span class="status-badge ${statusClass}">${statusText}</span></td>
        </tr>
      `.trim();
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
  }

  // ================================================================
  // EVENT BINDINGS
  // ================================================================

  function bindEvents() {
    // District change → load upazilas
    dom.district.addEventListener('change', function () {
      const val = this.value;
      state.selectedDistrict = val;
      clearError(this, dom.districtMsg);
      loadUpazilas(val);
    });

    // Upazila change → load unions
    dom.upazila.addEventListener('change', function () {
      const val = this.value;
      state.selectedUpazila = val;
      clearError(this, dom.upazilaMsg);
      loadUnions(val);
    });

    // Union change
    dom.union.addEventListener('change', function () {
      state.selectedUnion = this.value;
      clearError(this, dom.unionMsg);
    });

    // Search input clear errors on input
    dom.searchInput.addEventListener('input', function () {
      clearError(this, dom.validationMessage);
    });

    // Form submit
    dom.searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      performSearch();
    });

    // Search button click
    dom.searchBtn.addEventListener('click', function (e) {
      e.preventDefault();
      performSearch();
    });

    // Enter key on search input
    dom.searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        performSearch();
      }
    });

    // Reset upload with Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && state.isUploading) {
        resetUpload();
      }
    });
  }

  // ================================================================
  // INITIALIZATION
  // ================================================================

  async function init() {
    // Wait for DOM
    if (document.readyState === 'loading') {
      await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve));
    }

    // Load districts
    await loadDistricts();

    // Initialize upload card
    initUpload();

    // Bind all events
    bindEvents();

    console.log('🔍 Search Form initialized successfully');
  }

  // Bootstrap
  init().catch(err => {
    console.error('Search Form initialization failed:', err);
  });

})();
