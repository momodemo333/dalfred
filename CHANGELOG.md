# Changelog

## [2.31.0] - 2026-09-04

### Added
- **MCP call log, rate limit and alerting**, in a new admin tab *"Activité MCP"*.

  Dolibarr permissions say *what* a user may read; they say nothing about *how
  much*. An employee entitled to look up customers one at a time is not thereby
  entitled to pull the whole customer base in an afternoon, and until now
  nothing in the MCP surface noticed the difference. This adds the missing half.

  - **Call log** — one row per MCP call: who, which tool, with which arguments,
    how long it took, and whether it succeeded. For support when a customer
    reports a problem, and for traceability when someone asks what an agent
    actually read. Tool *results* are never stored: a log that copies the data
    it is meant to police becomes a second copy to protect. Arguments are
    recorded (that is what makes a call auditable) but can be switched off.
  - **Rate limit** — a per-user cap on tool calls over a rolling window.
    Refusals answer with the numbers, so the agent knows to wait rather than
    retry blindly. Only tool calls count: connecting and listing tools consume
    nothing, and refused calls are recorded but not counted, so a retrying
    client cannot deepen its own hole.
  - **Email alert** — warns an administrator when a user's volume stands out,
    with a per-user cooldown so one intensive session sends one mail rather
    than fifty.

  Both the limit and the alert are **off by default**: only the administrator
  knows what normal use looks like on their instance. Logging is on, because an
  MCP surface with no trail is not something to end up with by omission.

  Shared with emMCP through the new `dolibarr-mcp-audit` library, so a fix
  benefits both.

## [2.30.1] - 2026-09-02

### Fixed
- **`CAST(x AS DECIMAL(20,6))` was refused as a syntax error** by the MCP SQL
  tool. `CAST` was never the problem — `DECIMAL(20)` and `CHAR(10)` worked —
  the comma inside the type parameters was, which made formatting amounts with
  a scale impossible. Types with a scale are accepted now, including inside
  aggregates and `CONVERT`. Everything else still fails closed, and the SQL
  hardening suite is unchanged.

## [2.30.0] - 2026-09-02

### Added
- **New MCP tool `dolibarr_environment`.** An external agent can now ask what it
  is connected to instead of finding out through calls that fail: Dolibarr and
  PHP versions, the version of Dalfred serving the session, the MCP server
  version, the list of enabled Dolibarr modules, the entity, whether
  multicompany is active, and whether read-only SQL was granted to it.

  Two uses. For the agent: knowing the Dolibarr version and which modules exist
  lets it adapt its calls, and look up version-specific behaviour when needed,
  rather than discovering absent endpoints one refusal at a time. For you:
  asking the assistant "which version of the module is this install running?"
  now has an answer, which is the quickest way to tell whether a customer is up
  to date.

  The tool is ungated on purpose — knowing what you are connected to should not
  itself require a grant — and takes no parameters.

## [2.29.3] - 2026-08-28

### Fixed
- **A large tool result could break the conversation permanently.** Found in a
  customer's activity log, where the chain was visible end to end: a schema
  analysis returned a very large result, the history write was refused by MySQL
  with *"Got a packet bigger than 'max_allowed_packet' bytes"*, so the
  assistant message was never stored — and the hole it left bricked the thread
  on the next turn with the alternation error fixed in 2.28.0.

  2.28.0 already stopped the thread from dying, but the turn was still lost
  every time. The cause is fixed here: tool payloads were capped at 8 000
  characters *except* for the latest call/result pair, deliberately kept intact
  so the model could re-read it on the immediate follow-up. That grace had no
  upper bound, and since the whole history is rewritten in a single UPDATE, one
  oversized result was enough to make the row unwritable.

  The grace now stops at an absolute ceiling of 256 KB — far above any
  legitimate payload, far below any realistic `max_allowed_packet` (1 MB on a
  default XAMPP). A result of 98 KB is still persisted untouched; one of 588 KB
  is elided rather than lost. The ceiling applies even when payload truncation
  is switched off, since it exists to keep the write within what the server
  accepts, not to save context.

