<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service with transactions disabled.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, true>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NoTransactionService extends Service
{
    /** @var bool Track whether afterCommit() was called */
    public bool $afterCommitCalled = false;

    /** @var int|null The transaction level handle() ran at */
    public ?int $level = null;

    /** @var bool Wrap prepare()+handle() in a database transaction */
    protected bool $transactional = false;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     */
    public function __construct(ServiceInput $input = new ArrayInput([]))
    {
        parent::__construct($input);
    }

    /**
     * React to a successful outcome after the transaction has committed.
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->afterCommitCalled = true;
    }

    /**
     * Record the transaction level and succeed.
     *
     * @return true
     */
    #[\Override]
    protected function handle(): mixed
    {
        $this->level = DB::transactionLevel();

        return true;
    }
}
