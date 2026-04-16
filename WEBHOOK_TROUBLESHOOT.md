# Webhook Signature Invalid - Troubleshooting Guide

## Error Message
```json
{
    "status": "error",
    "message": "Webhook signature invalid",
    "timestamp": "2026-04-16 17:45:43"
}
```

## Common Causes

### 1. ❌ DEPLOY_TOKEN Not Set in .env

**Symptom**: Getting "Webhook signature invalid"

**Check**:
```bash
# SSH to your server
cat /path/to/project/.env | grep DEPLOY_TOKEN
```

**Should show**:
```
DEPLOY_TOKEN=your-random-secure-token-here
```

**If missing or empty**:
```bash
# Generate new random token
openssl rand -hex 32

# Edit .env
nano /path/to/project/.env

# Add line (replacing with your token):
DEPLOY_TOKEN=abc1234def5678...
```

### 2. ❌ GitHub Webhook Secret Doesn't Match .env Token

**Symptom**: Token is set in .env, but webhook still fails

**Check GitHub**:
1. Go to your repo: Settings → Webhooks
2. Click on your webhook URL
3. Click "Recent Deliveries" tab
4. Find the failed delivery
5. Click "Response" tab
6. Look for the error message

**Fix**:
1. Open your `.env` file and copy `DEPLOY_TOKEN` value exactly:
   ```bash
   cat /path/to/project/.env | grep DEPLOY_TOKEN
   ```

2. Go to GitHub: Settings → Webhooks → Your webhook

3. Edit the webhook:
   - Scroll to "Secret" field
   - Paste your exact `DEPLOY_TOKEN` value
   - Click "Update webhook"

4. Redeliver the webhook test

### 3. ❌ .env File Not in Correct Location

**Symptom**: "Webhook signature invalid" even with correct secret

**Check**:
```bash
# .env must be in PROJECT ROOT (not in public/)
ls -la /path/to/project/.env
# Should show file exists

# NOT in public folder
ls -la /path/to/project/public/.env
# Should show: No such file
```

**Fix**:
```bash
# Move .env to project root
mv /path/to/project/public/.env /path/to/project/.env

# Verify
cat /path/to/project/.env
```

## Testing Your Configuration

### Method 1: Test Endpoint (Localhost Only)

From your server, test the configuration:

```bash
# SSH to server
ssh user@yourhost.com

# Test from server (localhost)
curl "http://localhost/deploy.php?test"
```

**Expected response**:
```json
{
  "status": "success",
  "message": "Configuration test",
  "config": {
    "token_length": 64,
    "token_first_chars": "abc1234d...",
    "token_empty": false,
    "project_root": "/home/username/digital-union",
    "env_file_exists": true,
    "env_vars_loaded": 8,
    "php_version": "8.2.12"
  }
}
```

**If `token_empty` is `true`**:
- The .env file is not being read
- Check that .env exists in project root
- Verify .env file has correct `DEPLOY_TOKEN=...` line

### Method 2: Manual Token Test

Test that your token works:

```bash
# From your local machine (NOT the server)
curl "https://yourdomain.com/deploy.php?token=PASTE_YOUR_TOKEN_HERE&env=production"
```

Should return (no error about token):
```json
{
  "status": "success",
  "message": "Deployment completed successfully",
  ...
}
```

or some other deployment status, NOT "Invalid or missing deployment token"

### Method 3: Check Webhook Logs

```bash
# SSH to server
tail -50 /path/to/project/storage/logs/webhooks.log
```

Look for lines like:
```
[2026-04-16 17:45:43] [140.82.112.45] Request received
[2026-04-16 17:45:43] [140.82.112.45] Signature verification failed
[2026-04-16 17:45:43] [140.82.112.45] Deployment result {"status":"error"...}
```

## Step-by-Step Fix

### Step 1: Verify .env File

```bash
ssh user@yourhost.com
cd /path/to/project

# Check if .env exists
test -f .env && echo ".env exists" || echo ".env MISSING"

# Check if DEPLOY_TOKEN is set
grep "DEPLOY_TOKEN=" .env || echo "DEPLOY_TOKEN not found"

# See the value (first 10 chars)
grep "DEPLOY_TOKEN=" .env | cut -d= -f2 | head -c 10
```

### Step 2: Generate New Token

```bash
# Generate strong random token
NEW_TOKEN=$(openssl rand -hex 32)
echo "New token: $NEW_TOKEN"
```

### Step 3: Update .env

```bash
# Backup current .env
cp .env .env.backup

# Update DEPLOY_TOKEN
# Option A: Using sed (Linux/Mac)
sed -i "s/DEPLOY_TOKEN=.*/DEPLOY_TOKEN=$NEW_TOKEN/" .env

# Option B: Manual edit
nano .env
# Find DEPLOY_TOKEN line and replace value

# Verify change
grep "DEPLOY_TOKEN=" .env
```

### Step 4: Update GitHub Webhook Secret

1. Go to GitHub → Repository Settings → Webhooks
2. Click on your webhook
3. Scroll to Secret field
4. Clear it: `<Ctrl>+A` then `Delete`
5. Paste new token: `$NEW_TOKEN` value
6. Scroll down
7. Click **Update webhook**