## [2.29.2] - 2026-08-28

### Fixed
- **The new SQL permission was shown in English** on the user permissions
  screen. Dolibarr translates a permission through a `Permission<id>` key, and
  the one for `sqlquery` was missing; the other five Dalfred permissions had
  theirs. Added in French, English and Bulgarian.

## [2.29.1] - 2026-08-26

### Fixed
Three things a real session driving the MCP SQL tools ran into.

- **Schema exploration returned a truncated slice.** `dolibarr_sql_schema`
  called without a table listed every column of every table — around 380 KB on
  an ordinary instance, which the server then cut at 200 tables in alphabetical
  order. The answer stopped part-way through the alphabet and common tables such
  as `llx_facture` were simply absent, with nothing telling the caller. It now
  returns table names only in that case (393 tables in 13 KB on the same
  instance, complete), and the columns are one call away on the table that
  matters.
- **An exact table name behaved as a prefix.** Asking for `llx_facture` returned
  `llx_facture`, `llx_facturedet`, `llx_facture_extrafields` and a dozen more —
  large enough to break a client's response limit, which is why the exact name
  appeared to fail while a shortened one worked. An exact match now wins; the
  prefix behaviour is kept for a name matching no table.
- **`duration_ms` was always 0** for anything fast: the value was rounded to an
  integer. It is fractional now.

### Changed
- **`SELECT *` is allowed on ordinary tables.** It used to be refused on
  principle, on the grounds that a third-party module table could hold a key
  nobody had listed. That reasoning still stands, so instead of assuming, the
  columns the star stands for are now resolved and run through the same policy:
  the query is allowed where nothing sensitive is exposed, and refused — naming
  the offending column — where something is. `SELECT * FROM llx_societe` works;
  `SELECT * FROM llx_user` is refused because of `pass`. If the columns cannot
  be resolved at all, the refusal stands: "could not find out" must never be
  read as "nothing to hide".
- The schema tool's description now states that `datef` and `date_lim_reglement`
  are `date` while `datec` and `tms` are `datetime`, in server time.

## [2.29.0] - 2026-08-25

### Added
- **Read-only SQL access over MCP.** An external MCP client (claude.ai, Claude
  Code, any HTTP MCP client) can now query the Dolibarr database directly for
  reporting, through two tools: `dolibarr_sql_query` and `dolibarr_sql_schema`.
  The feature already existed in emMCP; Dalfred now offers exactly the same one,
  from the same shared code, so a customer running both modules gets the same
  behaviour either way.
- **New admin tab, "MCP SQL access."** Named that way on purpose: it governs
  *only* the SQL exposed through the external MCP endpoint. The SQL tools the
  agent uses inside the chat are configured in "Toolkit permissions" and are
  unaffected — the tab says so at the top.
- **Disabled by default, behind four independent conditions**, each of which
  refuses on its own: the global switch, the new Dolibarr right
  `dalfred->sqlquery->read` (granted to nobody by default, admins included), a
  per-user opt-in, and a multi-company guard. While any one of them is missing,
  the SQL tools are not merely blocked — they never appear in `tools/list` at
  all, because the MCP runtime excludes them from discovery.
- **Configurable limits**: maximum rows returned, statement timeout, maximum
  response size, and an audit trail that can be reduced to query hashes when
  the query text itself is too sensitive to store.
- **Audit trail** of every attempt, allowed or refused, in the new admin tab.
  Query *results* are never recorded.

### Security
- Only read statements are accepted, and the check is not a keyword scan: a
  lexer first refuses multi-statement text and executable comments, then a real
  SQL parser must produce a tree whose clauses all belong to a whitelist, then
  a policy is applied to every table, column and function in it. Consequences:
  UNION, CTEs, nested subqueries, joins and aggregates all work, while a write
  keyword sitting inside a string literal — `WHERE label = 'update stock'` — is
  correctly read as data and not as a statement.
