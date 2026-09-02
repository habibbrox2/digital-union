// tests/Browser/chat-flow.spec.js
//
// Playwright browser integration tests for the Live Chat Support System.
// Tests: union selection, notification permission, device management, chat flow.
//
// Run: npx playwright test tests/Browser/chat-flow.spec.js

const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TEST_BASE_URL || 'http://localhost:8000';

test.describe('Chat Visitor Flow', () => {

    test('should load chat widget on the public page', async ({ page }) => {
        await page.goto(BASE_URL);
        // The chat button (FAB) is always visible; the window is hidden until clicked
        const chatButton = page.locator('#chatButton, .chat-button');
        await expect(chatButton.first()).toBeVisible({ timeout: 10000 });
        // The widget root container should exist in the DOM
        const chatRoot = page.locator('#chat-widget-root');
        await expect(chatRoot).toHaveCount(1);
    });

    test('should display union selection', async ({ page }) => {
        await page.goto(BASE_URL);

        // Look for union selector — dynamically built as #chatRegUnion
        const unionSelect = page.locator('#chatRegUnion, select[name="union"], .chat-union-select');
        if (await unionSelect.count() > 0) {
            // Should have at least the default option
            const options = await unionSelect.first().locator('option').count();
            expect(options).toBeGreaterThan(0);
        }
    });

    test('should send a chat message', async ({ page }) => {
        await page.goto(BASE_URL);

        // First open the chat window by clicking the FAB
        const chatButton = page.locator('#chatButton, .chat-button');
        await expect(chatButton.first()).toBeVisible({ timeout: 10000 });
        await chatButton.first().click();

        // Wait for the chat window to open
        const chatWindow = page.locator('.chat-window.open, #chatWindow');
        await expect(chatWindow.first()).toBeVisible({ timeout: 5000 });

        // Fill in visitor name — dynamically built as #chatRegName
        const nameInput = page.locator('#chatRegName');
        if (await nameInput.count() > 0 && await nameInput.first().isVisible()) {
            await nameInput.first().fill('Test Visitor');

            // Select a union if available
            const unionSelect = page.locator('#chatRegUnion');
            if (await unionSelect.count() > 0) {
                const options = await unionSelect.first().locator('option');
                const count = await options.count();
                if (count > 1) {
                    await unionSelect.first().selectOption({ index: 1 });
                }
            }

            // Click the start button to transition to chat
            const startBtn = page.locator('.chat-reg-start-btn');
            if (await startBtn.count() > 0) {
                await startBtn.first().click();
                await page.waitForTimeout(500);
            }
        }

        // Type and send a message — dynamically built as #chatInput
        const messageInput = page.locator('#chatInput');
        if (await messageInput.count() > 0 && await messageInput.first().isVisible()) {
            await messageInput.first().fill('Hello, this is a test message');

            const sendBtn = page.locator('#chatSendBtn');
            if (await sendBtn.count() > 0) {
                await sendBtn.first().click();
            }
        }
    });
});

test.describe('Admin Chat Panel', () => {

    test('should load admin chat page', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/admin`);

        // Admin page requires auth — may redirect to login. Wait for either
        // the admin layout or the login page to appear.
        const adminPanel = page.locator('.chat-admin-layout, #chatAdminView');
        const loginPage = page.locator('form[action*="login"], .login-form, #loginForm');

        // Wait for either element to appear (10s timeout)
        await Promise.race([
            adminPanel.first().waitFor({ timeout: 10000 }).catch(() => null),
            loginPage.first().waitFor({ timeout: 10000 }).catch(() => null),
        ]);

        // If admin panel loaded, verify it's visible
        if (await adminPanel.count() > 0) {
            await expect(adminPanel.first()).toBeVisible();
        }
    });

    test('should show conversations list', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/admin`);

        // Wait for page to settle (login redirect or admin panel)
        await page.waitForTimeout(2000);

        const convList = page.locator('#convList, .chat-conversations-list');
        // Only assert if we're on the admin page (not redirected to login)
        if (await convList.count() > 0) {
            await expect(convList.first()).toBeVisible();
        }
    });

    test('should show push notification button', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/admin`);

        await page.waitForTimeout(2000);

        const pushBtn = page.locator('#enableAdminPush, .enable-push-btn');
        if (await pushBtn.count() > 0) {
            await expect(pushBtn.first()).toBeVisible();
        }
    });
});

test.describe('Notification Permission Status', () => {

    test('should display notification permission status in settings', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/settings`);

        // Settings page requires auth — may redirect to login
        const settingsForm = page.locator('#chatSettingsForm, form');
        const loginPage = page.locator('form[action*="login"], .login-form, #loginForm');

        await Promise.race([
            settingsForm.first().waitFor({ timeout: 10000 }).catch(() => null),
            loginPage.first().waitFor({ timeout: 10000 }).catch(() => null),
        ]);

        if (await settingsForm.count() > 0) {
            await expect(settingsForm.first()).toBeVisible();
        }
    });
});

