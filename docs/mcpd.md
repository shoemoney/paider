# MCP and mcpd

## Use mcpd tools in Paider

Install [Mozilla mcpd](https://github.com/mozilla-ai/mcpd) and the runtimes required by your servers.
In the project directory:

```sh
mcpd init
mcpd add time
paider mcpd:daemon
```

This runs mcpd in the foreground on `127.0.0.1:8090`. Ctrl-C stops it. `.mcpd.toml` is
required. Use `--addr` to select another listen address. Paider does not install the daemon
or start project-defined executables automatically.

In a second terminal:

```sh
PAIDER_MCPD_URL=http://127.0.0.1:8090 paider chat
# also supported by paider run
```

Paider discovers servers and full tool schemas from mcpd's HTTP API. Tool calls use the
normal Paider approval gate. An unavailable daemon fails discovery visibly. Remote calls
have timeouts, reject redirects, and never retry automatically. The endpoint must come
from the actual process environment; a repository cannot opt itself in through `.paider/.env`.
Only configure endpoints you trust: mcpd tools execute with the daemon's permissions.

This operates independently of the older `PAIDER_MCP`/`mcp.json` adapter, whose direct SDK
execution remains a placeholder. The mcpd HTTP API returns the first text content from a tool,
so other MCP content types are not exposed by this adapter.

## Expose Paider project tools

```sh
paider mcp:serve --root=/absolute/project/path
```

This is a stdio MCP server with `read_file`. Add `--allow-writes` to expose `write_file`
and `patch_file`. Project containment and sensitive-file guards remain active. There are no
interactive approval prompts over stdio; sensitive files stay denied. Protocol output uses stdout.
Configure an MCP host to launch the command with these arguments. This is separate from the
mcpd HTTP client; mcpd manages its supported server packages using `.mcpd.toml`.

## PHP mcpd plugins

The standalone [PHP plugin SDK](https://github.com/shoemoney/mcpd-plugins-sdk-php) implements
mcpd's gRPC request/response hooks using PHP and RoadRunner. See its README for installation,
plugin examples, and `.mcpd.toml` registration.
