<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

use Closure;
use InvalidArgumentException;

/**
 * Filtro anti-spam sin estado: honeypot + token firmado con tiempo mínimo y caducidad.
 *
 * El token es "timestamp.firma" (HMAC-SHA256). El JS lo pide al cargar la página y lo
 * envía en un campo oculto; así se sabe cuánto tiempo lleva abierto el formulario sin
 * guardar nada en el servidor.
 */
final class FormGuard
{
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock Fuente del tiempo actual (para pruebas).
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
            throw new InvalidArgumentException('FormGuard necesita una clave no vacía.');
        }
        $this->clock = $clock ?? time(...);
    }

    public function issueToken(): string
    {
        $ts = (string) ($this->clock)();
        return $ts . '.' . hash_hmac('sha256', $ts, $this->secret);
    }

    /** Antigüedad del token en segundos, o null si falta o la firma no es válida. */
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

    /** @param array<string, mixed> $input Datos del POST. */
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
