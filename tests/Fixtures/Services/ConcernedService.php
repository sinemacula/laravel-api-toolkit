<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that runs the given concerns around a handle() inserting a
 * users row, recording each hook it reaches and the output afterCommit() gets.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, mixed>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConcernedService extends Service
{
    /** @var array<int, string> The hooks reached, in order */
    public array $calls = [];

    /** @var mixed The output afterCommit() received */
    public mixed $committedOutput = null;

    /** @var array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>> The concerns to run */
    private readonly array $concernClasses;

    /**
     * Constructor.
     *
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>  $concernClasses
     */
    public function __construct(array $concernClasses)
    {
        parent::__construct(new ArrayInput([]));

        $this->concernClasses = $concernClasses;
    }

    /**
     * Return the given concerns.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        return $this->concernClasses;
    }

    /**
     * Record the prepare step.
     *
     * @return void
     */
    #[\Override]
    protected function prepare(): void
    {
        $this->calls[] = 'prepare';
    }

    /**
     * Record the handle step and insert a users row.
     *
     * @return string
     */
    #[\Override]
    protected function handle(): string
    {
        $this->calls[] = 'handle';

        DB::table('users')->insert(['name' => 'Concerned', 'email' => 'concerned@service.test']);

        return 'handled';
    }

    /**
     * Record the afterCommit step and the output it received.
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->calls[]         = 'afterCommit';
        $this->committedOutput = $output;
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
        $this->calls[] = 'onFailure';
    }
}
