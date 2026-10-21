<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

use Closure;
use InvalidArgumentException;

/**
 * Stateless anti-spam filter: honeypot + signed token with a minimum age and an expiry.
 *
 * The token is "timestamp.signature" (HMAC-SHA256). The JS requests it on page load and
 * sends it in a hidden field, so we know how long the form has been open without storing
 * anything on the server.
 */
final class FormGuard
{
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock Source of the current time (for tests).
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $minSeconds = 3,
        private readonly int $maxAge = 86400,
        private readonly string $honeypotField = 'website',
        private readonly string $tokenField = 'form_token',
        ?Closure $clock = null,
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('FormGuard needs a non-empty secret.');
        }
        $this->clock = $clock ?? time(...);
    }

    public function issueToken(): string
    {
        $ts = (string) ($this->clock)();
        return $ts . '.' . hash_hmac('sha256', $ts, $this->secret);
    }

    /** Token age in seconds, or null if it's missing or the signature is invalid. */
    public function tokenAge(string $token): ?int
    {
        if (!preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $token, $m)) {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $m[1], $this->secret), $m[2])) {
            return null;
        }
        return ($this->clock)() - (int) $m[1];
    }

    /** @param array<string, mixed> $input POST data. */
    public function check(array $input): Verdict
    {
        if (!empty($input[$this->honeypotField])) {
            return Verdict::Spam;
        }

        $token = $input[$this->tokenField] ?? '';
        $age = is_string($token) ? $this->tokenAge($token) : null;
        if ($age === null || $age > $this->maxAge) {
            return Verdict::Expired;
        }

        return $age < $this->minSeconds ? Verdict::Spam : Verdict::Ok;
    }
}
