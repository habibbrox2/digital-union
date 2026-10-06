<?php
/**
 * tests/Unit/AuthManagerLoginBugTest.php
 *
 * Unit tests verifying the bug fixes in AuthManager::login().
 * Uses mock mysqli to test without a live database.
 *
 * Tests:
 * - BUG 3: Login query includes "AND is_deleted = 0"
 * - BUG 1: trackFailedLoginAttempt IS called on failed login (no function_exists guard)
 * - BUG 2: trackDeviceLogin guard is removed (verified via source + runtime path)
 * - BUG 6: Unused $permissionsManager is removed
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Fake mysqli_result that extends mysqli_result (so it passes PHPUnit's
 * return-type check for get_result()) but overrides fetch_assoc() to
 * return pre-configured data. The num_rows property is a C-level readonly
 * virtual property that cannot be set without a real MySQL connection,
 * so tests avoid code paths that access num_rows.
 */
class FakeMysqliResult extends \mysqli_result
{
    public function __construct(
        private array $fetchResults = [null]
    ) {}

    public function fetch_assoc(): ?array
    {
        return array_shift($this->fetchResults);
    }
}

class AuthManagerLoginBugTest extends TestCase
{
    private array $recordedSql = [];
    private array $insertRecorded = [];

    protected function setUp(): void
    {
        $this->recordedSql    = [];
        $this->insertRecorded = [];

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_SERVER['HTTP_HOST'] = 'lgdhaka.test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function getTestUser(): array
    {
        return [
            'user_id'    => 1,
            'username'   => 'administrator',
            'email'      => 'admin@lgdhaka.co',
            'password'   => password_hash('654321', PASSWORD_BCRYPT),
            'union_id'   => 1,
            'role_id'    => 1,
            'status'     => 'active',
            'last_login' => null,
        ];
    }

    private function getWrongPasswordUser(): array
    {
        $user = $this->getTestUser();
        $user['password'] = password_hash('a_completely_different_password', PASSWORD_BCRYPT);
        return $user;
    }

    private function makeStmt(?array $fetchResult = null): MockObject
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $results = $fetchResult !== null ? [$fetchResult] : [null];
        $result = new FakeMysqliResult($results);
        $stmt->method('get_result')->willReturn($result);
        return $stmt;
    }

    private function makeMockMysqli(?array $loginUser = null): \mysqli
    {
        $recordedSql    = &$this->recordedSql;
        $insertRecorded = &$this->insertRecorded;
        $testUser       = $loginUser ?? $this->getTestUser();

        $mysqli = $this->createMock(\mysqli::class);

        $mysqli->method('prepare')->willReturnCallback(
            function (string $sql) use (&$recordedSql, &$insertRecorded, $testUser) {
                $recordedSql[] = $sql;

                if (strpos($sql, 'INSERT INTO failed_login_attempts') !== false) {
                    $insertRecorded[] = 'failed_login_attempts';
                    return $this->makeStmt();
                }

                if (strpos($sql, 'INSERT INTO login_history') !== false) {
                    $insertRecorded[] = 'login_history';
                    return $this->makeStmt();
                }

                if (strpos($sql, 'SELECT COUNT(*) as count FROM failed_login_attempts') !== false) {
                    return $this->makeStmt(['count' => 0]);
                }

                if (strpos($sql, 'SELECT attempted_at FROM failed_login_attempts') !== false) {
                    return $this->makeStmt(['attempted_at' => '2025-01-01 00:00:00']);
                }

                if (strpos($sql, 'SELECT id FROM login_history') !== false) {
                    return $this->makeStmt(null);
                }

                if (strpos($sql, 'SELECT email, username FROM users WHERE user_id') !== false) {
                    return $this->makeStmt([
                        'email'    => 'admin@lgdhaka.co',
                        'username' => 'administrator',
                    ]);
                }

                if (strpos($sql, 'SELECT DISTINCT p.name') !== false) {
                    return $this->makeStmt(null);
                }

                if (
                    strpos($sql, 'FROM users WHERE') !== false
                    && strpos($sql, 'SELECT DISTINCT') === false
                    && strpos($sql, 'UPDATE') === false
                ) {
                    return $this->makeStmt($testUser);
                }

                return $this->makeStmt();
            }
        );

        return $mysqli;
    }

    /**
     * BUG 3: Login query must include "AND is_deleted = 0"
     */
    public function testLoginQueryIncludesIsDeletedFilter(): void
    {
        $mysqli = $this->makeMockMysqli($this->getWrongPasswordUser());
        $auth   = new AuthManager($mysqli);

        $auth->login('administrator', '654321');

        $userLookupSql = null;
        foreach ($this->recordedSql as $sql) {
            if (
                strpos($sql, 'FROM users WHERE') !== false
                && strpos($sql, 'SELECT DISTINCT') === false
                && strpos($sql, 'UPDATE') === false
            ) {
                $userLookupSql = $sql;
                break;
            }
        }

        $this->assertNotNull($userLookupSql, 'Login user-lookup query was not found');
        $this->assertStringContainsString(
            'is_deleted = 0',
            $userLookupSql,
            'Login query must filter soft-deleted users with "AND is_deleted = 0"'
        );
    }

