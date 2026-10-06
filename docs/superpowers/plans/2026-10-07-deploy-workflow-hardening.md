# Deploy Workflow Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adopt the improved shared-hosting workflow with reliable checks, safer deployment, and reproducible Composer dependencies.

**Architecture:** Adapt the user-provided workflow for this repository rather than copying it blindly. Preserve the selected environment strategy: create `.env` from the GitHub secret on the runner, never upload it, and leave the server's existing `.env` untouched. Track the Composer lock file so CI and deploy use the same dependency versions.

**Tech Stack:** GitHub Actions YAML, Bash, Composer, SamKirkland FTP-Deploy-Action.

**Spec:** User-provided workflow in chat (2026-10-07), with the user's confirmed choice to preserve the server `.env`.

## Global Constraints

- Push deployments run on `main`; manual deployments offer `production` and `staging`.
- Do not upload `.env`, `.env.*`, or credential JSON files.
- Do not delete or overwrite the server's existing `.env`.
- Use the committed `composer.lock` for dependency installation and caching.
- Keep SSH post-deploy checks optional when `SSH_HOST` is unset.

## Review Focus

- `.env.example` is committed and must not cause the accidental-secret check to fail; test it as an allowed file.
- `.env.production` and `.env.local` must be caught and ignored; test representative secret-like names.
- The generated runner `.env` must not be uploaded or delete the server copy; verify the FTP exclusions and non-clean-slate setting.
- A vendor package's `.github` metadata must not trigger an FTP 553; verify the nested vendor exclusion.
- Missing FTP secrets must fail before upload, while missing optional SSH configuration must skip health checks.

---

### Task 1: Track stable dependencies and ignore local environment files

**Files:**
- Modify: `.gitignore`
- Add to version control: `composer.lock`

**Interfaces:**
- Produces a tracked Composer lock file consumed by both CI `composer install` steps.
- Preserves `.env.example` as the committed template while ignoring local `.env` variants.

- [ ] **Step 1: Update environment and lock-file ignore rules**

Ignore `.env` and `.env.*`, then explicitly allow `.env.example`. Remove `composer.lock` from the ignored dependency entries.

- [ ] **Step 2: Verify the ignore rules**

Run: `git check-ignore -v .env .env.local .env.production composer.lock`

Expected: environment files are ignored; `composer.lock` is not ignored; `.env.example` remains trackable.

- [ ] **Step 3: Validate the committed dependency metadata**

Run: `composer validate --strict`

Expected: `./composer.json is valid`.

### Task 2: Replace the deployment workflow with the hardened version

**Files:**
- Modify: `.github/workflows/deploy.yml`

**Interfaces:**
- Consumes the tracked `composer.lock` and repository GitHub Actions secrets.
- Produces a test-then-deploy workflow with optional SSH health checks.

- [ ] **Step 1: Configure workflow triggers, permissions, and concurrency**

Use `main` pushes, the existing production/staging manual input, read-only repository permissions, and one non-cancelling concurrency group per environment.

- [ ] **Step 2: Add deterministic test and pre-deploy checks**

Validate Composer strictly; install from the lock file; lint PHP files while excluding vendor, storage, and node_modules; detect debug helpers without flagging legitimate `die()` calls or excluded diagnostic scripts; reject accidental `.env` files while exempting `.env.example`; and report the working-tree status.

- [ ] **Step 3: Prepare deployment files without changing server environment**

Install production dependencies from the lock file. Create the runner `.env` from `secrets.ENV_FILE`, exclude it from transfer, and do not enable clean-slate deletion. Remove only development artifacts and local logs/caches from the runner deployment tree.

- [ ] **Step 4: Validate deployment configuration and upload**

Fail before upload if required FTP secrets are missing. Keep the repository-specific credential exclusions and exclude nested vendor `.github`, VCS metadata, tests, and development documentation to prevent the previously observed FTP 553.

- [ ] **Step 5: Retain optional remote health checks and useful outcome reporting**

When `SSH_HOST` is set, check PHP, Composer autoloading, Firebase configuration when present, and writable directories; otherwise skip SSH. Report deployment success or failure with environment, branch, and commit.

- [ ] **Step 6: Validate the workflow and deployment configuration**

Run `composer validate --strict`, `git diff --check`, the editor's YAML diagnostics, and targeted checks that `.env.example` passes, `.env.production` is rejected, and vendor `.github` paths are excluded.
