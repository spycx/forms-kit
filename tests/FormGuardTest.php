<?php

declare(strict_types=1);

namespace Spycx\FormsKit\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Spycx\FormsKit\FormGuard;
use Spycx\FormsKit\Verdict;

final class FormGuardTest extends TestCase
{
    private int $now = 1_800_000_000;

    private function guard(string $secret = 'secreto'): FormGuard
    {
        return new FormGuard($secret, clock: fn(): int => $this->now);
    }

    public function testTokenIsTimestampAndHexSignature(): void
    {
        $this->assertMatchesRegularExpression('/^1800000000\.[0-9a-f]{64}$/', $this->guard()->issueToken());
    }

    public function testAgeOfIssuedToken(): void
    {
        $token = $this->guard()->issueToken();
        $this->now += 42;
        $this->assertSame(42, $this->guard()->tokenAge($token));
    }

    public function testRejectsMalformedTamperedOrForeignTokens(): void
    {
        $token = $this->guard()->issueToken();
        [$ts, $sig] = explode('.', $token);

        $this->assertNull($this->guard()->tokenAge(''));
        $this->assertNull($this->guard()->tokenAge('basura'));
        $this->assertNull($this->guard()->tokenAge(($ts - 100) . '.' . $sig), 'timestamp changed');
        $this->assertNull($this->guard()->tokenAge($ts . '.' . str_repeat('0', 64)), 'fake signature');
        $this->assertNull($this->guard('otra-clave')->tokenAge($token), 'different key');
    }

    public function testValidTokenAfterMinimumPasses(): void
    {
        $token = $this->guard()->issueToken();
        $this->now += 3;
        $this->assertSame(Verdict::Ok, $this->guard()->check(['form_token' => $token]));
    }

    public function testTooFastIsSpam(): void
    {
        $token = $this->guard()->issueToken();
        $this->now += 2;
        $this->assertSame(Verdict::Spam, $this->guard()->check(['form_token' => $token]));
    }

    public function testHoneypotIsSpamEvenWithValidToken(): void
    {
        $token = $this->guard()->issueToken();
        $this->now += 10;
        $this->assertSame(Verdict::Spam, $this->guard()->check(['form_token' => $token, 'website' => 'http://x']));
    }

    public function testMissingForgedOrOldTokenIsExpired(): void
    {
        $token = $this->guard()->issueToken();
        $this->assertSame(Verdict::Expired, $this->guard()->check([]));
        $this->assertSame(Verdict::Expired, $this->guard()->check(['form_token' => ['array']]));
        $this->assertSame(Verdict::Expired, $this->guard('otra')->check(['form_token' => $token]));

        $this->now += 86401;
        $this->assertSame(Verdict::Expired, $this->guard()->check(['form_token' => $token]));
    }

    public function testCustomFieldNamesAndWindow(): void
    {
        $guard = new FormGuard('k', minSeconds: 10, maxAge: 60, honeypotField: 'url', tokenField: 't', clock: fn(): int => $this->now);
        $token = $guard->issueToken();

        $this->now += 5;
        $this->assertSame(Verdict::Spam, $guard->check(['t' => $token]));
        $this->now += 10;
        $this->assertSame(Verdict::Ok, $guard->check(['t' => $token, 'website' => 'ignorado']));
        $this->assertSame(Verdict::Spam, $guard->check(['t' => $token, 'url' => 'x']));
        $this->now += 60;
        $this->assertSame(Verdict::Expired, $guard->check(['t' => $token]));
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FormGuard('');
    }
}
