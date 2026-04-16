# Web Hosting Directory Structure

Your web hosting setup uses `public/` as the web root (document root). This document explains the correct directory layout and deployment configuration.

## Directory Structure

```
/home/username/digital-union/          ← Project Root (SSH access)
│
├─ deploy.sh                            ← Deployment script (run from here)
├─ deploy.php                           ← Source file (for updates)
├─ .env                                 ← Environment config
├─ config/                              ← Configuration files
├─ classes/                             ← PHP classes
├─ controllers/                         ← Controllers
├─ helpers/                             ← Helper functions
├─ templates/                           ← Twig templates
├─ storage/                             ← Logs, cache, uploads
│   ├─ logs/
│   ├─ cache/
│   └─ db_backups/
│
└─ public/                              ← Web Root (HTTP access via yoursite.com)
   ├─ deploy.php                        ← Webhook handler (web-accessible)
   ├─ index.php                         ← Application entry point
   ├─ assets/
   │   ├─ css/
   │   ├─ js/
   │   └─ images/
   └─ uploads/
```

## Important Files and Their Locations

| File | Location | Access | Purpose |
|------|----------|--------|---------|
| `deploy.sh` | Project root | SSH only | Deployment script that runs git pull, composer, migrations |
| `deploy.php` | `public/` | HTTP/Web | Webhook receiver for GitHub/GitLab, triggers deploy.sh |
| `.env` | Project root | SSH only | Database credentials, deployment token |
| `configs/` | Project root | SSH only | Application configuration |
| `storage/logs/` | Project root | SSH only | Deployment logs, webhook logs |

## Web Access URLs

- **Application**: `https://yourdomain.com/` → serves from `public/index.php`
- **Webhook Deploy**: `https://yourdomain.com/deploy.php?token=SECRET` → serves from `public/deploy.php`
- **NEVER**: `https://yourdomain.com/.env` (forbidden by .htaccess)
- **NEVER**: `https://yourdomain.com/storage/` (404 - outside public folder)
- **NEVER**: `https://yourdomain.com/deploy.sh` (404 - not in public folder)

## Deployment Flow

```
Push to GitHub
    ↓
GitHub webhook calls: https://yourdomain.com/deploy.php (public/deploy.php)
    ↓
deploy.php verifies signature and calls:
    bash /home/username/digital-union/deploy.sh production
    ↓
deploy.sh runs (from project root):
  - Git pull origin main
  - Composer install
  - Clear cache (storage/cache/)
  - Database migrations
  - Set permissions
    ↓
Deployment complete / site updated
```

## Setup Instructions for Your Hosting

### 1. SSH Into Your Server

```bash
ssh user@yourhost.com
cd ~/digital-union/  # or wherever your project is
```

### 2. Verify Directory Structure

```bash
# Check project root has deploy.sh
ls -la deploy.sh

# Check public folder 
ls -la public/deploy.php
ls -la public/index.php
```

### 3. Set Permissions

```bash
# Make scripts executable
chmod +x deploy.sh
chmod +x public/deploy.php

# Ensure storage is writable
chmod -R 775 storage/logs/
chmod -R 775 storage/db_backups/

# Ensure public is readable
chmod 755 public/
```

### 4. Configure Environment

Edit `.env` in project root:

```bash
nano .env
```

Set these variables:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_HOST=localhost
DB_USER=your_db_user
DB_PASS=your_db_password
DB_NAME=your_db_name

DEPLOY_TOKEN=generate-a-random-secure-token-here
```

Save with: `Ctrl+X` then `Y` then `Enter`

### 5. Generate Strong Deployment Token

```bash
# Generate random token
openssl rand -hex 32
```

Copy the output and:
1. Set it in `.env` as `DEPLOY_TOKEN=...`
2. Use it as the webhook secret in GitHub

### 6. Test Deployment Manually

```bash
# From project root
./deploy.sh production

# Check logs
tail -20 storage/logs/deploy_*.log
```

### 7. Configure GitHub Webhook

1. Go to: GitHub Repo → Settings → Webhooks → Add webhook

2. **Payload URL**: `https://yourdomain.com/deploy.php`
   - IMPORTANT: Use HTTPS, not HTTP

3. **Content type**: `application/json`

4. **Secret**: Paste your `DEPLOY_TOKEN` from step 5

5. **Events**: Push events only

6. **Active**: ☑ Checked

7. Click: Add webhook

8. Check "Recent Deliveries" tab to verify it works

## Testing the Webhook

### Manual Test Via URL

