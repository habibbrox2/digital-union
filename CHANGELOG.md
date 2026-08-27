## 1.3.0 (2026-08-27)

### Fixed - Trade Fee Update & Sync System

- **PHP (`ApplicationService.php`)**: Fixed `approveTradeBusinessMeta()` using `!empty()` for fee fields, which silently discarded zero-value fees (PHP `!empty(0)` is `false`). Changed to `isset() && !== ''` to match `updateTradeFees()` pattern, allowing proper zero-value fee saves.
- **PHP (`ApplicationService.php`)**: Added business_type table fee sync to `approveTradeBusinessMeta()` — when approving a trade application, the business_type template fees are now updated so future applications auto-fill with the latest values.
- **JS (`application_lists.twig`)**: Fixed approve modal fee field population using `|| ''` which discarded zero values (`0 || ''` → `''`). Changed to nullish coalescing `?? ''` to preserve zero-value fees.

## 1.2.0 (2026-08-27)

### Changed - jQuery to Vanilla JavaScript Migration (Application List Pages)

Converted all jQuery-dependent JavaScript code to vanilla JavaScript in the following files:

- **`templates/applications/application_lists.twig`**
  - Search applicant form handler: `$.ajax` → `fetch()` API, `$(selector)` → `document.getElementById()` / `document.querySelector()`
  - Main application list script: `$(document).ready()` → `DOMContentLoaded`, `$.ajax()` → `fetch()` with `URLSearchParams`, `$.each()` → `Array.forEach()`
  - Table loading overlay: `$('#el').fadeIn()` / `fadeOut()` → CSS opacity transitions
  - Table body operations: `$(tbody).empty()` / `append()` → `innerHTML` / `insertAdjacentHTML()`
  - Tooltip initialization: `$('[data-bs-toggle]').tooltip()` → Bootstrap 5 vanilla `new bootstrap.Tooltip()`
  - Address block collapse/expand: `$(el).animate()` → CSS transitions, `$(document).off().on()` event delegation → `document.addEventListener()`
  - Pagination: `$(pagination).empty()` / `append()` → `innerHTML` / `insertAdjacentHTML()`
  - Live search: `$('#liveSearch').on('input')` / `$('#liveSearch').on('keyup')` → `addEventListener()` with debounce
  - Sortable headers: `$(this).data()` → `el.dataset`, `$(el).removeClass().addClass()` → `el.classList.remove().add()`
  - Bulk operations: `$('#sonodTableBody').on('change', '.row-checkbox')` → event delegation via `addEventListener()`
  - Delete/On Hold/Reactivate handlers: `$(document).on('click', '.btn')` → single delegated `document.addEventListener('click')`
  - Modal handlers: `$(document).on('hidden.bs.modal')` → `el.addEventListener('hidden.bs.modal')`, `$(document).on('submit')` → `el.addEventListener('submit')`
  - All `$.ajax()` calls → `fetch()` with `URLSearchParams` and `.then()/.catch()`
  - Display messages: `$('#message').html()` / `.empty()` → `el.innerHTML` / `setTimeout()`
  - Dropdown change handlers: `$('#filter').on('change')` → `el.addEventListener('change')`
  - Trade fee auto-calculation: delegated `$(document).on('input')` → individual `addEventListener()` on each input

- **`templates/applications/appListByapplicant.twig`**
  - Address block collapse/expand: removed jQuery dependency entirely, replaced with vanilla JS `document.querySelectorAll()`, `element.click()`, CSS transitions
  - Removed jQuery version check script (no longer needed)

