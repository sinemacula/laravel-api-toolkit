<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Models\User;

/**
 * Fixture service meeting the transaction retry contract: it loads the user it
 * renames by key inside the transaction, registers its after-commit work within
 * each attempt, and throws the given failure on its first attempt.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, int>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RetrySafeRenameService extends Service
{
    /** @var array<int, int> The transaction level seen by each attempt */
    public array $levels = [];

    /** @var int The after-commit callbacks that ran */
    public int $callbacks = 0;

    /** @var int Transaction attempts; a top-level run retries a concurrency error */
    protected int $transactionAttempts = 2;

    /** @var \Throwable The failure thrown by the first attempt */
    private readonly \Throwable $failure;

    /**
     * Constructor.
     *
     * @param  int  $userId
     * @param  \Throwable  $failure
     */
    public function __construct(int $userId, \Throwable $failure)
    {
        parent::__construct(new ArrayInput(['id' => $userId]));

        $this->failure = $failure;
    }

    /**
     * Load the user by key, rename it, and fail the first attempt.
     *
     * @return int
     *
     * @throws \Throwable
     */
    #[\Override]
    protected function handle(): mixed
    {
        $this->levels[] = DB::transactionLevel();

        DB::afterCommit(function (): void {
            $this->callbacks++;
        });

        $user       = User::query()->findOrFail($this->input->integer('id'));
        $user->name = 'after';
        $user->save();

        if (count($this->levels) === 1) {
            throw $this->failure;
        }

        return $this->input->integer('id');
    }
}
