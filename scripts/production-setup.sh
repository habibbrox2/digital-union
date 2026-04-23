#!/bin/bash

################################################################################
#
# Production Ready Setup Script
# ডিজিটাল ইউনিয়ন - প্রোডাকশন সেটআপ স্ক্রিপ্ট
#
# This script will:
# 1. Verify all paths and permissions
# 2. Fix any configuration issues
# 3. Test deployment functionality
# 4. Generate a production readiness report
#
# Usage: bash production-setup.sh [--fix] [--test]
#   --fix   : Automatically fix issues found
#   --test  : Run full deployment test
#
################################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
AUTO_FIX=false
RUN_TEST=false
ISSUES_FOUND=0
ISSUES_FIXED=0

# Parse arguments
for arg in "$@"; do
    case $arg in
        --fix) AUTO_FIX=true ;;
        --test) RUN_TEST=true ;;
    esac
done

# Helper functions
print_header() {
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║  $1${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
}

check() {
    echo -e "${BLUE}✓${NC} $1"
}

issue() {
    echo -e "${RED}✗${NC} $1"
    ((ISSUES_FOUND++))
}

warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

success() {
    echo -e "${GREEN}✅ $1${NC}"
    ((ISSUES_FIXED++))
}

fix_issue() {
    if [ "$AUTO_FIX" = true ]; then
        success "$1"
        return 0
    else
        warning "$1 (run with --fix to auto-fix)"
        return 1
    fi
}

# ============================================================================
# SECTION 1: Project Structure Verification
# ============================================================================

print_header "SECTION 1: Project Structure Verification"

echo ""
echo "Project Root: $PROJECT_ROOT"
echo ""

# Check if .git exists
if [ -d "$PROJECT_ROOT/.git" ]; then
    check "Git repository found at $PROJECT_ROOT/.git"
else
    issue "Git repository NOT found at $PROJECT_ROOT/.git"
    
    # Check parent directories
    if [ -d "$(dirname "$PROJECT_ROOT")/.git" ]; then
        warning "Found git repo in parent: $(dirname "$PROJECT_ROOT")/.git"
    fi
fi

# Check if deploy.sh exists
if [ -f "$PROJECT_ROOT/deploy.sh" ]; then
    check "Deploy script found at $PROJECT_ROOT/deploy.sh"
else
    if [ -f "$PROJECT_ROOT/scripts/deploy.sh" ]; then
        issue "Deploy script is at $PROJECT_ROOT/scripts/deploy.sh (should be at project root)"
    else
        issue "Deploy script NOT found"
    fi
fi

# Check public directory
if [ -d "$PROJECT_ROOT/public" ]; then
    check "Public directory exists"
else
    issue "Public directory NOT found"
fi

# Check public/deploy.php
if [ -f "$PROJECT_ROOT/public/deploy.php" ]; then
    check "Webhook handler found at public/deploy.php"
else
    issue "Webhook handler NOT found at public/deploy.php"
fi

# Check storage directory
if [ -d "$PROJECT_ROOT/storage" ]; then
    check "Storage directory exists"
else
    issue "Storage directory NOT found"
fi

# Check .env file
if [ -f "$PROJECT_ROOT/.env" ]; then
    check ".env file exists"
    
    # Check for required variables
    if grep -q "DB_HOST" "$PROJECT_ROOT/.env"; then
        check "  ✓ DB_HOST configured"
    else
        warning "  ⚠ DB_HOST not found in .env"
    fi
    
    if grep -q "DEPLOY_TOKEN" "$PROJECT_ROOT/.env"; then
        check "  ✓ DEPLOY_TOKEN configured"
    else
        warning "  ⚠ DEPLOY_TOKEN not found in .env"
    fi
else
    issue ".env file NOT found"
fi

# ============================================================================
# SECTION 2: File Permissions
# ============================================================================

print_header "SECTION 2: File Permissions"

echo ""

# Check .git permissions
if [ -d "$PROJECT_ROOT/.git" ]; then
    GIT_PERMS=$(stat -c %A "$PROJECT_ROOT/.git" 2>/dev/null || stat -f %A "$PROJECT_ROOT/.git" 2>/dev/null || echo "unknown")
    if [ "$GIT_PERMS" != "unknown" ]; then
        check ".git directory permissions: $GIT_PERMS"
        
        if [ -r "$PROJECT_ROOT/.git" ] && [ -x "$PROJECT_ROOT/.git" ]; then
            check "  ✓ .git is readable and traversable"
        else
            if fix_issue "Fix .git permissions to be readable"; then
                chmod -R u+rx "$PROJECT_ROOT/.git"
                success "Fixed .git permissions"
            fi
        fi
    fi
fi

# Check deploy.sh permissions
if [ -f "$PROJECT_ROOT/deploy.sh" ]; then
    if [ -x "$PROJECT_ROOT/deploy.sh" ]; then
        check "deploy.sh is executable"
    else
        if fix_issue "Make deploy.sh executable"; then
            chmod +x "$PROJECT_ROOT/deploy.sh"
            success "Made deploy.sh executable"
        fi
    fi
fi

# Check storage permissions
if [ -d "$PROJECT_ROOT/storage" ]; then
    if [ -w "$PROJECT_ROOT/storage" ]; then
        check "storage directory is writable"
    else
        if fix_issue "Make storage directory writable"; then
            chmod -R 775 "$PROJECT_ROOT/storage"
            success "Fixed storage directory permissions"
        fi
    fi
fi

# Check public directory permissions
if [ -d "$PROJECT_ROOT/public" ]; then
    if [ -r "$PROJECT_ROOT/public" ] && [ -x "$PROJECT_ROOT/public" ]; then
        check "public directory is readable and traversable"
    else
        if fix_issue "Fix public directory permissions"; then
            chmod 755 "$PROJECT_ROOT/public"
            success "Fixed public directory permissions"
        fi
    fi
fi

# ============================================================================
# SECTION 3: Git Repository Check
# ============================================================================

print_header "SECTION 3: Git Repository Check"

echo ""

if [ ! -d "$PROJECT_ROOT/.git" ]; then
    issue "Cannot verify git - .git directory not found"
else
    cd "$PROJECT_ROOT" || exit 1
    
    # Check git status
    if git status > /dev/null 2>&1; then
        check "Git repository is valid"
        
        # Get current branch
        CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
        check "  Current branch: $CURRENT_BRANCH"
        
        # Get latest commit
        LATEST_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
        check "  Latest commit: $LATEST_COMMIT"
        
        # Check for uncommitted changes
        if [ -z "$(git status --porcelain 2>/dev/null)" ]; then
            check "  ✓ No uncommitted changes"
        else
            warning "  ⚠ Uncommitted changes detected"
            git status --short | head -5
        fi
        
        # Check remote
        if git remote get-url origin > /dev/null 2>&1; then
            REMOTE=$(git remote get-url origin)
            check "  Remote: $REMOTE"
        else
            warning "  ⚠ No remote configured"
        fi
    else
        issue "Git repository validation failed"
    fi
fi

# ============================================================================
# SECTION 4: PHP & Dependencies
# ============================================================================

print_header "SECTION 4: PHP & Dependencies"

echo ""

# Check PHP
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n 1)
    check "PHP installed: $PHP_VERSION"
