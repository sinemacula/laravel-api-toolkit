<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Exceptions\JobAttemptException;
use Tests\Fixtures\Repositories\DeferrableUserRepository;

/**
 * Fixture job that defers one user row per attempt and throws on the given
 * attempts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeferringJob implements ShouldQueue
{
    use InteractsWithQueue;

    /** @var array<int, int> The rows already buffered when each attempt began */
    public static array $bufferedAtStart = [];

    /** @var array<int, \SineMacula\ApiToolkit\Repositories\Concerns\WritePool> The pool each attempt deferred into */
    public static array $pools = [];

    /**
     * Create a new deferring job instance.
     *
     * @param  string  $label
     * @param  array<int, int>  $failingAttempts
     * @return void
     */
    public function __construct(

        /** The label each deferred row's email is built from */
        public readonly string $label,

        /** The attempts that throw once their row is deferred */
        public readonly array $failingAttempts = [],
    ) {}

    /**
     * Reset the recorded attempts between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$bufferedAtStart = [];
        self::$pools           = [];
    }

    /**
     * Defer a row addressed by this attempt, then throw if it should fail.
     *
     * @param  \Tests\Fixtures\Repositories\DeferrableUserRepository  $users
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePool  $pool
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \Tests\Fixtures\Exceptions\JobAttemptException
     */
    public function handle(DeferrableUserRepository $users, WritePool $pool): void
    {
        self::$bufferedAtStart[] = $pool->count();
        self::$pools[]           = $pool;

        $attempt = $this->attempts();

        $users->defer(['name' => $this->label, 'email' => "{$this->label}-{$attempt}@queue.test"]);

        if (in_array($attempt, $this->failingAttempts, true)) {
            throw new JobAttemptException("Attempt {$attempt} failed.");
        }
    }
}
