<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

/** Un campo del formulario y sus reglas de validación. */
final class Field
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly ?int $max = null,
        public readonly bool $email = false,
        /** Texto largo: va al final del correo de texto y recibe la variable "{name}Html" con <br>. */
        public readonly bool $multiline = false,
        /** Mensaje de error si no valida; null usa el mensaje "invalid_field" del endpoint. */
        public readonly ?string $error = null,
    ) {
    }

    public function isValid(string $value): bool
    {
        if ($value === '') {
            return !$this->required;
        }
        if ($this->max !== null && mb_strlen($value) > $this->max) {
            return false;
        }
        return !$this->email || filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
