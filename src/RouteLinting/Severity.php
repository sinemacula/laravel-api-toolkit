<?php

namespace SineMacula\ApiToolkit\RouteLinting;

/**
 * The severity tier for a route-linting finding.
 *
 * ERROR-severity violations gate CI (the command exits non-zero when any are
 * present). WARNING-severity violations are surfaced but do not raise the error
 * gate. Every rule declares its tier through this enum so callers never
 * hard-code string comparisons against finding metadata.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Severity: string
{
    /** A finding that must be resolved before the route passes the linter gate. */
    case ERROR = 'error';

    /** A finding that is surfaced for awareness but does not block the gate. */
    case WARNING = 'warning';
}
