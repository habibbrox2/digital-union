# Deployment Troubleshooting Guide

## Current Error: "Not a Git Repository"

### Error Message
```
fatal: not a git repository (or any parent up to mount point /)
Stopping at filesystem boundary (GIT_DISCOVERY_ACROSS_FILESYSTEM not set).
```

### What This Means
The deployment script cannot find the `.git` directory when running from the web server context.

---

## Step 1: Diagnose Your Deployment Setup

### Run the Diagnostic Test

Access this URL from your server (using SSH terminal or local machine):

```
https://lgdhaka.co/deploy.php?test
```

**OR** if you have SSH access, test locally:
```bash
curl https://lgdhaka.co/deploy.php?test
```

This will return JSON with all your deployment configuration. Look for:

```json
{
  "git": {
    "project_root": "...",
    "git_directory": "...",
    "git_exists": true/false,
    "deploy_script_exists": true/false
  }
}
```

### What to Look For

| Field | Expected | Issue |
|-------|----------|-------|
| `git_exists` | `true` | ❌ `.git` directory not found at expected location |
| `deploy_script_exists` | `true` | ❌ `deploy.sh` script not found |
| `git_readable` | `true` | ❌ Permission issue accessing `.git` |
| `parent_git_exists` | Should check parent | ⚠️ `.git` may be one level up |

---

## Step 2: SSH Into Your Server and Check Paths

```bash
ssh tdhuedhn@65.21.174.100
```

### Find Your Project Root

From your server, check where the project is located:

```bash
# List the home directory
ls -la /home/tdhuedhn/

# Check if lgdhaka directory has .git
ls -la /home/tdhuedhn/lgdhaka/.git

# Check if ht_docs directory has .git  
ls -la /home/tdhuedhn/lgdhaka/ht_docs/.git

# Check where the web files are
ls -la /home/tdhuedhn/lgdhaka/ht_docs/public/deploy.php
```

### Determine Your True Project Root

Run this command to find all `.git` directories:

```bash
find /home/tdhuedhn -name ".git" -type d 2>/dev/null
```

This will show you the actual project root(s).

---

## Step 3: Verify Git Repository Structure

Once you've identified the project root, verify the structure:

```bash
# Navigate to project root (replace with correct path)
cd /home/tdhuedhn/lgdhaka/ht_docs

# Verify git exists
ls -la .git

# Check git status
git status

# Check remote
git remote -v

# Check current branch
git branch -a
```

### Expected Output

```bash
$ git status
On branch main
Your branch is up to date with 'origin/main'.

$ git remote -v
origin  https://github.com/YOUR-REPO/lgdhaka.git (fetch)
origin  https://github.com/YOUR-REPO/lgdhaka.git (push)
```

---

## Step 4: Fix Permission Issues

If `.git` exists but is not readable:

```bash
# Check current permissions
ls -ld /home/tdhuedhn/lgdhaka/ht_docs/.git

# Fix if needed (add read/execute permissions)
chmod -R u+rx /home/tdhuedhn/lgdhaka/ht_docs/.git

# Make sure web server user can read (if needed)
chmod -R o+rx /home/tdhuedhn/lgdhaka/ht_docs/.git
```

---

## Step 5: Verify deploy.sh is Executable

```bash
# Check permissions on deploy.sh
ls -la /home/tdhuedhn/lgdhaka/ht_docs/deploy.sh

# Should show: -rwxr-xr-x (755 permissions)

# If not executable, fix it:
chmod +x /home/tdhuedhn/lgdhaka/ht_docs/deploy.sh
```

---

## Step 6: Update Your Directory Path (If Needed)

If your actual project root differs from what deploy.php expects:

### Option A: Update deploy.php Directly

Edit your `public/deploy.php` and find this section:

```php
// Determine project root by finding git repository
define('PROJECT_ROOT', find_git_root());
```

If auto-detection isn't working, you can hardcode the path:

```php
// Manually set the project root (replace with your actual path)
define('PROJECT_ROOT', '/home/tdhuedhn/lgdhaka/ht_docs');
```

### Option B: Create Symlink (If `.git` is in Parent)

If your structure is:
```
/home/tdhuedhn/lgdhaka/.git              ← Git root here
/home/tdhuedhn/lgdhaka/ht_docs/public/   ← Web root here
```

Create a symlink in the ht_docs directory:

```bash
cd /home/tdhuedhn/lgdhaka/ht_docs
ln -s ../.git .git
```

---

## Step 7: Test Manual Deployment

Once paths are fixed, test the deployment manually:

```bash
# SSH to server
ssh tdhuedhn@65.21.174.100

# Navigate to project root
cd /home/tdhuedhn/lgdhaka/ht_docs

# Run deploy script manually
bash ./deploy.sh production
```

