<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Facades\App;
use SineMacula\ApiToolkit\Concerns\Lockable;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;

/**
 * Abstract action skeleton.
 *
 * Holds a typed input and an explicit actor. Subclasses implement the six
 * lifecycle hooks; the runner sequences them in a fixed, transaction-aware
 * order. run() returns a total ServiceResult for a caller that branches on it,
 * and runOrFail() returns the output or throws the failure. While a run is in
 * progress, context() returns the ServiceContext it runs with. One instance
 * must not run concurrently.
 *
 * @template TInput of \SineMacula\ApiToolkit\Services\Contracts\ServiceInput
 * @template TOutput
 *
 * @phpstan-import-type ServiceHooks from \SineMacula\ApiToolkit\Services\ServiceRunner
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Service implements LockKeyProvider
{
    use Lockable;

    /** @var bool Wrap prepare()+handle() in a database transaction */
    protected bool $transactional = true;

    /** @var int Transaction retry attempts */
    protected int $transactionAttempts = 3;

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = false;

    /** @var \SineMacula\ApiToolkit\Services\Contracts\Actor|null Explicit causer; null until set by by() or withContext() */
    private ?Actor $actor = null;

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null Fully-built execution context; null until set by withContext() */
    private ?ServiceContext $attachedContext = null;

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context of the run in progress; null outside a run */
    private ?ServiceContext $runContext = null;

    /**
     * Constructor.
     *
     * @param  TInput  $input
     */
    public function __construct(

        /** @var TInput The typed input for this service action */
        protected readonly ServiceInput $input,
    ) {}

    /**
     * Resolve a new service instance from the container with the given input.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     * @return static
     */
    public static function make(ServiceInput $input): static
    {
        $class    = static::class;
        $instance = App::make($class, ['input' => $input]);
        assert($instance instanceof static);

        return $instance;
    }

    /**
     * Record the explicit causer for this invocation.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @return static
     */
    public function by(Actor $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * Attach a prebuilt execution context (actor, correlation id, source, and
     * metadata) to a synchronous run, taking the actor from it. This is the
     * entry point for a caller that needs to tag a run with its own source or
     * metadata; run() otherwise builds an INTERNAL context of its own. A later
     * by() changes actor() outside a run but not the attached context, so the
     * run still uses the context's actor.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return static
     */
    public function withContext(ServiceContext $context): static
    {
        $this->attachedContext = $context;
        $this->actor           = $context->actor;

        return $this;
    }

    /**
     * Return the actor for this invocation.
     *
     * While a run is in progress this is the actor of the run's context, so it
     * matches the actor the lifecycle events report. Outside a run it is the
     * actor given to by() or withContext(), or an AnonymousActor when none has
     * been supplied. Never reads Auth or any ambient state.
     *
     * @return \SineMacula\ApiToolkit\Services\Contracts\Actor
     */
    public function actor(): Actor
    {
        return $this->runContext->actor ?? $this->actor ?? new AnonymousActor;
    }

    /**
     * Execute the service synchronously and return a total result.
     *
     * Reuses the context given through withContext(), or builds an INTERNAL
     * one, then delegates lifecycle sequencing to ServiceRunner, which makes
     * that context available through context() for the duration of the run.
     * Never throws for business failures, so it suits a caller that branches on
     * the result; runOrFail() suits one that only wants the output.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<TOutput>
     */
    final public function run(): ServiceResult
    {
        $context = $this->attachedContext ?? ServiceContext::for($this->actor(), ServiceSource::INTERNAL);
        $class   = ServiceRunner::class;
        $runner  = App::make($class);
        assert($runner instanceof ServiceRunner);

        return $runner->run($this, $context);
    }

    /**
     * Execute the service synchronously and return its output, or throw.
     *
     * Runs exactly as run() does, then throws the failure instead of returning
     * it: the captured exception, or a ServiceFailedException when the failed
     * result captured none.
     *
     * @return TOutput
     *
     * @throws \Throwable
     */
    final public function runOrFail(): mixed
    {
        return $this->run()->throw()->output();
    }

    /**
     * Push the service onto the queue via ServiceJob.
     *
     * @return mixed
     */
    final public function dispatch(): mixed
    {
        $context = $this->attachedContext ?? ServiceContext::for($this->actor());

        return ServiceJob::dispatch(static::class, $this->input, $context);
    }

    /**
     * Generate the cache-lock key for this service.
     *
     * @return string
     */
    #[\Override]
    public function getLockKey(): string
    {
        return sha1(static::class . '|' . $this->lockId());
    }

    /**
     * Bind the context of the run in progress, returning the one it replaces.
     *
     * Package-internal seam: called exclusively by ServiceRunner, which binds
     * the run's context before resolving the hooks and restores the previous
     * one when the run ends.
     *
     * @internal
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext|null  $context
     * @return \SineMacula\ApiToolkit\Services\ServiceContext|null
     */
    final public function bindRunContext(?ServiceContext $context): ?ServiceContext
    {
        $previous         = $this->runContext;
        $this->runContext = $context;

        return $previous;
    }

    /**
     * Return the lifecycle hooks and configuration for this service.
     *
     * Package-internal seam: creates closures in Service scope so that
     * protected method access is valid. Called exclusively by ServiceRunner.
     *
     * @internal
     *
     * @return ServiceHooks
     */
    final public function serviceHooks(): array
    {
        return [
            'authorize' => function (): void {
                $this->authorize();
            },
            'validate' => function (): void {
                $this->validate();
            },
            'prepare' => function (): void {
                $this->prepare();
            },
            'handle'      => fn (): mixed => $this->handle(),
            'afterCommit' => function (mixed $output): void {
                $this->afterCommit($output);
            },
            'onFailure' => function (\Throwable $exception): void {
                $this->onFailure($exception);
            },
            'concerns'            => $this->concerns(),
            'lockId'              => $this->lockId(),
            'transactional'       => $this->transactional,
            'transactionAttempts' => $this->transactionAttempts,
            'lockable'            => $this->lockable,
            // Flows verbatim to lifecycle-event listeners; see ServiceInput::toArray()
            'inputSummary' => $this->input->toArray(),
        ];
    }

    /**
     * Return the execution context of the run in progress.
     *
     * Available in every lifecycle hook, concerns() and lockId(): the context
     * given through withContext(), the INTERNAL one run() built, or the QUEUE
     * one a queued run rebuilt. Work deferred beyond the run must capture it
     * first.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceContext
     *
     * @throws \LogicException
     */
    final protected function context(): ServiceContext
    {
        return $this->runContext ?? throw new \LogicException('The service context is only available while the service is running.');
    }

    /**
     * Authorize the current actor to perform this action.
     *
     * Runs before the lock and transaction. Throw AuthorizationException to
     * deny access. Default: allow.
     *
     * @return void
     */
    protected function authorize(): void {}

    /**
     * Validate the input data for this action.
     *
     * Runs before the lock and transaction. Throw ValidationException to signal
     * invalid input. Default: pass.
     *
     * @return void
     */
    protected function validate(): void {}

    /**
     * Perform pre-handle setup inside the transaction.
     *
     * Load or lock rows, pre-compute values, or perform any setup that must
     * happen within the transaction but before handle(). Default: no-op.
     *
     * @return void
     */
    protected function prepare(): void {}

    /**
     * Execute the core domain action.
     *
     * Runs inside the transaction. Signal failure only by throwing; the return
     * value is the typed output threaded through ServiceResult.
     *
     * @return TOutput
     */
    abstract protected function handle(): mixed;

    /**
     * React to a successful outcome after the transaction has committed.
     *
     * Runs outside the lock and transaction. Exceptions thrown here are
     * captured as side-effect errors on the result and reported through the
     * exception handler, leaving the committed outcome intact. Default: no-op.
     *
     * @param  TOutput  $output
     * @return void
     */
    protected function afterCommit(mixed $output): void {}

    /**
     * React to a failed outcome after the transaction has rolled back.
     *
     * Runs after rollback and lock release. Exceptions thrown here are caught
     * and reported through the exception handler. Default: no-op.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    protected function onFailure(\Throwable $exception): void {}

    /**
     * Return the ordered list of custom concern classes.
     *
     * Concerns run inside the transaction, in declaration order, wrapping the
     * core (prepare + handle). Default: none.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    protected function concerns(): array
    {
        return [];
    }

    /**
     * Return the unique lock identity for this invocation.
     *
     * Required when $lockable is true; must be non-empty. The final lock key is
     * sha1(class | lockId()). Default: empty string (disabled).
     *
     * @return string
     */
    protected function lockId(): string
    {
        return '';
    }
}
