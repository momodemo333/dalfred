<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\Gemini\Gemini;

/**
 * ConfigService - Manages Dalfred configuration from Dolibarr constants
 *
 * This service retrieves configuration values from Dolibarr's llx_const table
 * and provides defaults when constants are not set.
 */
class ConfigService
{
    protected \DoliDB $db;
    protected int $entityId;
    protected array $cache = [];

    /**
     * Default configuration values
     */
    /** Default max tokens for AI response */
    public const DEFAULT_MAX_TOKENS = 16384;

    /** Default AI model */
    public const DEFAULT_MODEL = 'claude-sonnet-4-6';

    protected const DEFAULTS = [
        // AI Provider
        'DALFRED_AI_PROVIDER' => 'anthropic',
        'DALFRED_MODEL' => self::DEFAULT_MODEL,
        'DALFRED_MAX_TOKENS' => self::DEFAULT_MAX_TOKENS,

        // Chat history
        'DALFRED_CONTEXT_WINDOW' => 150000,
        'DALFRED_MAX_THREADS_PER_USER' => 10,

        // MCP
        'DALFRED_MCP_ENABLED' => 1,

        // Debug
        'DALFRED_DEBUG' => 0,
        'DALFRED_LOG_CONVERSATIONS' => 1,

        // Rate limiting
        'DALFRED_RATE_LIMIT_REQUESTS' => 100,
        'DALFRED_RATE_LIMIT_TOKENS' => 500000,

        // MySQL Toolkit (global settings)
        'DALFRED_MYSQL_TOOLKIT_ENABLED' => 0,
        'DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED' => 0,

        // Attachments
        'DALFRED_ATTACH_ENABLED' => 1,
    ];

    public function __construct(\DoliDB $db, int $entityId = 1)
    {
        $this->db = $db;
        $this->entityId = $entityId;
    }

