<?php
/**
 * tests/Integration/DeviceRevokeTest.php
 *
 * PHPUnit tests for FCM device token revoke functionality.
 * Tests that device tokens can be revoked and will not receive push notifications.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class DeviceRevokeTest extends TestCase
{
    private ?\mysqli $mysqli;
    private ?ChatModel $chatModel;

    protected function setUp(): void
    {
        if (empty($_ENV['TEST_DB_HOST'])) {
            $this->markTestSkipped('TEST_DB_HOST not configured');
        }

        $this->mysqli = new \mysqli(
            $_ENV['TEST_DB_HOST'],
            $_ENV['TEST_DB_USER'],
            $_ENV['TEST_DB_PASS'],
            $_ENV['TEST_DB_NAME']
        );

        if ($this->mysqli->connect_error) {
            $this->markTestSkipped('Cannot connect to test database');
        }

        $this->mysqli->set_charset('utf8mb4');
        $this->chatModel = new ChatModel($this->mysqli);
    }

    protected function tearDown(): void
    {
        if ($this->mysqli && !$this->mysqli->connect_error) {
            // Clean up test data
            $this->mysqli->query("DELETE FROM fcm_tokens WHERE session_id LIKE 'test-device-%'");
            $this->mysqli->close();
        }
    }

    public function testRevokeSingleDevice(): void
    {
        $userId = $this->createTestAdmin();
        $tokenId = $this->createTestToken($userId, 'test-device-revoke-1');

        $this->chatModel->revokeDevice($userId, $tokenId);

        // Verify the device is revoked
        $stmt = $this->mysqli->prepare("SELECT revoked_at FROM fcm_tokens WHERE id = ?");
        $stmt->bind_param("i", $tokenId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row['revoked_at'], 'Device should have revoked_at set');
    }

    public function testRevokeAllDevices(): void
    {
        $userId = $this->createTestAdmin();
        $token1 = $this->createTestToken($userId, 'test-device-revoke-all-1');
        $token2 = $this->createTestToken($userId, 'test-device-revoke-all-2');

        $this->chatModel->revokeAllDevices($userId);

        // Verify both devices are revoked
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as cnt FROM fcm_tokens WHERE user_id = ? AND revoked_at IS NULL AND user_type = 'admin'");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertEquals(0, (int)$row['cnt'], 'All devices should be revoked');
    }

    public function testRevokedTokenNotReturnedByGetUserDevices(): void
    {
        $userId = $this->createTestAdmin();
        $tokenId = $this->createTestToken($userId, 'test-device-revoke-filter');

        // Before revoke, device should be visible
        $devices = $this->chatModel->getUserDevices($userId);
        $found = false;
        foreach ($devices as $d) {
            if ($d['id'] == $tokenId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Device should be visible before revoke');

        // Revoke it
        $this->chatModel->revokeDevice($userId, $tokenId);

        // After revoke, device should not be in active list
        $devices = $this->chatModel->getUserDevices($userId);
        $found = false;
        foreach ($devices as $d) {
            if ($d['id'] == $tokenId) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'Revoked device should not appear in active device list');
    }

    public function testAdminFcmTokensExcludesRevoked(): void
    {
        $userId = $this->createTestAdmin();
        $token = 'test-device-revoke-admin-' . bin2hex(random_bytes(8));
        $this->chatModel->saveDeviceToken('admin_' . $userId, $token, '{}', $userId);

        // Before revoke, token should be in admin tokens
        $tokens = $this->chatModel->getAllAdminFcmTokens();
        $found = false;
        foreach ($tokens as $t) {
            if ($t['fcm_token'] === $token) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Token should be in admin list before revoke');

        // Revoke
        $tokenId = 0;
        $result = $this->mysqli->query("SELECT id FROM fcm_tokens WHERE fcm_token = '" . $this->mysqli->real_escape_string($token) . "' LIMIT 1");
        if ($row = $result->fetch_assoc()) {
            $tokenId = (int)$row['id'];
        }
        $result->free();

        if ($tokenId > 0) {
            $this->chatModel->revokeDevice($userId, $tokenId);
        }

        // After revoke, token should not be in admin list
        $tokens = $this->chatModel->getAllAdminFcmTokens();
        $found = false;
        foreach ($tokens as $t) {
            if ($t['fcm_token'] === $token) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'Revoked token should not appear in admin FCM tokens');
    }

    // ---- Helpers ----

    private function createTestAdmin(): int
    {
        $email = 'test_device_admin_' . bin2hex(random_bytes(4)) . '@test.example.com';
        $stmt = $this->mysqli->prepare("
            INSERT INTO users (email, password, name_en, role_id, status, is_deleted, created_at)
            VALUES (?, '$2y$10$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuu', 'Test Admin', 1, 'active', 0, NOW())
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function createTestToken(int $userId, string $sessionId): int
    {
        $token = 'test-token-' . bin2hex(random_bytes(8));
        $deviceInfo = json_encode(['browser' => 'Chrome', 'platform' => 'Windows']);
        $stmt = $this->mysqli->prepare("
            INSERT INTO fcm_tokens (session_id, fcm_token, user_type, user_id, device_info, created_at, updated_at)
            VALUES (?, ?, 'admin', ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param("ssis", $sessionId, $token, $userId, $deviceInfo);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }
}
