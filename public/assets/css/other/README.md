# CSS Files - Purpose & Usage

## Active Files

### bootstrap.min.css
- **Purpose**: Bootstrap 5.3 framework (loaded via CDN in layout.twig)
- **Status**: ✅ Active in main layout
- **Used By**: All authenticated pages via layout.twig

### bootstrap.css (unminified)
- **Purpose**: Unminified Bootstrap for development/debugging
- **Status**: ⚠️ Development only (not loaded in production)

### font-awesome.min.css & font-awesome.css
- **Purpose**: Font Awesome icon library (v6.x)
- **Status**: ✅ Active (font-awesome.min.css loaded in public.twig)
- **Used By**: Public-facing pages

### combined.public.css
- **Purpose**: Custom CSS for public pages (scrollbar styling, Google fonts, custom utilities)
- **Status**: ✅ Active in public.twig
- **Used By**: Public-facing pages only
- **Note**: Contains custom scrollbar styles and custom utility classes

## Deprecated/Removed Files

### ❌ bootstrap-v3.1.0.min.css (DELETED)
- Old Bootstrap 3.1.0 version
- Was referenced in public.twig but conflicted with Bootstrap 5.3
- Removed: 2026-06-13

### ❌ combined.css (DELETED)
- Legacy concatenated CSS file
- No references found in active codebase
- Removed: 2026-06-13

## Backup Directory

### /backup/
Contains backups of legacy CSS files:
- `combined.css` - backup copy
- `main.css` - backup copy  
- `obd.css` - backup copy
- `style.css` - backup copy

**Status**: Can be safely deleted or moved outside public folder if backups are not needed.

## Recommendations

1. ✅ **Done**: Removed bootstrap-v3.1.0.min.css from public.twig
2. ✅ **Done**: Removed unused combined.css
3. ⚠️ **TODO**: Consider moving /backup/ outside public folder to reduce web-accessible files
4. 📝 **Note**: combined.public.css should be reviewed for consolidation with main styles.css if desired

## File Load Order

### Main Layout (layout.twig)
1. Bootstrap 5.3 (CDN)
2. Font Awesome 6.x (CDN)
3. vs-datepicker.css
4. Bootstrap Icons (CDN)
5. styles.css (custom)
6. navbar.css (navigation)
7. Inline style block

### Public Pages (public.twig)
1. bootstrap.min.css
2. font-awesome.min.css
3. font-awesome pro v5.10 (CDN)
4. combined.public.css (custom public styles)
