<?php

/*
 * php-scoper configuration for Dalfred's release build (not used at runtime).
 *
 * Why: Dolibarr loads every module into one PHP process, and many modules, the
 * core included (htdocs/includes/), ship their own copy of psr/log,
 * psr/http-message, guzzle, symfony/*... PHP holds one class per name: the copy
 * loaded first wins, and when ours was written against another major version
 * the result is a fatal error nobody can catch. emMCP met it in production in
 * November 2026 (a psr/log 1.x LoggerInterface loaded by someone else, then the
 * runtime's psr/log 3 NullLogger -> "Declaration ... must be compatible").
 * Dalfred carries the same MCP runtime plus its own dependency tree (NeuronAI,
 * guzzle, psr/http-*), loaded on every page through its hooks.
 *
 * The build therefore scopes BOTH trees, each under its own prefix so the two
 * copies of guzzle they contain never meet either:
 *  - the module's vendor/ together with src/      -> DalfredVendor\
 *  - dolibarr-mcp-server/ (runtime and its vendor) -> DalfredMcpVendor\
 * The tree and the prefix are passed by tools/isolate-dependencies.sh
 * (DALFRED_SCOPE_SRC, DALFRED_SCOPE_PREFIX).
 *
 * Left unprefixed, on purpose:
 *  - Dalfred\        the module's own classes, named by its PHP pages;
 *  - DolibarrMcp\    the runtime, and the API other modules implement
 *                    (ToolExtension, SqlCapabilityInterface), shared with emMCP;
 *  - Mcp\            the MCP SDK, whose attributes (#[McpTool]) extension
 *                    modules use and DirectMcpBridge reads;
 *  - DolibarrMcpOAuth\, DolibarrMcpSql\, DolibarrMcpAudit\  the bundled
 *                    libraries, loaded by their own autoloaders;
 *  - every global name (no namespace): Dolibarr's own classes, functions and
 *    constants that src/ calls (\DoliDB, \dol_syslog(), DOL_DATA_ROOT...), and
 *    the polyfills / guarded helpers some packages declare globally.
 * References from the scoped code to the namespaces above are left as they are;
 * references to every other namespace are rewritten to the prefix.
 *
 * Files written in the global namespace (polyfill bootstraps and stubs,
 * symfony/deprecation-contracts, ralouphie/getallheaders...) are copied
 * untouched: each declares a global function or class behind a
 * function_exists()/class_exists() guard. Scoped, php-scoper would move the
 * declaration under the prefix while the callers, global names being excluded,
 * keep calling the global one, which then may not exist.
 */

declare(strict_types=1);

$src = rtrim((string) getenv('DALFRED_SCOPE_SRC'), '/');
$prefix = (string) getenv('DALFRED_SCOPE_PREFIX');
if ($src === '' || !is_dir($src) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $prefix)) {
    throw new RuntimeException('DALFRED_SCOPE_SRC and DALFRED_SCOPE_PREFIX must be set (see tools/isolate-dependencies.sh)');
}

/*
 * Every PHP file under vendor/ that declares no namespace. Composer's own files
 * are regenerated after scoping and need no special care.
 */
$globalFiles = array();
if (is_dir($src.'/vendor')) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src.'/vendor', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (strpos($path, '/vendor/composer/') !== false || $path === $src.'/vendor/autoload.php') {
            continue;
        }
        if (!preg_match('/^\s*namespace\s+[A-Za-z_\\\\]/m', (string) file_get_contents($path))) {
            $globalFiles[] = $path;
        }
    }
    sort($globalFiles);
}

// A name without any namespace separator, i.e. a global symbol.
$global = '/^[A-Za-z_][A-Za-z0-9_]*$/';

return [
    'prefix' => $prefix,

    // Anchored regexes, not plain names: php-scoper 0.18.17 matches a plain
    // 'Mcp' against ANY namespace segment, case-insensitively, so it also left
    // NeuronAI\MCP\ unprefixed (and outside the authoritative classmap).
    'exclude-namespaces' => array_map(
        static fn (string $ns): string => '~^'.preg_quote($ns, '~').'(\\\\|$)~',
        ['Dalfred', 'DolibarrMcp', 'DolibarrMcpOAuth', 'DolibarrMcpSql', 'DolibarrMcpAudit', 'Mcp', 'Symfony\\Polyfill']
    ),

    'exclude-classes' => [$global],
    'exclude-functions' => [$global],
    'exclude-constants' => [$global],

    'exclude-files' => $globalFiles,

    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
