# interserver/mcp-openapi-core

Shared core for InterServer's two MCP servers: OpenAPI-to-tools translation,
upstream proxying, and OAuth resource-server authentication.

| Consumer | Surface |
|---|---|
| [`interserver/my-mcp-server`](https://github.com/interserver/my-mcp-server) | `mcp.interserver.net` — client + public |
| [`interserver/my-admin-mcp-server`](https://github.com/interserver/my-admin-mcp-server) | `adminmcp.interserver.net` — admin, IP-allowlisted |

## Why this package exists

The two application repos this replaced were **byte-identical apart from a
namespace, one cache-dir string, and default URLs** — and after nine commits each
was missing half the other's fixes:

- the admin repo had the empty-string-coalesce fix, an explicit missing-config
  guard, and a route-before-build ordering fix; none were backported, so the
  client paid a full OpenAPI parse on every CORS preflight;
- the client repo had a working bundle pipeline; the admin repo's pointed at a
  file that did not exist;
- `isDestructiveOperation()` carried admin-only heuristics (`/admin/orders/`, an
  `^admin(Cancel|Delete|Refund|…)` regex) in **both**, so the client shipped rules
  that could never match one of its own paths.

Everything that differs between the two surfaces is therefore **data** here — a
`Profile` row, a `ScopeMap`, a `DestructiveClassifier` config — never a branch in
the code.

## Layout

```
src/
  OpenApi/     SpecFetcher · OpenApiParser · SchemaSimplifier
               DestructiveClassifier · ToolCache · ToolDefinition
  Server/      ServerFactory · OpenApiToolLoader · OpenApiToolHandler · UpstreamClient
  Auth/        IntrospectionClient · IntrospectionTokenValidator
               ScopeMap · ScopeToolFilter · AuthContext
  Http/        FrontController · LazyAuthMiddleware
               ProtectedResourceMetadata · CredentialExtractor
  Profile/     Profile · ProfileRegistry · ProfileResolver
  Support/     Config
bin/warm-cache
```

## The things that are easy to get wrong

**Cache on content, never on path.** The implementation this replaced keyed its
cache on `md5($specPath)` and checked freshness with
`filemtime($cache) >= filemtime($spec)`. When the admin spec moved, `filemtime()`
on the missing file returned `false`, the comparison held, and a stale cache
served a phantom tool catalogue for months. `SpecFetcher` treats an unfetchable
spec as an **error**, never as a cache hit.

**Warm the cache on deploy.** `Yaml::parse()` on the 1.31 MB client spec costs
~593 ms; building and registering the resulting 311 tools costs ~14 ms. Run
`bin/warm-cache` from the deploy and from cron.

**A 401 must be an HTTP status.** A `200` carrying `{"result":{"isError":true}}`
is handed to the model as text — no Connect card, no auth prompt. That is the most
common way a connector silently does nothing. `LazyAuthMiddleware` refuses at the
transport, with `WWW-Authenticate` and a `resource_metadata` pointer.

**Sessions must be shared storage under PHP-FPM.** With the SDK's default
`InMemorySessionStore` every request is a fresh process, so the session created by
`initialize` is gone by the next call. Run against the official conformance suite
that way and *every* scenario fails with "Session not found or has expired";
swapping in a file or Redis store turned that into 8 passing with no other change.

**Audience binding is the control that matters.** Two resource servers on two
hosts share one authorization server. RFC 8707 audience binding is the only thing
stopping an admin-scoped token being replayed at the client server. Declare
`resourceIdentifiers` on every production profile — a profile with none accepts
any audience.

**`ProxyErrorOverride` destroys JSON-RPC error bodies.** With it on — as the
origin vhost has it — Apache replaces the body of every proxied response ≥ 400
with its own HTML error page. Every spec-required error (`-32020`, `-32021`,
`-32022`, `-32601`) arrives as an empty shell with the right status code. Set
`ProxyErrorOverride off` in each vhost explicitly.

## Testing

```bash
composer test          # Unit + Integration + Contract
composer ci            # lint + phpstan + tests
```

| Suite | What it covers |
|---|---|
| `Unit` | pure logic — parser, schema simplifier, classifier, scope maps, introspection, middleware |
| `Integration` | a real SDK server driven by raw JSON-RPC through an in-memory transport; both protocol eras |
| `Contract` | all 756 live operations against the rules that get a connector rejected |

The `Contract` suite skips unless the specs are on disk. Point it at them:

```bash
MCP_CLIENT_SPEC=/path/to/openapi.yaml MCP_ADMIN_SPEC=/path/to/openapi-admin.yaml vendor/bin/phpunit
```

## Release discipline

Three repos move together. Tag core, bump both apps, merge all three — do not let
one app sit on an older core across a protocol change. Both apps pin a caret range
with a **committed lock**; the origin repos gitignored theirs while depending on
`dev-main`, which is the direct cause of nobody knowing what protocol version was
being served.

CI runs each app against the pinned core, and a scheduled job runs this package
against `mcp/sdk: dev-main` so a breaking change in a pre-1.0 dependency surfaces
before it is tagged.