- **`templates/applications/forms/update_form.twig`**
  - Document ready: `$(document).ready()` → `DOMContentLoaded`
  - Dropdown population: `$.each()` → `Array.forEach()`, `$(selector).empty().append()` → `innerHTML` / `insertAdjacentHTML()`
  - Geo data fetch: `$.ajax()` → `fetch()` with `URLSearchParams`
  - Address field population: `$(selector).val()` → `el.value`, `$(selector).css()` → `el.style`
  - Cascading dropdowns: `$(selector).data('geo-id')` → `el.selectedOptions[0].dataset.geoId`
  - Documents preview: `$(selector).clone()`, `$(selector).find()` → `cloneNode(true)`, `querySelector()`
  - Applicant name: `$(selector).prop('checked')` → `el.checked`, `$(selector).prop('readonly')` → `el.readOnly`
  - Personal info: `$(selector).val()` → `el.value`, `$(selector).css()` → `el.style`
  - Form submit: `$(form).off().on()` → `removeEventListener()` / `addEventListener()`
  - Datepicker: Kept jQuery UI datepicker with feature detection and vanilla JS fallback

- **`templates/applications/forms/default.twig`**
  - Datepicker init: Updated to use `jQuery()` instead of `$()` for safer feature detection

- **`templates/applications/forms/reapply.twig`**
  - Datepicker init: Updated to use `jQuery()` instead of `$()` for safer feature detection

### Notes
- jQuery UI datepicker is retained where used (form date inputs) with feature detection and vanilla JS fallback
- All other jQuery code has been converted to vanilla JavaScript

### Benefits
- Eliminated jQuery dependency from application list and form pages
- Reduced page load time by removing jQuery library requirement
- Used modern browser APIs (`fetch`, `classList`, `dataset`, CSS transitions)
- Improved code maintainability with standard JavaScript patterns

## 1.1.0

* Add bypass-proxy-for option added in 0.12.3 (see [#302](https://github.com/KnpLabs/snappy/pull/302))
* Fix symfony/process 4.2 deprecation notice (see [#331](https://github.com/KnpLabs/snappy/pull/331))
* Drop suppor for unmaintained PHP versions (5.6 and 7.0, see [#337](https://github.com/KnpLabs/snappy/pull/337)
* Drop support for unmaintained symfony/process versions (see [#337](https://github.com/KnpLabs/snappy/pull/337))
* Pass on error code in checkProcessStatus (see [#328](https://github.com/KnpLabs/snappy/pull/328))

Thanks to @joshpme, @drigani, @fbourigault, @NiR- and @leimd for their work.

## 1.0.4

* Support cache-dir for Image generation  (see [#297](https://github.com/KnpLabs/snappy/pull/297)).

Thank you @dimitrilahaye for their work.

## 1.0.3

* Add support to Symfony 4 ([#290](https://github.com/KnpLabs/snappy/pull/290))
* Use PHPUnit\Framework\TestCase instead of PHPUnit_Framework_TestCase ([#287](https://github.com/KnpLabs/snappy/pull/287))

Credits go to @michaelperrin and @carusogabriel.

## 1.0.2

*A BC break was introduced in v1.0.0: using objects castable to string with a cyclic dependency to the generator 
as option value would break `setOption()` / `setOptions()` methods.* 

* Use logger context rather than `var_export` to log option values (see [#283](https://github.com/KnpLabs/snappy/pull/283))

Credits go to: @barryvdh.

## 1.0.1

* Fix `Call to a member function debug() on null` logger (see [#270](https://github.com/KnpLabs/snappy/pull/270))

## 1.0.0

* Don't check if it's a file when the path is bigger than `PHP_MAXPATHLEN` (see [#224](https://github.com/KnpLabs/snappy/pull/224))
* Pass `image-dpi` and `image-quality` options as integer (see [#251](https://github.com/KnpLabs/snappy/pull/251))
* Improve documentation readability (see [#255](https://github.com/KnpLabs/snappy/pull/255))
* Add logging capabilities to generators (see [#264](https://github.com/KnpLabs/snappy/pull/264))
* Add some more frequent questions/issues to the FAQ (see [#263](https://github.com/KnpLabs/snappy/pull/263), [#265](https://github.com/KnpLabs/snappy/pull/265), [#266](https://github.com/KnpLabs/snappy/pull/266))

Credits go to: @wouterbulten, @martinssipenko, @Herz3h, @akovalyov, @NiR-.
