<?php

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
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
abstract class Service implements LockKeyProvider, ServiceInterface
{
    use Lockable;

    /** @var bool|null Service outcome status */
    protected ?bool $status = null;

    /** @var bool Indicate whether to use database transactions for the service */
    protected readonly bool $useTransaction;

    /** @var bool Indicate whether to lock the task execution */
    protected readonly bool $useLock;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->useTransaction = $this->shouldUseTransaction();
        $this->useLock        = $this->shouldUseLock();

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
     * Initialize the service.
     *
     * Called during construction, after configuration initialization. If the
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
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    protected function getLockId(): string
    {
        return '';
    }

    /**
     * Determine whether to use database transactions for the service.
     *
     * Override in subclasses to disable transaction wrapping.
     *
     * @return bool
     */
    protected function shouldUseTransaction(): bool
    {
        return true;
    }

    /**
     * Determine whether to lock the task execution.
     *
     * Override in subclasses to enable cache-based locking.
     *
     * @return bool
     */
    protected function shouldUseLock(): bool
    {
        return false;
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
