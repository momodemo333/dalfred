<?php

declare(strict_types=1);

namespace Dalfred\Service;

use Dalfred\Icon;

/**
 * Reads the four DALFRED_BRAND_* constants and exposes brand-aware helpers
 * for the rest of the codebase. Stateless and cheap to instantiate.
 */
final class BrandingService
{
    public const DEFAULT_NAME = 'Dalfred';
    public const DEFAULT_PRIMARY = '#4CAF50';
    public const DEFAULT_SECONDARY = '#45a049';

    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';
    private const LOGO_NAME_PATTERN = '/^logo\.(png|svg|jpg)$/';

    public function getName(): string
    {
        $value = trim(getDolGlobalString('DALFRED_BRAND_NAME', self::DEFAULT_NAME));
        return $value === '' ? self::DEFAULT_NAME : $value;
    }

    public function getPrimaryColor(): string
    {
        return $this->validateColor(
            getDolGlobalString('DALFRED_BRAND_COLOR_PRIMARY', self::DEFAULT_PRIMARY),
            self::DEFAULT_PRIMARY
        );
    }

    public function getSecondaryColor(): string
    {
        return $this->validateColor(
            getDolGlobalString('DALFRED_BRAND_COLOR_SECONDARY', self::DEFAULT_SECONDARY),
            self::DEFAULT_SECONDARY
        );
    }

    public function getLogoUrl(): ?string
    {
        $name = getDolGlobalString('DALFRED_BRAND_LOGO_PATH', '');
        if ($name === '' || !preg_match(self::LOGO_NAME_PATTERN, $name)) {
            return null;
        }
        // Served by ajax/branding.php — that endpoint enforces the same whitelist.
        return dol_buildpath('/dalfred/ajax/branding.php?file=' . rawurlencode($name), 1);
    }

    /**
     * Snippet ready to be embedded in a <style> tag at the top of a page that
     * displays the chat widget or fullscreen.
     */
    public function getCssVariables(): string
    {
        $primary = $this->getPrimaryColor();
        $secondary = $this->getSecondaryColor();
        $shadow = $this->hexToRgba($primary, 0.3);
        $shadowHover = $this->hexToRgba($primary, 0.4);

        return ':root {'
            . ' --dalfred-primary: ' . $primary . ';'
            . ' --dalfred-secondary: ' . $secondary . ';'
            . ' --dalfred-primary-shadow: ' . $shadow . ';'
            . ' --dalfred-primary-shadow-hover: ' . $shadowHover . ';'
            . ' }';
    }

    /**
     * Returns the HTML to display "the agent's picture": uploaded logo if any,
     * otherwise the default Lucide bot icon.
     */
    public function getAgentIconHtml(string $sizeClass = 'dalfred-icon-md'): string
    {
        $logoUrl = $this->getLogoUrl();
        if ($logoUrl !== null) {
            return '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '"'
                . ' alt="' . htmlspecialchars($this->getName(), ENT_QUOTES, 'UTF-8') . '"'
                . ' class="dalfred-agent-logo ' . htmlspecialchars($sizeClass, ENT_QUOTES, 'UTF-8') . '">';
        }
        return Icon::render('bot', ['class' => 'dalfred-agent-icon ' . $sizeClass]);
    }

    private function validateColor(string $value, string $fallback): string
    {
        return preg_match(self::COLOR_PATTERN, $value) === 1 ? $value : $fallback;
    }

    private function hexToRgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return 'rgba(' . $r . ', ' . $g . ', ' . $b . ', ' . $alpha . ')';
    }
}