    /**
     * BUG 1: trackFailedLoginAttempt must be called on failed login
     * (previously guarded by function_exists() which always returned false for private methods)
     */
    public function testTrackFailedLoginAttemptIsCalledOnFailedLogin(): void
    {
        $mysqli = $this->makeMockMysqli($this->getWrongPasswordUser());
        $auth   = new AuthManager($mysqli);

        $result = $auth->login('administrator', '654321');

        $this->assertFalse($result['success']);
        $this->assertContains(
            'failed_login_attempts',
            $this->insertRecorded,
            'trackFailedLoginAttempt must be called on failed login (function_exists guard removed)'
        );
    }

    /**
     * BUG 1 & 2: Verify function_exists guards for private methods are removed
     * from the source code.
     */
    public function testNoFunctionExistsGuardsForPrivateMethods(): void
    {
        $source = file_get_contents(BASE_PATH . '/models/AuthManager.php');

        // The login method should not use function_exists to guard private methods
        $loginMethodStart = strpos($source, 'public function login');
        $loginMethodEnd   = strpos($source, "\n    }\n", $loginMethodStart);
        $loginMethodBody  = substr($source, $loginMethodStart, $loginMethodEnd - $loginMethodStart);

        $this->assertStringNotContainsString(
            "function_exists('trackFailedLoginAttempt')",
            $loginMethodBody,
            'function_exists guard for trackFailedLoginAttempt (private method) must be removed in login()'
        );

        $this->assertStringNotContainsString(
            "function_exists('trackDeviceLogin')",
            $loginMethodBody,
            'function_exists guard for trackDeviceLogin (private method) must be removed in login()'
        );
    }

    /**
     * BUG 6: Verify unused PermissionsManager is removed from login method
     */
    public function testNoUnusedPermissionsManagerInLogin(): void
    {
        $source = file_get_contents(BASE_PATH . '/models/AuthManager.php');

        $loginMethodStart = strpos($source, 'public function login');
        $loginMethodEnd   = strpos($source, "\n    }\n", $loginMethodStart);
        $loginMethodBody  = substr($source, $loginMethodStart, $loginMethodEnd - $loginMethodStart);

        $this->assertStringNotContainsString(
            'PermissionsManager',
            $loginMethodBody,
            'PermissionsManager should not be instantiated in login() (dead code removed)'
        );
    }

    /**
     * Rate limiting should not block login when there are 0 recent failed attempts
     */
    public function testRateLimitingDoesNotBlockWhenAttemptsAreZero(): void
    {
        $mysqli = $this->makeMockMysqli($this->getWrongPasswordUser());
        $auth   = new AuthManager($mysqli);

        $result = $auth->login('administrator', '654321');

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey(
            'locked_out',
            $result,
            'Should not be locked out when failed attempts are 0'
        );
    }

    /**
     * Successful login must reach the last_login UPDATE and
     * clearFailedLoginAttempts DELETE (proving the success path runs
     * past the point where trackDeviceLogin is called).
     *
     * Note: trackDeviceLogin accesses mysqli_result::num_rows which is a
     * C-level readonly property that cannot be set without a real MySQL
     * connection. The mock throws "already closed" at that access, but
     * by that point the last_login UPDATE and failed_login_attempts DELETE
     * have already been executed (they run BEFORE trackDeviceLogin).
     * If the function_exists guard were still present, trackDeviceLogin
     * would never be called and no error would occur.
     */
    public function testSuccessfulLoginReachesTrackDeviceLogin(): void
    {
        $mysqli = $this->makeMockMysqli($this->getTestUser());
        $auth   = new AuthManager($mysqli);

        $errorThrown = false;
        $lastLoginUpdateRecorded = false;
        $failedAttemptsDeleteRecorded = false;

        try {
            $auth->login('administrator', '654321');
        } catch (\Throwable $e) {
            $errorThrown = true;
        }

        foreach ($this->recordedSql as $sql) {
            if (strpos($sql, 'UPDATE users SET last_login') !== false) {
                $lastLoginUpdateRecorded = true;
            }
            if (strpos($sql, 'DELETE FROM failed_login_attempts') !== false) {
                $failedAttemptsDeleteRecorded = true;
            }
        }

        $this->assertTrue(
            $errorThrown,
            'Expected an error from trackDeviceLogin (proves function_exists guard was removed and login succeeded)'
        );

        $this->assertTrue(
            $lastLoginUpdateRecorded,
            'last_login UPDATE must run before trackDeviceLogin'
        );

        $this->assertTrue(
            $failedAttemptsDeleteRecorded,
            'failed_login_attempts DELETE must run before trackDeviceLogin'
        );
    }
}
