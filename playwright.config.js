const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: 'tests/Browser',
    use: {
        baseURL: process.env.TEST_BASE_URL || 'http://localhost:8000',
        headless: true,
        trace: 'on-first-retry',
    },
    webServer: {
        command: 'php -S 0.0.0.0:8000 -t public public/router.php',
        url: 'http://localhost:8000',
        timeout: 15000,
        reuseExistingServer: true,
    },
});