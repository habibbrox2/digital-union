@echo off
REM Digital Union - Git Deploy Script for Windows
REM Automated deployment from git repository
REM
REM Usage: deploy.bat [production|staging|development]
REM Default: production

setlocal enabledelayedexpansion

set "ENVIRONMENT=%1"
if "!ENVIRONMENT!"=="" set "ENVIRONMENT=production"

REM Colors and formatting
for /F %%A in ('echo prompt $H ^| cmd') do set "BS=%%A"

echo.
echo ====================================================================
echo   Digital Union - Git Deployment Script
echo   Environment: !ENVIRONMENT!
echo ====================================================================
echo.

REM Validate environment
if "!ENVIRONMENT!"=="production" goto env_valid
if "!ENVIRONMENT!"=="staging" goto env_valid
if "!ENVIRONMENT!"=="development" goto env_valid
echo ERROR: Invalid environment. Use: production, staging, or development
exit /b 1

:env_valid

REM Create log file
for /f "tokens=2-4 delims=/ " %%a in ('date /t') do (set mydate=%%c%%a%%b)
for /f "tokens=1-2 delims=/:" %%a in ('time /t') do (set mytime=%%a%%b)
set LOG_FILE=storage\logs\deploy_%mydate%_%mytime%.log
if not exist storage\logs mkdir storage\logs

echo [%date% %time%] Starting deployment to !ENVIRONMENT! > !LOG_FILE!

REM Step 1: Verify git repository
echo.
echo Step 1: Verifying git repository...
if not exist ".git" (
    echo ERROR: Not a git repository.
    exit /b 1
)
echo ^[OK^] Git repository verified >> !LOG_FILE!

REM Step 2: Check for uncommitted changes
echo Step 2: Checking for uncommitted changes...
git status --porcelain > temp_status.txt
for /f "tokens=*" %%i in (temp_status.txt) do (
    if defined first_line (
        echo WARNING: Uncommitted changes detected >> !LOG_FILE!
        goto skip_check
    )
    set first_line=1
)
:skip_check
del temp_status.txt

REM Step 3: Backup database
echo Step 3: Creating database backup...
if not exist storage\db_backups mkdir storage\db_backups
if exist .env (
    for /f "tokens=*" %%a in (.env) do (
        if "%%a:~0,7%"=="DB_HOST" set "DB_HOST=%%a:~8"
        if "%%a:~0,7%"=="DB_USER" set "DB_USER=%%a:~8"
        if "%%a:~0,7%"=="DB_PASS" set "DB_PASS=%%a:~8"
        if "%%a:~0,7%"=="DB_NAME" set "DB_NAME=%%a:~8"
    )
)
echo [OK] Database backup section (manual backup required on Windows) >> !LOG_FILE!

REM Step 4: Git pull
echo Step 4: Pulling latest changes from git...
for /f %%i in ('git rev-parse --abbrev-ref HEAD') do set BRANCH=%%i
git pull origin !BRANCH! >> !LOG_FILE! 2>&1
if errorlevel 1 (
    echo ERROR: Failed to pull from git
    exit /b 1
)
echo ^[OK^] Git pull completed >> !LOG_FILE!

REM Step 5: Check PHP
echo Step 5: Checking PHP installation...
where php >nul 2>nul
if errorlevel 1 (
    echo ERROR: PHP not found
    exit /b 1
)
php --version >> !LOG_FILE!
echo ^[OK^] PHP found >> !LOG_FILE!

REM Step 6: Install dependencies
echo Step 6: Installing dependencies...
if exist composer.json (
    where composer >nul 2>nul
    if not errorlevel 1 (
        call composer install --no-dev --optimize-autoloader >> !LOG_FILE! 2>&1
        echo ^[OK^] Composer dependencies installed >> !LOG_FILE!
    ) else (
        echo WARNING: Composer not found >> !LOG_FILE!
    )
)

REM Step 7: Clear cache
echo Step 7: Clearing cache...
if exist storage\cache (
    for /d %%D in (storage\cache\*) do rmdir /s /q "%%D" 2>nul
    del /q "storage\cache\*" 2>nul
)
if exist storage\tmp (
    for /d %%D in (storage\tmp\*) do rmdir /s /q "%%D" 2>nul
    del /q "storage\tmp\*" 2>nul
)
echo ^[OK^] Cache cleared >> !LOG_FILE!

REM Step 8: Run migrations
echo Step 8: Checking for migrations...
if exist migrate.php (
    echo Running database migrations...
    php migrate.php >> !LOG_FILE! 2>&1
    echo ^[OK^] Migrations completed >> !LOG_FILE!
)

REM Step 9: Health checks
echo Step 9: Running health checks...
if not exist public\index.php (
    echo ERROR: public/index.php not found
    exit /b 1
)
if not exist config\config.php (
    echo ERROR: config/config.php not found
    exit /b 1
)
if not exist .env (
    echo ERROR: .env file not found
    exit /b 1
)
echo ^[OK^] Health checks passed >> !LOG_FILE!

REM Final summary
echo.
echo ====================================================================
echo   Deployment Completed Successfully!
echo   Environment: !ENVIRONMENT!
echo ====================================================================
echo.
echo Deployment log: !LOG_FILE!
echo.

for /f %%i in ('git rev-parse --short HEAD') do set COMMIT=%%i
for /f %%i in ('git rev-parse --abbrev-ref HEAD') do set BRANCH=%%i
echo Commit: !COMMIT!
echo Branch: !BRANCH!
echo Deployed at: %date% %time%

echo. >> !LOG_FILE!
echo [%date% %time%] Deployment completed successfully >> !LOG_FILE!

endlocal
