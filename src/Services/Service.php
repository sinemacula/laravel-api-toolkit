<?php

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Base API service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Service implements LockKeyProvider, ServiceInterface
{
    use Lockable;

    /** @var bool|null Service outcome status */
    protected ?bool $status = null;

    /**
     * Constructor.
     *
     * @param  array|\Illuminate\Support\Collection|\stdClass  $payload
     */
    public function __construct(

        /** The service input payload */
        protected array|Collection|\stdClass $payload = [],

    ) {
        $this->payload = (!$payload instanceof Collection && !$payload instanceof \stdClass) ? collect($payload) : $payload;
    }

    /**
     * Get the service status.
     *
     * @return bool|null
     */
    public function getStatus(): ?bool
    {
        return $this->status;
    }

    /**
     * Prepare the service for execution.
     *
     * Called after lock acquisition and before handle(). Subclasses
     * override this to perform validation, data loading, or other
     * pre-execution setup. Exceptions thrown here trigger the
     * failed() callback and are rethrown.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function prepare(): void {}

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * Called after the handler completes and the transaction has
     * committed. Runs outside the try/catch block -- exceptions here
     * will NOT trigger the failed() callback.
     *
     * @return void
     */
    public function success(): void {}

    /**
     * Method is triggered if the handle method failed.
     *
     * Called when prepare() or handle() throws an exception, before
     * the exception is rethrown. The lock is released in the finally
     * block after this method returns.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void {}

    /**
     * Run the service.
     *
     * Builds a concern pipeline from the concerns() declaration
     * and executes it. The innermost step runs the core lifecycle
     * (prepare, handle, success/failed). Concerns wrap around this
     * core in declaration order.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function run(): bool
    {
        $pipeline = $this->buildPipeline();

        $this->status = $pipeline();

        $this->success();

        return $this->status;
    }

    /**
     * Generate the key used for cache-based locking.
     *
     * @return string
     */
    #[\Override]
    public function getLockKey(): string
    {
        return sha1(static::class . '|' . $this->getLockId());
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    abstract protected function handle(): bool;

    /**
     * Return the ordered list of concern classes for this service.
     *
     * Override in subclasses to declare cross-cutting concerns.
     * Concerns execute in declaration order: the first concern is
     * the outermost wrapper, the last is closest to the core
     * lifecycle.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    protected function concerns(): array
    {
        return [];
    }

    /**
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    protected function getLockId(): string
    {
        return '';
    }

    /**
     * Build the concern pipeline around the core lifecycle.
     *
     * Resolves each concern class from the container and folds
     * them right-to-left around the core lifecycle closure. The
     * first concern in the array is the outermost wrapper.
     *
     * @return \Closure(): bool
     */
    private function buildPipeline(): \Closure
    {
        $core = fn (): bool => $this->executeCore();

        $concerns = array_map(
            fn (string $class): ServiceConcern => app()->make($class),
            $this->concerns()
        );

        return array_reduce(
            array_reverse($concerns),
            fn (\Closure $next, ServiceConcern $concern): \Closure => fn (): bool => $concern->execute($this, $next),
            $core
        );
    }

    /**
     * Execute the core service lifecycle.
     *
     * Runs prepare() then handle() within a try/catch block.
     * On exception, calls failed() and rethrows. This is the
     * innermost step of the concern pipeline.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    private function executeCore(): bool
    {
        try {

            $this->prepare();

            return $this->handle();

        } catch (\Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }
}
