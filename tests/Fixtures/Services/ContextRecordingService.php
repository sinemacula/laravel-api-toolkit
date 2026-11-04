<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Exceptions\ServiceExecutionException;

/**
 * Fixture service that records the run context each hook reads.
 *
 * Every lifecycle hook, concerns() and lockId() stores $this->context() under
 * its own name, so tests can assert which context each one saw. handle() throws
 * when $fails is set, so the failure path records onFailure() instead of
 * afterCommit().
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContextRecordingService extends Service
{
    /** @var bool Whether handle() throws instead of returning */
    public bool $fails = false;

    /** @var array<string, \SineMacula\ApiToolkit\Services\ServiceContext> The context each hook read, keyed by hook name */
    public array $seen = [];

    /**
     * Return the context of the run in progress.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceContext
     */
    public function currentContext(): ServiceContext
    {
        return $this->context();
    }

    /**
     * Record the context the authorize step read.
     *
     * @return void
     */
    #[\Override]
    protected function authorize(): void
    {
        $this->seen['authorize'] = $this->context();
    }

    /**
     * Record the context the validate step read.
     *
     * @return void
     */
    #[\Override]
    protected function validate(): void
    {
        $this->seen['validate'] = $this->context();
    }

    /**
     * Record the context the prepare step read.
     *
     * @return void
     */
    #[\Override]
    protected function prepare(): void
    {
        $this->seen['prepare'] = $this->context();
    }

    /**
     * Record the context the handle step read, then return or throw.
     *
     * @return string
     *
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    protected function handle(): string
    {
        $this->seen['handle'] = $this->context();

        if ($this->fails) {
            throw new ServiceExecutionException('Recording service failed.');
        }

        return 'recorded';
    }

    /**
     * Record the context the afterCommit step read.
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->seen['afterCommit'] = $this->context();
    }

    /**
     * Record the context the onFailure step read.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFailure(\Throwable $exception): void
    {
        $this->seen['onFailure'] = $this->context();
    }

    /**
     * Record the context concerns() read and declare no concerns.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        $this->seen['concerns'] = $this->context();

        return [];
    }

    /**
     * Record the context lockId() read and return its correlation id.
     *
     * @return string
     */
    #[\Override]
    protected function lockId(): string
    {
        $this->seen['lockId'] = $this->context();

        return $this->context()->correlationId;
    }
}
