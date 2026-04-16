#!/bin/bash

################################################################################
#
# Digital Union - Auto Deploy Script for Web Hosting
# Automated one-click deployment from git repository
#
# Installation:
#   1. Upload this script to your web hosting server root
#   2. Make it executable: chmod +x deploy.sh
#   3. Create deploy.php for webhook calls (see DEPLOYMENT.md)
#   4. Configure git credentials on hosting server
#
# Usage:
#   ./deploy.sh [environment] [--webhook] [--silent]
#
# Examples:
#   ./deploy.sh production                    # Full deployment
#   ./deploy.sh production --webhook          # Webhook call (JSON output)
#   ./deploy.sh staging --silent              # No interactive output
#   curl http://yoursite.com/deploy.php?env=production  # Auto trigger
#
# Features:
#   - Git pull latest changes
#   - Install/update dependencies
#   - Clear cache
#   - Run migrations
#   - Backup database
#   - Set permissions
#   - Health check
#   - Webhook ready (JSON responses)
#   - Lock mechanism to prevent concurrent deployments
#
################################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
ENVIRONMENT="${1:-production}"
WEBHOOK_MODE="${2:-}" # --webhook or --silent
SILENT_MODE=false
WEBHOOK_OUTPUT=false

# Parse flags
if [ "$WEBHOOK_MODE" = "--webhook" ]; then
    WEBHOOK_OUTPUT=true
    SILENT_MODE=true
elif [ "$WEBHOOK_MODE" = "--silent" ]; then
    SILENT_MODE=true
fi

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LOCK_FILE="${SCRIPT_DIR}/.deploy.lock"
LOG_DIR="${SCRIPT_DIR}/storage/logs"
LOG_FILE="${LOG_DIR}/deploy_$(date +%Y%m%d_%H%M%S).log"
DEPLOYMENT_REPORT="${LOG_DIR}/latest-deployment.json"

# Ensure log directory exists
mkdir -p "$LOG_DIR"

# Deployment tracking
DEPLOY_START_TIME=$(date +%s)
DEPLOY_STEPS=()
DEPLOY_ERRORS=0

# Helper functions
log() {
    local message="$@"
    local timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
    
    if [ "$SILENT_MODE" = false ]; then
        echo -e "${BLUE}${timestamp}${NC} - ${message}"
    fi
    echo "[${timestamp}] ${message}" >> "$LOG_FILE"
}

success() {
    local message="$@"
    local timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
    
    if [ "$SILENT_MODE" = false ]; then
        echo -e "${GREEN}✅ ${message}${NC}"
    fi
    echo "[${timestamp}] SUCCESS: ${message}" >> "$LOG_FILE"
    DEPLOY_STEPS+=("✅ $message")
}

error() {
    local message="$@"
    local timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
    
    if [ "$SILENT_MODE" = false ]; then
        echo -e "${RED}❌ ERROR: ${message}${NC}"
    fi
    echo "[${timestamp}] ERROR: ${message}" >> "$LOG_FILE"
    DEPLOY_STEPS+=("❌ $message")
    ((DEPLOY_ERRORS++))
    
    cleanup_lock
    send_webhook_response "error" "$message"
    exit 1
}

warning() {
    local message="$@"
    local timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
    
    if [ "$SILENT_MODE" = false ]; then
        echo -e "${YELLOW}⚠️  WARNING: ${message}${NC}"
    fi
    echo "[${timestamp}] WARNING: ${message}" >> "$LOG_FILE"
    DEPLOY_STEPS+=("⚠️  $message")
}

# Deployment locking (prevent concurrent deployments)
acquire_lock() {
    if [ -f "$LOCK_FILE" ]; then
        local LOCK_AGE=$(($(date +%s) - $(stat -f%m "$LOCK_FILE" 2>/dev/null || stat -c%Y "$LOCK_FILE")))
        if [ "$LOCK_AGE" -lt 300 ]; then
            error "Deployment already in progress (lock file exists). Please wait."
        fi
    fi
    touch "$LOCK_FILE"
}

cleanup_lock() {
    rm -f "$LOCK_FILE"
}

# Webhook response functions
send_webhook_response() {
    if [ "$WEBHOOK_OUTPUT" = true ]; then
        local status="$1"
        local message="$2"
        local elapsed=$(($(date +%s) - DEPLOY_START_TIME))
        
        # Build JSON response
        local json_response="{
  \"status\": \"$status\",
  \"environment\": \"$ENVIRONMENT\",
  \"message\": \"$message\",
  \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
  \"duration_seconds\": $elapsed,
  \"commit\": \"$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')\",
  \"branch\": \"$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')\",
  \"steps\": ["
        
        local first=true
        for step in "${DEPLOY_STEPS[@]}"; do
            if [ "$first" = true ]; then
                json_response+="\"$(echo "$step" | sed 's/"/\\"/g')\""
                first=false
            else
                json_response+=",\"$(echo "$step" | sed 's/"/\\"/g')\""
            fi
        done
        
        json_response+="]
}"
        
        echo "$json_response" > "$DEPLOYMENT_REPORT"
        
        if [ "$SILENT_MODE" = true ] && [ "$WEBHOOK_OUTPUT" = true ]; then
            echo "$json_response"
        fi
    fi
}

trap cleanup_lock EXIT

# Header
if [ "$SILENT_MODE" = false ]; then
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║      Digital Union - Auto Deploy for Web Hosting               ║"
    echo "║              Environment: $ENVIRONMENT"
    echo "║              Mode: $([ "$WEBHOOK_OUTPUT" = true ] && echo "WEBHOOK" || echo "DIRECT")"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
fi

