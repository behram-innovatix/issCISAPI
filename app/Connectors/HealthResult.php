<?php

namespace App\Connectors;

final readonly class HealthResult
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}

    public static function ok(string $message = 'Connected'): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
