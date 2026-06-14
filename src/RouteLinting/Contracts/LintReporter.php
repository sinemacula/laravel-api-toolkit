<?php

namespace SineMacula\ApiToolkit\RouteLinting\Contracts;

use SineMacula\ApiToolkit\RouteLinting\RouteLintReport;

/**
 * Outbound port for rendering the lint report to the invoking surface.
 *
 * Implementers receive the fully-assembled {@see RouteLintReport} verdict
 * aggregate and render findings (grouped by severity) plus any stale-waiver
 * entries to their backing output surface (e.g. console, structured log).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface LintReporter
{
    /**
     * Render the report (findings grouped by severity, plus stale-waiver findings) to the invoking surface.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @return void
     */
    public function report(RouteLintReport $report): void;
}
