# Changelog

## [2.26.1] - 2026-07-14

### Changed
- **Cloud model lists refreshed (July 2026)** — IDs were reconciled with the providers' official catalogues and live `/models` endpoints, then checked through Dalfred's native API surfaces:
  - **Anthropic**: added **Claude Fable 5** (`claude-fable-5`) and **Claude Sonnet 5** (`claude-sonnet-5`), retained every currently returned Claude API model, and switched pre-4.6 entries to their pinned IDs. Opus 4.8 remains the first recommended selector entry and provider-switch fallback; the existing configured default remains Sonnet 4.6.
  - **OpenAI**: added **GPT-5.6 Sol** (`gpt-5.6-sol`, flagship of the GPT-5.6 family launched 2026-07-09, new recommended model). The two sibling tiers are deliberately not listed yet: `gpt-5.6-terra` consistently returns "insufficient permissions" on standard keys (verified-org gated) and `gpt-5.6-luna` still fails intermittently with the same 401 during the ongoing rollout (~2 failures out of 5 pings on 2026-07-14). Both remain reachable through the "Other / Custom" entry and already resolve to the right context window in `ModelCapacityRegistry`; relist them once `tools/check_models.php` passes reliably. Previous GPT-5.x/4.x entries are all still active and kept.
  - **Mistral**: removed deprecated `magistral-medium-latest`; refreshed the general, reasoning, code and Ministral families using maintained `*-latest` aliases whose API metadata confirms chat completion and function calling support. Mistral Medium 3.5 is now the first recommended entry and provider-switch fallback instead of Mistral Large.
  - **Gemini**: completed the current general-purpose catalogue with Gemini 3 Flash Preview plus the still-active Gemini 2.5 Pro and Flash-Lite; media, Live, agent-only and shut-down models remain excluded.
- **Model metadata**: refreshed context windows from the live provider metadata (Claude 5/4.6/4.5, GPT-5.6, current Mistral/Ministral families and Gemini), and aligned Mistral image-upload capability with the current multimodal model metadata.

### Added
- **`tests/ConfigServiceModelListsTest.php`**: static invariants on the curated model lists — non-empty per cloud provider, IDs consistent with the provider-prefix contract used by the provider-switch fallback, every exposed model resolves to a real capacity in `ModelCapacityRegistry` (never the generic 150k fallback), and presence/absence of the July 2026 key models.

## [2.26.0] - 2026-07-07

### Fixed
- **"Create a project" (and any tool call with an imperfect resource name) no longer fails with an opaque CSRF/HTML error** (customer report): the embedded MCP server now canonicalizes resource names on every tool — singular (`project` → `projects`), capitalization (`Invoices` → `invoices`), underscores (`supplier_invoices` → `supplierinvoices`) and frequent French nouns (`facture`, `devis`, `tiers`) are auto-corrected when they match a known Dolibarr core endpoint, while custom-module endpoint names pass through untouched. Endpoints can no longer escape `/api/index.php/` via a leading slash (the actual mechanism behind the CSRF page), and non-API error responses are turned into short actionable messages instead of kilobytes of raw HTML. See the dolibarr-mcp-server changelog for details; validated end-to-end on a live instance (agent-driven project creation, French/singular/case variants, unknown-resource error path).
- **Support diagnostics (admin "About" page) now show the real Dolibarr root URL and REST endpoint** instead of only `DOL_URL_ROOT`, which can legitimately be empty on root installations and was misleading during support analysis.

### Changed
- **MCP connection is request-scoped for embedded Dalfred calls**: Dalfred passes the Dolibarr URL and the current user's API key directly to the embedded MCP container (`ConnectionConfig`) instead of mutating process-wide environment variables. This is safer for PHP-FPM worker reuse and multicompany contexts.

## [2.25.0] - 2026-07-07

### Added
- **Smart Queries can now be shared (or made private again) through the agent**: the `smart_query_update` tool gained a `scope` parameter (`private`/`shared`) and `smart_query_save` accepts an optional `scope` at creation time (default stays `private`). Previously the tool schema did not expose the field at all, so when a user asked the agent to share a saved query, the model would pass `scope` anyway and the call crashed with `Unknown named parameter $scope` — the agent retried, failed silently each time, and eventually gave up (observed live during a customer demo). Invalid values are rejected with an explicit error; ownership rules are unchanged (only the owner can modify a query). Covered by the new `tests/SmartQueryScopeTest.php`.

### Changed
- **DirectMcpBridge adapted to the official MCP PHP SDK**: the embedded dolibarr-mcp-server migrated from `php-mcp/server` to the official `mcp/sdk`, moving the `McpTool` and `Schema` attributes to the `Mcp\Capability\Attribute` namespace. Only the imports changed — reflection, coercion and error handling are untouched. Validated in the PHP 8.1 container with all 20 tools loading and executing.
- The embedded MCP server now normalizes `{"id": …}` / `{"rowid": …}` list filters into `t.rowid` sqlfilters, because many Dolibarr list endpoints silently ignore raw `id` query params (see the dolibarr-mcp-server changelog for details).

### Fixed
- **`DalfredMigrations::MODULE_VERSION` resynchronized with the module version**: the constant was lagging at 2.23.0 while the descriptor was at 2.24.3. No migration above 2.22.0 exists yet so nothing was actually skipped, but any future migration tagged above 2.23.0 would silently never run on customer installs. Both versions are now bumped together, as required.

This public repository starts from the Dalfred 2.24.x codebase.

Earlier private development history is intentionally not imported because it contained internal development notes and environment-specific information that are not suitable for a public repository.

## 2.24.x

- Added resilience around empty provider responses.
- Improved tool-call handling and diagnostics.
- Improved context-window handling for supported AI models.
- Added/extended test coverage for chat-history integrity scenarios.
- Continued improvements to file attachments, generated files, knowledge entries, slash commands, and token usage observability.

Future public releases will use this changelog normally.
