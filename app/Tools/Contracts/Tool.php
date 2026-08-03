<?php

namespace App\Tools\Contracts;

use App\Tools\ToolResult;

interface Tool
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): array;

    /**
     * $approved is set only by the caller's own PHP code after Gate::decide() (or Loop's
     * internal bookkeeping reads) — never derived from $input. Tools must not read an
     * 'approved'/'approval' key out of $input as a substitute for this parameter.
     */
    public function execute(array $input, bool $approved = false): ToolResult;
}
