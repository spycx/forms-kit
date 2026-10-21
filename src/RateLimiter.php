<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

use Closure;

/**
 * Límite de intentos por clave (normalmente la IP) en una ventana de tiempo.
 *
 * Guarda las marcas de tiempo en un archivo JSON por clave (nombre = hash de la clave)
 * con bloqueo exclusivo. Si no puede escribir en el directorio, no bloquea a nadie.
 */
final class RateLimiter
{
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock Fuente del tiempo actual (para pruebas).
     */
    public function __construct(
        private readonly string $dir,
        private readonly int $max = 5,
        private readonly int $window = 3600,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Registra un intento para la clave y dice si sigue dentro del límite. */
    public function attempt(string $key): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return true;
        }
        $fh = @fopen($this->dir . '/' . hash('sha256', $key), 'c+');
        if ($fh === false) {
            return true;
        }
        flock($fh, LOCK_EX);

        $now  = ($this->clock)();
        $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
        $hits = array_values(array_filter(
            is_array($hits) ? $hits : [],
            fn($t): bool => is_int($t) && $t > $now - $this->window
        ));
        $allowed = count($hits) < $this->max;
        if ($allowed) {
            $hits[] = $now;
        }

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        flock($fh, LOCK_UN);
        fclose($fh);
        return $allowed;
    }
}
