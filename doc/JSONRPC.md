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

**Known limitation: read tools do not work for `App\Entity\Asset`.** Tested end to end
against a live `/_mcp` session on 2026-08-16, in three steps:

1. `Asset::__construct(string $originalUrl)` was required, so every `tools/call` failed with
   `Argument #1 ($originalUrl) must be of type string, null given` — API Platform builds the
   entity through `symfony/object-mapper`. Fixed properly: `$originalUrl` moved off the
   constructor and `Asset::fromOriginalUrl()` is now the only supported way to create one
   (id and url must be set together, since the id is `xxh3(originalUrl)`).
2. The call then failed reading the still-uninitialized property. Defaulting it to `''`
   made the call *succeed* — and return a freshly constructed empty entity
   (`originalUrl: ""`, `createdAt` = now, `marking: "new"`) instead of the query results.
   A silently wrong answer is worse than the crash, so that default was reverted; a bare
   `new Asset()` still throws.
3. Retried as an item tool (`uriTemplate: '/assets/{id}'`). Same shape: the response echoed
   the `id` that was passed in with every other field empty. No state provider ever runs.

Root cause for the listing half: `McpTool extends HttpOperation` but does **not** implement
`CollectionOperationInterface` (which `GetCollection` does), so a collection tool cannot be
expressed at all. The item half appears to be a straight round-trip of the tool arguments.

So Asset ships with no `mcp:` block. `meili_search_index` already covers asset lookup over
MCP and works. The `fromOriginalUrl()` factory is worth keeping regardless — it makes the
id/url coherence un-bypassable.

meili-bundle's five tools (`meili_search_index`, `meili_get_document`,
`meili_similar_documents`, `meili_search_facets`, `meili_describe_collection`) register fine
and were already there — they had simply never been reachable while `/tools` was the only
route.