Watch for errors. Common issues:

```bash
error: could not lock config file .git/config
# → Permission issue with .git directory

fatal: not a git repository
# → .git directory not found or not accessible
```

---

## Step 8: Test Webhook Deployment

Once manual deployment works, test via webhook:

```bash
# From your local machine or server
curl -X POST https://lgdhaka.co/deploy.php?token=YOUR_TOKEN_HERE

# Or test with specific environment
curl -X POST https://lgdhaka.co/deploy.php?token=YOUR_TOKEN_HERE&env=production
```

You should see JSON response:

```json
{
  "status": "success",
  "message": "Deployment to production completed successfully",
  "data": {
    "status": "success",
    "environment": "production",
    ...
  }
}
```

---

## Step 9: Common Issues & Solutions

### Issue: "Project root path may be incorrect"

**Cause:** `.git` directory not found at expected location

**Solution:**
1. Run diagnostic test: `https://lgdhaka.co/deploy.php?test`
2. Check actual project root on server: `find /home/tdhuedhn -name ".git" -type d`
3. Update PROJECT_ROOT in deploy.php to correct path

### Issue: "Deploy script not found"

**Cause:** `deploy.sh` doesn't exist at expected location

**Solution:**
1. SSH to server
2. Navigate to project root: `cd /home/tdhuedhn/lgdhaka/ht_docs`
3. Verify script exists: `ls -la deploy.sh`
4. If in wrong location, move it: `mv deploy.sh ./deploy.sh`

### Issue: "Permission denied"

**Cause:** Web server user doesn't have read/execute permissions

**Solution:**
```bash
# Fix .git directory permissions
chmod -R u+rx /home/tdhuedhn/lgdhaka/ht_docs/.git

# Fix deploy.sh permissions
chmod +x /home/tdhuedhn/lgdhaka/ht_docs/deploy.sh

# Fix storage permissions
chmod -R 775 /home/tdhuedhn/lgdhaka/ht_docs/storage/
```

### Issue: "Deployment execution failed"

**Cause:** Bash not available or PHP function restrictions

**Solution:**
1. Check PHP functions available: `https://lgdhaka.co/deploy.php?test` (look for `proc_open_available`, `shell_exec_available`)
2. Verify bash is installed: `which bash` on server
3. Contact hosting if bash is disabled

---

## Step 10: Verify .env Configuration

The deployment token and database credentials are in your `.env` file. Verify it's in the project root:

```bash
# Check .env exists
ls -la /home/tdhuedhn/lgdhaka/ht_docs/.env

# Check DEPLOY_TOKEN is set
grep DEPLOY_TOKEN /home/tdhuedhn/lgdhaka/ht_docs/.env

# Should output something like:
# DEPLOY_TOKEN=9qnTd3.9ApPC5
```

The deploy.php automatically reads this file to get the token and database credentials.

---

## Step 11: Check Webhook Logs

After fixing the issue, check if deployments are working:

```bash
# SSH to server
ssh tdhuedhn@65.21.174.100

# View recent webhook logs
tail -50 /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/webhooks.log

# View recent deployment logs  
ls -ltr /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/deploy_*.log | tail -5
tail -50 /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/deploy_*.log
```

---

## Complete Checklist

- [ ] Ran diagnostic test (`?test` endpoint)
- [ ] SSH'd to server and found `.git` directory location
- [ ] Verified git repository status (`git status`)
- [ ] Checked `.git` permissions are readable
- [ ] Verified `deploy.sh` is executable
- [ ] Updated PROJECT_ROOT if needed
- [ ] Tested manual deployment with `bash deploy.sh production`
- [ ] Tested webhook deployment with curl
- [ ] Verified `.env` file exists with DEPLOY_TOKEN
- [ ] Checked webhook/deployment logs

---

## Still Having Issues?

Check these files for clues:

1. **Webhook Log**: `/home/tdhuedhn/lgdhaka/ht_docs/storage/logs/webhooks.log`
2. **Latest Deployment Log**: `/home/tdhuedhn/lgdhaka/ht_docs/storage/logs/latest-deployment.json`
3. **Server Error Log**: Contact hosting for web server error logs
4. **PHP Error Log**: Check via hosting control panel

---

## Configuration Summary

Your deployment configuration:

| Item | Value |
|------|-------|
| **Domain** | https://lgdhaka.co |
| **Webhook URL** | https://lgdhaka.co/deploy.php |
| **Server IP** | 65.21.174.100 |
| **SSH Username** | tdhuedhn |
| **Path to deploy.php** | /home/tdhuedhn/lgdhaka/ht_docs/public/deploy.php |
| **Expected Project Root** | /home/tdhuedhn/lgdhaka/ht_docs |
| **Environment Token** | 9qnTd3.9ApPC5 (from password field) |

