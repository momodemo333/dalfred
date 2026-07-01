<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function dol_syslog;
use function max;
use function min;
use function sleep;

/**
 * DEBUG-ONLY tool used to reproduce long-running requests on production:
 * forces the worker to sleep for a known duration so timeouts can be
 * traced precisely (chat.php POST? thread.php poll? proxy? PHP-FPM
 * worker exhaustion?).
 *
 * Disabled by default. Enable on demand via:
 *   UPDATE llx_const SET value='1' WHERE name='DALFRED_DEBUG_SLEEP_TOOL_ENABLED';
 * (or via dolibarr_set_const). Disable when debugging is over.
 *
 * Hard-clamped to 1..180 seconds so it cannot be weaponised.
 */
class DebugSleepTool extends Tool
{
    public const MAX_SECONDS = 180;

    public function __construct()
    {
        parent::__construct(
            name: 'debug_sleep',
            description:
                'DEBUG ONLY — Sleep for N seconds, then return. Used by the '
                . 'developer to test long-running requests, gateway timeouts, '
                . 'session locks and worker pool exhaustion. Do NOT use in '
                . 'normal conversations. Only enabled when the admin '
                . 'explicitly turns DALFRED_DEBUG_SLEEP_TOOL_ENABLED on.',
        );
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'seconds',
                type: PropertyType::INTEGER,
                description: 'How many seconds to sleep. Clamped to [1, ' . self::MAX_SECONDS . '].',
                required: true,
            ),
            ToolProperty::make(
                name: 'label',
                type: PropertyType::STRING,
                description: 'Free-form tag echoed back in logs and in the result, to disambiguate concurrent test runs.',
                required: false,
            ),
        ];
    }

    public function __invoke(int $seconds, string $label = ''): string
    {
        $clamped = max(1, min(self::MAX_SECONDS, $seconds));

        dol_syslog(
            '[Dalfred] debug_sleep START — seconds=' . $clamped
            . ($label !== '' ? ' label=' . $label : ''),
            LOG_WARNING
        );

        sleep($clamped);

        dol_syslog(
            '[Dalfred] debug_sleep END — seconds=' . $clamped
            . ($label !== '' ? ' label=' . $label : ''),
            LOG_WARNING
        );

        return json_encode([
            'success' => true,
            'slept_seconds' => $clamped,
            'requested_seconds' => $seconds,
            'label' => $label,
            'message' => 'Slept ' . $clamped . ' second(s)' . ($label !== '' ? ' (label="' . $label . '")' : '') . '.',
        ], JSON_UNESCAPED_UNICODE);
    }
}
