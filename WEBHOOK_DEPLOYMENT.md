# Web Hosting Auto-Deployment Guide

## Overview

This guide covers automatic deployment from git to your web hosting using webhooks. Changes pushed to your repository will trigger automatic deployments.

## Quick Setup

### 1. Upload Files to Web Hosting

Upload these files to your web hosting server:
- `deploy.sh` → Root directory
- `deploy.php` → Root directory (or web-accessible folder)

```bash
# Make deploy.sh executable
chmod +x deploy.sh
```

### 2. Configure Deployment Token

Set a secure deployment token in your web server environment:

**Option A: Via .env file**
```bash
DEPLOY_TOKEN=your-super-secret-token-here
```

**Option B: Via environment variable**
```bash
export DEPLOY_TOKEN="your-super-secret-token-here"
```

**Option C: Directly in deploy.php**
```php
define('DEPLOY_TOKEN', 'your-super-secret-token-here');
```

### 3. Test Manual Deployment

Test that deployment works manually:

```bash
# Direct script execution
ssh user@yourhost.com
cd /path/to/digital-union
./deploy.sh production

# Or via HTTP webhook endpoint
curl "http://yoursite.com/deploy.php?token=your-secret-token&env=production"
```

### 4. Configure GitHub Webhook

Go to your GitHub repository:

1. **Settings** → **Webhooks** → **Add webhook**

2. **Payload URL**: `http://yoursite.com/deploy.php`
   - Use HTTPS if possible: `https://yoursite.com/deploy.php`
   - Ensure deploy.php is web-accessible

3. **Content type**: `application/json`

4. **Secret**: Your deployment token (same as `DEPLOY_TOKEN`)

5. **Events**: Select "Push events" (or "Just the push event")

6. **Active**: ✅ Check this box

7. Click **Add webhook**

### 5. Configure GitLab Webhook

Go to your GitLab project:

1. **Settings** → **Integrations** → **Add webhook**

2. **URL**: `http://yoursite.com/deploy.php`

3. **Push events**: ✅ Check this box

4. **Secret token**: Your deployment token (same as `DEPLOY_TOKEN`)

5. Click **Add webhook**

6. Test webhook delivery to verify communication

## Automatic Deployment Flow

### Branch-to-Environment Mapping

```
Branch → Environment
main    → production
develop → staging
development → development (optional)
```

### Webhook Event Flow

```
1. Code pushed to GitHub/GitLab
   ↓
2. Webhook triggered automatically
   ↓
3. POST request to deploy.php
   ↓
4. Signature verified (GitHub) or Token verified (GitLab)
   ↓
5. Branch matched to environment
   ↓
6. deploy.sh executed with --webhook flag
   ↓
7. JSON response returned with deployment status
```

## Deployment Script Modes

### Direct Execution (Interactive)
```bash
./deploy.sh production
```
Output: Real-time colored terminal output

### Webhook Execution (JSON Response)
```bash
./deploy.sh production --webhook
```
Output: JSON response suitable for HTTP webhooks

### Silent Mode
```bash
./deploy.sh production --silent
```
Output: Logs to file only, no terminal output

## API Endpoints

### Manual Deployment via Token
```bash
GET http://yoursite.com/deploy.php?token=YOUR_TOKEN&env=production

Response:
{
  "status": "success",
  "message": "Deployment completed successfully",
  "timestamp": "2026-04-16 15:30:45",
  "data": {
    "environment": "production",
    "duration_seconds": 120,
    "commit": "abc1234",
    "branch": "main",
    "steps": [
      "✅ Deploying to: production",
      "✅ Git repository verified",
      "✅ Database backup created",
      "✅ Git pull completed",
      "✅ PHP found: PHP 8.2.12",
      "✅ Composer dependencies installed",
      "✅ Cache cleared",
      "✅ Permissions set",
      "✅ Migrations completed",
      "✅ Health checks passed"
    ]
  }
}
```

### GitHub Webhook Automatic
```
POST http://yoursite.com/deploy.php
Headers:
  Authorization: signature from GitHub
  X-GitHub-Event: push
  X-GitHub-Delivery: webhook-delivery-id
  Content-Type: application/json

Body: GitHub push event payload
```

### GitLab Webhook Automatic
```
POST http://yoursite.com/deploy.php
Headers:
  X-Gitlab-Token: your-secret-token
  X-Gitlab-Event: push_events

Body: GitLab push event payload
```

## Configuration Options

### Environments

Edit deployment path/environment configuration in deploy.php:

```php
define('DEPLOY_ENVIRONMENTS', [
    'production' => [
        'branch' => 'main',        // Which git branch triggers this
        'path' => __DIR__,         // Deployment directory
        'script' => './deploy.sh', // Deploy script location
        'timeout' => 300,          // Max 5 minutes
    ],
    'staging' => [
        'branch' => 'develop',
        'path' => __DIR__,
        'script' => './deploy.sh',
        'timeout' => 300,
    ],
]);
```

### IP Whitelisting

Restrict webhook sources to known IPs:

```php
define('ALLOWED_IPS', [
    '127.0.0.1',           // Localhost
    '::1',                 // IPv6 localhost
    '140.82.112.0/20',     // GitHub ranges
    '143.55.64.0/20',      // GitHub ranges
    '192.30.252.0/22',     // GitHub ranges
    '185.199.108.0/22',    // GitHub ranges
    '1.2.3.4',             // Your office IP
]);
```

## Monitoring Deployments

### View Deployment Logs

```bash
# Latest deployment
tail -f storage/logs/deploy_*.log

# Webhook requests
tail -f storage/logs/webhooks.log

# Latest deployment JSON report
cat storage/logs/latest-deployment.json
```

### Webhook Log Format

```
[2026-04-16 15:30:45] [140.82.112.45] Request received {"method":"POST","token_provided":false,"environment":"production"}
[2026-04-16 15:30:45] [140.82.112.45] Webhook verified {"event":"push","source":"GitHub","branch":"refs/heads/main","commit":"abc123def456","pusher":"habibbrox2"}
[2026-04-16 15:30:47] [140.82.112.45] Executing deployment {"environment":"production","command":"..."}
[2026-04-16 15:32:15] [140.82.112.45] Deployment result {"status":"success","message":"Deployment completed successfully"}
```

### Deployment Report (JSON)

```json
{
  "status": "success",
  "environment": "production",
  "message": "Deployment completed successfully",
  "timestamp": "2026-04-16T15:30:45Z",
  "duration_seconds": 120,
  "commit": "abc1234d",
  "branch": "main",
  "steps": [
    "✅ Deploying to: production",
    "✅ Git repository verified",
    "✅ Database backup created",
    "✅ Git pull completed",
    "✅ PHP found: PHP 8.2.12 (cli)",
    "✅ Composer dependencies installed",
    "✅ Cache cleared",
    "✅ Permissions set",
    "✅ Migrations completed",
    "✅ Health checks passed"
  ]
}
```

## Troubleshooting

### Webhook Not Triggering

**Check 1: GitHub Webhook Settings**
- Go to repository Settings → Webhooks
- Check Recent Deliveries tab
- Look for error messages

**Check 2: Firewall Rules**
- Ensure your hosting allows HTTP POST
- Check if port 443 (HTTPS) is open if using HTTPS URL

**Check 3: Deploy.php Accessibility**
```bash
# Test from your server
curl "http://yoursite.com/deploy.php?token=wrongtoken"
# Should return JSON error, not 404
```

### Deployment Fails on Webhook

**Check 1: Token Verification**
- Verify `DEPLOY_TOKEN` matches GitHub secret
- Check GitHub webhook secret is set correctly

**Check 2: Script Permissions**
```bash
ls -la deploy.sh
# Should show: -rwxr-xr-x (755)
chmod +x deploy.sh
```

**Check 3: Git Access**
```bash
ssh user@host
cd /path/to/project
git pull origin main
# Should work without prompting for password
```

**Check 4: Deployment Logs**
```bash
tail -50 storage/logs/deploy_*.log
tail -50 storage/logs/webhooks.log
```

### Permission Denied Errors

```bash
# Fix file permissions
chmod 755 deploy.sh
chmod 755 deploy.php
chmod 755 storage/logs/
chmod 755 storage/db_backups/
```

### Composer Not Found

```bash
# On hosting, use PHP directly
php -d memory_limit=-1 composer.phar install --no-dev

# Update deploy.php to use correct composer path
define('COMPOSER_PATH', 'php composer.phar');
```

### Database Connection Error

- Verify `.env` has correct credentials
- Test connection manually: `mysql -h HOST -u USER -p -D DATABASE`
- Ensure backup directory exists: `mkdir -p storage/db_backups`

### Git Pull Hangs

- Check SSH permissions for git user
- Ensure `ssh-agent` is running
- Test: `ssh -T git@github.com`

## Advanced Configuration

### Custom Deployment Hooks

