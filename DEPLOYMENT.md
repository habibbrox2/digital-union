# Deployment Guide - Digital Union LGDHAKA

## Overview

The deployment scripts automate the process of pulling the latest code from git, installing dependencies, clearing cache, and running migrations to production, staging, or development environments.

## Available Deployment Scripts

### Linux/macOS
```bash
./deploy.sh [environment]
```

### Windows
```cmd
deploy.bat [environment]
```

### PHP (Universal)
```bash
php deploy.php [environment]
```

## Environments

### Production
- **Stability**: Maximum priority
- **Debug Mode**: OFF
- **Cache**: Enabled
- **Database Backups**: Automatic
- **Usage**: `./deploy.sh production`

### Staging
- **Stability**: High priority
- **Debug Mode**: Conditional
- **Cache**: Enabled
- **Database Backups**: Manual
- **Usage**: `./deploy.sh staging`

### Development
- **Stability**: Low priority
- **Debug Mode**: ON
- **Cache**: Disabled
- **Database Backups**: Optional
- **Usage**: `./deploy.sh development`

## Deployment Steps

The deployment script performs the following steps in order:

### 1. **Git Repository Verification**
   - Verifies the working directory is a git repository
   - Checks for uncommitted changes

### 2. **Database Backup**
   - Creates automatic backup before deployment
   - Files stored in `storage/db_backups/`
   - Named with timestamp: `backup_YYYYMMDD_HHMMSS.sql`

### 3. **Git Pull**
   - Pulls latest changes from remote repository
   - Checks out to the current branch

### 4. **PHP Check**
   - Verifies PHP installation
   - Checks PHP version compatibility

### 5. **Install Dependencies**
   - Runs `composer install` with optimizations
   - Uses `--no-dev` flag for production
   - Creates `vendor/autoload.php`

### 6. **Clear Cache**
   - Removes Twig template cache
   - Clears temporary files
   - Empties `storage/cache/` and `storage/tmp/`

### 7. **Set Permissions**
   - Sets `public/` to 755
   - Sets `storage/` to 775
   - Sets `public/index.php` to 644

### 8. **Database Migrations**
   - Runs `migrate.php` if it exists
   - Updates database schema as needed
   - Applies data transformations

### 9. **Health Checks**
   - Verifies critical files exist
   - Validates configuration
   - Reports deployment status

## Usage Examples

### Minimal Usage
```bash
# Deploy to production (default)
./deploy.sh
```

### Deploy to Staging
```bash
./deploy.sh staging
```

### Deploy to Development
./deploy.sh development
```

## Pre-Deployment Checklist

Before running deployment:

- [ ] You have git access to the repository
- [ ] Your SSH keys are configured (for git pull)
- [ ] `.env` file is configured with correct database credentials
- [ ] Database user has sufficient permissions
- [ ] Web server is running
- [ ] Sufficient disk space available (minimum 500MB)
- [ ] No critical processes running
- [ ] Recent database backup exists (outside deployment script)

## Pre-Deployment Backup

**Always backup manually before production deployment:**

```bash
# Manual database backup (Linux/macOS)
mysqldump -u DB_USER -p DB_NAME > backup_manual_$(date +%Y%m%d_%H%M%S).sql

# Or using the migration script
php migrate.php
```

## Environment Configuration

### .env Example
```bash
APP_ENV=production
APP_DEBUG=false
DB_HOST=localhost
DB_USER=lgdhaka_user
DB_PASS=secure_password
DB_NAME=tdhuedhn_lgdhaka
```

### Production .env Settings
```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
SESSION_TIMEOUT=1800
```

## Troubleshooting

### Permission Denied Error
```bash
# Fix script permissions
chmod +x deploy.sh
chmod +x deploy.bat
```

### Git Pull Fails
```bash
# Check git status
git status

# Check remote configuration
git remote -v

# Manually pull and retry
git pull origin main
./deploy.sh
```

### Composer Not Found
```bash
# Install Composer globally
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
mv composer.phar /usr/local/bin/composer
```

### Database Connection Error
```bash
# Verify .env database credentials
cat .env | grep DB_

# Test MySQL connection
mysql -h DB_HOST -u DB_USER -p DB_NAME
```

### Migration Failures
```bash
# Check migration log
tail -f storage/logs/migrations.log

# Manually run migrations
php migrate.php

# Review database state
mysql -u DB_USER -p DB_NAME -e "SHOW TABLES;"
```

## Rollback Procedure

If deployment fails:

### 1. **Restore Database**
```bash
# Restore from backup
mysql -u DB_USER -p DB_NAME < storage/db_backups/backup_YYYYMMDD_HHMMSS.sql
```

### 2. **Revert Git Changes**
```bash
# Check deployment commit
git log --oneline -5

# Revert to previous version
git revert HEAD
# or
git reset --hard origin/previous-branch
```

### 3. **Clear Cache**
```bash
rm -rf storage/cache/*
rm -rf storage/tmp/*
```

### 4. **Restart Services**
```bash
# Restart web server (example: Apache)
sudo systemctl restart apache2

# Restart PHP-FPM if using Nginx
sudo systemctl restart php-fpm
```

## Deployment Logging

All deployment actions are logged to:
```
storage/logs/deploy_YYYYMMDD_HHMMSS.log
```

### View Recent Deployments
```bash
# Linux/macOS
tail -20 storage/logs/deploy_*.log

# Windows
type storage\logs\deploy_*.log | more
```

### Log Format
```
[2026-04-16 14:30:45] Starting deployment to production
[2026-04-16 14:30:46] Git repository verified
[2026-04-16 14:30:47] Database backup created
[2026-04-16 14:30:50] Git pull completed
[2026-04-16 14:30:55] Composer dependencies installed
[2026-04-16 14:30:56] Cache cleared
[2026-04-16 14:31:02] Migrations completed
[2026-04-16 14:31:03] Health checks passed
[2026-04-16 14:31:03] Deployment completed successfully
```

## Automated Deployment (CI/CD)

### GitHub Actions Example
```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Deploy
        run: |
          chmod +x deploy.sh
          ./deploy.sh production
```

### GitLab CI Example
```yaml
deploy:production:
  stage: deploy
  script:
    - chmod +x deploy.sh
    - ./deploy.sh production
  only:
    - main
```

## Post-Deployment Verification

After deployment, verify:

1. **Application Loads**
   ```bash
   curl https://yourdomain.com
   ```

2. **Database Connected**
   - Check admin dashboard
   - Verify data displays correctly

3. **Logs Clean**
   ```bash
   # Check for errors
   tail -50 storage/logs/migrations.log
   ```

4. **Permissions Correct**
   ```bash
   # Verify file permissions
   ls -la public/
   ls -la storage/
   ```

## Performance Considerations

- Deployments typically take 2-5 minutes
- Database backups may take longer with large databases
- Composer install may require network access
- Cache clearing is done automatically

## Security Best Practices

- ✅ Keep `.env` file out of git (already in .gitignore)
- ✅ Use strong database passwords
- ✅ Configure SSH keys properly for git access
- ✅ Restrict deployment script access to admins
- ✅ Test deployments in staging first
- ✅ Maintain regular backups outside git
- ✅ Monitor deployment logs for issues

## Support

For deployment issues or questions:
- Email: hrhabib.hrs@gmail.com
- GitHub: https://github.com/habibbrox2/digital-union/issues

---

**Last Updated**: April 16, 2026  
**Version**: 1.0.0  
**Maintained By**: Hr Habib Brox