- Credential columns (`password`, `api_key`, `token`, `secret`, …) are refused
  wherever they appear, whatever the alias, as are `information_schema`,
  `mysql`, and the modules' own token, audit and permission tables.
- Queries run on the Dolibarr credentials but on a **separate mysqli session**,
  with a mandatory statement timeout, a pinned SQL mode and a READ ONLY
  transaction, so none of that leaks into the application connection.

## [2.28.0] - 2026-08-25

### Fixed
- **Conversations permanently stuck on an error.** A thread could reach a state
  where every single message came back as *"An error occurred while processing
  your message."*, with no way out other than starting a new conversation. The
  cause was a corrupted message history: NeuronAI validates the whole
  user/assistant alternation on every write, so one bad position anywhere makes
  the thread unusable forever (`Invalid message sequence at position N`).
  The repair pass that was supposed to prevent this reset its expectations after
  every tool message, which let two real corruptions through — both of them
  produced by a turn interrupted mid-flight (PHP timeout on a long generation,
  fatal error, provider hanging up):
  - the assistant answer lost **after** the tool result was already saved;
  - the assistant answer lost right after the user's message, with a legitimate
    assistant message before it.
  The repair now mirrors the validator's state machine exactly, and runs when
  the next message is written rather than when the thread is loaded — so it can
  tell a dead turn from one still running in async mode. **Threads already
  broken repair themselves as soon as the user sends another message**, no
  manual intervention needed.

### Changed
- **Chat messages are now translated.** Every message the chat can return —
  error messages, the `/help` command listing, attachment warnings, the
  placeholders inserted when repairing a conversation — went through
  `$langs->trans()` and is available in French, English and Bulgarian. Non-French
  speaking users used to receive French error messages regardless of their
  Dolibarr language.
- **The assistant is told the user's interface language.** The system prompt
  said "French by default"; it now carries the user's actual Dolibarr language
  and instructs the agent to answer in it, tool results included.
- **A dedicated message for a corrupted conversation**, replacing the generic
  error: it tells the user the thread has just been repaired and that sending
  the message again is enough.
- **The full-screen chat page now receives the JavaScript translation table.**
  Only the widget injected it, so on `chat.php` every JS string silently fell
  back to its hardcoded French default — drop zone, expired attachment,
  unsupported file type, copy feedback, shared-command badge, timeout message —
  whatever the user's language. The table is now built once in
  `dalfred_js_translations()` and injected by both.

### Added
- Errors raised outside the agent run (configuration, MCP handshake, attachment
  ingestion, a fatal thrown before the agent exists) are now recorded in the
  admin **Activity log**. They previously only reached `dolibarr.log`, which is
  usually out of reach on a customer instance.
- Regression tests covering conversation repair: interrupted tool turn, orphan
  user message, consecutive same-role messages, leading assistant message,
  in-flight async turn left untouched, and repair idempotency.

## [2.27.1] - 2026-08-04

### Fixed
- **Top bar robot icon size.** The Dalfred icon in the top-right toolbar
  (widget toggle, next to the fullscreen chat icon) was noticeably larger
  than the neighboring Dolibarr icons (print, help…). It is now sized to
  visually match them. Also applies to a custom brand logo when one is
  uploaded.

## [2.27.0] - 2026-07-16

### Added
- **Optional external MCP access.** Dalfred can now expose its Dolibarr MCP
  server as a remote Streamable HTTP endpoint with OAuth 2.1 (claude.ai
  connectors, Claude Code, any HTTP MCP client), in addition to the built-in
  chat. **Disabled by default** — enable it from the new admin tab
  *"Accès externe MCP"* (toggle `DALFRED_MCP_EXTERNAL_ENABLED`). While
  disabled, the endpoint returns HTTP 403. Uses the shared
  `dolibarr-mcp-oauth` library (same OAuth 2.1 / PKCE S256 / sha256-hashed
  tokens as the emMCP module); OAuth data lives in the new
  `llx_dalfred_oauth_*` tables. Requires the Dolibarr REST **API** module.

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
