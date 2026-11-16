<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that runs a given body as handle(), recording the transaction
 * level of each attempt and whether afterCommit() ran.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, mixed>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TransactionRecordingService extends Service
{
    /** @var array<int, int> The transaction level seen by each attempt */
    public array $levels = [];

    /** @var bool Whether afterCommit() ran */
    public bool $committed = false;

    /** @var \Closure(): mixed The body run as handle() */
    private readonly \Closure $body;

    /**
     * Constructor.
     *
     * @param  \Closure(): mixed  $body
     * @param  int|null  $attempts
     */
    public function __construct(\Closure $body, ?int $attempts = null)
    {
        parent::__construct(new ArrayInput([]));

        $this->body                = $body;
        $this->transactionAttempts = $attempts ?? $this->transactionAttempts;
    }

    /**
     * Record the transaction level and run the body.
     *
     * @return mixed
     */
    #[\Override]
    protected function handle(): mixed
    {
        $this->levels[] = DB::transactionLevel();

        return ($this->body)();
    }

    /**
     * Record that the transaction committed.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->committed = true;
    }
}