# Validate environment
case "$ENVIRONMENT" in
    production|staging|development)
        success "Deploying to: $ENVIRONMENT"
        ;;
    *)
        error "Invalid environment: $ENVIRONMENT. Use: production, staging, or development"
        ;;
esac

# Acquire lock to prevent concurrent deployments
acquire_lock

# Step 1: Verify git repository
log "Step 1: Verifying git repository..."
if [ ! -d ".git" ]; then
    error "Not a git repository. Please run this script from the project root."
fi
success "Git repository verified"

# Step 2: Check for uncommitted changes
log "Step 2: Checking for uncommitted changes..."
if [ -z "$(git status --porcelain)" ]; then
    success "No uncommitted changes"
else
    warning "Uncommitted changes detected. Proceeding anyway..."
    git status --short | tee -a "$LOG_FILE"
fi

# Step 3: Backup database
log "Step 3: Creating database backup..."
if command -v mysqldump &> /dev/null; then
    DB_BACKUP_DIR="${SCRIPT_DIR}/storage/db_backups"
    mkdir -p "$DB_BACKUP_DIR"
    
    if [ -f ".env" ]; then
        source .env
        BACKUP_FILE="${DB_BACKUP_DIR}/backup_$(date +%Y%m%d_%H%M%S).sql"
        
        if mysqldump -h "${DB_HOST:-localhost}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" > "$BACKUP_FILE" 2>/dev/null; then
            success "Database backup created: $BACKUP_FILE"
        else
            warning "Failed to create database backup. Continuing anyway..."
        fi
    else
        warning "No .env file found. Skipping database backup."
    fi
else
    warning "mysqldump not found. Skipping database backup."
fi

# Step 4: Git pull
log "Step 4: Pulling latest changes from git..."
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
log "Current branch: $CURRENT_BRANCH"

if git pull origin "$CURRENT_BRANCH" 2>&1 | tee -a "$LOG_FILE"; then
    success "Git pull completed"
else
    error "Failed to pull from git"
fi

# Step 5: Check PHP
log "Step 5: Checking PHP installation..."
if ! command -v php &> /dev/null; then
    error "PHP not found. Please install PHP."
fi
PHP_VERSION=$(php -v | head -n 1)
success "PHP found: $PHP_VERSION"

# Step 6: Install/update dependencies
log "Step 6: Installing dependencies..."
if [ -f "composer.json" ]; then
    if command -v composer &> /dev/null; then
        if composer install --no-dev --optimize-autoloader 2>&1 | tee -a "$LOG_FILE"; then
            success "Composer dependencies installed"
        else
            error "Failed to install composer dependencies"
        fi
    else
        warning "Composer not found. Skipping composer install."
    fi
else
    warning "No composer.json found. Skipping composer install."
fi

# Step 7: Clear cache
log "Step 7: Clearing cache..."
rm -rf storage/cache/* 2>/dev/null || true
rm -rf storage/tmp/* 2>/dev/null || true
success "Cache cleared"

# Step 8: Set permissions
log "Step 8: Setting file permissions..."
chmod -R 755 public/ || warning "Could not set permissions on public/"
chmod -R 775 storage/ || warning "Could not set permissions on storage/"
chmod 644 public/index.php || warning "Could not set permissions on index.php"
success "Permissions set"

# Step 9: Run migrations (if script exists)
log "Step 9: Checking for migrations..."
if [ -f "migrate.php" ]; then
    log "Running database migrations..."
    if php migrate.php 2>&1 | tee -a "$LOG_FILE"; then
        success "Migrations completed"
    else
        warning "Migrations reported issues. Check log."
    fi
else
    log "No migrate.php found. Skipping migrations."
fi

# Step 10: Environment-specific tasks
log "Step 10: Running environment-specific tasks..."
case "$ENVIRONMENT" in
    production)
        log "Production deployment tasks..."
        # Ensure debug mode is off
        if grep -q "APP_DEBUG=true" .env; then
            warning "APP_DEBUG is enabled in production!"
        fi
        success "Production tasks completed"
        ;;
    staging)
        log "Staging deployment tasks..."
        success "Staging tasks completed"
        ;;
    development)
        log "Development deployment tasks..."
        success "Development tasks completed"
        ;;
esac

# Step 11: Health check
log "Step 11: Running health checks..."
HEALTH_OK=true

# Check if index.php is accessible
if [ -f "public/index.php" ]; then
    success "public/index.php found"
else
    error "public/index.php not found!"
    HEALTH_OK=false
fi

# Check if config files exist
if [ -f "config/config.php" ]; then
    success "config/config.php found"
else
    error "config/config.php not found!"
    HEALTH_OK=false
fi

if [ -f ".env" ]; then
    success ".env file found"
else
    error ".env file not found!"
    HEALTH_OK=false
fi

if [ "$HEALTH_OK" = true ]; then
    success "Health checks passed"
else
    error "Health checks failed"
fi

# Final summary
echo -e "${BLUE}"
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                  Deployment Completed! ✅                      ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

DEPLOY_END_TIME=$(date +%s)
DEPLOY_DURATION=$((DEPLOY_END_TIME - DEPLOY_START_TIME))

log "Deployment to $ENVIRONMENT completed successfully in ${DEPLOY_DURATION}s"
log "Commit: $(git rev-parse --short HEAD)"
log "Branch: $(git rev-parse --abbrev-ref HEAD)"
log "Log file: $LOG_FILE"

success "Deployment finished at $(date '+%Y-%m-%d %H:%M:%S')"

# Send webhook response
send_webhook_response "success" "Deployment completed successfully"

if [ "$WEBHOOK_OUTPUT" = true ]; then
    if [ "$SILENT_MODE" = true ]; then
        cat "$DEPLOYMENT_REPORT"
    fi
fi

cleanup_lock
