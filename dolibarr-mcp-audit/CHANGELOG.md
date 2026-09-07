# Changelog

## [1.0.0] - 2026-09-04

Initial release.

### Added
- `CallLog` — one row per MCP call, and the count the limiter reads.
- `RateLimiter` / `Decision` — a per-user cap over a rolling window, with the
  numbers behind a refusal so the agent can act on them.
- `AlertNotifier` — email warning when a user's volume stands out, with a
  per-user cooldown claimed before sending so one busy session sends one mail.
- `McpAudit` — the façade a host entry point talks to, which also fixes the
  ordering that matters: check before dispatch, record either way, evaluate the
  alert after recording.
