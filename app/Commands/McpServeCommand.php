<?php

namespace App\Commands;

use App\Tools\Contracts\Tool;
use App\Tools\PatchFileTool;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;
use LaravelZero\Framework\Commands\Command;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool as McpTool;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Transport\StdioTransport;

final class McpServeCommand extends Command
{
    protected $signature = 'mcp:serve {--root= : Project directory (defaults to cwd)} {--allow-writes : Expose write and patch tools}';

    protected $description = 'Expose project file tools as a stdio MCP server';

    public function handle(): int
    {
        $root = realpath($this->option('root') ?: getcwd());
        if ($root === false || ! is_dir($root)) {
            fwrite(STDERR, "Invalid project directory\n");

            return self::FAILURE;
        }
        $tools = [new ReadFileTool($root)];
        if ($this->option('allow-writes')) {
            $tools[] = new WriteFileTool($root);
            $tools[] = new PatchFileTool($root);
        }
        $builder = Server::builder()->setServerInfo('paider', '0.1.0');
        foreach ($tools as $tool) {
            $builder->add(new McpTool($tool->name(), null, $tool->inputSchema(), $tool->description(), null),
                new class($tool) implements ToolHandlerInterface
                {
                    public function __construct(private Tool $tool) {}

                    public function execute(array $arguments, ClientGateway $gateway): mixed
                    {
                        // No interactive grants over stdio: sensitive-file requests remain denied.
                        unset($arguments['approved'], $arguments['approval']);
                        $result = $this->tool->execute($arguments);

                        return new CallToolResult([new TextContent($result->output)], ! $result->ok);
                    }
                });
        }

        return $builder->build()->run(new StdioTransport);
    }
}