    /**
     * Get a configuration value
     *
     * @param string $key Configuration key (without DALFRED_ prefix or with)
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        // Normalize key
        $key = $this->normalizeKey($key);

        // Check cache first (takes priority over everything)
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Try to get from database first (more up-to-date than global conf)
        $value = $this->getFromDatabase($key);
        if ($value !== null) {
            $this->cache[$key] = $value;
            return $value;
        }

        // Fallback to Dolibarr global conf (loaded at startup)
        global $conf;
        if (isset($conf->global->$key)) {
            $this->cache[$key] = $conf->global->$key;
            return $this->cache[$key];
        }

        // Return default
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /**
     * Get integer configuration value
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * Get boolean configuration value
     */
    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * Get string configuration value
     */
    public function getString(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    /**
     * Get the AI model to use.
     *
     * Validates that the stored model is compatible with the current provider.
     * When a user switches provider (e.g. Anthropic → Mistral) without updating
     * the model, the old model (e.g. claude-sonnet-4-6) would be sent to the
     * new provider's API, causing a 400 error. This method detects the mismatch
     * and falls back to the provider's default model.
     */
    public function getModel(): string
    {
        $model = $this->getString('DALFRED_MODEL', self::DEFAULT_MODEL);
        $provider = $this->getProvider();

        // If model belongs to a different provider, use this provider's default
        if ($this->isModelFromOtherProvider($model, $provider)) {
            $models = array_keys(self::getModelsForProvider($provider));
            if (!empty($models)) {
                return $models[0];
            }
        }

        return $model;
    }

    /**
     * Check if a model identifier belongs to a different provider
     * based on known model name prefixes.
     */
    private function isModelFromOtherProvider(string $model, string $currentProvider): bool
    {
        $prefixes = [
            'anthropic' => ['claude-'],
            'openai'    => ['gpt-', 'o3-', 'o4-'],
            'mistral'   => ['mistral-', 'magistral', 'codestral', 'open-mistral'],
            'gemini'    => ['gemini-'],
        ];

        foreach ($prefixes as $provider => $providerPrefixes) {
            if ($provider === $currentProvider) {
                continue;
            }
            foreach ($providerPrefixes as $prefix) {
                if (str_starts_with($model, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the context window size
     */
    public function getContextWindow(): int
    {
        return $this->getInt('DALFRED_CONTEXT_WINDOW', 150000);
    }

    /**
     * Get max tokens for AI response
     */
    public function getMaxTokens(): int
    {
        return $this->getInt('DALFRED_MAX_TOKENS', self::DEFAULT_MAX_TOKENS);
    }

    /**
     * Get the maximum tool payload size (in tokens) before tool inputs/results
     * are elided from chat history. 0 disables the feature.
     *
     * See dev/2026-06-05-tool-payload-truncation-design.md
     */
    public function getToolPayloadMaxTokens(): int
    {
        return $this->getInt('DALFRED_TOOL_PAYLOAD_MAX_TOKENS', 2000);
    }

    /**
     * Check if debug mode is enabled
     */
    public function isDebugEnabled(): bool
    {
        return $this->getBool('DALFRED_DEBUG', false);
    }

    /**
     * Check if MCP is enabled.
     *
     * @deprecated Since 2.11.2 — MCP is always enabled. Kept for backward
     * compatibility with any external caller. Always returns true.
     */
    public function isMcpEnabled(): bool
    {
        return true;
    }

    /**
     * Check if attachments are enabled.
     */
    public function isAttachmentsEnabled(): bool
    {
        return $this->getBool('DALFRED_ATTACH_ENABLED', true);
    }

    /**
     * Get the MCP server path
     */
    public function getMcpServerPath(): string
    {
        // This is always relative to module directory
        return dirname(__DIR__, 2) . '/dolibarr-mcp-server';
    }

    /**
     * Get the active AI provider name
     */
    public function getProvider(): string
    {
        return $this->getString('DALFRED_AI_PROVIDER', 'anthropic');
    }

    /**
     * Get the API key for the currently active provider
     */
    public function getActiveProviderApiKey(): string
    {
        $provider = $this->getProvider();
        return $this->getProviderApiKey($provider);
    }

    /**
     * Get the API key for a specific provider
     */
    public function getProviderApiKey(string $provider): string
    {
        $constMap = [
            'anthropic' => 'DALFRED_ANTHROPIC_API_KEY',
            'openai' => 'DALFRED_OPENAI_API_KEY',
            'mistral' => 'DALFRED_MISTRAL_API_KEY',
            'gemini' => 'DALFRED_GEMINI_API_KEY',
        ];

        $constName = $constMap[$provider] ?? null;
        if (!$constName) {
            return '';
        }

        $key = $this->getString($constName, '');
        if (!empty($key)) {
            return $key;
        }

        // Fallback to PHP constant
        if (defined($constName)) {
            return constant($constName);
        }

        return '';
    }

    /**
     * Get Ollama base URL
     */
    public function getOllamaUrl(): string
    {
        return $this->getString('DALFRED_OLLAMA_URL', 'http://localhost:11434/api');
    }

    /**
     * Get Ollama HTTP timeout in seconds.
     *
     * NeuronAI's default GuzzleHttpClient hardcodes a 60s read timeout, which is
     * frequently too short for self-hosted Ollama running on CPU (cold load can
     * exceed 2 minutes for a 7B model, and tool-calling responses with a large
     * tool catalogue routinely push past 60s even when warm). Surface this as a
     * Dolibarr constant so administrators can raise it without patching vendor.
     */
    public function getOllamaTimeout(): float
    {
        return (float) $this->getInt('DALFRED_OLLAMA_TIMEOUT', 300);
    }

    /**
     * Get HTTP timeout (seconds) for cloud providers (Anthropic, OpenAI,
     * Mistral, Gemini).
     *
     * NeuronAI's default GuzzleHttpClient hardcodes a 60 s read timeout, which
     * is too short on production threads carrying a large context + tool
     * catalogue — Sonnet 4.6 / GPT-5 can legitimately take 60-90 s to start
     * streaming a response, and the cURL error 28 "Operation timed out after
     * 60000 ms with 0 bytes received" shows up on client incidents.
     *
     * Defaults to 300 s (matches DALFRED_OLLAMA_TIMEOUT for consistency across
     * providers). Connect timeout stays at 10 s — if we can't open the TCP
     * socket in 10 s, it's not a server-side slowness issue.
     */
    public function getCloudHttpTimeout(): float
    {
        return (float) $this->getInt('DALFRED_HTTP_TIMEOUT', 300);
    }

    /**
     * Get the maximum number of times a single tool may be invoked within
     * one chat turn before NeuronAI raises ToolRunsExceededException.
     *
     * NeuronAI's default is 10, which is too low for legitimate analytical
     * workloads (e.g. multi-query SQL exploration to answer one user
     * question). Two prod incidents (customer-instance, customer-instance on
     * Anthropic Sonnet 4.6) hit the cap on ~11 mysql_select_query calls
     * in a single turn during normal usage. Clamped to [5, 100] so an
     * admin typo can't break the agent.
     */
    public function getToolMaxRuns(): int
    {
        $value = $this->getInt('DALFRED_TOOL_MAX_RUNS', 25);
        return max(5, min(100, $value));
    }

    /**
     * Build a GuzzleHttpClient pre-configured with the cloud HTTP timeout.
     *
     * Centralises the construction so every cloud provider gets the same
     * timeout settings without copy-pasting the GuzzleHttpClient args.
     */
    protected function buildCloudHttpClient(): GuzzleHttpClient
    {
        return new GuzzleHttpClient(
            customHeaders: [],
            timeout: $this->getCloudHttpTimeout(),
            connectTimeout: 10.0
        );
    }

    /**
     * Get Ollama context window (num_ctx) in tokens.
     *
     * Ollama defaults to 2048-4096 tokens regardless of the underlying model's
     * native context (e.g. qwen2.5 supports 32k, llama3.1 supports 128k). The
     * Dalfred system prompt plus the MCP tool catalogue alone exceeds 4k, which
     * truncates user messages and tool results, leading to empty replies and
     * confused tool-calls. 16384 is a safe default that fits in 8-12 GB VRAM
     * for a 7B model and still leaves room for several tool round-trips. Set
     * to 0 to leave Ollama on its built-in default (not recommended).
     */
    public function getOllamaNumCtx(): int
    {
        return $this->getInt('DALFRED_OLLAMA_NUM_CTX', 16384);
    }

    /**
     * Check if the active provider's API key is configured
     */
    public function isApiKeyConfigured(): bool
    {
        $provider = $this->getProvider();
        if ($provider === 'ollama') {
            return !empty($this->getOllamaUrl());
        }
        return !empty($this->getActiveProviderApiKey());
    }

    /**
     * Create the AI provider instance based on current configuration
     *
     * @param string|null $model Override model (optional)
     * @param int|null $maxTokens Override max tokens (optional)
     * @return AIProviderInterface
     * @throws \RuntimeException If provider is not properly configured
     */
    public function createProvider(?string $model = null, ?int $maxTokens = null): AIProviderInterface
    {
        $provider = $this->getProvider();
        $model = $model ?? $this->getModel();
        $maxTokens = $maxTokens ?? $this->getMaxTokens();

        switch ($provider) {
            case 'anthropic':
                $apiKey = $this->getProviderApiKey('anthropic');
                if (empty($apiKey)) {
                    throw new \RuntimeException('Anthropic API key not configured. Please set it in Dalfred AI configuration.');
                }
                return new Anthropic(
                    key: $apiKey,
                    model: $model,
                    max_tokens: $maxTokens,
                    httpClient: $this->buildCloudHttpClient()
                );

            case 'openai':
                $apiKey = $this->getProviderApiKey('openai');
                if (empty($apiKey)) {
                    throw new \RuntimeException('OpenAI API key not configured. Please set it in Dalfred AI configuration.');
                }
                return new OpenAI(
                    key: $apiKey,
                    model: $model,
                    httpClient: $this->buildCloudHttpClient()
                );

            case 'mistral':
                $apiKey = $this->getProviderApiKey('mistral');
                if (empty($apiKey)) {
                    throw new \RuntimeException('Mistral API key not configured. Please set it in Dalfred AI configuration.');
                }
                return new Mistral(
                    key: $apiKey,
                    model: $model,
                    parameters: ['tool_choice' => 'auto'],
                    httpClient: $this->buildCloudHttpClient()
                );

            case 'gemini':
                $apiKey = $this->getProviderApiKey('gemini');
                if (empty($apiKey)) {
                    throw new \RuntimeException('Google Gemini API key not configured. Please set it in Dalfred AI configuration.');
                }
                return new Gemini(
                    key: $apiKey,
                    model: $model,
                    httpClient: $this->buildCloudHttpClient()
                );

            case 'ollama':
                $url = $this->getOllamaUrl();
                if (empty($url)) {
                    throw new \RuntimeException('Ollama URL not configured. Please set it in Dalfred AI configuration.');
                }
                $httpClient = new GuzzleHttpClient(
                    customHeaders: [],
                    timeout: $this->getOllamaTimeout(),
                    connectTimeout: 10.0
                );
                $parameters = [];
                $numCtx = $this->getOllamaNumCtx();
                if ($numCtx > 0) {
                    // Ollama /api/chat accepts a top-level "options" object
                    // (see https://github.com/ollama/ollama/blob/main/docs/api.md).
                    // NeuronAI spreads $this->parameters into the body, so we
                    // pass "options" as a direct key.
                    $parameters['options'] = ['num_ctx' => $numCtx];
                }
                return new Ollama(
                    url: $url,
                    model: $model,
                    parameters: $parameters,
                    httpClient: $httpClient
                );

            default:
                throw new \RuntimeException("Unsupported AI provider: {$provider}");
        }
    }

    /**
     * Get available models for a given provider
     *
     * @return array<string, string> model_id => label
     */
    public static function getModelsForProvider(string $provider): array
    {
        // Lists curated 2026-06. When a provider deprecates a model that is
        // still configured by a client, the admin UI re-injects the stored
        // value via the "Other / Custom" entry — the saved config is never
        // lost (see ai_setup.php's dalfredPopulateModelSelect()).
        // Sibling migration in DalfredMigrations remaps a small set of known-
        // dead defaults (e.g. gemini-2.0-flash → 2.5-flash) so customers who
        // never touched the model setting are upgraded silently.
        switch ($provider) {
            case 'anthropic':
                // Source : skill claude-api (catalogue Anthropic, juin 2026).
                return [
                    'claude-opus-4-8' => 'Claude Opus 4.8 (Recommended)',
                    'claude-opus-4-7' => 'Claude Opus 4.7',
                    'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (Balanced)',
                    'claude-haiku-4-5' => 'Claude Haiku 4.5 (Fast)',
                ];
            case 'openai':
                // Source : developers.openai.com/api/docs/models (juin 2026).
                return [
                    'gpt-5.5' => 'GPT-5.5 (Recommended)',
                    'gpt-5.4' => 'GPT-5.4',
                    'gpt-5.4-mini' => 'GPT-5.4 Mini (Fast)',
                    'gpt-5' => 'GPT-5',
                    'gpt-5-mini' => 'GPT-5 Mini',
                    'gpt-4.1' => 'GPT-4.1',
                    'gpt-4o' => 'GPT-4o',
                ];
            case 'mistral':
                // Source : docs.mistral.ai (alias *-latest, juin 2026).
                return [
                    'mistral-large-latest' => 'Mistral Large (Recommended)',
                    'mistral-medium-latest' => 'Mistral Medium',
                    'mistral-small-latest' => 'Mistral Small (Fast)',
                    'magistral-medium-latest' => 'Magistral Medium (Reasoning)',
                    'codestral-latest' => 'Codestral (Code)',
                ];
            case 'gemini':
                // Source : ai.google.dev/gemini-api/docs/models (juin 2026).
                return [
                    'gemini-3.5-flash' => 'Gemini 3.5 Flash (Recommended)',
                    'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro',
                    'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite (Fastest)',
                    'gemini-2.5-flash' => 'Gemini 2.5 Flash',
                ];
            case 'ollama':
                return []; // Free text input
            default:
                return [];
        }
    }

    /**
     * Get the list of supported providers
     *
     * @return array<string, string> provider_id => label
     */
    public static function getSupportedProviders(): array
    {
        return [
            'anthropic' => 'Anthropic (Claude)',
            'openai' => 'OpenAI (GPT)',
            'mistral' => 'Mistral AI',
            'gemini' => 'Google Gemini',
            'ollama' => 'Ollama (Self-hosted)',
        ];
    }

    /**
     * Test the AI connection for the active provider
     *
     * @return array{success: bool, error?: string, http_code?: int}
     */
    public function testAiConnection(): array
    {
        $provider = $this->getProvider();
        $model = $this->getModel();

        if ($provider === 'ollama') {
            return $this->testOllamaConnection();
        }

        $apiKey = $this->getActiveProviderApiKey();
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'API key not configured for provider: ' . $provider];
        }

        switch ($provider) {
            case 'anthropic':
                return $this->testAnthropicConnection($apiKey, $model);
            case 'gemini':
                return $this->testGeminiConnection($apiKey, $model);
            case 'openai':
            case 'mistral':
                return $this->testOpenAICompatibleConnection($provider, $apiKey, $model);
            default:
                return ['success' => false, 'error' => 'Unsupported provider: ' . $provider];
        }
    }

    /**
     * Test Anthropic API connection
     */
    protected function testAnthropicConnection(string $apiKey, string $model): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $model,
            'max_tokens' => 10,
            'messages' => [['role' => 'user', 'content' => 'Hi']]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        return $this->executeCurlTest($ch);
    }

    /**
     * Test OpenAI-compatible API connection (OpenAI, Mistral)
     */
    protected function testOpenAICompatibleConnection(string $provider, string $apiKey, string $model): array
    {
        $urls = [
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
        ];

        $url = $urls[$provider] ?? null;
        if (!$url) {
            return ['success' => false, 'error' => 'Unknown provider: ' . $provider];
        }

        // Recent OpenAI models (GPT-5, o1/o3 families) reject `max_tokens`
        // and require `max_completion_tokens`. Mistral still uses `max_tokens`.
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ];
        if ($provider === 'openai') {
            $payload['max_completion_tokens'] = 10;
        } else {
            $payload['max_tokens'] = 10;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        return $this->executeCurlTest($ch);
    }

    /**
     * Test Google Gemini API connection
     */
    protected function testGeminiConnection(string $apiKey, string $model): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'contents' => [['parts' => [['text' => 'Hi']]]],
            'generationConfig' => ['maxOutputTokens' => 10]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        return $this->executeCurlTest($ch);
    }

    /**
     * Test Ollama connection
     */
    protected function testOllamaConnection(): array
    {
        $url = rtrim($this->getOllamaUrl(), '/');
        // Try the tags endpoint to check connectivity
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, str_replace('/api', '', $url) . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 200) {
            return ['success' => true, 'http_code' => $httpCode];
        }

        return ['success' => false, 'error' => $curlError ?: 'Ollama not reachable at ' . $url, 'http_code' => $httpCode];
    }

    /**
     * Execute a curl test and return result
     */
    protected function executeCurlTest($ch): array
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => $curlError ?: 'Curl request failed', 'http_code' => 0];
        }

        if ($httpCode == 200) {
            return ['success' => true, 'http_code' => $httpCode];
        }

        $responseData = json_decode($response, true);
        $errorDetail = $responseData['error']['message'] ?? $curlError ?: 'Unknown error';
        return ['success' => false, 'error' => $errorDetail, 'http_code' => $httpCode];
    }

    /**
     * Get user's Dolibarr API key for MCP calls
     *
     * @param int $userId Dolibarr user ID
     * @return string|null API key or null if not found
     */
    public function getUserDolibarrApiKey(int $userId): ?string
    {
        $sql = "SELECT api_key FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $userId;
        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            if (!empty($row->api_key)) {
                // Dolibarr stores API keys encrypted (dolcrypt:...), decrypt them
                if (function_exists('dolDecrypt')) {
                    return dolDecrypt($row->api_key);
                }
                return $row->api_key;
            }
        }

        return null;
    }

    /**
     * Check if user has a valid API key
     *
     * @param int $userId Dolibarr user ID
     * @return bool
     */
    public function userHasApiKey(int $userId): bool
    {
        return !empty($this->getUserDolibarrApiKey($userId));
    }

    /**
     * Set a configuration value in database
     *
     * @param string $key Configuration key
     * @param mixed $value Value to set
     * @return bool Success
     */
    public function set(string $key, $value): bool
    {
        $key = $this->normalizeKey($key);

        // Use Dolibarr's dolibarr_set_const function if available
        if (function_exists('dolibarr_set_const')) {
            $result = dolibarr_set_const($this->db, $key, $value, 'chaine', 0, '', $this->entityId);
            if ($result > 0) {
                $this->cache[$key] = $value;
                return true;
            }
            return false;
        }

        // Manual insert/update
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($key) . "' AND entity = " . (int) $this->entityId;
        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            // Update
            $sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . $this->db->escape((string) $value) . "' WHERE name = '" . $this->db->escape($key) . "' AND entity = " . (int) $this->entityId;
        } else {
            // Insert
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const (name, value, type, visible, entity) VALUES ('" . $this->db->escape($key) . "', '" . $this->db->escape((string) $value) . "', 'chaine', 1, " . (int) $this->entityId . ")";
        }

        if ($this->db->query($sql)) {
            $this->cache[$key] = $value;
            return true;
        }

        return false;
    }

    /**
     * Get value from database
     */
    protected function getFromDatabase(string $key): ?string
    {
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($key) . "' AND entity IN (0, " . (int) $this->entityId . ") ORDER BY entity DESC LIMIT 1";

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            $value = $row->value;

            // Decrypt values encrypted by Dolibarr (dolcrypt:AES-256-CTR:...)
            if (!empty($value) && is_string($value) && str_starts_with($value, 'dolcrypt:') && function_exists('dolDecrypt')) {
                $value = dolDecrypt($value);
            }

            return $value;
        }

        return null;
    }

    /**
     * Normalize configuration key
     */
    protected function normalizeKey(string $key): string
    {
        // Add prefix if not present
        if (strpos($key, 'DALFRED_') !== 0) {
            $key = 'DALFRED_' . strtoupper($key);
        }
        return strtoupper($key);
    }

    /**
     * Clear the configuration cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Get all configuration values as array
     */
    public function getAll(): array
    {
        $config = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $config[$key] = $this->get($key);
        }
        return $config;
    }
}
