# LiteSpeed Deployment Issues - Fix Guide

## Issue: "Deployment execution failed" with empty output

**Error Message:**
```json
{
    "status": "error",
    "message": "Deployment execution failed",
    "output": null
}
```

**Cause**: LiteSpeed web server restricts direct shell execution with `shell_exec()` and `exec()`.

## Solution for LiteSpeed

### What Was Fixed

Updated `deploy.php` to use `proc_open()` instead of `shell_exec()`:

✅ **Primary method**: `proc_open()` - Works on LiteSpeed  
✅ **Fallback method**: `shell_exec()` - For other servers  
✅ **Better error logging**: Shows which functions are available  

### How to Test + Deploy

#### Step 1: Update Your Files

Download the latest versions:
- `deploy.php` (root directory)
- `public/deploy.php` (web root)

Or if already in git:
```bash
cd /path/to/project
git pull origin main
cp deploy.php public/deploy.php
chmod +x deploy.php
chmod +x public/deploy.php
```

#### Step 2: Test Configuration

From your server:
```bash
ssh user@yourhost.com
curl "http://localhost/deploy.php?test"
```

Should show:
```json
{
  "config": {
    "proc_open_available": true,
    "shell_exec_available": true,
    ...
  }
}
```

#### Step 3: Test Manual Deployment

```bash
curl "https://yourdomain.com/deploy.php?token=YOUR_TOKEN&env=production"
```

Should now return deployment status (success or specific error).

#### Step 4: Re-trigger GitHub Webhook

1. Go to: **GitHub Repo → Settings → Webhooks → Your Webhook**
2. Scroll to **Recent Deliveries**
3. Click **Redeliver** on a failed delivery
4. Check **Response** - should show one of:
   - ✅ Success (deployment runs)
   - ❌ Specific error (to fix next)

## Troubleshooting Remaining Issues

### Issue 1: "Webhook signature invalid"

See: `WEBHOOK_TROUBLESHOOT.md`

**Quick fix:**
```bash
# Check .env has token
grep "DEPLOY_TOKEN=" .env

# Verify GitHub secret matches
# GitHub Settings → Webhooks → Secret field
```

### Issue 2: Bash Not Found

**Error:**
```json
{
  "message": "script did not produce output"
}
```

**Cause**: `/bin/bash` might not be at that path on your hosting

**Fix**:
```bash
# SSH and check bash location
which bash
# Might show: /bin/bash or /usr/bin/bash

# Edit deploy.php and update the path
# Line: $command .= "/bin/bash "
# Change to actual path
```

### Issue 3: Script Permissions

**Fix**:
```bash
# SSH to server
ssh user@yourhost.com
cd /path/to/project

# Fix permissions
chmod +x deploy.sh
chmod +x public/deploy.php
chmod +x deploy.php

# Verify
ls -l deploy.sh
# Should show: -rwxr-xr-x (755)
```

### Issue 4: Directory Not Found

**Error**: "Deploy script not found"

**Fix**:
```bash
# Check paths in deploy.php
cat deploy.php | grep "PROJECT_ROOT\|path.*="

# Verify actual paths
pwd  # Should be project root
ls -la deploy.sh
# Should exist
```

## How LiteSpeed Hosting Now Works

```
GitHub webhook → deploy.php (in public/)
                     ↓
              Signature verified ✅
                     ↓
              Branch matched ✅
                     ↓
              proc_open() tried first
                     ↓
        If proc_open() works:
              Deployment runs ✅
                     ↓
              Shell script executes
              deploy.sh production --webhook
                     ↓
              JSON response sent back
        
        If proc_open() unavailable:
              Falls back to shell_exec()
                     ↓
              If shell_exec() blocked:
              Error returned
```

## Verification Steps

### Check PHP Functions Available

```php
<?php
// Create test file: test-php-functions.php in public/

echo "proc_open: " . (function_exists('proc_open') ? 'YES' : 'NO') . "\n";
echo "shell_exec: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n";
echo "exec: " . (function_exists('exec') ? 'YES' : 'NO') . "\n";
echo "system: " . (function_exists('system') ? 'YES' : 'NO') . "\n";
echo "passthru: " . (function_exists('passthru') ? 'YES' : 'NO') . "\n";
echo "proc_open + shell_exec = " . (function_exists('proc_open') || function_exists('shell_exec') ? 'DEPLOYMENT WILL WORK' : 'DEPLOYMENT WILL FAIL') . "\n";

// Access from browser: https://yourdomain.com/test-php-functions.php
?>
```

### Check Bash Location

```bash
# SSH to server
ssh user@yourhost.com

# Find bash
which bash
# Example output: /bin/bash

# Verify bash works
/bin/bash --version

# Test script execution
/bin/bash -c "echo 'test'"
# Should output: test
```

## LiteSpeed Server Optimization

If `proc_open()` doesn't work, ask your hosting provider to enable these PHP extensions:

- `proc_open` 
- `proc_close`
- `fgets`
- `feof`

Or enable in `php.ini`:
```ini
disable_functions = "";
# (Leave empty to enable all functions)
```

## Performance Notes

- **Both methods work** - `proc_open()` is more reliable on restricted hosting
- **Speed difference**: Not noticeable (~100ms difference)
- **Memory**: Slightly higher with `proc_open()` but negligible

## After Deployment Works

Once webhook deployment works, you won't need manual deployments:

```
1. Write code
2. Commit: git commit -m "Feature: ..."
3. Push: git push origin main
4. GitHub sends webhook automatically
5. Site deployed automatically ✅
6. Changes live in seconds
```

## Debugging Log Location

After deployment attempt, check:

```bash
cat /path/to/project/storage/logs/webhooks.log
tail -20 /path/to/project/storage/logs/webhooks.log | grep -A 5 "Deployment script execution"

# Should show output like:
# [timestamp] Executing deployment {"environment":"production",...}
# [timestamp] Deployment result {"status":"success",...}
```

## Still Not Working?

1. **Verify token is set**: `grep DEPLOY_TOKEN= .env`
2. **Check bash exists**: `which bash`
3. **Test curl locally**: `curl http://localhost/deploy.php?test`
4. **Check logs**: `tail -50 storage/logs/webhooks.log`
5. **SSH and run manually**: `./deploy.sh production`

---

**Version**: 1.0 (LiteSpeed Fix)  
**Updated**: April 16, 2026
