<?php

declare(strict_types=1);

namespace Dalfred\MCP;

use DolibarrMcp\Bootstrap;
use DolibarrMcp\Client\DolibarrClient;
use DolibarrMcp\Client\ApiSchemaClient;
use DolibarrMcp\Container;
use DolibarrMcp\Support\FieldMapper;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Direct PHP bridge to MCP server tools.
 *
 * Instead of launching a subprocess (proc_open) or HTTP server,
 * this bridge loads the MCP server classes directly in the same PHP process
 * and converts #[McpTool] annotated methods into NeuronAI Tool objects.
 *
 * This is 100% compatible with shared hosting (no proc_open, no ReactPHP).
 */
class DirectMcpBridge
{
    private Container $container;

    /** @var array<string, object> Cache of instantiated tool classes */
    private array $toolInstances = [];

    public function __construct(string $dolibarrUrl, string $apiKey)
    {
        // Set env vars for DolibarrClient::fromEnvironment()
        putenv('DOLIBARR_URL=' . $dolibarrUrl);
        putenv('DOLIBARR_API_KEY=' . $apiKey);

        // Load the MCP server autoloader
        $mcpAutoloader = $this->findMcpAutoloader();
        if ($mcpAutoloader && !class_exists(Bootstrap::class, false)) {
            require_once $mcpAutoloader;
        }

        // Build the container (creates DolibarrClient, ApiSchemaClient, etc.)
        $this->container = Bootstrap::createContainer();
    }

