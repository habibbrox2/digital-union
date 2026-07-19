/**
 * ============================================================
 *  ImageCropper — Reusable component-based image crop module
 *  Version: 2.0.0
 *  Technology: Vanilla JS + Cropper.js (latest stable) + Bootstrap 5
 * ============================================================
 *
 *  🎯 Philosophy: Component-based, zero duplicate code.
 *     Just add data-crop="true" → everything works automatically.
 *     To add a new upload field, only ONE data attribute needed.
 *
 *  ─── Data Attributes ─────────────────────────────────────────
 *
 *  Required:
 *    data-crop="true"       — Enables crop on this file input
 *
 *  Optional (auto-detected if omitted by traversing nearby DOM):
 *    data-crop-mode         — 'photo' (default) | 'signature' | 'document'
 *    data-crop-trigger      — CSS selector(s) for elements that open file picker
 *                             (e.g. "#uploadCardBtn, #uploadDropZone")
 *    data-crop-dropzone     — CSS selector for drag-and-drop zone element
 *    data-preview           — ID of <img> for preview
 *    data-preview-container — ID of preview wrapper div
 *    data-drop-zone         — ID of the drop zone (background area)
 *
 *  ─── Example ─────────────────────────────────────────────────
 *
 *    <!-- Only ONE attribute needed to enable crop: -->
 *    <input type="file" id="applicant_photo" data-crop="true">
 *
 *    <!-- Full custom config: -->
 *    <input type="file" id="signature_upload" data-crop="true"
 *           data-crop-mode="signature"
 *           data-crop-trigger="#sigBtn, #sigDropZone"
 *           data-crop-dropzone="#sigDropZone"
 *           data-preview="sigPreviewImg"
 *           data-preview-container="sigPreviewContainer"
 *           data-drop-zone="sigDropZone">
 *
 *  ─── Modes ────────────────────────────────────────────────────
 *
 *    photo:      1:1 ratio → 300×300 JPEG (white bg, 0.9 quality)
 *    signature:  free ratio → max 600px wide PNG (transparent bg)
 *    document:   free ratio → preserve original resolution JPEG
 *
 * ============================================================
 */

