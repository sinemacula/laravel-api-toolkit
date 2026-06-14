<?php

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException;
use SineMacula\ApiToolkit\RouteLinting\LintRoutes;
use SineMacula\ApiToolkit\RouteLinting\Output\ConsoleLintReporter;

/**
 * Artisan command to lint the application route table against RESTful conventions.
 *
 * Invokes the LintRoutes use case, renders the report via ConsoleLintReporter,
 * and exits non-zero when the verdict contains any ERROR-severity violations.
 * Warning-severity findings and stale waivers are surfaced but do not gate the
 * exit code. A misconfigured allowlist entry (StaleWaiverException) is treated
 * as an error-grade configuration failure and exits non-zero immediately.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LintRoutesCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:lint-routes';

    /** @var string The console command description. */
    protected $description = 'Lint the application route table against the RESTful URL conventions';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\LintRoutes  $linter
     * @return int
     */
    public function handle(LintRoutes $linter): int
    {
        $reporter = new ConsoleLintReporter($this->output);

        try {
            $report = $linter->lint();
        } catch (StaleWaiverException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $reporter->report($report);

        return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
    }
}