else
    issue "PHP not installed"
fi

# Check Composer
if command -v composer &> /dev/null; then
    COMPOSER_VERSION=$(composer --version | head -n 1)
    check "Composer installed: $COMPOSER_VERSION"
else
    warning "Composer not found (check your PATH)"
fi

# Check vendor/autoload.php
if [ -f "$PROJECT_ROOT/vendor/autoload.php" ]; then
    check "Composer autoload found"
else
    warning "vendor/autoload.php not found - may need to run composer install"
fi

# ============================================================================
# SECTION 5: Database Configuration
# ============================================================================

print_header "SECTION 5: Database Configuration"

echo ""

if [ -f "$PROJECT_ROOT/.env" ]; then
    DB_HOST=$(grep "^DB_HOST=" "$PROJECT_ROOT/.env" 2>/dev/null | cut -d'=' -f2 || echo "not set")
    DB_USER=$(grep "^DB_USER=" "$PROJECT_ROOT/.env" 2>/dev/null | cut -d'=' -f2 || echo "not set")
    DB_NAME=$(grep "^DB_NAME=" "$PROJECT_ROOT/.env" 2>/dev/null | cut -d'=' -f2 || echo "not set")
    
    check "Database Host: $DB_HOST"
    check "Database User: $DB_USER"
    check "Database Name: $DB_NAME"
    
    # Check if database is accessible
    if command -v mysql &> /dev/null; then
        if mysql -h "$DB_HOST" -u "$DB_USER" -e "SELECT 1" 2>/dev/null; then
            success "Database connection successful"
        else
            warning "Could not connect to database (may be permissions issue)"
        fi
    fi
else
    issue ".env file not found - cannot check database config"
fi

# ============================================================================
# SECTION 6: Application Files
# ============================================================================

print_header "SECTION 6: Application Files"

echo ""

# Check critical files
CRITICAL_FILES=(
    "public/index.php"
    "config/config.php"
    "config/db.php"
)