const ImageCropper = (function () {

  'use strict';

  // ────────────────────────────────────────────────────────────
  //  Configuration
  // ────────────────────────────────────────────────────────────

  const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB

  const MODE_CONFIG = Object.freeze({
    photo: {
      aspectRatio: 1,
      outputWidth: 300,
      outputHeight: 300,
      quality: 0.9,
      format: 'jpeg',
      backgroundColor: '#ffffff',
      label: 'ছবি',
    },
    signature: {
      aspectRatio: NaN,
      outputWidth: 600,
      outputHeight: undefined,
      quality: 0.92,
      format: 'png',
      backgroundColor: 'transparent',
      label: 'সিগনেচার',
    },
    document: {
      aspectRatio: NaN,
      outputWidth: undefined,
      outputHeight: undefined,
      quality: 0.95,
      format: 'jpeg',
      backgroundColor: '#ffffff',
      label: 'ডকুমেন্ট',
    },
  });

  // ────────────────────────────────────────────────────────────
  //  Component State
  // ────────────────────────────────────────────────────────────

  let cropperInstance = null;
  let currentFile = null;
  let currentConfig = {};
  let currentCallbacks = {};

  let modalEl = null;
  let cropImageEl = null;
  let modalInstance = null;
  let modalReady = false;

  // ────────────────────────────────────────────────────────────
  //  Validation
  // ────────────────────────────────────────────────────────────

  function validateFile(file) {
    if (!file) {
      return { valid: false, message: 'কোনো ফাইল নির্বাচন করা হয়নি।' };
    }
    if (!ALLOWED_TYPES.includes(file.type)) {
      return {
        valid: false,
        message: 'অনুমোদিত ফাইল ফরম্যাট: JPG, JPEG, PNG, WEBP',
      };
    }
    if (file.size > MAX_FILE_SIZE) {
      return {
        valid: false,
        message: 'ফাইলের সর্বোচ্চ আকার ৫ এমবি। আপনার ফাইল ' +
          (file.size / (1024 * 1024)).toFixed(1) + ' এমবি।',
      };
    }
    return { valid: true, message: '' };
  }

  // ────────────────────────────────────────────────────────────
  //  Modal — created once, reused for every crop
  // ────────────────────────────────────────────────────────────

  function buildModal() {
    if (modalReady) return;
    if (document.getElementById('imageCropModal')) {
      modalEl = document.getElementById('imageCropModal');
      cropImageEl = document.getElementById('imageCropTarget');
      modalReady = true;
      return;
    }

    const html = [
      '<div class="modal fade" id="imageCropModal" tabindex="-1" role="dialog"',
      '     aria-labelledby="imageCropModalLabel" aria-hidden="true"',
      '     data-bs-backdrop="static" data-bs-keyboard="false">',
      '  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">',
      '    <div class="modal-content border-0 shadow-lg">',
      '      <div class="modal-header border-bottom-0 pb-0">',
      '        <h5 class="modal-title fw-bold" id="imageCropModalLabel">',
      '          <i class="fas fa-crop-alt me-2 text-primary"></i>',
      '          <span id="imageCropModalTitleText">ছবি ক্রপ করুন</span>',
      '        </h5>',
      '        <button type="button" class="btn-close" id="imageCropModalCloseBtn"',
      '                aria-label="বন্ধ করুন"></button>',
      '      </div>',
      '      <div class="modal-body p-3">',
      '        <div class="crop-wrapper bg-light rounded-3 overflow-hidden"',
      '             style="min-height:320px;max-height:70vh;">',
      '          <img id="imageCropTarget" src="" alt="ক্রপ করার জন্য ছবি"',
      '               style="max-width:100%;display:block;">',
      '        </div>',
      '      </div>',
      '      <div class="modal-footer border-top-0 pt-2 pb-3 px-3',
      '                  d-flex justify-content-between align-items-center flex-wrap gap-2">',
      '        <button type="button" class="btn btn-outline-secondary px-4"',
      '                id="imageCropCancelBtn" aria-label="বাতিল করুন">',
      '          <i class="fas fa-times me-1"></i> Cancel',
      '        </button>',
      '        <div class="d-flex gap-2">',
      '          <button type="button" class="btn btn-outline-info"',
      '                  id="imageCropRotateLeftBtn"',
      '                  title="বামে ঘোরান" aria-label="বামে ৯০ ডিগ্রি ঘোরান">',
      '            <i class="fas fa-undo-alt"></i>',
      '          </button>',
      '          <button type="button" class="btn btn-outline-info"',
      '                  id="imageCropRotateRightBtn"',
      '                  title="ডানে ঘোরান" aria-label="ডানে ৯০ ডিগ্রি ঘোরান">',
      '            <i class="fas fa-redo-alt"></i>',
      '          </button>',
      '        </div>',
      '        <button type="button" class="btn btn-primary px-4"',
      '                id="imageCropDoneBtn" aria-label="ক্রপ সম্পন্ন">',
      '          <i class="fas fa-check me-1"></i> Done',
      '        </button>',
      '      </div>',
      '    </div>',
      '  </div>',
      '</div>',
    ].join('\n');

    document.body.insertAdjacentHTML('beforeend', html);

    modalEl = document.getElementById('imageCropModal');
    cropImageEl = document.getElementById('imageCropTarget');

    // ─── Button events ──────────────────────────────────────────
    document.getElementById('imageCropCancelBtn').addEventListener('click', cancelCrop);
    document.getElementById('imageCropModalCloseBtn').addEventListener('click', cancelCrop);
    document.getElementById('imageCropRotateLeftBtn').addEventListener('click', function () {
      if (cropperInstance) cropperInstance.rotate(-90);
    });
    document.getElementById('imageCropRotateRightBtn').addEventListener('click', function () {
      if (cropperInstance) cropperInstance.rotate(90);
    });
    document.getElementById('imageCropDoneBtn').addEventListener('click', processCrop);

    // ─── Keyboard: ESC + focus trap ─────────────────────────────
    modalEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        cancelCrop();
        return;
      }
      if (e.key !== 'Tab') return;
      const focusable = modalEl.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });

    // ─── Cleanup on hidden ──────────────────────────────────────
    modalEl.addEventListener('hidden.bs.modal', function () {
      // destroyCropper() nullifies onerror/onload internally before setting src=''
      destroyCropper();
    });

    modalReady = true;
  }

  // ────────────────────────────────────────────────────────────
  //  Modal helpers
  // ────────────────────────────────────────────────────────────

  function showModal() {
    buildModal();
    if (!modalInstance) {
      modalInstance = new bootstrap.Modal(modalEl, {
        backdrop: 'static',
        keyboard: false,
      });
    }
    modalInstance.show();
    setTimeout(function () {
      const btn = document.getElementById('imageCropCancelBtn');
      if (btn) btn.focus();
    }, 100);
  }

  function hideModal() {
    // Blur active element if it's inside the modal to avoid Bootstrap
    // aria-hidden warning (modal has aria-hidden='true' but descendant has focus)
    if (document.activeElement && modalEl && modalEl.contains(document.activeElement)) {
      document.activeElement.blur();
    }
    if (modalInstance) {
      modalInstance.hide();
    }
    // Clean up Bootstrap's leftover backdrop
    var bd = document.querySelector('.modal-backdrop');
    if (bd) bd.remove();
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
  }

  function cancelCrop() {
    destroyCropper();
    hideModal();
    if (currentCallbacks.onCancel) currentCallbacks.onCancel();
  }

  // ────────────────────────────────────────────────────────────
  //  Cropper lifecycle
  // ────────────────────────────────────────────────────────────

  function handleImageError() {
    console.error('ImageCropper: Failed to load image.');
    // destroyCropper() nullifies onerror/onload internally before setting src='',
    // preventing infinite loop where src='' (current page URL) triggers onerror.
    destroyCropper();
    hideModal();
    alert('ছবি লোড করা সম্ভব হয়নি।');
  }

  function initCropper(imageUrl, mode) {
    // destroyCropper() internally nullifies onerror/onload before setting src='',
    // so we're safe from the infinite loop where src='' triggers onerror.
    destroyCropper();

    var config = MODE_CONFIG[mode] || MODE_CONFIG.photo;
    currentConfig = config;

    var titleEl = document.getElementById('imageCropModalTitleText');
    if (titleEl) titleEl.textContent = config.label + ' ক্রপ করুন';

    // Assign event handlers BEFORE setting src to avoid race conditions
    // (blob URLs can fire the 'load' event synchronously when src is set)
    cropImageEl.onload = function () {
      cropperInstance = new Cropper(cropImageEl, {
        aspectRatio: config.aspectRatio,
        viewMode: 1,
        dragMode: 'crop',
        initialAspectRatio: config.aspectRatio,
        autoCropArea: 0.8,
        restore: false,
        guides: true,
        center: true,
        highlight: false,
        cropBoxMovable: true,
        cropBoxResizable: true,
        toggleDragModeOnDblclick: false,
        zoomable: true,
        zoomOnTouch: true,
        zoomOnWheel: true,
        wheelZoomRatio: 0.05,
        movable: true,
        rotatable: true,
        scalable: true,
        minContainerWidth: 200,
        minContainerHeight: 200,
        responsive: true,
        checkCrossOrigin: false,
        // checkOrientation disabled: Cropper.js makes an XHR to the blob URL
        // to read EXIF orientation, then internally replaces the image src with
        // a corrected canvas data URL. This re-triggers our onload handler,
        // causing a second `new Cropper()` call on an already-initialized
        // element, which fails and fires onerror. Modern browsers handle EXIF
        // orientation natively, so this is a safe trade-off.
        checkOrientation: false,
      });
    };

    cropImageEl.onerror = function () {
      // If the URL is a blob URL, try fallback with FileReader data URL
      if (currentFile && imageUrl && imageUrl.startsWith('blob:')) {
        console.warn('ImageCropper: Blob URL failed, retrying with FileReader...');
        // Set onerror to handleImageError so if data URL also fails, user sees error
        cropImageEl.onerror = handleImageError;
        var fallbackReader = new FileReader();
        fallbackReader.onload = function (e2) {
          cropImageEl.src = e2.target.result;
        };
        fallbackReader.onerror = function () {
          handleImageError();
        };
        fallbackReader.readAsDataURL(currentFile);
        return;
      }
      handleImageError();
    };

    // Trigger image load AFTER handlers are assigned
    cropImageEl.src = imageUrl;
  }

  function destroyCropper() {
    if (cropperInstance) {
      cropperInstance.destroy();
      cropperInstance = null;
    }
    if (cropImageEl) {
      if (cropImageEl.src && cropImageEl.src.startsWith('blob:')) {
        URL.revokeObjectURL(cropImageEl.src);
      }
      // Prevent onerror triggered by cropImageEl.src = ''
      // (empty URL resolves to current page, which fails to load as an image).
      // Must be done here — every call to destroyCropper() triggers this cleanup.
      cropImageEl.onerror = null;
      cropImageEl.onload = null;
      cropImageEl.src = '';
    }
    currentFile = null;
  }

  // ────────────────────────────────────────────────────────────
  //  Crop processing → blob output
  // ────────────────────────────────────────────────────────────

  function processCrop() {
    if (!cropperInstance) return;

    var config = currentConfig;
    var format = config.format === 'jpeg' ? 'image/jpeg' : 'image/png';
    var canvas;

    if (config.outputWidth && config.outputHeight) {
      // Fixed dimensions (photo: 300×300)
      canvas = cropperInstance.getCroppedCanvas({
        width: config.outputWidth,
        height: config.outputHeight,
        fillColor: config.backgroundColor || '#fff',
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
      });
    } else if (config.outputWidth && !config.outputHeight) {
      // Max width, auto height (signature: max 600px)
      canvas = cropperInstance.getCroppedCanvas({
        width: config.outputWidth,
        fillColor: config.backgroundColor || 'transparent',
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
      });
    } else {
      // Preserve original resolution (document)
      var cropData = cropperInstance.getData();
      canvas = cropperInstance.getCroppedCanvas({
        width: Math.round(cropData.width),
        height: Math.round(cropData.height),
        fillColor: config.backgroundColor || '#fff',
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
      });
    }

    canvas.toBlob(function (blob) {
      if (!blob) {
        console.error('ImageCropper: Failed to create blob.');
        return;
      }

      var blobUrl = URL.createObjectURL(blob);
      var originalName = currentFile ? currentFile.name : 'cropped_image.jpg';
      var ext = format === 'image/png' ? 'png' : 'jpg';
      var croppedFile = new File(
        [blob],
        originalName.replace(/\.[^.]+$/, '') + '_cropped.' + ext,
        { type: format, lastModified: Date.now() }
      );

      if (currentCallbacks.onCropComplete) {
        currentCallbacks.onCropComplete({
          blobUrl: blobUrl,
          blob: blob,
          file: croppedFile,
          canvas: canvas,
        });
      }

      destroyCropper();
      hideModal();
    }, format, config.quality || 0.9);
  }

  // ────────────────────────────────────────────────────────────
  //  Validation error display
  // ────────────────────────────────────────────────────────────

  function showError(message) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'error',
        title: 'Invalid File',
        text: message,
        confirmButtonColor: '#1696e7',
        confirmButtonText: 'ঠিক আছে',
      });
    } else {
      alert(message);
    }
  }

  // ────────────────────────────────────────────────────────────
  //  DOM Discovery — Find related elements near the input
  // ────────────────────────────────────────────────────────────

  /**
   * Discover related elements for a given file input.
   * Uses data-* attributes if provided, otherwise auto-discovers
   * by traversing nearby DOM (siblings, parent card, known IDs).
   *
   * @param {HTMLInputElement} input
   * @returns {{
   *   inputId: string,
   *   mode: string,
   *   triggers: string[],       // CSS selectors for click triggers
   *   dropzone: string|null,    // CSS selector for drag-and-drop zone
   *   previewId: string,
   *   previewContainerId: string,
   *   dropZoneId: string
   * }}
   */
  function discoverElements(input) {
    var id = input.id;
    if (!id) return null;

    // Read data attributes
    var mode = input.getAttribute('data-crop-mode') || 'photo';

    // Use explicit triggers if provided, otherwise auto-discover
    var triggerSelector = input.getAttribute('data-crop-trigger') || '';
    var dropzoneSelector = input.getAttribute('data-crop-dropzone') || '';
    var previewId = input.getAttribute('data-preview') || '';
    var previewContainerId = input.getAttribute('data-preview-container') || '';
    var dropZoneId = input.getAttribute('data-drop-zone') || '';

    // Auto-discover triggers if not specified
    if (!triggerSelector) {
      triggerSelector = discoverTriggers(input);
    }
    if (!dropzoneSelector) {
      dropzoneSelector = discoverDropZone(input);
    }

    // Auto-discover preview IDs if not specified
    if (!previewId) previewId = discoverPreviewId(input);
    if (!previewContainerId) previewContainerId = discoverPreviewContainerId(input);
    if (!dropZoneId) dropZoneId = dropzoneSelector.replace(/^#/, '');

    return {
      inputId: id,
      mode: mode,
      triggers: triggerSelector ? triggerSelector.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [],
      dropzone: dropzoneSelector,
      previewId: previewId,
      previewContainerId: previewContainerId,
      dropZoneId: dropZoneId,
    };
  }

  /**
   * Auto-discover trigger elements near the file input.
   * Strategy: look for the nearest ancestor `.upload-card`,
   * then find buttons and clickable areas within it.
   */
  function discoverTriggers(input) {
    var card = input.closest('.upload-card') || input.closest('.card') || input.parentElement;
    if (!card) return '';

    var selectors = [];

    // Look for upload button
    var btn = card.querySelector('[id$="UploadBtn"], [id$="uploadBtn"], [id$="CardBtn"], .upload-card-btn, .btn-upload');
    if (btn && btn.id) selectors.push('#' + btn.id);

    // Look for drop zone (also works as click trigger)
    var dz = card.querySelector('[id$="DropZone"], [id$="dropZone"], .upload-preview-area, .upload-card-top');
    if (dz && dz.id) selectors.push('#' + dz.id);

    return selectors.join(',');
  }

  /**
   * Auto-discover the drag-and-drop zone.
   */
  function discoverDropZone(input) {
    var card = input.closest('.upload-card') || input.closest('.card') || input.parentElement;
    if (!card) return '';

    var dz = card.querySelector('[id$="DropZone"], [id$="dropZone"], .upload-preview-area, .upload-card-top');
    if (dz && dz.id) return '#' + dz.id;

    return '';
  }

  /**
   * Auto-discover preview img ID.
   */
  function discoverPreviewId(input) {
    var card = input.closest('.upload-card') || input.closest('.card') || input.parentElement;
    if (!card) return '';

    var img = card.querySelector('[id$="PreviewImg"], [id$="previewImg"], .upload-card-preview img');
    return img ? img.id : '';
  }

  /**
   * Auto-discover preview container ID.
   */
  function discoverPreviewContainerId(input) {
    var card = input.closest('.upload-card') || input.closest('.card') || input.parentElement;
    if (!card) return '';

    var el = card.querySelector('[id$="PreviewContainer"], [id$="previewContainer"], .upload-card-preview');
    return el ? el.id : '';
  }

  // ────────────────────────────────────────────────────────────
  //  Update preview — called after crop completes
  // ────────────────────────────────────────────────────────────

  function updatePreview(discovery, result) {
    var previewEl = document.getElementById(discovery.previewId);
    var previewContainer = document.getElementById(discovery.previewContainerId);
    var dropZone = document.getElementById(discovery.dropZoneId);

    if (previewEl) {
      if (previewEl.dataset.cropBlobUrl) {
        URL.revokeObjectURL(previewEl.dataset.cropBlobUrl);
      }
      previewEl.src = result.blobUrl;
      previewEl.dataset.cropBlobUrl = result.blobUrl;
      previewEl.style.display = 'block';
      previewEl.removeAttribute('hidden');
    }
    if (previewContainer) {
      previewContainer.style.display = 'block';
      previewContainer.removeAttribute('hidden');
      // Hide placeholder elements inside
      var placeholder = previewContainer.querySelector('.upload-card-icon, .upload-card-text, .placeholder-text');
      if (placeholder) placeholder.style.display = 'none';
    }
    if (dropZone) {
      dropZone.style.backgroundImage = 'none';
      dropZone.style.background = '#F2F2F2';
    }

    // Remove size warnings if any
    var warn = document.getElementById('photo-size-warning');
    if (warn) warn.remove();
  }

  // ────────────────────────────────────────────────────────────
  //  Wire up a single input — called by autoInit
  // ────────────────────────────────────────────────────────────

  function wireInput(input) {
    var discovery = discoverElements(input);
    if (!discovery) return;

    var inputId = discovery.inputId;

    // Store discovery on the element for reuse
    input._cropDisc = discovery;

    // ── 1. Wire click triggers → file input ─────────────────
    discovery.triggers.forEach(function (sel) {
      if (!sel) return; // safety guard
      var els = document.querySelectorAll(sel);
      els.forEach(function (el) {
        el.addEventListener('click', function (e) {
          e.preventDefault();
          input.click();
        });
      });
    });

    // ── 2. Wire drag-and-drop on drop zone ──────────────────
    if (discovery.dropzone) {
      var dropZoneEls = document.querySelectorAll(discovery.dropzone);
      dropZoneEls.forEach(function (dz) {
        var card = dz.closest('.upload-card');
        var dragCounter = 0;

        dz.addEventListener('dragenter', function (e) {
          e.preventDefault();
          e.stopPropagation();
          dragCounter++;
          if (card) card.classList.add('drag-over');
        });

        dz.addEventListener('dragover', function (e) {
          e.preventDefault();
          e.stopPropagation();
          if (card) card.classList.add('drag-over');
        });

        dz.addEventListener('dragleave', function (e) {
          e.preventDefault();
          e.stopPropagation();
          dragCounter--;
          if (dragCounter <= 0) {
            dragCounter = 0;
            if (card) card.classList.remove('drag-over');
          }
        });

        dz.addEventListener('drop', function (e) {
          e.preventDefault();
          e.stopPropagation();
          dragCounter = 0;
          if (card) card.classList.remove('drag-over');

          var files = e.dataTransfer.files;
          if (files.length > 0) {
            input.files = files;
            startCropFlow(input);
          }
        });
      });
    }

    // ── 3. Wire file selection → crop flow ──────────────────
    input.addEventListener('change', function (e) {
      if (!e.target.files || !e.target.files[0]) return;
      startCropFlow(e.target);
    });
  }

  // ────────────────────────────────────────────────────────────
  //  Start the crop flow for an input
  // ────────────────────────────────────────────────────────────

  function startCropFlow(input) {
    var discovery = input._cropDisc;
    if (!discovery) return;

    var file = input.files ? input.files[0] : null;
    if (!file) return;

    // Validate
    var validation = validateFile(file);
    if (!validation.valid) {
      input.value = '';
      showError(validation.message);
      return;
    }

    // Store state
    currentFile = file;
    currentCallbacks = {
      onCropComplete: function (result) {
        updatePreview(discovery, result);

        // Store the cropped file back in the file input for form submission
        try {
          var dt = new DataTransfer();
          dt.items.add(result.file);
          input.files = dt.files;
        } catch (err) {
          console.warn('ImageCropper: Could not set input files.', err);
        }
      },
      onCancel: function () {
        input.value = '';
      },
    };

    // Build modal (first time only)
    buildModal();

    // Open modal and start crop using blob URL (more reliable than data URL
    // which can fail for large images due to base64 size limits)
    showModal();
    initCropper(URL.createObjectURL(file), discovery.mode);
  }

  // ────────────────────────────────────────────────────────────
  //  Public API
  // ────────────────────────────────────────────────────────────

  /**
   * autoInit() — Scan the page for [data-crop="true"] inputs
   * and wire them up automatically.
   *
   * Call once after DOM ready:
   *   ImageCropper.autoInit();
   *
   * Or use the HTML-only approach:
   *   <input type="file" data-crop="true">
   *
   * No JavaScript code needed in templates!
   */
  function autoInit() {
    var inputs = document.querySelectorAll('input[type="file"][data-crop="true"]');
    inputs.forEach(function (input) {
      // Guard: skip if already wired
      if (input._cropDisc) return;
      wireInput(input);
    });
  }

  /**
   * init() — Manual initialization for advanced usage.
   * Use this only if you need explicit control over the flow.
   * For most cases, autoInit() is sufficient.
   */
  function init(options) {
    if (!options || !options.inputId) {
      console.error('ImageCropper.init(): inputId is required.');
      return;
    }
    var input = document.getElementById(options.inputId);
    if (!input) {
      console.error('ImageCropper.init(): Input #' + options.inputId + ' not found.');
      return;
    }

    // Merge options into discovery for manual init
    input._cropDisc = input._cropDisc || {};
    input._cropDisc.mode = options.mode || 'photo';
    input._cropDisc.previewId = options.previewId || input._cropDisc.previewId || '';
    input._cropDisc.previewContainerId = options.previewContainerId || input._cropDisc.previewContainerId || '';
    input._cropDisc.dropZoneId = options.dropZoneId || input._cropDisc.dropZoneId || '';

    // Override callbacks
    currentCallbacks = {
      onCropComplete: options.onCropComplete || function (r) { updatePreview(input._cropDisc, r); },
      onCancel: options.onCancel || function () { input.value = ''; },
    };

    var file = input.files ? input.files[0] : null;
    if (!file) return;

    var validation = validateFile(file);
    if (!validation.valid) {
      input.value = '';
      showError(validation.message);
      return;
    }

    currentFile = file;
    buildModal();

    // Open modal and start crop using blob URL (more reliable than data URL)
    showModal();
    initCropper(URL.createObjectURL(file), options.mode || 'photo');
  }

  /**
   * destroy() — Clean up modal and cropper.
   */
  function destroy() {
    destroyCropper();
    if (modalInstance) {
      modalInstance.dispose();
      modalInstance = null;
    }
    if (modalEl) {
      modalEl.remove();
      modalEl = null;
    }
    modalReady = false;
  }

  // ────────────────────────────────────────────────────────────
  //  Export
  // ────────────────────────────────────────────────────────────

  return {
    autoInit: autoInit,
    init: init,
    destroy: destroy,
    MODE_PHOTO: 'photo',
    MODE_SIGNATURE: 'signature',
    MODE_DOCUMENT: 'document',
  };

})();
