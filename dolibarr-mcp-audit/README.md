# dolibarr-mcp-audit

Shared call logging, rate limiting and alerting for MCP-enabled Dolibarr
modules ([emMCP](https://github.com/momodemo333/emmcp), Dalfred).

## Why

Dolibarr permissions say **what** a user may read. They say nothing about **how
much**. An employee entitled to look up customers one at a time is not thereby
entitled to pull the whole customer base in an afternoon — and until now nothing
in the MCP surface noticed the difference.

This library adds the missing half:

- **A call log**, so support can see what an agent actually did, and an
  administrator can see who read what and when.
- **A rate limit**, so bulk extraction stops being quiet.
- **An email alert**, so somebody hears about it without having to watch a screen.

## Design notes

**One table for the trail and the limit.** The limiter counts the log rows
themselves rather than keeping a separate counter, so the number it enforces and
the history an administrator reads can never disagree.

**Results are never stored.** Arguments are — they are what makes a call
auditable ("which customer did it read?") — truncated, and switchable off. But a
log that copies the data it is meant to police becomes a second copy to protect.

**Only tool calls count.** Protocol traffic (initialize, tools/list,
notifications) moves no business data. Counting it meant a client that merely
reconnects spent its quota before reading anything: with a limit of 3, the
caller got exactly one real call. Refused calls are recorded but not counted
either, otherwise a retrying client deepens its own hole and the window never
drains.

**The alert claims its cooldown before sending.** A busy agent crosses the
threshold once and stays above it for every call that follows, so the naive
version mails the administrator fifty times and gets itself filtered. Writing
the cooldown first costs a lost alert if the mail then fails — preferable.

**Off by default, both of them.** A limit set without thought is a support
ticket waiting to happen, and only the administrator knows what normal use looks
like on their instance.

## Settings

Each host module owns its own constants, so emMCP and Dalfred can be installed
side by side. With prefix `DALFRED`:

| Constant | Default | Meaning |
|---|---|---|
| `DALFRED_MCP_LOG_ENABLED` | on | Record calls |
| `DALFRED_MCP_LOG_ARGUMENTS` | on | Record call arguments |
| `DALFRED_MCP_LOG_RETENTION_DAYS` | 90 | Purge older rows |
| `DALFRED_MCP_RATE_LIMIT` | 0 (off) | Max tool calls per window |
| `DALFRED_MCP_RATE_WINDOW` | 60 | Window, in minutes |
| `DALFRED_MCP_ALERT_THRESHOLD` | 0 (off) | Calls before warning an admin |
| `DALFRED_MCP_ALERT_EMAIL` | company email | Recipient |
| `DALFRED_MCP_ALERT_COOLDOWN` | 720 | Minutes between alerts, per user |

## Usage

```php
$audit = new DolibarrMcpAudit\McpAudit($db, new DolibarrMcpAudit\AuditConfig('dalfred', 'dalfred_', 'DALFRED'));
$call  = DolibarrMcpAudit\McpAudit::describeRequest($rawBody);

// Before dispatching — a limit applied afterwards caps nothing.
$decision = $audit->check($userId);
if (!$decision->allowed) {
    // $decision->message() tells the agent what happened, in numbers it can act on.
}

// After — success or failure, because a refused call is what the trail is for.
$audit->record($userId, $login, $call['method'], $call['tool'], $call['arguments'],
               $durationMs, $success, $error, $call['client']);
```

## License

GPL-3.0-or-later.
