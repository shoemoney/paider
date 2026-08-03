<?php

namespace App\Tools;

final readonly class ToolResult
{
    public function __construct(
        public bool $ok,
        public string $output,
        public array $meta = [],
    ) {}

    public static function ok(string $output, array $meta = []): self
    {
        return new self(true, $output, $meta);
    }

    public static function fail(string $output, array $meta = []): self
    {
        return new self(false, $output, $meta);
    }
}
