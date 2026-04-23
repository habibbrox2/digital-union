# 🚀 Production Setup - Copy & Paste Commands

## সবচেয়ে সহজ উপায় (Copy-Paste করুন)

### আপনার Computer এ এই command চালান:

```bash
ssh tdhuedhn@65.21.174.100 << 'EOFSETUP'
cd /home/tdhuedhn/lgdhaka/ht_docs
wget https://raw.githubusercontent.com/YOUR_REPO/main/scripts/production-setup.sh -O /tmp/setup.sh || curl https://raw.githubusercontent.com/YOUR_REPO/main/scripts/production-setup.sh -o /tmp/setup.sh
bash /tmp/setup.sh --fix
EOFSETUP
```

---

## অথবা Manual Command দিয়ে (Step by Step):

```bash
# Step 1: SSH সার্ভারে যান
ssh tdhuedhn@65.21.174.100

# Step 2: Project directory এ যান
cd /home/tdhuedhn/lgdhaka/ht_docs

# Step 3: সবকিছু চেক করুন
bash scripts/production-setup.sh

# Step 4: সব issues ঠিক করুন
bash scripts/production-setup.sh --fix

# Step 5: Deployment test করুন (optional)
bash scripts/production-setup.sh --fix --test
```

---

## Windows PowerShell Users

```powershell
# PowerShell এ এই কমান্ড দিন:
ssh -l tdhuedhn 65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash scripts/production-setup.sh --fix
```

---

## Linux/Mac Users

```bash
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash scripts/production-setup.sh --fix
```

---

## যখন Prompt করবে:

```
Enter password: 9qnTd3.9ApPC5
```

---

## Expected Output (Success):

```
╔════════════════════════════════════════════════════════════════╗
║           ✅ SYSTEM IS PRODUCTION READY! ✅                    ║
╚════════════════════════════════════════════════════════════════╝
```

---

## After Setup Complete:

### 1. Test Webhook

```bash
curl https://lgdhaka.co/deploy.php?token=9qnTd3.9ApPC5
```

### 2. Check Deployment Status

```bash
# From server
tail -50 /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/webhooks.log
```

### 3. Push to GitHub

এখন যখন push করবেন, automatic deployment হবে!

```bash
git push origin main
```

---

## Troubleshooting

### Issues আছে?

```bash
ssh tdhuedhn@65.21.174.100
cd /home/tdhuedhn/lgdhaka/ht_docs
bash scripts/production-setup.sh
```

Output দেখুন কোথায় ❌ আছে

### Manual Fix:

```bash
# Deploy script executable করুন
chmod +x deploy.sh

# Permissions ঠিক করুন
chmod -R u+rx .git
chmod -R 775 storage/

# Git check করুন
git status

# PHP check করুন
php -v
```

---

## Quick Links

- 📖 Full Guide: `/docs/PRODUCTION_SETUP_GUIDE.md`
- 🐛 Troubleshooting: `/docs/DEPLOYMENT_TROUBLESHOOTING.md`
- 📝 Hosting Setup: `/docs/HOSTING_SETUP.md`
- 🚀 Deployment Guide: `/docs/DEPLOYMENT.md`
