<?php

declare(strict_types=1);

namespace Dalfred;

/**
 * Helper to inline Lucide SVG icons in PHP-rendered HTML.
 *
 * Usage:
 *   echo Icon::render('bot');
 *   echo Icon::render('bot', ['class' => 'dalfred-icon-lg']);
 *
 * The icon name maps to a file in img/icons/lucide/{name}.svg. The SVG
 * is read once per request and cached in memory. Custom attributes are
 * merged into the root <svg> element — class is appended, others overwrite.
 */
final class Icon
{
    private const ICONS_DIR = __DIR__ . '/../img/icons/lucide';
    private const NAME_PATTERN = '/^[a-z0-9-]+$/';

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * @param array<string, string> $attributes Extra attributes for the <svg>.
     *   - 'class' is appended to the existing classes.
     *   - Any other key overwrites the existing attribute.
     */
    public static function render(string $name, array $attributes = []): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return self::missingPlaceholder($name);
        }

        if (!isset(self::$cache[$name])) {
            $path = self::ICONS_DIR . '/' . $name . '.svg';
            if (!is_file($path)) {
                error_log("[Dalfred] Icon::render — missing icon: {$name}");
                return self::missingPlaceholder($name);
            }
            self::$cache[$name] = (string) file_get_contents($path);
        }

        $svg = self::$cache[$name];

        // Always ensure the standard Lucide class is present so CSS can target icons.
        // Merge with any caller-supplied class; other attributes overwrite.
        $lucideClass = 'lucide lucide-' . $name;
        $mergedAttributes = array_merge(['class' => $lucideClass], $attributes);
        if (isset($attributes['class'])) {
            $mergedAttributes['class'] = $lucideClass . ' ' . $attributes['class'];
        }

        return self::injectAttributes($svg, $mergedAttributes);
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function injectAttributes(string $svg, array $attributes): string
    {
        // Locate the opening <svg ...> tag — Lucide SVGs use multi-line format.
        // Capture an optional trailing slash so self-closing <svg ... /> is handled.
        if (!preg_match('/<svg\b([\s\S]*?)(\/?)>/i', $svg, $matches, PREG_OFFSET_CAPTURE)) {
            error_log("[Dalfred] Icon::injectAttributes — could not locate <svg> opening tag; attributes not injected.");
            return $svg;
        }
        $existingAttrs = $matches[1][0];
        $selfClose     = $matches[2][0]; // '/' or ''
        $tagStart = $matches[0][1];
        $tagEnd = $tagStart + strlen($matches[0][0]);

        foreach ($attributes as $key => $value) {
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            // Match attribute with any whitespace (including newlines) before it.
            // NOTE: the pattern assumes attribute values do not contain literal quotes,
            // which holds for all current Lucide v0.460.0 icons.
            $pattern = '/[\s]+' . preg_quote($key, '/') . '="([^"]*)"/';
            if ($key === 'class' && preg_match($pattern, $existingAttrs, $m)) {
                $merged = trim($m[1] . ' ' . $escapedValue);
                $existingAttrs = preg_replace(
                    $pattern,
                    ' class="' . $merged . '"',
                    $existingAttrs,
                    1
                );
            } elseif (preg_match($pattern, $existingAttrs)) {
                $existingAttrs = preg_replace(
                    $pattern,
                    ' ' . $key . '="' . $escapedValue . '"',
                    $existingAttrs,
                    1
                );
            } else {
                // Safety net: if regex failed to detect the attribute but the raw
                // string already contains it (e.g. value with embedded quotes), log
                // a warning instead of silently writing a duplicate.
                if (str_contains($existingAttrs, ' ' . $key . '="')) {
                    error_log("[Dalfred] Icon::injectAttributes — attribute '{$key}' detected by substring but not by regex (possible quoted value); appending may duplicate it.");
                }
                $existingAttrs .= "\n  " . $key . '="' . $escapedValue . '"';
            }
        }

        return substr($svg, 0, $tagStart) . '<svg' . $existingAttrs . $selfClose . '>' . substr($svg, $tagEnd);
    }

    private static function missingPlaceholder(string $name): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" '
            . 'width="24" height="24" viewBox="0 0 24 24" '
            . 'class="dalfred-icon-missing" '
            . 'data-icon="' . $safeName . '">'
            . '<rect x="3" y="3" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"/>'
            . '</svg>';
    }
}
