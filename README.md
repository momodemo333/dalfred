# Dalfred

Dalfred is an AI assistant module for [Dolibarr ERP/CRM](https://www.dolibarr.org/) (current version: 2.35.1).

It adds a chat-based interface backed by NeuronAI v3 and tool access to Dolibarr business data such as third parties, invoices, orders, products, accounting data, and knowledge entries.

## Features

- AI chat embedded in Dolibarr (floating widget and dedicated chat page), with multiple conversations
- Multi-provider configuration: Anthropic Claude, OpenAI, Mistral (including Mistral Large 4), Google Gemini, Ollama (self-hosted)
- Tool-based access to Dolibarr data through the bundled MCP engine, always with the Dolibarr permissions of the user
- Knowledge base, slash-command shortcuts and Smart Queries (saved SQL queries)
- File attachments and generated downloadable files
- Token usage observability and activity log
- Optional external MCP access (OAuth) for AI clients such as claude.ai or ChatGPT, with MCP SQL access and MCP activity tabs
- Admin pages for configuration, permissions, maintenance and diagnostics

## Requirements

- Dolibarr 16 or later
- PHP 8.1 or later, with the `curl`, `json`, `mbstring` and `openssl` extensions
- Dolibarr **API REST** module enabled (each user of Dalfred needs a Dolibarr API key)
- An API key for the AI provider you enable, or a self-hosted Ollama server

The release ZIP already contains every PHP dependency Dalfred needs. They are **isolated**: the bundled libraries are prefixed under the `DalfredVendor\` and `DalfredMcpVendor\` namespaces, so another module or Dolibarr core loading its own copy of the same library (psr/log, guzzle, …) cannot conflict with Dalfred. The **Diagnostic** tab shows whether isolation is active.

## Installation

1. Download the ZIP (DoliStore or https://www.e-dem.com) and unzip it into `htdocs/custom/`. The final folder is `htdocs/custom/dalfred/`. Nothing else to install: do **not** run Composer on a release package.
2. Enable **Dalfred** in *Home > Setup > Modules/Applications* (enable the **API REST** module too if needed). The database tables are created automatically.
3. Open the module's setup page (gear icon next to Dalfred in the module list) and configure your AI provider in the **AI Configuration** tab.
4. Give users the **Use Dalfred AI Assistant** permission and make sure each of them has a Dolibarr API key (the **Permissions** tab shows who is ready).

### Updating

Unzip the new version over the previous one. Database migrations run automatically on the next page load; the **Maintenance** tab shows the files version and the database version, and its button **Vérifier et mettre à jour la base de données** (check and update the database; the Maintenance page is currently French-only) runs the migrations on demand.

## Configuration tabs

The setup page has 13 tabs: General Setup, Branding, AI Configuration, Permissions, Activity Log, Memory, Token usage, Maintenance, Diagnostic, External MCP access, MCP SQL access, MCP activity and About. Each setting is described in the online documentation: https://www.e-dem.com (Docs > Dalfred).

## Troubleshooting

- **Start with the Diagnostic tab**: it checks the stack, dependency isolation, library conflicts, the last error of the MCP engine and obsolete files, and produces a report for support (no secrets).
- **Maintenance tab**: if the files version and the database version differ, click **Vérifier et mettre à jour la base de données**.
- **After updating by unzipping, nothing seems to change**: as a general precaution, restart PHP (PHP-FPM, or Apache when PHP runs as a module), or ask your hosting provider to do it. PHP's opcode cache (opcache) can keep serving the previous code.
- **Support**: support@e-dem.com — https://www.e-dem.com. Please attach the support report from the Diagnostic tab.

## Development

This repository contains the module source code. When working from the source repository (not from a release ZIP), dependencies are installed with Composer, and the MCP engine is expected as a separate checkout under `dolibarr-mcp-server/`. The release build (`make build-release`) installs production dependencies, bundles the MCP engine and prefixes all third-party dependencies with php-scoper.

## Documentation

- `docs/INSTALLATION_CONFIGURATION.md`
- `docs/RATE_LIMITS.md`
- `CHANGELOG.md`
- Online documentation: https://www.e-dem.com

## Security

Do not commit API keys, `.env` files, customer data, logs, database dumps, or local Dolibarr configuration files.

If you discover a vulnerability, please report it privately to support@e-dem.com before opening a public issue.

## License

GPL-3.0-or-later.
