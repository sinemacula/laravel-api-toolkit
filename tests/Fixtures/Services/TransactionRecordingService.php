<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture service that runs a given body as handle(), recording the transaction
 * level of each attempt and of each afterCommit() run, and the exception each
 * onFailure() run received.
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

    /** @var array<int, int> The transaction level seen by each afterCommit() run */
    public array $commitLevels = [];

    /** @var array<int, \Throwable> The exception each onFailure() run received */
    public array $failures = [];

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context afterCommit() read */
    public ?ServiceContext $commitContext = null;

    /** @var (\Closure(): void)|null Work afterCommit() runs once it has recorded */
    public ?\Closure $onCommit = null;

    /** @var \Closure(): mixed The body run as handle() */
    private readonly \Closure $body;

    /**
     * Constructor.
     *
     * @param  \Closure(): mixed  $body
     * @param  int|null  $attempts
     * @param  bool  $transactional
     */
    public function __construct(\Closure $body, ?int $attempts = null, bool $transactional = true)
    {
        parent::__construct(new ArrayInput([]));

        $this->body                = $body;
        $this->transactionAttempts = $attempts ?? $this->transactionAttempts;
        $this->transactional       = $transactional;
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
     * Record the transaction level and context, then run the commit work.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->commitLevels[] = DB::transactionLevel();
        $this->commitContext  = $this->context();

        if ($this->onCommit === null) {
            return;
        }

        ($this->onCommit)();
    }

    /**
     * Record the exception the failed run ended with.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFailure(\Throwable $exception): void
    {
        $this->failures[] = $exception;
    }
}
