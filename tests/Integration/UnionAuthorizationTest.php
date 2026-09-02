<?php
/**
 * tests/Integration/UnionAuthorizationTest.php
 *
 * PHPUnit tests for union access check on chat endpoints.
 * Tests that admin users can only access conversations within their union scope.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UnionAuthorizationTest extends TestCase
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
            // Clean up test sessions
            $this->mysqli->query("DELETE FROM chat_sessions WHERE session_id LIKE 'test-auth-%'");
            $this->mysqli->close();
        }
    }

    public function testAdminCanAccessAnySession(): void
    {
        // Admin role_id=1 should have access to any session
        $adminId = $this->createTestAdmin(1, null);
        $sessionId = $this->createTestSession('test-auth-admin-any', null);

        $result = $this->chatModel->canUserAccessSession($adminId, $sessionId);

        $this->assertTrue($result, 'Admin (role_id=1) should access any session');
    }

    public function testUnionAdminCannotAccessOtherUnionSession(): void
    {
        // Union admin (role_id=2) should only access their union's sessions
        $unionAdminId = $this->createTestAdmin(2, 100);
        $otherUnionSession = $this->createTestSession('test-auth-other-union', 200);

        $result = $this->chatModel->canUserAccessSession($unionAdminId, $otherUnionSession);

        $this->assertFalse($result, 'Union admin should not access other union sessions');
    }

    public function testUnionAdminCanAccessOwnUnionSession(): void
    {
        $unionAdminId = $this->createTestAdmin(2, 100);
        $ownUnionSession = $this->createTestSession('test-auth-own-union', 100);

        $result = $this->chatModel->canUserAccessSession($unionAdminId, $ownUnionSession);

        $this->assertTrue($result, 'Union admin should access own union sessions');
    }

    public function testInactiveUserCannotAccessAnySession(): void
    {
        $inactiveUserId = $this->createTestAdmin(1, null, 'inactive');
        $sessionId = $this->createTestSession('test-auth-inactive', null);

        $result = $this->chatModel->canUserAccessSession($inactiveUserId, $sessionId);

        $this->assertFalse($result, 'Inactive user should not access any session');
    }

    public function testDeletedUserCannotAccessAnySession(): void
    {
        $deletedUserId = $this->createTestAdmin(1, null, 'active', true);
        $sessionId = $this->createTestSession('test-auth-deleted', null);

        $result = $this->chatModel->canUserAccessSession($deletedUserId, $sessionId);

        $this->assertFalse($result, 'Deleted user should not access any session');
    }

    // ---- Helper methods ----

    private function createTestAdmin(int $roleId, ?int $unionId, string $status = 'active', bool $isDeleted = false): int
    {
        $email = 'test_admin_' . bin2hex(random_bytes(4)) . '@test.example.com';
        $stmt = $this->mysqli->prepare("
            INSERT INTO users (email, password, name_en, role_id, union_id, status, is_deleted, created_at)
            VALUES (?, '$2y$10$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuu', 'Test Admin', ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("siibi", $email, $roleId, $unionId, $status, $isDeleted);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function createTestSession(string $sessionId, ?int $unionId): string
    {
        if ($unionId !== null) {
            $stmt = $this->mysqli->prepare("
                INSERT INTO chat_sessions (session_id, visitor_name, union_id, status, created_at, updated_at)
                VALUES (?, 'Test Visitor', ?, 'active', NOW(), NOW())
            ");
            $stmt->bind_param("si", $sessionId, $unionId);
        } else {
            $stmt = $this->mysqli->prepare("
                INSERT INTO chat_sessions (session_id, visitor_name, status, created_at, updated_at)
                VALUES (?, 'Test Visitor', 'active', NOW(), NOW())
            ");
            $stmt->bind_param("s", $sessionId);
        }
        $stmt->execute();
        $stmt->close();
        return $sessionId;
    }
}
