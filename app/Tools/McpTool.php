<?php

namespace App\Tools;

use App\Tools\Contracts\Tool;

/**
 * Thin Tool adapter for a single MCP server tool.
 * Wraps an MCP tool definition discovered via the PHP SDK into Paider's Tool contract.
 * The adapter is deliberately minimal: it forwards execute() to the underlying MCP client.
 */
class McpTool implements Tool
{
    public function __construct(
        private readonly string $toolName,
        private readonly string $description,
        private readonly array $inputSchema,
        /** @var callable(array, bool): ToolResult */
        private readonly mixed $executor,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    public function execute(array $input, bool $approved = false): ToolResult
    {
        return ($this->executor)($input, $approved);
    }
}
