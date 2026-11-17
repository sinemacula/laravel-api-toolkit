<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Exceptions\ServiceExecutionException;

/**
 * Lockable fixture service that records, in every hook, the transaction level
 * and whether another owner could take its lock.
 *
 * validate() requires a name, and handle() inserts a users row with it before
 * throwing when $fails is set.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LifecycleRecordingService extends Service
{
    /** @var bool Whether handle() throws after its insert */
    public bool $fails = false;

    /** @var array<string, array{level: int, lockFree: bool}> What each hook saw, keyed by hook name */
    public array $seen = [];

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = true;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Input\ArrayInput  $input
     * @param  bool  $transactional
     */
    public function __construct(ArrayInput $input = new ArrayInput(['name' => 'Lifecycle']), bool $transactional = true)
    {
        parent::__construct($input);

        $this->transactional = $transactional;
    }

    /**
     * Record the authorize step.
     *
     * @return void
     */
    #[\Override]
    protected function authorize(): void
    {
        $this->record('authorize');
    }

    /**
     * Record the validate step and require a name.
     *
     * @return void
     */
    #[\Override]
    protected function validate(): void
    {
        $this->record('validate');

        Validator::validate($this->input->toArray(), ['name' => ['required', 'string']]);
    }

    /**
     * Record the prepare step.
     *
     * @return void
     */
    #[\Override]
    protected function prepare(): void
    {
        $this->record('prepare');
    }

    /**
     * Record the handle step, insert a users row, then return or throw.
     *
     * @return string
     *
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    protected function handle(): string
    {
        $this->record('handle');

        DB::table('users')->insert(['name' => $this->input->string('name'), 'email' => 'lifecycle@service.test']);

        if ($this->fails) {
            throw new ServiceExecutionException('Lifecycle service failed.');
        }

        return 'recorded';
    }

    /**
     * Record the afterCommit step.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->record('afterCommit');
    }

    /**
     * Record the onFailure step.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFailure(\Throwable $exception): void
    {
        $this->record('onFailure');
    }

    /**
     * Return the unique lock identity for this invocation.
     *
     * @return string
     */
    #[\Override]
    protected function lockId(): string
    {
        return 'lifecycle';
    }

    /**
     * Record the transaction level and whether the lock is free.
     *
     * @param  string  $hook
     * @return void
     */
    private function record(string $hook): void
    {
        $this->seen[$hook] = [
            'level'    => DB::transactionLevel(),
            'lockFree' => Cache::lock($this->getLockKey(), 1)->get(static fn (): bool => true) === true,
        ];
    }
}
