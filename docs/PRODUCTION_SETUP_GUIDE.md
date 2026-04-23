# Production Setup - Step by Step (Bangla & English)

## 🔧 সেটআপ করার তিনটি উপায়

### Option 1: Quick Automated Setup (সবচেয়ে সহজ)

আপনার local machine এ PowerShell বা Terminal খুলুন:

```powershell
# Windows PowerShell
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash production-setup.sh
```

```bash
# Mac/Linux Terminal
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash production-setup.sh
```

**Output:** System check রিপোর্ট পাবেন

---

### Option 2: Auto-Fix All Issues (সবকিছু ঠিক করবে)

```bash
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash production-setup.sh --fix
```

**Output:** সব issues ঠিক করবে automatically

---

### Option 3: Full Test Including Deployment

```bash
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash production-setup.sh --fix --test
```

**Output:** সব কিছু চেক করবে + deployment test করবে

---

## 📋 Manual Verification (যদি স্ক্রিপ্ট ব্যবহার না করেন)

### Step 1: Git Verify করুন
```bash
ssh tdhuedhn@65.21.174.100

# Check .git exists
ls -la /home/tdhuedhn/lgdhaka/ht_docs/.git

# Check git status
cd /home/tdhuedhn/lgdhaka/ht_docs
git status
```

**Expected Output:**
```
On branch main
Your branch is up to date with 'origin/main'.
nothing to commit, working tree clean
```

### Step 2: Permissions Fix করুন
```bash
cd /home/tdhuedhn/lgdhaka/ht_docs

# Make deploy.sh executable
chmod +x deploy.sh

# Fix .git permissions
chmod -R u+rx .git

# Fix storage permissions
chmod -R 775 storage/

# Fix public directory
chmod 755 public/
```

### Step 3: Verify deploy.php exists
```bash
ls -la public/deploy.php
```

**Expected:** deploy.php ফাইল দেখতে পাবেন

### Step 4: Check .env file
```bash
# Verify .env exists
ls -la .env

# Check DEPLOY_TOKEN
grep DEPLOY_TOKEN .env
```

### Step 5: Test PHP
```bash
php -v
php -r "echo 'PHP Works';"
```

### Step 6: Test Database
```bash
# Check if mysqldump is available
which mysqldump

# Or test with mysql
mysql -h localhost -u root -p -e "SELECT 1;"
```

---

## ✅ Production Ready Checklist

আপনার system production ready তখনই হবে যখন এই সবগুলি ✅:

- [ ] `.git` directory exists and is readable
- [ ] `deploy.sh` is executable
- [ ] `public/deploy.php` exists  
- [ ] `storage/` directory is writable
- [ ] `.env` file exists with proper configuration
- [ ] `DEPLOY_TOKEN` is set in `.env`
- [ ] PHP is installed and working
- [ ] Database credentials are valid
- [ ] No security issues (`.env` not in public, `.git` not in public)

---

## 🚀 পরে করার কাজ

### 1. Webhook Setup (GitHub/GitLab)

GitHub এ গিয়ে Settings > Webhooks এ যান:

```
Webhook URL: https://lgdhaka.co/deploy.php
Content type: application/json
Secret: (আপনার DEPLOY_TOKEN from .env)
Events: push, pull_request
```

### 2. Test Webhook

```bash
curl -X POST https://lgdhaka.co/deploy.php?token=YOUR_DEPLOY_TOKEN
```

### 3. Monitor Logs

```bash
# Real-time deployment logs
tail -f /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/deploy_*.log

# Webhook logs
tail -f /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/webhooks.log
```

---

## 🐛 Troubleshooting

### সমস্যা: "Not a git repository"

**সমাধান:**
```bash
cd /home/tdhuedhn/lgdhaka/ht_docs

# Find where .git actually is
find ~ -name ".git" -type d 2>/dev/null

# If it's in parent directory
ln -s ../.git .git
```

### সমস্যা: "Permission denied"

**সমাধান:**
```bash
cd /home/tdhuedhn/lgdhaka/ht_docs
chmod +x deploy.sh
chmod -R u+rx .git
chmod -R 775 storage/
```

### সমস্যা: "Command not found: php"

**সমাধান:**
```bash
# Check PHP path
which php

# If not found, ask hosting provider to install PHP
# Or use full path like: /usr/bin/php

# Add to PATH if needed
export PATH=$PATH:/usr/local/bin
```

---

## 📞 সাহায্য দরকার?

যদি কোনো সমস্যা হয়:

1. **স্ক্রিপ্ট চালান:** `bash production-setup.sh`
2. **এর output শেয়ার করুন** সমস্যা সহ
3. **আমাকে জানান:** কোন step এ failure হচ্ছে

---

## 🎯 Server Connection Commands

প্রতিবার SSH দিয়ে connect করার জন্য:

```bash
ssh tdhuedhn@65.21.174.100
```

Password when prompted: `9qnTd3.9ApPC5`

Project directory এ যান:
```bash
cd /home/tdhuedhn/lgdhaka/ht_docs
```

---

## 📊 Quick Reference

| Item | Value |
|------|-------|
| **Server IP** | 65.21.174.100 |
| **SSH User** | tdhuedhn |
| **Project Path** | /home/tdhuedhn/lgdhaka/ht_docs |
| **Web Root** | /home/tdhuedhn/lgdhaka/ht_docs/public |
| **Domain** | https://lgdhaka.co |
| **Webhook URL** | https://lgdhaka.co/deploy.php |

---

## ✨ Success Indicator

Setup complete যখন এমন দেখবেন:

```
✅ SYSTEM IS PRODUCTION READY!
```

Deployment ready যখন এমন দেখবেন:

```bash
$ curl https://lgdhaka.co/deploy.php?token=YOUR_TOKEN

{
  "status": "success",
  "message": "Deployment to production completed successfully"
}
```

---

**প্রস্তুত? এখনই শুরু করুন:**
```bash
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash production-setup.sh --fix
```
