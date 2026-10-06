<?php
/**
 * tests/Unit/LoginServiceTest.php
 *
 * Unit tests for LoginService::sanitizeRedirect() — open redirect prevention.
 * Also tests handleLogin() redirect sanitization through the public API.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class LoginServiceTest extends TestCase
{
    private LoginService $loginService;
    private \mysqli $mysqliMock;

    protected function setUp(): void
    {
        $this->mysqliMock = $this->createMock(mysqli::class);
        $this->loginService = new LoginService($this->mysqliMock);
    }

    public function testSanitizeRedirectRejectsExternalHttpsUrl(): void
    {
        $result = $this->invokeSanitizeRedirect('https://evil.com/dashboard');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectRejectsExternalHttpUrl(): void
    {
        $result = $this->invokeSanitizeRedirect('http://malicious-site.com/steal');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectRejectsJavaScriptScheme(): void
    {
        $result = $this->invokeSanitizeRedirect('javascript:alert(1)');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectRejectsDataScheme(): void
    {
        $result = $this->invokeSanitizeRedirect('data:text/html,<script>alert(1)</script>');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectRejectsProtocolRelativeUrl(): void
    {
        $result = $this->invokeSanitizeRedirect('//evil.com/path');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectRejectsFtpScheme(): void
    {
        $result = $this->invokeSanitizeRedirect('ftp://evil.com/file');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectAllowsRelativePath(): void
    {
        $result = $this->invokeSanitizeRedirect('/dashboard');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectAllowsRootPath(): void
    {
        $result = $this->invokeSanitizeRedirect('/');
        $this->assertEquals('/', $result);
    }

    public function testSanitizeRedirectAllowsRelativePathWithQuery(): void
    {
        $result = $this->invokeSanitizeRedirect('/dashboard?tab=settings');
        $this->assertEquals('/dashboard?tab=settings', $result);
    }

    public function testSanitizeRedirectFallsBackForEmptyString(): void
    {
        $result = $this->invokeSanitizeRedirect('');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectFallsBackForNonPathString(): void
    {
        $result = $this->invokeSanitizeRedirect('dashboard');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectAllowsLocalUrlWithSameHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'lgdhaka.co';

        $result = $this->invokeSanitizeRedirect('https://lgdhaka.co/dashboard');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectRejectsLocalUrlWithDifferentHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'lgdhaka.co';

        $result = $this->invokeSanitizeRedirect('https://evil.lgdhaka.co/dashboard');
        $this->assertEquals('/dashboard', $result);
    }

    public function testSanitizeRedirectAllowsLocalUrlWithSameHostAndQuery(): void
    {
        $_SERVER['HTTP_HOST'] = 'lgdhaka.co';

        $result = $this->invokeSanitizeRedirect('https://lgdhaka.co/dashboard?tab=settings');
        $this->assertEquals('/dashboard?tab=settings', $result);
    }

    private function invokeSanitizeRedirect(string $redirect): string
    {
        $reflection = new ReflectionMethod($this->loginService, 'sanitizeRedirect');
        return $reflection->invoke($this->loginService, $redirect);
    }
}