```bash
# From your local machine
curl "https://yourdomain.com/deploy.php?token=YOUR_TOKEN&env=production"

# Should return JSON:
# {"status":"success","message":"Deployment completed successfully",...}
```

### Test Via GitHub

1. Go to webhook Settings → Recent Deliveries
2. Find latest delivery
3. Click "Redeliver" button
4. Check Response tab for status

### Check Logs

```bash
# SSH into server, then:
tail -50 ~/digital-union/storage/logs/webhooks.log
tail -50 ~/digital-union/storage/logs/deploy_*.log
```

## Troubleshooting

### "404 Not Found" for deploy.php

- **Problem**: `https://yourdomain.com/deploy.php` returns 404
- **Cause**: deploy.php not copied to public folder
- **Fix**: 
  ```bash
  cp deploy.php public/deploy.php
  chmod +x public/deploy.php
  ```

### "Permission denied" on deploy.sh

- **Problem**: Webhook fails, logs show: "Permission denied: deploy.sh"
- **Cause**: Script not executable
- **Fix**:
  ```bash
  chmod +x deploy.sh
  chmod +x public/deploy.php
  ```

### Deployment times out

- **Problem**: Webhook returns 504, deployment never completes
- **Cause**: Composer install or migrations too slow
- **Fix**: SSH and test manually:
  ```bash
  ./deploy.sh production
  # Check what step is slow
  ```

### Database connection error

- **Problem**: Deploy fails on "Database backup created"
- **Cause**: Wrong .env credentials
- **Fix**:
  ```bash
  # Test MySQL connection
  mysql -h localhost -u DB_USER -p DB_NAME
  # Use credentials from .env
  ```

### Can't access .env from web

- **Good!** This is the correct behavior.
- `.htaccess` should block web access to `.env`
- If you can access it, your .htaccess is missing

### Webhook secret mismatch

- **Problem**: Webhook fails with "Webhook signature invalid"
- **Cause**: GitHub secret ≠ DEPLOY_TOKEN in .env
- **Fix**:
  1. Generate new token: `openssl rand -hex 32`
  2. Update .env: `DEPLOY_TOKEN=new-token`
  3. Update GitHub webhook secret to same value
  4. Redeliver webhook

## File Synchronization

After deployment, these files/directories are updated:

- ✅ `config/` - Configuration updated
- ✅ `classes/` - PHP classes updated
- ✅ `controllers/` - Controllers updated
- ✅ `helpers/` - Helpers updated
- ✅ `templates/` - Twig templates updated
- ✅ `public/assets/` - CSS, JS, images updated
- ✅ `storage/cache/` - Cache cleared (regenerated on use)
- ✅ `storage/logs/` - New deployment logs created
- ❌ `storage/db_backups/` - NOT affected by git pull

## Database Backups

- **Before each deployment**: Automatic backup created at `storage/db_backups/backup_YYYYMMDD_HHMMSS.sql`
- **Manual backup**: `mysqldump -u DB_USER -p DB_NAME > backup_$(date +%Y%m%d_%H%M%S).sql`
- **Restore**: `mysql -u DB_USER -p DB_NAME < backup_file.sql`

## Security Checklist

- ☑ `.env` file exists and has strong secret
- ☑ `DEPLOY_TOKEN` is random and complex (32+ chars)
- ☑ deploy.php only accessible via HTTPS
- ☑ GitHub webhook uses HTTPS URL
- ☑ `.htaccess` blocks direct web access to `.env`, `deploy.sh`, etc.
- ☑ `storage/` directory outside web root (not in public/)
- ☑ Permissions set correctly (755 for public, 775 for storage)

## Performance Tips

1. **First deployment is slow**: Composer install takes 2-3 minutes
2. **Subsequent deployments faster**: Only changed files downloaded, caching used
3. **Database backups**: Usually <10 seconds for typical database
4. **Migrations**: Usually <30 seconds
5. **Full deployment**: Usually 2-5 minutes total

## Rollback Procedure

If deployment breaks the site:

```bash
# SSH into server
cd ~/digital-union/

# Revert to previous commit
git revert HEAD
# or
git reset --hard origin/main

# Clear cache
rm -rf storage/cache/*
rm -rf storage/tmp/*

# Apply previous database state
mysql -u DB_USER -p DB_NAME < storage/db_backups/backup_YYYYMMDD_HHMMSS.sql
```

---

**Version**: 1.0 (Public Directory Web Root Setup)  
**Last Updated**: April 16, 2026  
**Author**: Hr Habib Brox
