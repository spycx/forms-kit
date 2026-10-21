<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

/** FormEndpoint's JSON response, kept separate from sending it so it can be tested. */
final class Response
{
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = [],
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
    }
}