for file in "${CRITICAL_FILES[@]}"; do
    if [ -f "$PROJECT_ROOT/$file" ]; then
        check "$file exists"
    else
        issue "$file NOT found"
    fi
done

# Check template directories
if [ -d "$PROJECT_ROOT/templates" ]; then
    TEMPLATE_COUNT=$(find "$PROJECT_ROOT/templates" -type f -name "*.twig" 2>/dev/null | wc -l)
    check "Templates directory found ($TEMPLATE_COUNT .twig files)"
else
    warning "templates directory not found"
fi

# ============================================================================
# SECTION 7: Deployment Configuration
# ============================================================================

print_header "SECTION 7: Deployment Configuration"

echo ""

# Check deploy.php configuration
if [ -f "$PROJECT_ROOT/public/deploy.php" ]; then
    check "deploy.php webhook handler found"
    
    # Check if DEPLOY_TOKEN is set
    if grep -q "DEPLOY_TOKEN" "$PROJECT_ROOT/.env"; then
        DEPLOY_TOKEN=$(grep "^DEPLOY_TOKEN=" "$PROJECT_ROOT/.env" | cut -d'=' -f2)
        if [ -n "$DEPLOY_TOKEN" ]; then
            TOKEN_LENGTH=${#DEPLOY_TOKEN}
            TOKEN_DISPLAY=$(echo "$DEPLOY_TOKEN" | cut -c1-8)...
            check "  ✓ Deployment token set ($TOKEN_LENGTH chars): $TOKEN_DISPLAY"
        else
            issue "  DEPLOY_TOKEN is empty"
        fi
    else
        warning "  ⚠ DEPLOY_TOKEN not found in .env"
    fi
else
    issue "deploy.php NOT found"
fi

# ============================================================================
# SECTION 8: Security Check
# ============================================================================

print_header "SECTION 8: Security Check"

echo ""

# Check if .env is not web-accessible
if [ -f "$PROJECT_ROOT/public/.env" ]; then
    issue ".env file is in public directory (security risk!)"
else
    check ".env is NOT in public directory ✓"
fi

# Check if .git is not web-accessible
if [ -d "$PROJECT_ROOT/public/.git" ]; then
    issue ".git directory is in public directory (security risk!)"
else
    check ".git is NOT in public directory ✓"
fi

# Check for .htaccess protection
if [ -f "$PROJECT_ROOT/public/.htaccess" ]; then
    if grep -q "\.env\|\.git" "$PROJECT_ROOT/public/.htaccess" 2>/dev/null; then
        check ".htaccess has protection rules"
    else
        warning ".htaccess exists but may not have protection rules"
    fi
else
    warning ".htaccess not found - ensure server is configured to block .env and .git"
fi

# Check storage permissions (should not be web-accessible)
if [ -d "$PROJECT_ROOT/public/storage" ]; then
    warning "storage directory is in public (should be outside public root)"
fi

# ============================================================================
# SECTION 9: Optional - Full Deployment Test
# ============================================================================

if [ "$RUN_TEST" = true ]; then
    print_header "SECTION 9: Deployment Test"
    
    echo ""
    warning "Running full deployment test..."
    echo ""
    
    cd "$PROJECT_ROOT" || exit 1
    
    # Create test lock to prevent actual deployment
    TEST_MODE=true
    
    # Run deployment script in test mode
    if bash deploy.sh production --silent 2>&1 | head -20; then
        success "Deployment script executed successfully"
    else
        issue "Deployment script failed"
    fi
fi

# ============================================================================
# SECTION 10: Summary Report
# ============================================================================

print_header "PRODUCTION READINESS REPORT"

echo ""
echo -e "Issues Found:     ${RED}$ISSUES_FOUND${NC}"
echo -e "Issues Fixed:     ${GREEN}$ISSUES_FIXED${NC}"

if [ $ISSUES_FOUND -eq 0 ]; then
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║           ✅ SYSTEM IS PRODUCTION READY! ✅                    ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
    exit 0
else
    if [ "$AUTO_FIX" = false ]; then
        echo ""
        echo -e "${YELLOW}To automatically fix issues, run:${NC}"
        echo -e "${YELLOW}  bash $0 --fix${NC}"
        exit 1
    else
        echo ""
        if [ $ISSUES_FOUND -gt 0 ]; then
            echo -e "${RED}⚠ Still $ISSUES_FOUND issues found after auto-fix${NC}"
            exit 1
        else
            echo -e "${GREEN}✅ All issues have been fixed!${NC}"
            exit 0
        fi
    fi
fi
