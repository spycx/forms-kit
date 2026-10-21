<?php

declare(strict_types=1);

namespace Spycx\FormsKit\Tests;

use PHPUnit\Framework\TestCase;
use Spycx\FormsKit\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private string $dir;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/forms-kit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function limiter(int $max = 3, int $window = 60): RateLimiter
    {
        return new RateLimiter($this->dir, $max, $window, fn(): int => $this->now);
    }

    public function testAllowsUpToMaxThenBlocks(): void
    {
        $limiter = $this->limiter();
        $this->assertTrue($limiter->attempt('1.2.3.4'));
        $this->assertTrue($limiter->attempt('1.2.3.4'));
        $this->assertTrue($limiter->attempt('1.2.3.4'));
        $this->assertFalse($limiter->attempt('1.2.3.4'));
    }

    public function testKeysAreIndependent(): void
    {
        $limiter = $this->limiter(max: 1);
        $this->assertTrue($limiter->attempt('a'));
        $this->assertFalse($limiter->attempt('a'));
        $this->assertTrue($limiter->attempt('b'));
    }

    public function testWindowSlides(): void
    {
        $limiter = $this->limiter(max: 2, window: 60);
        $limiter->attempt('ip');
        $this->now += 30;
        $limiter->attempt('ip');
        $this->assertFalse($limiter->attempt('ip'));

        $this->now += 31; // el primero ya salió de la ventana
        $this->assertTrue($limiter->attempt('ip'));
        $this->assertFalse($limiter->attempt('ip'));
    }

    public function testPersistsAcrossInstances(): void
    {
        $this->limiter(max: 1)->attempt('ip');
        $this->assertFalse($this->limiter(max: 1)->attempt('ip'));
    }

    public function testFileNameDoesNotExposeTheKey(): void
    {
        $this->limiter()->attempt('203.0.113.9');
        $files = array_map('basename', glob($this->dir . '/*') ?: []);
        $this->assertSame([hash('sha256', '203.0.113.9')], $files);
    }

    public function testFailsOpenWhenStorageIsUnusable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fk');
        try {
            $limiter = new RateLimiter($file . '/sub', 1, 60);
            $this->assertTrue($limiter->attempt('ip'));
            $this->assertTrue($limiter->attempt('ip'));
        } finally {
            unlink($file);
        }
    }
}
