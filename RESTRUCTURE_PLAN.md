# Project Restructure Plan

Goal: Reorganize into clean, standard production-ready PHP project structure while keeping:
- Project root unchanged
- Web root (public/) unchanged as entry point
- All functionality intact

## Proposed Structure
```
app/
├── Core/           # Core classes (Router, etc.)
├── Http/
│   └── Controllers/
├── Models/
├── Services/       # From modules/Services
├── Helpers/
├── Support/        # Utility classes
└── Exceptions/

bootstrap/
├── app.php         # Application bootstrap
└── autoload.php

config/             # Configuration files (keep as-is but organized)

public/             # Web root - UNCHANGED
├── index.php
├── .htaccess
└── assets/

resources/
└── views/          # From templates/

routes/             # Route definitions

storage/            # Logs, cache, uploads - UNCHANGED
├── logs/
├── cache/
└── ...

tests/              # Tests - UNCHANGED
vendor/             # Dependencies - UNCHANGED
```

## Migration Steps
1. Add PSR-4 autoloading to composer.json
2. Create new directory structure
3. Move files to appropriate locations
4. Update all namespaces and use statements
5. Update autoloading logic
6. Update all require/include paths
7. Update configuration
8. Test thoroughly
9. Update documentation

## Files to Move
- classes/Router.php → app/Core/Router.php
- controllers/* → app/Http/Controllers/
- models/* (except maybe restructure) → app/Models/
- modules/Services/* → app/Services/
- helpers/* → app/Helpers/
- templates/* → resources/views/

But need to handle carefully to avoid breaking things.

## Status — completed (2026-10-26)

Applied to this project; structure verified with lint, PHPUnit and HTTP smoke tests.

| Plan step | Result |
|---|---|
| 1. PSR-4 autoloading | Kept global class names (no `namespace`) and used a composer **classmap** (`"autoload": { "classmap": ["app/"] }`) plus the app autoloader in `bootstrap/autoload.php`. Full PSR-4 would have required `use` imports across every call site. |
| 2–3. Create structure, move files | `classes/Router.php` → `app/Core/Router.php`, `controllers/*` → `app/Http/Controllers/*`, `models/*` → `app/Models/*`, `modules/Services/*` → `app/Services/*`, `helpers/*` → `app/Helpers/*`, `templates/*` → `resources/views/*`, `config/routes.php` → `routes/web.php`. |
| 4–5. Autoloading + require paths | `public/index.php` is now error-setup only; `bootstrap/autoload.php` (composer, app autoloader, helpers, config) and `bootstrap/app.php` (router, routes, dispatch) do the rest. Every `__DIR__`-relative path was re-resolved. |
| 6. Configuration | `config/autoload.php` removed (superseded by `bootstrap/autoload.php`); `public/` and `config/` untouched otherwise. |
| 7. Test | `php -l` clean on 132 files; PHPUnit 25/25 passing (5 skipped — need `TEST_DB_HOST`); `/`, `/login`, `/member`, `/chairman`, `/secretary`, `/register` → 200, `/dashboard`, `/applications` → 302 (auth), error log empty. |
| 8. Documentation | README, DEPLOYMENT and QUICK_START structures updated (this section added). |