    /**
     * Find the MCP server's vendor autoloader
     */
    private function findMcpAutoloader(): ?string
    {
        // Relative to dalfred module root
        $paths = [
            dirname(__DIR__, 2) . '/dolibarr-mcp-server/vendor/autoload.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get all MCP tools as NeuronAI Tool objects.
     *
     * @return Tool[]
     */
    public function tools(): array
    {
        $toolClasses = $this->getToolClasses();
        $tools = [];

        foreach ($toolClasses as $className) {
            $reflection = new ReflectionClass($className);
            $instance = $this->resolveInstance($className);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $mcpToolAttrs = $method->getAttributes(McpTool::class);
                if (empty($mcpToolAttrs)) {
                    continue;
                }

                $mcpTool = $mcpToolAttrs[0]->newInstance();
                $toolName = $mcpTool->name ?? $method->getName();
                $toolDescription = $mcpTool->description ?? '';

                $tool = Tool::make(
                    name: $toolName,
                    description: $toolDescription,
                )->setCallable(function (...$args) use ($instance, $method) {
                    return $this->callToolMethod($instance, $method, $args);
                });

                // Add properties from method parameters
                foreach ($method->getParameters() as $param) {
                    $schemaAttrs = $param->getAttributes(Schema::class);
                    $schema = !empty($schemaAttrs) ? $schemaAttrs[0]->newInstance() : null;

                    $type = $this->resolvePropertyType($param->getType());
                    $required = !$param->isOptional();
                    $description = $schema?->description ?? null;

                    $property = new ToolProperty(
                        name: $param->getName(),
                        type: $type,
                        description: $description,
                        required: $required,
                        enum: $schema?->enum ?? [],
                    );

                    $tool->addProperty($property);
                }

                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * Call a tool method with the given arguments.
     */
    private function callToolMethod(object $instance, ReflectionMethod $method, array $args): string
    {
        // Map positional args to named parameters
        $params = $method->getParameters();
        $namedArgs = [];

        foreach ($params as $i => $param) {
            $name = $param->getName();
            $value = null;
            $found = false;

            if (array_key_exists($name, $args)) {
                $value = $args[$name];
                $found = true;
            } elseif (array_key_exists($i, $args)) {
                $value = $args[$i];
                $found = true;
            }

            // If the AI passed null for a non-nullable typed optional param, use default
            if ($found && $value === null && $param->isOptional()) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && !$type->allowsNull()) {
                    $value = $param->getDefaultValue();
                }
            }

            if ($found) {
                $namedArgs[$name] = $value;
            } elseif ($param->isOptional()) {
                $namedArgs[$name] = $param->getDefaultValue();
            }
        }

        // Coerce scalar argument types so a smaller model that passes
        // numeric strings (e.g. "42" for an int param) doesn't crash with a
        // TypeError before the tool even runs. When coercion is impossible
        // (e.g. the model passed a textual reference like "CO2306-0002" for
        // an int id), return a structured error message that guides the
        // model toward the correct tool call instead of bubbling up an
        // opaque PHP TypeError.
        $coercionError = $this->coerceArgs($method, $namedArgs);
        if ($coercionError !== null) {
            return $coercionError;
        }

        try {
            $result = $method->invokeArgs($instance, $namedArgs);
        } catch (\Throwable $e) {
            // Return error as string so the agent can retry with different parameters
            $encoded = json_encode([
                'error' => true,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                return json_encode(['error' => true, 'message' => 'Tool exception (encoding failed): ' . json_last_error_msg()]);
            }
            return $encoded;
        }

        // Tools return strings (JSON), wrap if needed
        if (is_string($result)) {
            return $result;
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return json_encode(['error' => true, 'message' => 'Failed to encode tool result: ' . json_last_error_msg()]);
        }
        return $encoded;
    }

    /**
     * Coerce $namedArgs in-place to match the scalar types declared on the
     * tool method. Returns null on success, or a JSON-encoded error payload
     * when an argument cannot be coerced — in which case the caller MUST NOT
     * invoke the method (the error string is what's returned to the LLM).
     *
     * Why this exists: small Ollama models routinely pass numeric strings
     * ("42") or textual references ("CO2306-0002") where the tool declared
     * a strict int parameter. PHP 8 strict_types raises TypeError before the
     * tool body runs, leaving the model with an opaque PHP error it can't
     * recover from. By coercing valid numeric strings to int and refusing
     * non-numeric strings with a guidance message, we turn cryptic crashes
     * into actionable recoveries the LLM can chain into a different tool
     * call (typically `dolibarr_list` with `sqlfilters` to resolve a ref to
     * a rowid).
     *
     * @param array<string, mixed> $namedArgs Modified in place.
     */
    private function coerceArgs(ReflectionMethod $method, array &$namedArgs): ?string
    {
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (!array_key_exists($name, $namedArgs)) {
                continue;
            }
            $value = $namedArgs[$name];
            if ($value === null) {
                continue;
            }

            $type = $param->getType();
            if (!($type instanceof ReflectionNamedType)) {
                continue;
            }
            $typeName = $type->getName();
            $isBuiltin = $type->isBuiltin();
            if (!$isBuiltin) {
                continue;
            }

            // Already correct PHP type → nothing to do.
            $actual = get_debug_type($value);
            if ($typeName === 'int' && $actual === 'int') {
                continue;
            }
            if ($typeName === 'float' && ($actual === 'float' || $actual === 'int')) {
                continue;
            }
            if ($typeName === 'bool' && $actual === 'bool') {
                continue;
            }
            if ($typeName === 'string' && $actual === 'string') {
                continue;
            }
            if ($typeName === 'array' && $actual === 'array') {
                continue;
            }

            // Coerce numeric-string → int/float
            if (($typeName === 'int' || $typeName === 'float') && is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    if ($type->allowsNull()) {
                        $namedArgs[$name] = null;
                        continue;
                    }
                    return $this->parameterError($name, $typeName, $value, $param);
                }
                if (is_numeric($trimmed)) {
                    $namedArgs[$name] = $typeName === 'int' ? (int) $trimmed : (float) $trimmed;
                    continue;
                }
                return $this->parameterError($name, $typeName, $value, $param);
            }

            // Coerce common bool encodings
            if ($typeName === 'bool') {
                if (is_int($value)) {
                    $namedArgs[$name] = $value !== 0;
                    continue;
                }
                if (is_string($value)) {
                    $low = strtolower(trim($value));
                    if (in_array($low, ['true', '1', 'yes', 'on'], true)) {
                        $namedArgs[$name] = true;
                        continue;
                    }
                    if (in_array($low, ['false', '0', 'no', 'off', ''], true)) {
                        $namedArgs[$name] = false;
                        continue;
                    }
                }
                return $this->parameterError($name, $typeName, $value, $param);
            }

            // Coerce scalar → string when the tool wants a string
            if ($typeName === 'string' && (is_int($value) || is_float($value) || is_bool($value))) {
                $namedArgs[$name] = (string) $value;
                continue;
            }
        }

        return null;
    }

    /**
     * Build a structured error message for the LLM when an argument cannot
     * be coerced to the declared type. Includes a hint specific to the most
     * common mistake (passing a textual reference where a rowid is expected).
     *
     * @param mixed $value
     */
    private function parameterError(string $name, string $expectedType, $value, \ReflectionParameter $param): string
    {
        $given = get_debug_type($value);
        $preview = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        if (strlen((string) $preview) > 80) {
            $preview = substr((string) $preview, 0, 80) . '…';
        }

        $hint = "Parameter \"{$name}\" must be of type {$expectedType}, but received {$given} ({$preview}).";

        // Common case: id-like int param receiving a textual ref like "CO2306-0002".
        if ($expectedType === 'int' && is_string($value) && !is_numeric(trim($value))) {
            if (preg_match('/^(id|rowid|sourceid|contactid|fk_\w+)$/i', $name)) {
                $hint .= ' If "' . $preview . '" is a document reference (ref) like "CO2306-0002", first call '
                    . '`dolibarr_list` with `sqlfilters` (for orders: "(t.ref:=:\'' . $preview . '\')") '
                    . 'to retrieve the numeric rowid, then call this tool again with that rowid.';
            } else {
                $hint .= ' Provide a numeric value (Dolibarr rowid).';
            }
        }

        return json_encode(
            ['error' => true, 'message' => $hint],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{"error":true,"message":"Argument coercion failed"}';
    }

    /**
     * Resolve a PHP type to a NeuronAI PropertyType.
     */
    private function resolvePropertyType(?\ReflectionType $type): PropertyType
    {
        if ($type instanceof ReflectionNamedType) {
            return match ($type->getName()) {
                'int', 'float' => PropertyType::NUMBER,
                'bool' => PropertyType::BOOLEAN,
                'array' => PropertyType::ARRAY,
                default => PropertyType::STRING,
            };
        }

        return PropertyType::STRING;
    }

    /**
     * Get the list of tool classes to scan.
     */
    private function getToolClasses(): array
    {
        return [
            \DolibarrMcp\Tools\ExplorerTools::class,
            \DolibarrMcp\Tools\CrudTools::class,
            \DolibarrMcp\Tools\DocumentTools::class,
            \DolibarrMcp\Tools\LineTools::class,
            \DolibarrMcp\Tools\ActionTools::class,
            \DolibarrMcp\Tools\ExtrafieldTools::class,
            \DolibarrMcp\Tools\ContactTools::class,
            \DolibarrMcp\Tools\FileGenerationTools::class,
        ];
    }

    /**
     * Resolve a tool class instance from the container.
     */
    private function resolveInstance(string $className): object
    {
        if (!isset($this->toolInstances[$className])) {
            $this->toolInstances[$className] = $this->container->get($className);
        }

        return $this->toolInstances[$className];
    }
}
