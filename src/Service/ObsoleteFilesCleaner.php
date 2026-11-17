<?php

declare(strict_types=1);

namespace Dalfred\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Removes files an earlier Dalfred package shipped and the current one must
 * not leave behind.
 *
 * Customers often update by unzipping the new version over the old one, which
 * adds and replaces files but never deletes any. Until 2.35.0 the package
 * carried dolibarr-mcp-server/public/ (a standalone MCP server under the web
 * root, answering without authentication and taking the Dolibarr URL from a
 * request header) and dolibarr-mcp-server/bin/. The module never used them.
 *
 * Safety:
 *  - only the fixed relative paths below, resolved under the module root, and
 *    only when that root really holds modDalfred's descriptor;
 *  - every real path must stay strictly inside the module root;
 *  - symbolic links are deleted, never followed;
 *  - a development checkout (the runtime is a git repository) is left alone:
 *    there those directories belong to the runtime's own repository;
 *  - never throws: failures (insufficient rights...) are returned, logged by
 *    the caller and shown on Setup > Diagnostic.
 */
final class ObsoleteFilesCleaner
{
    /** Paths relative to the module root. */
    public const PATHS = [
        'dolibarr-mcp-server/public',
        'dolibarr-mcp-server/bin',
    ];

    private string $moduleDir;

    public function __construct(string $moduleDir)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
    }

    public static function forThisModule(): self
    {
        return new self(dirname(__DIR__, 2));
    }

    /**
     * True on a development checkout, where nothing must be removed.
     */
    public function isDevelopmentCheckout(): bool
    {
        return file_exists($this->moduleDir . '/dolibarr-mcp-server/.git');
    }

    /**
     * Obsolete paths still present (relative), empty on a development checkout.
     *
     * @return string[]
     */
    public function remaining(): array
    {
        if ($this->isDevelopmentCheckout()) {
            return [];
        }
        $left = [];
        foreach (self::PATHS as $rel) {
            $abs = $this->moduleDir . '/' . $rel;
            if (file_exists($abs) || is_link($abs)) {
                $left[] = $rel;
            }
        }

        return $left;
    }

    /**
     * @return array{removed: string[], failed: array<string, string>, skipped: bool}
     */
    public function clean(): array
    {
        $result = ['removed' => [], 'failed' => [], 'skipped' => false];

        $root = realpath($this->moduleDir);
        if ($root === false || !is_file($root . '/core/modules/modDalfred.class.php')) {
            $result['failed']['*'] = 'not a Dalfred module directory: ' . $this->moduleDir;

            return $result;
        }
        if ($this->isDevelopmentCheckout()) {
            $result['skipped'] = true;

            return $result;
        }

        foreach (self::PATHS as $rel) {
            $abs = $root . '/' . $rel;
            if (!file_exists($abs) && !is_link($abs)) {
                continue;
            }
            $error = $this->remove($abs, $root);
            clearstatcache();
            if ($error === null && !file_exists($abs) && !is_link($abs)) {
                $result['removed'][] = $rel;
            } else {
                $result['failed'][$rel] = $error ?? 'could not be removed';
            }
        }

        return $result;
    }

    /**
     * Remove a file, link or directory tree that must lie under $root.
     *
     * @return string|null Error, null on success
     */
    private function remove(string $path, string $root): ?string
    {
        if (is_link($path)) {
            return @unlink($path) ? null : 'cannot delete link (permissions?)';
        }
        $real = realpath($path);
        if ($real === false || strpos($real . '/', $root . '/') !== 0 || $real === $root) {
            return 'resolves outside the module directory, left alone';
        }
        if (is_file($real)) {
            return @unlink($real) ? null : 'cannot delete file (permissions?)';
        }

        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $p = $item->getPathname();
                if (is_link($p) || !$item->isDir()) {
                    if (!@unlink($p)) {
                        return 'cannot delete ' . substr($p, strlen($root) + 1) . ' (permissions?)';
                    }
                } elseif (!@rmdir($p)) {
                    return 'cannot delete ' . substr($p, strlen($root) + 1) . ' (permissions?)';
                }
            }
        } catch (\Throwable $e) {
            return 'cannot read ' . substr($real, strlen($root) + 1) . ': ' . $e->getMessage();
        }

        return @rmdir($real) ? null : 'cannot delete ' . substr($real, strlen($root) + 1) . ' (permissions?)';
    }
}
