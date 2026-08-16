# MCP / JSON-RPC

MCP *is* JSON-RPC 2.0, so this one endpoint covers agent-facing calls. mediary serves it
at **`/_mcp`**, from `symfony/mcp-bundle`.

> Superseded: this file used to document `ecourty/mcp-server-bundle` (`#[AsTool]` classes
> under `src/Mcp/Tools`, served at `/tools`). That package is no longer installed. The
> `/tools` route survived it and returned a 500 on every request —
> "Controller `mcp_server.entrypoint_controller` does neither exist as service nor as
> class" — until 2026-08-16. Both the route and `src/Mcp/` are gone.

## Wiring

- `config/bundles.php` — `McpBundle` is **dev/test only**. The endpoint is
  unauthenticated, and mediary can write assets and spend money on AI tasks.
- `config/packages/mcp.yaml` — `client_transports.http: true`. Both transports default to
  false, and with neither enabled `RouteLoader` registers **no route at all**, which is why
  `debug:router` can show nothing despite the bundle being installed.
- `config/routes/mcp.yaml` — `type: mcp`, scoped `when@dev` / `when@test` to match
  `bundles.php`. Loading it in prod fails with "no extension able to load the
  configuration for mcp".
- `http.allowed_hosts` — DNS-rebinding protection defaults to localhost only, so requests
  through the symfony proxy came back `403 Forbidden: Invalid Host header`. `mediary.wip`
  is allowed explicitly.

## Talking to it

Non-initialize requests need the session id returned by `initialize` in the
`Mcp-Session-Id` response header, and `Accept` must list both content types.

```bash
# 1. initialize, capturing the session id
SID=$(curl -s -D /tmp/h -o /dev/null -X POST \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' \
  https://mediary.wip/_mcp; grep -i '^mcp-session-id' /tmp/h | tr -d '\r' | cut -d' ' -f2)

# 2. list tools
curl -s -X POST -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' https://mediary.wip/_mcp | jq
```

## Adding a tool

Tools come from API Platform's experimental `mcp:` option on `#[ApiResource]` (4.3+), the
same pattern ssai uses — see `ssai/src/Entity/Acc.php`:

```php
#[ApiResource(
    operations: [new GetCollection()],
    mcp: [
        new McpTool(method: 'GET', uriTemplate: '/accs', name: 'list_accessions',
                    description: 'List accessions.'),
    ],
)]
```

**Known limitation.** This does not currently work for `App\Entity\Asset`. The tool
registers and `tools/call` routes to it, but API Platform's ObjectMapper path tries to
*instantiate* the entity to build the schema, and `Asset::__construct` requires
`$originalUrl`:

```
Argument #1 ($originalUrl) must be of type string, null given,
called in vendor/symfony/object-mapper/ObjectMapper.php on line 237
```

Exposing Asset needs a read-only output DTO (or an upstream fix). meili-bundle's five
tools (`meili_search_index`, `meili_get_document`, `meili_similar_documents`,
`meili_search_facets`, `meili_describe_collection`) register fine and were already there —
they had simply never been reachable while `/tools` was the only route.
