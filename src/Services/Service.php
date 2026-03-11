<?php

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Contracts\HasSuccessCallback;
use SineMacula\ApiToolkit\Services\Contracts\Initializable;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Base API service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Service implements ServiceInterface
{
    use Lockable;

    /** @var bool|null Service outcome status */
    protected ?bool $status = null;

    /**
     * Constructor.
     *
     * @param  array|\Illuminate\Support\Collection|\stdClass  $payload
     * @param  bool  $useTransaction
     * @param  bool  $useLock
     */
    public function __construct(

        /** The service input payload */
        protected array|Collection|\stdClass $payload = [],

        /** Whether to wrap handle() in a database transaction */
        protected readonly bool $useTransaction = true,

        /** Whether to acquire an exclusive cache lock before execution */
        protected readonly bool $useLock = false,

    ) {
        $this->payload = (!$payload instanceof Collection && !$payload instanceof \stdClass) ? collect($payload) : $payload;

        if ($this->useLock && !$this->getLockId()) {
            throw new \RuntimeException('Lock key is not set');
        }

        $this->initialize();
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
     * Orchestrates the full service lifecycle: lock acquisition,
     * prepare/handle execution (with optional transaction wrapping and
     * error handling), success notification, and lock release.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function run(): bool
    {
        $this->acquireLock();

        $this->executeLifecycle();

        $this->notifySuccess();

        return $this->status;
    }

    /**
     * Initialize the service.
     *
     * Called during construction, after payload normalization. If the
     * service (via a trait) implements Initializable, the trait's
     * initializeTrait() method is called to set up trait-specific state.
     *
     * @return void
     */
    protected function initialize(): void
    {
        if ($this instanceof Initializable) {
            $this::initializeTrait();
        }
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    abstract protected function handle(): bool;

    /**
     * Generate the key used for locking the task execution.
     *
     * @return string
     */
    protected function generateLockKey(): string
    {
        return sha1(static::class . '|' . $this->getLockId());
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
     * Conditionally acquire the cache lock for exclusive execution.
     *
     * Called at the start of run(). If $useLock is false, this method
     * is a no-op. When locking is enabled, delegates to the Lockable
     * trait's lock() method. Exceptions from lock acquisition propagate
     * to the caller.
     *
     * @return void
     */
    private function acquireLock(): void
    {
        if ($this->useLock) {
            $this->lock();
        }
    }

    /**
     * Execute the service lifecycle within a try/catch/finally block.
     *
     * Runs prepare() then executeHandler() within a try block. On
     * exception, calls failed() and rethrows. The finally block always
     * calls unlock() to release any acquired lock, even on exception.
     *
     * @return void
     *
     * @throws \Throwable
     */
    private function executeLifecycle(): void
    {
        try {

            $this->prepare();

            $this->status = $this->executeHandler();

        } catch (\Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Execute the service handler, optionally within a database
     * transaction.
     *
     * When $useTransaction is true, wraps handle() in a
     * DB::transaction() call with 3 retry attempts. When false, calls
     * handle() directly. Returns the boolean result from handle().
     *
     * @return bool
     */
    private function executeHandler(): bool
    {
        return $this->useTransaction
            ? (bool) DB::transaction(fn () => $this->handle(), 3)
            : $this->handle();
    }

    /**
     * Notify success callbacks after a successful service execution.
     *
     * Calls the service's own success() callback first, then checks
     * whether the service (via a trait) implements HasSuccessCallback
     * and invokes onTraitSuccess(). Runs outside the try/catch block --
     * exceptions here will NOT trigger the failed() callback.
     *
     * @return void
     */
    private function notifySuccess(): void
    {
        $this->success();

        if ($this instanceof HasSuccessCallback) {
            $this->onTraitSuccess();
        }
    }
}
