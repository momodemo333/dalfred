# Dalfred

Dalfred is an AI assistant module for [Dolibarr ERP/CRM](https://www.dolibarr.org/).

It adds a chat-based interface backed by NeuronAI v3 and tool access to Dolibarr business data such as third parties, invoices, orders, products, accounting data, and knowledge entries.

## Features

- AI chat embedded in Dolibarr
- Multi-provider configuration: Anthropic Claude, OpenAI, Mistral, Google Gemini, Ollama
- Tool-based access to Dolibarr data through an MCP bridge
- Knowledge base and slash-command shortcuts
- File attachments and generated downloadable files
- Token usage observability
- Admin pages for configuration, diagnostics, permissions, and maintenance

## Requirements

- Dolibarr compatible with external modules
- PHP 8.1+
- Composer for dependency installation
- API keys for the AI provider(s) you enable

## Installation

This repository contains the module source code.

For a classic Dolibarr installation, install the module under your Dolibarr custom modules directory, then install Composer dependencies:

```bash
cd htdocs/custom/dalfred
composer install --no-dev --optimize-autoloader
```

Then enable the module from Dolibarr admin and configure your AI provider in the Dalfred admin pages.

## MCP bridge

Dalfred expects the Dolibarr MCP server runtime to be available under `dolibarr-mcp-server/` when using the direct MCP bridge. In private release builds this dependency is packaged into the ZIP.

Public packaging automation for that runtime will be documented separately.

## Documentation

- `docs/INSTALLATION_CONFIGURATION.md`
- `docs/RATE_LIMITS.md`
- `CHANGELOG.md`

## Security

Do not commit API keys, `.env` files, customer data, logs, database dumps, or local Dolibarr configuration files.

If you discover a vulnerability, please report it privately to the maintainer before opening a public issue.

## License

GPL-3.0-or-later.
