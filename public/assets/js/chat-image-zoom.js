/**
 * chat-image-zoom.js
 * Standalone image zoom modal for chat attached files.
 * Features: mouse wheel zoom, drag-to-pan, pinch-to-zoom,
 *           zoom in/out buttons, rotate, fit-to-screen, reset,
 *           gallery/slideshow with prev/next navigation.
 *
 * Gallery usage:
 *   ChatImageZoom.open(imgElement, fileName)              // single image
 *   ChatImageZoom.open(imgElement, fileName, images, idx) // gallery
 *
 *   images = [{ element: imgEl, name: 'Image', url: '...' }, ...]
 *   idx = current index into images
 */

var ChatImageZoom = (function () {
  'use strict';

  // ========================
  // State
  // ========================
  var state = {
    isOpen: false,
    scale: 1,
    minScale: 0.25,
    maxScale: 10,
    rotation: 0,
    translateX: 0,
    translateY: 0,
    isDragging: false,
    dragStartX: 0,
    dragStartY: 0,
    dragStartTranslateX: 0,
    dragStartTranslateY: 0,
    lastPinchDist: 0,
    imageUrl: '',
    imageName: '',
    imageNaturalWidth: 0,
    imageNaturalHeight: 0,
    // Gallery
    images: [],           // Array of { element, name, url }
    currentIndex: -1,
    _arrowKeyHandler: null,
  };

  // ========================
  // DOM Elements
  // ========================
  var els = {};
  var _built = false;

  function buildModal() {
    if (_built) return;
    _built = true;

    var overlay = document.createElement('div');
    overlay.className = 'chat-image-zoom-overlay';
    overlay.id = 'chatImageZoomOverlay';

    // Header
    var header = document.createElement('div');
    header.className = 'chat-image-zoom-header';

    var counter = document.createElement('span');
    counter.className = 'chat-image-zoom-counter';
    counter.id = 'chatImageZoomCounter';
    header.appendChild(counter);

    var title = document.createElement('span');
    title.className = 'chat-image-zoom-title';
    title.id = 'chatImageZoomTitle';
    header.appendChild(title);

    var zoomLevel = document.createElement('span');
    zoomLevel.className = 'chat-image-zoom-level';
    zoomLevel.id = 'chatImageZoomLevel';
    zoomLevel.textContent = '100%';
    header.appendChild(zoomLevel);

    var closeBtn = document.createElement('button');
    closeBtn.className = 'chat-image-zoom-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.setAttribute('aria-label', 'Close');
    header.appendChild(closeBtn);
    overlay.appendChild(header);

    // Body (image container)
    var body = document.createElement('div');
    body.className = 'chat-image-zoom-body';
    body.id = 'chatImageZoomBody';

    // Prev / Next arrows (gallery navigation)
    var prevBtn = document.createElement('button');
    prevBtn.className = 'chat-zoom-nav-btn chat-zoom-nav-prev';
    prevBtn.id = 'chatZoomNavPrev';
    prevBtn.innerHTML = '<i class=\"fas fa-chevron-left\"></i>';
    prevBtn.setAttribute('aria-label', 'Previous image');
    prevBtn.setAttribute('title', 'Previous');
    body.appendChild(prevBtn);

    var nextBtn = document.createElement('button');
    nextBtn.className = 'chat-zoom-nav-btn chat-zoom-nav-next';
    nextBtn.id = 'chatZoomNavNext';
    nextBtn.innerHTML = '<i class=\"fas fa-chevron-right\"></i>';
    nextBtn.setAttribute('aria-label', 'Next image');
    nextBtn.setAttribute('title', 'Next');
    body.appendChild(nextBtn);

    var img = document.createElement('img');
    img.className = 'chat-image-zoom-img';
    img.id = 'chatImageZoomImg';
    img.alt = 'Zoomed image';
    img.draggable = false;
    body.appendChild(img);
    overlay.appendChild(body);

    // Toolbar
    var toolbar = document.createElement('div');
    toolbar.className = 'chat-image-zoom-toolbar';
    toolbar.innerHTML =
      '<button type="button" class="chat-zoom-btn" data-action="zoom-out" title="জুম আউট"><i class="fas fa-search-minus"></i></button>' +
      '<button type="button" class="chat-zoom-btn" data-action="zoom-in" title="জুম ইন"><i class="fas fa-search-plus"></i></button>' +
      '<button type="button" class="chat-zoom-btn" data-action="reset" title="রিসেট"><i class="fas fa-undo"></i></button>' +
      '<button type="button" class="chat-zoom-btn" data-action="fit" title="ফিট"><i class="fas fa-expand"></i></button>' +
      '<button type="button" class="chat-zoom-btn" data-action="rotate-left" title="ঘুরান"><i class="fas fa-redo-alt"></i></button>' +
      '<a class="chat-zoom-btn chat-zoom-download" data-action="download" title="ডাউনলোড" target="_blank" rel="noopener noreferrer"><i class="fas fa-download"></i></a>';
    overlay.appendChild(toolbar);

    document.body.appendChild(overlay);

    // Cache refs
    els.overlay = overlay;
    els.header = header;
    els.counter = counter;
    els.title = title;
    els.zoomLevel = zoomLevel;
    els.closeBtn = closeBtn;
    els.body = body;
    els.img = img;
    els.toolbar = toolbar;
    els.prevBtn = prevBtn;
    els.nextBtn = nextBtn;

    // Bind events
    bindEvents();
  }

  // ========================
  // Event Binding
  // ========================
  function bindEvents() {
    // Close
    els.closeBtn.addEventListener('click', close);

    els.overlay.addEventListener('click', function (e) {
      if (e.target === els.overlay) close();
    });

    // Keyboard: Escape to close, arrow keys for gallery navigation
    state._arrowKeyHandler = function (e) {
      if (!state.isOpen) return;
      if (e.key === 'Escape') {
        close();
        return;
      }
      if (e.key === 'ArrowLeft') {
        navigateTo(state.currentIndex - 1);
        e.preventDefault();
      }
      if (e.key === 'ArrowRight') {
        navigateTo(state.currentIndex + 1);
        e.preventDefault();
      }
    };
    document.addEventListener('keydown', state._arrowKeyHandler);

    // Toolbar buttons
    els.toolbar.addEventListener('click', function (e) {
      var btn = e.target.closest('.chat-zoom-btn');
      if (!btn || !btn.dataset.action) return;
      e.preventDefault();
      e.stopPropagation();
      switch (btn.dataset.action) {
        case 'zoom-in': zoomIn(); break;
        case 'zoom-out': zoomOut(); break;
        case 'reset': resetZoom(); break;
        case 'fit': fitToScreen(); break;
        case 'rotate-left': rotateLeft(); break;
        case 'download': downloadImage(); break;
      }
    });

    // Gallery prev/next buttons
    els.prevBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      navigateTo(state.currentIndex - 1);
    });
    els.nextBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      navigateTo(state.currentIndex + 1);
    });

    // Mouse wheel zoom
    els.body.addEventListener('wheel', function (e) {
      if (!state.isOpen) return;
      e.preventDefault();
      var delta = e.deltaY > 0 ? -0.1 : 0.1;
      zoomAtPoint(delta, e.clientX, e.clientY);
    }, { passive: false });

    // Mouse drag to pan
    els.body.addEventListener('mousedown', function (e) {
      if (!state.isOpen || e.button !== 0) return;
      if (e.target === els.img || e.target.closest('.chat-image-zoom-img')) {
        state.isDragging = true;
        state.dragStartX = e.clientX;
        state.dragStartY = e.clientY;
        state.dragStartTranslateX = state.translateX;
        state.dragStartTranslateY = state.translateY;
        els.body.style.cursor = 'grabbing';
        e.preventDefault();
      }
    });

    document.addEventListener('mousemove', function (e) {
      if (!state.isDragging) return;
      var dx = e.clientX - state.dragStartX;
      var dy = e.clientY - state.dragStartY;
      state.translateX = state.dragStartTranslateX + dx;
      state.translateY = state.dragStartTranslateY + dy;
      applyTransform();
    });

    document.addEventListener('mouseup', function () {
      if (state.isDragging) {
        state.isDragging = false;
        if (els.body) els.body.style.cursor = '';
      }
    });

    // Touch events for mobile
    var touchStartDist = 0;
    var touchStartScale = 1;
    var touchStartX = 0;
    var touchStartY = 0;
    var touchStartTranslateX = 0;
    var touchStartTranslateY = 0;
    var isTouching = false;

    els.body.addEventListener('touchstart', function (e) {
      if (!state.isOpen) return;
      if (e.touches.length === 2) {
        touchStartDist = getTouchDist(e.touches);
        touchStartScale = state.scale;
        e.preventDefault();
      } else if (e.touches.length === 1 && (e.target === els.img || e.target.closest('.chat-image-zoom-img'))) {
        isTouching = true;
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        touchStartTranslateX = state.translateX;
        touchStartTranslateY = state.translateY;
      }
    }, { passive: false });

    els.body.addEventListener('touchmove', function (e) {
      if (!state.isOpen) return;
      if (e.touches.length === 2) {
        e.preventDefault();
        var newDist = getTouchDist(e.touches);
        var ratio = newDist / touchStartDist;
        var newScale = touchStartScale * ratio;
        state.scale = Math.max(state.minScale, Math.min(state.maxScale, newScale));
        applyTransform();
        updateZoomLevel();
      } else if (e.touches.length === 1 && isTouching) {
        var dx = e.touches[0].clientX - touchStartX;
        var dy = e.touches[0].clientY - touchStartY;
        state.translateX = touchStartTranslateX + dx;
        state.translateY = touchStartTranslateY + dy;
        applyTransform();
      }
    }, { passive: false });

    els.body.addEventListener('touchend', function () {
      isTouching = false;
    });

    // Double-click to zoom
    els.img.addEventListener('dblclick', function (e) {
      if (!state.isOpen) return;
      e.preventDefault();
      zoomAtPoint(0.5, e.clientX, e.clientY);
    });

    // Window resize
    window.addEventListener('resize', function () {
      if (state.isOpen) {
        clampTranslation();
        applyTransform();
      }
    });
  }

  function getTouchDist(touches) {
    var dx = touches[0].clientX - touches[1].clientX;
    var dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
  }

  // ========================
  // Gallery Navigation
  // ========================
  function navigateTo(index) {
    if (!state.images || state.images.length === 0) return;
    if (index < 0 || index >= state.images.length) return;

    state.currentIndex = index;
    var entry = state.images[index];
    if (!entry) return;

    var src = entry.url || (entry.element ? (entry.element.src || entry.element.getAttribute('src')) : '');
    var name = entry.name || 'Image';

    if (!src) return;

    state.imageUrl = src;
    state.imageName = name;
    state.scale = 1;
    state.translateX = 0;
    state.translateY = 0;
    state.rotation = 0;

    // Update title and counter
    els.title.textContent = name;
    updateGalleryCounter();

    // Update navigation buttons visibility
    updateNavButtons();

    // Load image
    els.img.src = src;
    els.img.onload = function () {
      state.imageNaturalWidth = els.img.naturalWidth;
      state.imageNaturalHeight = els.img.naturalHeight;
      fitToScreen();
    };
    els.img.style.transform = '';
  }

  function updateGalleryCounter() {
    if (els.counter && state.images && state.images.length > 1) {
      els.counter.textContent = (state.currentIndex + 1) + ' / ' + state.images.length;
      els.counter.style.display = '';
    } else if (els.counter) {
      els.counter.style.display = 'none';
    }
  }

  function updateNavButtons() {
    if (!els.prevBtn || !els.nextBtn) return;
    var hasMultiple = state.images && state.images.length > 1;
    if (!hasMultiple) {
      els.prevBtn.style.display = 'none';
      els.nextBtn.style.display = 'none';
      return;
    }
    els.prevBtn.style.display = '';
    els.nextBtn.style.display = '';
    els.prevBtn.classList.toggle('disabled', state.currentIndex <= 0);
    els.nextBtn.classList.toggle('disabled', state.currentIndex >= state.images.length - 1);
  }

  // ========================
  // Zoom & Transform
  // ========================
  function applyTransform() {
    if (!els.img) return;
    var transform = 'translate(' + state.translateX + 'px, ' + state.translateY + 'px) ' +
                    'scale(' + state.scale + ') ' +
                    'rotate(' + state.rotation + 'deg)';
    els.img.style.transform = transform;
  }

  function updateZoomLevel() {
    if (els.zoomLevel) {
      els.zoomLevel.textContent = Math.round(state.scale * 100) + '%';
    }
  }

  function zoomAtPoint(delta, clientX, clientY) {
    if (!els.body) return;

    var rect = els.body.getBoundingClientRect();
    var x = clientX - rect.left;
    var y = clientY - rect.top;

    var oldScale = state.scale;
    var newScale = state.scale * (1 + delta);
    newScale = Math.max(state.minScale, Math.min(state.maxScale, newScale));

    var ratio = newScale / oldScale;
    state.translateX = x - (x - state.translateX) * ratio;
    state.translateY = y - (y - state.translateY) * ratio;
    state.scale = newScale;

    clampTranslation();
    applyTransform();
    updateZoomLevel();
  }

  function zoomIn() {
    var centerX = window.innerWidth / 2;
    var centerY = window.innerHeight / 2;
    zoomAtPoint(0.25, centerX, centerY);
  }

  function zoomOut() {
    var centerX = window.innerWidth / 2;
    var centerY = window.innerHeight / 2;
    zoomAtPoint(-0.25, centerX, centerY);
  }

  function resetZoom() {
    state.scale = 1;
    state.translateX = 0;
    state.translateY = 0;
    state.rotation = 0;
    applyTransform();
    updateZoomLevel();
  }

  function fitToScreen() {
    if (!els.body || !state.imageNaturalWidth || !state.imageNaturalHeight) {
      resetZoom();
      return;
    }

    var containerRect = els.body.getBoundingClientRect();
    var containerW = containerRect.width - 40;
    var containerH = containerRect.height - 40;

    var scaleX = containerW / state.imageNaturalWidth;
    var scaleY = containerH / state.imageNaturalHeight;
    state.scale = Math.min(scaleX, scaleY, 1);

    var imgW = state.imageNaturalWidth * state.scale;
    var imgH = state.imageNaturalHeight * state.scale;
    state.translateX = (containerRect.width - imgW) / 2;
    state.translateY = (containerRect.height - imgH) / 2;
    state.rotation = 0;

    applyTransform();
    updateZoomLevel();
  }

  function rotateLeft() {
    state.rotation = (state.rotation - 90) % 360;
    applyTransform();
  }

  function clampTranslation() {
    if (!els.body || !state.imageNaturalWidth || !state.imageNaturalHeight) return;

    var rect = els.body.getBoundingClientRect();
    var imgW = state.imageNaturalWidth * state.scale;
    var imgH = state.imageNaturalHeight * state.scale;

    var maxTx = Math.max(0, (imgW - rect.width) / 2);
    var minTx = Math.min(0, -(imgW - rect.width) / 2);
    var maxTy = Math.max(0, (imgH - rect.height) / 2);
    var minTy = Math.min(0, -(imgH - rect.height) / 2);

    if (imgW <= rect.width) {
      state.translateX = (rect.width - imgW) / 2;
    } else {
      state.translateX = Math.max(minTx, Math.min(maxTx, state.translateX));
    }

    if (imgH <= rect.height) {
      state.translateY = (rect.height - imgH) / 2;
    } else {
      state.translateY = Math.max(minTy, Math.min(maxTy, state.translateY));
    }
  }

  // ========================
  // Download
  // ========================
  function downloadImage() {
    var link = els.toolbar.querySelector('.chat-zoom-download');
    if (link) {
      link.href = state.imageUrl;
      link.setAttribute('download', state.imageName || 'image');
      link.click();
    }
  }

  // ========================
  // Open / Close
  // ========================
  function open(imgElement, fileName, images, currentIndex) {
    buildModal();
    if (!els.img) return;

    // Store gallery collection if provided
    if (images && Array.isArray(images) && images.length > 0) {
      state.images = images;
      state.currentIndex = (typeof currentIndex === 'number' && currentIndex >= 0 && currentIndex < images.length)
        ? currentIndex : 0;
    } else {
      // Single image — create a one-element gallery
      state.images = [{
        element: imgElement,
        name: fileName || imgElement.alt || 'Image',
        url: imgElement.src || imgElement.getAttribute('src') || '',
      }];
      state.currentIndex = 0;
    }

    var entry = state.images[state.currentIndex];
    var src = entry.url;
    var name = entry.name;

    state.imageUrl = src;
    state.imageName = name;
    state.scale = 1;
    state.translateX = 0;
    state.translateY = 0;
    state.rotation = 0;
    state.isOpen = true;

    state.imageNaturalWidth = imgElement.naturalWidth || 0;
    state.imageNaturalHeight = imgElement.naturalHeight || 0;

    els.title.textContent = name;
    updateGalleryCounter();
    updateNavButtons();

    els.img.src = src;
    els.img.onload = function () {
      state.imageNaturalWidth = els.img.naturalWidth;
      state.imageNaturalHeight = els.img.naturalHeight;
      fitToScreen();
    };

    els.img.style.transform = '';

    els.overlay.style.display = 'flex';
    requestAnimationFrame(function () {
      els.overlay.classList.add('open');
    });

    document.body.style.overflow = 'hidden';
  }

  function openUrl(url, fileName, images, currentIndex) {
    var tempImg = new Image();
    tempImg.src = url;
    var name = fileName || 'Image';

    if (tempImg.complete && tempImg.naturalWidth) {
      open(tempImg, name, images, currentIndex);
      return;
    }

    tempImg.onload = function () {
      open(tempImg, name, images, currentIndex);
    };
    tempImg.onerror = function () {
      var fallback = { src: url, naturalWidth: 0, naturalHeight: 0, alt: name };
      open(fallback, name, images, currentIndex);
    };
  }

  function close() {
    if (!els.overlay) return;
    state.isOpen = false;
    state.images = [];
    state.currentIndex = -1;
    els.overlay.classList.remove('open');
    setTimeout(function () {
      els.overlay.style.display = 'none';
    }, 250);
    document.body.style.overflow = '';
  }

  /**
   * Clean up keyboard handler when module is no longer needed
   * (called automatically on page unload)
   */
  function destroy() {
    if (state._arrowKeyHandler) {
      document.removeEventListener('keydown', state._arrowKeyHandler);
      state._arrowKeyHandler = null;
    }
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', destroy);
  }

  // ========================
  // Public API
  // ========================
  return {
    open: function (imgElement, fileName, images, currentIndex) {
      open(imgElement, fileName, images, currentIndex);
    },
    openUrl: function (url, fileName, images, currentIndex) {
      openUrl(url, fileName, images, currentIndex);
    },
    close: close,
    destroy: destroy,
  };
})();
