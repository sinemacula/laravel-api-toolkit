<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Repositories\DeferrableUserRepository;

/**
 * Fixture service that defers one user row per run inside its transaction and
 * fails its first run.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, true>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeferringService extends Service
{
    /** @var int The number of times the service has run */
    public static int $runs = 0;

    /**
     * Reset the run count between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$runs = 0;
    }

    /**
     * Defer a row addressed by this run, failing the first.
     *
     * @return true
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    protected function handle(): mixed
    {
        $run = ++self::$runs;

        app(DeferrableUserRepository::class)->defer(['name' => 'Service', 'email' => "service-{$run}@queue.test"]);

        if ($run === 1) {
            throw new ServiceExecutionException('The first run failed.');
        }

        return true;
    }
}