Add pre/post deployment scripts:

```bash
# Edit deploy.sh to call custom hooks
HOOK_PRE="./hooks/pre-deploy.sh"
HOOK_POST="./hooks/post-deploy.sh"

# Run pre-deployment hook
if [ -f "$HOOK_PRE" ]; then
    bash "$HOOK_PRE" "$ENVIRONMENT"
fi

# ... deployment steps ...

# Run post-deployment hook
if [ -f "$HOOK_POST" ]; then
    bash "$HOOK_POST" "$ENVIRONMENT"
fi
```

### Slack Notifications

Add webhook notification to Slack:

```bash
# In deploy.sh after successful deployment
if [ "$ENVIRONMENT" = "production" ]; then
    curl -X POST "$SLACK_WEBHOOK_URL" \
        -H 'Content-Type: application/json' \
        -d "{
            \"text\": \"✅ Deployment to $ENVIRONMENT successful!\",
            \"attachments\": [{
                \"color\": \"good\",
                \"fields\": [
                    {\"title\": \"Environment\", \"value\": \"$ENVIRONMENT\", \"short\": true},
                    {\"title\": \"Commit\", \"value\": \"$(git rev-parse --short HEAD)\", \"short\": true}
                ]
            }]
        }"
fi
```

### Email Notifications

```bash
# Send email on deployment failure
if [ $DEPLOY_ERRORS -gt 0 ]; then
    mail -s "❌ Deployment Failed: $ENVIRONMENT" admin@yourdomain.com < "$LOG_FILE"
fi
```

## Security Best Practices

1. **Use HTTPS URLs** for webhooks
2. **Strong deployment token** - Generate random string
3. **Keep token secret** - Don't commit to git
4. **IP whitelist** - Only allow cloud provider IPs
5. **Monitor webhook logs** - Check for unauthorized access attempts
6. **Rotate tokens regularly** - Change every 90 days
7. **Disable debug mode in production** - Set `APP_DEBUG=false`
8. **Backup before deployment** - Automatic in deploy script

## Performance Optimization

### Concurrent Deployment Lock

Deploy script uses lock file to prevent overlapping deployments:

```bash
# Lock mechanism
LOCK_FILE=".deploy.lock"
timeout_5_minutes=300

# Current lock prevents new deployment for 5 minutes
# After 5 minutes, lock is ignored
```

### Reduce Deployment Time

1. **Skip unnecessary steps**
   - Comment out database backup if not needed
   - Skip composer install for simple changes

2. **Use composer cache**
   ```bash
   composer install --no-dev --optimize-autoloader --prefer-dist
   ```

3. **Parallel operations** (if supported)
   - Use background jobs for non-critical tasks

## Deployment Checklist

- [ ] Deploy script uploaded and executable
- [ ] Deploy.php uploaded and web-accessible
- [ ] Deployment token configured in environment
- [ ] GitHub webhook configured with correct URL
- [ ] Webhook secret matches deployment token
- [ ] Git SSH keys configured on hosting
- [ ] Database credentials in .env file
- [ ] Storage directories have correct permissions
- [ ] Manual deployment tested successfully
- [ ] Webhook delivery tested from GitHub
- [ ] Deployment logs monitored
- [ ] Backup strategy verified
- [ ] DNS configured for domain
- [ ] HTTPS certificate installed (optional but recommended)

## Example Deployment

**Scenario**: Pushing code to main branch

```
git push origin main
  ↓
GitHub detects push
  ↓
GitHub sends webhook POST to: https://yoursite.com/deploy.php
  ↓
deploy.php receives request with push event data
  ↓
IP verified (GitHub range)
  ↓
Signature verified using deployment token
  ↓
Branch "main" matched to "production" environment
  ↓
deploy.sh production --webhook executed
  ↓
Steps executed:
  ✅ Git repository verified
  ✅ Database backup created (backup_20260416_153045.sql)
  ✅ Git pull completed (2 new commits)
  ✅ Composer dependencies installed
  ✅ Cache cleared
  ✅ Permissions set
  ✅ Migrations executed (1 migration)
  ✅ Health checks passed
  ↓
JSON response sent back to GitHub webhook
  ↓
Deployment complete! Site now running latest code
```

## Support

- GitHub: https://github.com/habibbrox2/digital-union/issues
- Email: hrhabib.hrs@gmail.com
- Documentation: See DEPLOYMENT.md for additional info

---

**Version**: 2.0.0 (Web Hosting Auto-Deployment)  
**Updated**: April 16, 2026