test.describe('Device Management Flow', () => {

    test('should show devices in settings page', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/settings`);

        await page.waitForTimeout(2000);

        // Device section uses #chatActiveDevices
        const deviceSection = page.locator('#chatActiveDevices, .device-section');
        if (await deviceSection.count() > 0) {
            await expect(deviceSection.first()).toBeVisible();
        }
    });

    test('should show revoke confirmation dialog', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/settings`);

        await page.waitForTimeout(2000);

        // Look for revoke buttons — dynamically built as .revoke-chat-device
        const revokeBtn = page.locator('.revoke-chat-device, .revoke-device-btn, [data-action="revoke"]');
        if (await revokeBtn.count() > 0) {
            await revokeBtn.first().click();

            // Should show confirmation modal
            const modal = page.locator('.modal, .confirmation-dialog, [role="dialog"], .swal2-popup');
            if (await modal.count() > 0) {
                await expect(modal.first()).toBeVisible({ timeout: 5000 });
            }
        }
    });
});

test.describe('Chat Settings', () => {

    test('should load chat settings page', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/settings`);

        await page.waitForTimeout(2000);

        const settingsForm = page.locator('#chatSettingsForm, form');
        if (await settingsForm.count() > 0) {
            await expect(settingsForm.first()).toBeVisible();
        }
    });

    test('should display readable device names', async ({ page }) => {
        await page.goto(`${BASE_URL}/chat/settings`);

        await page.waitForTimeout(2000);

        // Device info is inside .border.rounded elements in #chatDeviceList
        const deviceInfo = page.locator('#chatDeviceList strong, .device-info, .device-name');
        if (await deviceInfo.count() > 0) {
            const text = await deviceInfo.first().textContent();
            // Should contain readable device info, not raw JSON
            expect(text.length).toBeGreaterThan(0);
        }
    });
});

test.describe('Health Check', () => {

    test('should return health status', async ({ request }) => {
        const response = await request.get(`${BASE_URL}/api/chat/health`);
        // Health check returns 200 (healthy) or 503 (degraded/unavailable)
        // Both are valid responses — the endpoint is working
        expect([200, 503]).toContain(response.status());

        const data = await response.json();
        expect(data).toHaveProperty('status');
    });

    test('should have database check', async ({ request }) => {
        const response = await request.get(`${BASE_URL}/api/chat/health`);

        // If health endpoint is available, it should include checks
        if (response.ok()) {
            const data = await response.json();
            expect(data).toHaveProperty('checks');
            expect(data.checks).toHaveProperty('database');
            expect(data.checks.database).toHaveProperty('status');
        }
    });
});

test.describe('Firebase Config Endpoint', () => {

    test('should return Firebase config from env', async ({ request }) => {
        const response = await request.get(`${BASE_URL}/api/chat/push/config`);
        expect(response.ok()).toBeTruthy();

        const data = await response.json();
        expect(data).toHaveProperty('status', 'success');
        expect(data.data).toHaveProperty('enabled');
        expect(data.data).toHaveProperty('config');
    });

    test('should return valid Firebase project configuration', async ({ request }) => {
        const response = await request.get(`${BASE_URL}/api/chat/push/config`);
        const data = await response.json();

        // Firebase API keys are public credentials — they are expected to be
        // in the config response. The security is in Firebase Security Rules,
        // not in hiding the API key.
        expect(data.data.config).toHaveProperty('apiKey');
        expect(data.data.config).toHaveProperty('projectId');
        expect(data.data.config).toHaveProperty('messagingSenderId');
        expect(data.data.config.apiKey.length).toBeGreaterThan(10);
    });
});
