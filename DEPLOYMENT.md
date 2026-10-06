# 🚀 GitHub Actions Deployment Setup for Shared Hosting

This guide explains how to set up safe, automated deployment from GitHub to your shared hosting server.

## 📋 Prerequisites

- GitHub repository with this code
- Shared hosting with **SFTP/SSH access** (recommended) or FTP
- Firebase project for FCM push notifications

---

## 🔐 Step 1: Configure GitHub Repository Secrets

Go to your GitHub repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**

### Required Secrets

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `FTP_HOST` | SFTP/FTP server hostname | `ftp.yourhost.com` or `yourdomain.com` |
| `FTP_USERNAME` | FTP/SFTP username | `youruser@yourdomain.com` |
| `FTP_PASSWORD` | FTP/SFTP password | `your_strong_password` |
| `FTP_PORT` | Port (22 for SFTP, 21 for FTP) | `22` |
| `FTP_PROTOCOL` | `sftp` (recommended) or `ftp` | `sftp` |
| `FTP_REMOTE_DIR` | Remote path on server | `/public_html/` or `/home/youruser/public_html/` |

### Optional (for post-deploy commands via SSH)

| Secret Name | Description |
|-------------|-------------|
| `SSH_HOST` | SSH server hostname |
| `SSH_USERNAME` | SSH username |
| `SSH_PRIVATE_KEY` | Private SSH key (OpenSSH format) |
| `SSH_PORT` | SSH port (default 22) |
| `SSH_REMOTE_DIR` | Remote path on server |

### Environment File (CRITICAL - contains all configs)

Create **one secret named `ENV_FILE`** with the **entire content** of your production `.env` file:

```bash
# Copy your production .env content exactly:
DB_HOST=localhost
DB_PORT=3306
DB_USER=prod_user
DB_PASS=prod_password
DB_NAME=prod_db
# ... all other variables from .env.example ...
FCM_ENABLED=true
FCM_API_KEY=AIzaSy...
# ... etc
```

### Firebase Service Account (for FCM)

Create secret named `FIREBASE_SERVICE_ACCOUNT` with the **entire JSON content** of your Firebase Admin SDK service account file:

```json
{
  "type": "service_account",
  "project_id": "your-project",
  "private_key_id": "...",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
  "client_email": "firebase-adminsdk@your-project.iam.gserviceaccount.com",
  "client_id": "...",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk%40your-project.iam.gserviceaccount.com"
}
```

---

## 📁 Step 2: Prepare Shared Hosting

### Directory Structure
Ensure your hosting root has:
```
/public_html/           # or /home/user/www/
├── index.php           # Your front controller
├── .htaccess           # URL rewriting
├── config/             # Will be deployed
├── controllers/
├── models/
├── modules/
├── templates/
├── public/
│   ├── assets/
│   ├── uploads/
│   └── ...
├── storage/
│   ├── logs/
│   └── cache/
└── vendor/             # Created by composer install
```

### Permissions (run once via SSH/cPanel)
```bash
chmod 755 public_html
chmod 775 public_html/storage public_html/public/uploads
find public_html -type f -name "*.php" -exec chmod 644 {} \;
```

### PHP Version
Ensure hosting uses **PHP 8.1+** with extensions: `pdo_mysql`, `mbstring`, `curl`, `openssl`, `gd`, `zip`, `intl`, `bcmath`, `json`

---

## 🔄 Step 3: Deployment Flow

### Automatic (on push to main)
```bash
git push origin main
```
Triggers workflow → runs tests → deploys via SFTP

### Manual (via GitHub UI)
1. Go to **Actions** tab
2. Select **Deploy to Shared Hosting**
3. Click **Run workflow**
4. Choose environment (production/staging)

---

## ✅ Step 4: Verify Deployment

After deployment completes:

1. **Check site loads**: Visit your domain
2. **Test FCM**: Open browser devtools → Application → Service Workers → check `/firebase-messaging-sw.js` loads
3. **Test chat**: Open visitor widget, send message
4. **Check admin panel**: Login, verify notifications work
5. **Check logs**: `storage/logs/` for any errors

---

## 🛡️ Security Checklist

- [ ] `.env` is in `.gitignore` (already done)
- [ ] `vendor/` is in `.gitignore` (already done)
- [ ] Firebase service account JSON is in `.gitignore` (already done)
- [ ] All secrets stored in **GitHub Secrets**, not in repo
- [ ] `APP_DEBUG=false` in production
- [ ] `APP_ENV=production` in production
- [ ] HTTPS enforced on hosting
- [ ] Database credentials are strong and unique
- [ ] SSH keys used instead of passwords (if possible)

---

## 🔧 Troubleshooting

### "Permission denied" on deploy
- Check `FTP_USERNAME`/`FTP_PASSWORD` are correct
- Verify `FTP_REMOTE_DIR` path exists on server
- Try SFTP (port 22) instead of FTP (port 21)

### "Composer not found" on server
- The workflow runs `composer install` locally and uploads `vendor/`
- No composer needed on shared hosting

### FCM not working
- Verify `FIREBASE_SERVICE_ACCOUNT` secret is valid JSON
- Check `config/digi-union-lgdhaka-firebase-adminsdk-fbsvc-39e5307dd4.json` exists on server after deploy
- Verify FCM config in browser: `/api/chat/push/config`

### Database connection failed
- Verify `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` in `ENV_FILE` secret
- Some shared hosts require `DB_HOST=localhost` not `127.0.0.1`

---

## 📝 Files Created

| File | Purpose |
|------|---------|
| `.github/workflows/deploy.yml` | GitHub Actions workflow |
| `.env.example` | Template with all required variables |
| `DEPLOYMENT.md` | This guide |

---

## 🆘 Need Help?

1. Check **Actions** tab for workflow logs
2. Enable debug: Add `ACTIONS_STEP_DEBUG=true` as repository secret
3. Verify secrets are set correctly (no extra spaces/newlines)
4. Test SFTP connection manually first: `sftp user@host`

**Never commit real credentials to Git!** Always use GitHub Secrets.