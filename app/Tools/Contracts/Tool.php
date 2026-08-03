<?php

namespace App\Tools\Contracts;

use App\Tools\ToolResult;

interface Tool
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): array;

    public function execute(array $input): ToolResult;
}
