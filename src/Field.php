<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

/** A form field and its validation rules. */
final class Field
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly ?int $max = null,
        public readonly bool $email = false,
        /** Long text: goes last in the plain-text email and gets a "{name}Html" template variable with <br>. */
        public readonly bool $multiline = false,
        /** Error message when invalid; null uses the endpoint's "invalid_field" message. */
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