### Step 5: Test Webhook

1. Still in GitHub webhook settings
2. Scroll down to "Recent Deliveries"
3. Click the "Redeliver" button on the latest failed delivery
4. Check the Response tab

**Success response**:
```json
{
  "status": "success",
  "message": "Deployment completed successfully",
  "environment": "production",
  "commit": "abc1234",
  "steps": [...]
}
```

## Webhook Secret Mismatch Examples

| Scenario | GitHub Secret | .env DEPLOY_TOKEN | Result |
|----------|---------------|------------------|--------|
| ✅ Correct | `abc123def456` | `abc123def456` | ✅ Works |
| ❌ Wrong | `abc123def456` | `xyz789` | ❌ Invalid signature |
| ❌ Empty | (empty field) | `abc123def456` | ❌ No signature sent |
| ❌ Typo | `abc123def456` | `abc123def45` | ❌ Invalid signature |

## Advanced Debugging

### See Actual Signature Computation

```bash
# Create test file with your token and payload
echo -n "your_payload_here" > payload.txt
DEPLOY_TOKEN="your-token-here"

# Compute expected signature
echo -n "$(cat payload.txt)" | openssl dgst -sha256 -hmac "$DEPLOY_TOKEN" -hex
# Output: sha256=abc123def456...

# This should match GitHub's X-Hub-Signature-256 header
```

### Enable Detailed Logging

Edit `public/deploy.php` and find the webhook verification section. Add this:

```php
// Add after signature verification fails
log_webhook("Debug signature info", [
    'signature_received' => $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? 'none',
    'token_length' => strlen(DEPLOY_TOKEN),
    'payload_length' => strlen(file_get_contents('php://input')),
]);
```

Then check logs:
```bash
tail -100 /path/to/project/storage/logs/webhooks.log | grep "Debug signature"
```

## Common Mistakes

### ❌ Mistake 1: Token in Single Quotes
```bash
# Wrong - includes the quotes
DEPLOY_TOKEN='abc123def456'
# This becomes: 'abc123def456' (with quotes)

# Correct
DEPLOY_TOKEN=abc123def456
```

### ❌ Mistake 2: Token with Special Characters Not Escaped
```bash
# Wrong (if token has special chars)
DEPLOY_TOKEN=abc123!def&456

# Correct (escape or use quotes without wrapping)
DEPLOY_TOKEN=abc123\!def\&456
```

### ❌ Mistake 3: .env in Public Folder
```bash
# Wrong
/path/to/project/public/.env

# Correct
/path/to/project/.env
```

### ❌ Mistake 4: GitHub Secret Copy-Pasted with Extra Spaces
```bash
# In GitHub, secret might show: "abc123def456 "
# With trailing space

# Extract correctly (no spaces)
DEPLOY_TOKEN=abc123def456
```

## Manual Verification Checklist

- [ ] `.env` file exists in project root (not in public/)
- [ ] `DEPLOY_TOKEN` line exists in `.env`
- [ ] Token value is not empty
- [ ] Token is exactly 64 hex characters (from `openssl rand -hex 32`)
- [ ] GitHub webhook secret field has same value as `.env` DEPLOY_TOKEN
- [ ] Test endpoint returns `token_empty: false`
- [ ] Webhook files have correct permissions:
  ```bash
  ls -la deploy.php public/deploy.php
  # Should show: -rw-r--r-- (at least readable)
  ```
- [ ] Project root is correct:
  ```bash
  cat /path/to/project/.env | grep DB_
  # Should show database configuration
  ```

## Still Having Issues?

### Check These Files

```bash
# 1. Verify deploy.php is in public
ls -la public/deploy.php

# 2. Check .env permissions
ls -la .env
# Should be readable by web server

# 3. Check log permissions
ls -la storage/logs/
# Should be writable: drwxr-xr-x

# 4. Verify git pull works
git pull origin main

# 5. Test composer
composer --version
```

### Quick Debug Script

```bash
#!/bin/bash
cd /path/to/project

echo "=== .env Check ==="
test -f .env && echo "✅ .env exists" || echo "❌ .env missing"
grep "DEPLOY_TOKEN=" .env && echo "✅ DEPLOY_TOKEN found" || echo "❌ DEPLOY_TOKEN missing"

echo ""
echo "=== File Permissions ==="
ls -l deploy.php public/deploy.php .env

echo ""
echo "=== GitHub IP Allowed ==="
# GitHub uses these IP ranges - verify firewall allows them
echo "GitHub ranges: 140.82.112.0/20, 143.55.64.0/20, 192.30.252.0/22, 185.199.108.0/22"

echo ""
echo "=== Test Endpoint ==="
curl -s http://localhost/deploy.php?test | grep -q "token_empty" && echo "✅ Test endpoint works" || echo "❌ Test endpoint failed"

echo ""
echo "=== Recent Logs ==="
tail -5 storage/logs/webhooks.log
```

---

**Version**: 1.0  
**Last Updated**: April 16, 2026
