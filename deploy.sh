#!/bin/bash

################################################################################
#
# Digital Union - Git Deploy Script
# Automated deployment from git repository
#
# Usage: ./deploy.sh [production|staging|development]
# Default: production
#
# Features:
#   - Git pull latest changes
#   - Install/update dependencies
#   - Clear cache
#   - Run migrations
#   - Backup database
#   - Set permissions
#   - Health check
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
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LOG_FILE="${SCRIPT_DIR}/storage/logs/deploy_$(date +%Y%m%d_%H%M%S).log"

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Helper functions
log() {
    echo -e "${BLUE}$(date '+%Y-%m-%d %H:%M:%S')${NC} - $@" | tee -a "$LOG_FILE"
}

success() {
    echo -e "${GREEN}✅ $@${NC}" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}❌ ERROR: $@${NC}" | tee -a "$LOG_FILE"
    exit 1
}

warning() {
    echo -e "${YELLOW}⚠️  WARNING: $@${NC}" | tee -a "$LOG_FILE"
}

# Header
echo -e "${BLUE}"
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║         Digital Union - Git Deployment Script                  ║"
echo "║              Environment: $ENVIRONMENT"
echo "╚════════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Validate environment
case "$ENVIRONMENT" in
    production|staging|development)
        success "Deploying to: $ENVIRONMENT"
        ;;
    *)
        error "Invalid environment: $ENVIRONMENT. Use: production, staging, or development"
        ;;
esac

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

log "Deployment to $ENVIRONMENT completed successfully"
log "Commit: $(git rev-parse --short HEAD)"
log "Branch: $(git rev-parse --abbrev-ref HEAD)"
log "Log file: $LOG_FILE"

success "Deployment finished at $(date '+%Y-%m-%d %H:%M:%S')"
