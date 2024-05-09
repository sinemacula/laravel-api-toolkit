<?php

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Traits\Lockable;
use Throwable;

/**
 * Base API service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class Service implements ServiceInterface
{
    use Lockable;

    /** @var bool|null Service outcome status */
    protected ?bool $status = null;

    /** @var bool Indicate whether to use database transactions for the service */
    protected bool $useTransaction = true;

    /** @var bool Indicate whether to lock the task execution */
    protected bool $useLock = false;

    /**
     * Constructor.
     *
     * @param  array  $input
     */
    public function __construct(

        /** The service input data */
        protected array $input = []

    ) {}

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
     * Instruct the service to use database transactions.
     *
     * NOTE: Transactions are only supported on MySQL databases running the
     * InnoDB engine
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function useTransaction(): static
    {
        $this->useTransaction = true;

        return $this;
    }

    /**
     * Instruct the service not to use database transactions.
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function dontUseTransaction(): static
    {
        $this->useTransaction = false;

        return $this;
    }

    /**
     * Instruct the service to use a cache lock.
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function useLock(): static
    {
        if (!$this->getLockId()) {
            throw new RuntimeException('Lock key is not set');
        }

        $this->useLock = true;

        return $this;
    }

    /**
     * Instruct the service not to use a cache lock.
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function dontUseLock(): static
    {
        $this->useLock = false;

        return $this;
    }

    /**
     * Prepare the service for execution.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function prepare(): void {}

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    public function success(): void {}

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void {}

    /**
     * Run the service.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function run(): bool
    {
        if ($this->useLock) {
            $this->lock();
        }

        try {

            // Prepare the service for execution
            $this->prepare();

            // Execute the service handler
            $this->status = $this->useTransaction
                ? DB::transaction(fn () => $this->handle(), 3)
                : $this->handle();

        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        } finally {
            $this->unlock();
        }

        // The success callback is run outside the try/catch block in order to
        // ensure it does not trigger the failed callback if exceptions are
        // thrown. Typically, if an exception is thrown in the success callback,
        // then this would be a bug considering all changes are committed at
        // this point
        $this->success();

        return $this->status;
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
        return sha1(get_class($this) . '|' . $this->getLockId());
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
}
