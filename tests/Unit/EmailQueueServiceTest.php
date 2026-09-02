<?php
/**
 * tests/Unit/EmailQueueServiceTest.php
 *
 * PHPUnit tests for EmailQueueService.
 * Tests email queueing, processing, and cleanup.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class EmailQueueServiceTest extends TestCase
{
    private ?\mysqli $mysqli;
    private ?EmailQueueService $service;

    protected function setUp(): void
    {
        // Skip if no test database configured
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
            $this->markTestSkipped('Cannot connect to test database: ' . $this->mysqli->connect_error);
        }

        $this->mysqli->set_charset('utf8mb4');
        $this->service = new EmailQueueService($this->mysqli);

        // Ensure table exists
        $this->service->ensureTable();

        // Clean up test data
        $this->mysqli->query("DELETE FROM chat_email_queue WHERE recipient_email LIKE '%@test.example.com'");
    }

    protected function tearDown(): void
    {
        if ($this->mysqli && !$this->mysqli->connect_error) {
            $this->mysqli->query("DELETE FROM chat_email_queue WHERE recipient_email LIKE '%@test.example.com'");
            $this->mysqli->close();
        }
    }

    public function testQueueAddsEmailToDatabase(): void
    {
        $id = $this->service->queue(
            'test@example.com',
            'Test Subject',
            '<p>Test body</p>',
            ['recipient_name' => 'Test User']
        );

        $this->assertGreaterThan(0, $id);

        // Verify the email is in the database
        $stmt = $this->mysqli->prepare("SELECT * FROM chat_email_queue WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertEquals('test@example.com', $row['recipient_email']);
        $this->assertEquals('Test Subject', $row['subject']);
        $this->assertEquals('queued', $row['status']);
        $this->assertEquals(0, $row['attempts']);
    }

    public function testQueueWithScheduledAtFuture(): void
    {
        $futureTime = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $id = $this->service->queue(
            'future@test.example.com',
            'Future Email',
            '<p>Future body</p>',
            ['scheduled_at' => $futureTime]
        );

        $this->assertGreaterThan(0, $id);

        // Verify it's queued for the future
        $stmt = $this->mysqli->prepare("SELECT scheduled_at FROM chat_email_queue WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertEquals($futureTime, $row['scheduled_at']);
    }

    public function testGetStatsReturnsCounts(): void
    {
        // Add some test emails
        $this->service->queue('stats1@test.example.com', 'Test 1', '<p>Body 1</p>');
        $this->service->queue('stats2@test.example.com', 'Test 2', '<p>Body 2</p>');

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('queued', $stats);
        $this->assertArrayHasKey('sent', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['queued']);
    }

    public function testCancelSetsCancelledStatus(): void
    {
        $id = $this->service->queue(
            'cancel@test.example.com',
            'Cancel Test',
            '<p>Will be cancelled</p>'
        );

        $this->service->cancel($id);

        $stmt = $this->mysqli->prepare("SELECT status FROM chat_email_queue WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertEquals('cancelled', $row['status']);
    }

    public function testCleanupRemovesOldEntries(): void
    {
        // Insert an old entry
        $this->mysqli->query("
            INSERT INTO chat_email_queue (recipient_email, subject, body, status, created_at)
            VALUES ('old@test.example.com', 'Old', '<p>Old</p>', 'sent', DATE_SUB(NOW(), INTERVAL 60 DAY))
        ");

        $deleted = $this->service->cleanup(30);

        $this->assertGreaterThanOrEqual(1, $deleted);
    }
}
