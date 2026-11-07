<?php

namespace Tests\Unit\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Cache;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SineMacula\ApiToolkit\Services\InjectableService;
use SineMacula\ApiToolkit\Services\Traits\HasServiceLifecycle;
use Tests\Fixtures\Services\HandlerlessService;
use Tests\Fixtures\Services\InjectedPreparationService;
use Tests\Fixtures\Services\InjectedService;
use Tests\Fixtures\Services\OptionalInjectionService;
use Tests\Fixtures\Services\PrivateHandlerService;
use Tests\Fixtures\Services\PrivatePreparationService;
use Tests\Fixtures\Services\RepositoryAwareService;
use Tests\Fixtures\Services\ServiceProbe;
use Tests\Fixtures\Services\UnresolvableService;

/**
 * Injectable service tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InjectableService::class)]
#[CoversTrait(HasServiceLifecycle::class)]
class InjectableServiceTest extends ServiceTestCase
{
    /** @var string The entry the injected handler logs */
    private const string HANDLED = 'handle:default';

    /**
     * Handler parameters are resolved from the container inside a transaction
     * given three attempts, and a scalar parameter keeps its default.
     *
     * @return void
     */
    #[Test]
    public function handlerParametersAreResolvedFromTheContainer(): void
    {
        $probe = new ServiceProbe();
        Container::getInstance()->instance(ServiceProbe::class, $probe);

        $service = new InjectedService();

        static::assertTrue($service->run());
        static::assertTrue($service->getStatus());
        static::assertSame($probe, $service->probe);
        static::assertSame(['begin', self::HANDLED, 'commit'], $this->log->entries);
        static::assertSame([3], $this->db->attempts);
    }

    /**
     * An injected handler runs without a transaction when they are off.
     *
     * @return void
     */
    #[Test]
    public function injectedHandlerRunsWithoutATransaction(): void
    {
        $service = (new InjectedService())->dontUseTransaction();

        static::assertTrue($service->run());
        static::assertInstanceOf(ServiceProbe::class, $service->probe);
        static::assertSame([self::HANDLED], $this->log->entries);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * The handler's return value becomes the run result and the status.
     *
     * @param  bool  $transaction
     * @param  bool  $result
     * @return void
     */
    #[Test]
    #[DataProvider('outcomes')]
    public function handlerResultIsReturned(bool $transaction, bool $result): void
    {
        $service = new InjectedService(['result' => $result]);

        if (!$transaction) {
            $service->dontUseTransaction();
        }

        static::assertSame($result, $service->run());
        static::assertSame($result, $service->getStatus());
    }

    /**
     * Each combination of transaction use and handler result.
     *
     * @return iterable<string, array{bool, bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'transaction, true'     => [true, true];
        yield 'transaction, false'    => [true, false];
        yield 'no transaction, true'  => [false, true];
        yield 'no transaction, false' => [false, false];
    }

    /**
     * Prepare parameters are resolved from the container, and prepare runs
     * before the transaction opens.
     *
     * @return void
     */
    #[Test]
    public function prepareParametersAreResolvedFromTheContainer(): void
    {
        $service = new InjectedPreparationService();

        static::assertTrue($service->run());
        static::assertSame(['prepare:ServiceProbe', 'begin', 'handle', 'commit'], $this->log->entries);
    }

    /**
     * A prepare exception runs the failed hook and propagates unchanged without
     * running the handler or opening a transaction.
     *
     * @return void
     */
    #[Test]
    public function prepareExceptionPropagatesAfterTheFailedHook(): void
    {
        $exception = new RuntimeException('not ready');
        $service   = new InjectedPreparationService(['prepare_exception' => $exception]);

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame($exception, $service->failure);
        static::assertSame(['prepare:ServiceProbe'], $this->log->entries);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * A service using repository accessors and no prepare hook runs without
     * prepare reaching its magic call handler.
     *
     * @return void
     */
    #[Test]
    public function undefinedPrepareIsSkippedWithoutMagicCalls(): void
    {
        static::assertTrue((new RepositoryAwareService())->run());
    }

    /**
     * An optional class-typed handler parameter is resolved from the container
     * rather than left at its default.
     *
     * @return void
     */
    #[Test]
    public function optionalClassParameterIsResolved(): void
    {
        $service = new OptionalInjectionService();

        static::assertTrue($service->run());
        static::assertInstanceOf(ServiceProbe::class, $service->probe);
    }

    /**
     * A handler exception rolls back, runs the failed hook and propagates
     * unchanged.
     *
     * @return void
     */
    #[Test]
    public function handlerExceptionPropagatesAfterTheFailedHook(): void
    {
        $exception = new RuntimeException('boom');
        $service   = new InjectedService(['exception' => $exception]);

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame($exception, $service->failure);
        static::assertSame(['begin', self::HANDLED, 'rollback'], $this->log->entries);
        static::assertNull($service->getStatus());
    }

    /**
     * The lock is held while the handler runs and released afterwards.
     *
     * @return void
     */
    #[Test]
    public function lockIsHeldWhileTheHandlerRuns(): void
    {
        $service = (new InjectedService(['lock_id' => 'record-1']))->useLock();

        static::assertTrue($service->run());
        static::assertSame(['begin', self::HANDLED, 'locked', 'commit'], $this->log->entries);
        static::assertTrue(Cache::lock($service->lockKey(), 1)->get());
    }

    /**
     * The lock is released when the handler throws.
     *
     * @return void
     */
    #[Test]
    public function lockIsReleasedWhenTheHandlerThrows(): void
    {
        $exception = new RuntimeException('locked out');
        $service   = (new InjectedService(['lock_id' => 'record-1', 'exception' => $exception]))->useLock();

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertTrue(Cache::lock($service->lockKey(), 1)->get());
    }

    /**
     * A failing injected handler leaves no contextual binding behind.
     *
     * @return void
     */
    #[Test]
    public function failingInjectedHandlerLeavesNoContextBehind(): void
    {
        $container = Container::getInstance();
        $container->bind('choice', fn () => 'global');
        $container->when(InjectedService::class)->needs('choice')->give(fn () => 'service');

        $exception = new RuntimeException('injected');
        $service   = (new InjectedService(['exception' => $exception]))->dontUseTransaction();

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame('global', $container->make('choice'));
    }

    /**
     * A handler dependency the container cannot build fails the service.
     *
     * @return void
     */
    #[Test]
    public function unresolvableHandlerDependencyRunsTheFailedHook(): void
    {
        $service   = new UnresolvableService();
        $exception = $this->runExpectingFailure($service);

        static::assertInstanceOf(BindingResolutionException::class, $exception);
        static::assertSame($exception, $service->failure);
    }

    /**
     * A service without a handler is rejected before it is prepared.
     *
     * @return void
     */
    #[Test]
    public function serviceWithoutAHandlerIsRejected(): void
    {
        $service   = new HandlerlessService();
        $exception = $this->runExpectingFailure($service);

        static::assertInstanceOf(LogicException::class, $exception);
        static::assertSame('Service [' . HandlerlessService::class . '] must define a protected handle() method', $exception->getMessage());
        static::assertFalse($service->prepared);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * A private hook the base class cannot call is rejected.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Services\InjectableService>  $class
     * @param  string  $hook
     * @return void
     */
    #[Test]
    #[DataProvider('privateHooks')]
    public function privateHookIsRejected(string $class, string $hook): void
    {
        $exception = $this->runExpectingFailure(new $class());

        static::assertInstanceOf(LogicException::class, $exception);
        static::assertSame(sprintf('Service [%s] must not define %s() as private', $class, $hook), $exception->getMessage());
    }

    /**
     * Each service with a private hook and the hook it hides.
     *
     * @return iterable<string, array{class-string<\SineMacula\ApiToolkit\Services\InjectableService>, string}>
     */
    public static function privateHooks(): iterable
    {
        yield 'handle'  => [PrivateHandlerService::class, 'handle'];
        yield 'prepare' => [PrivatePreparationService::class, 'prepare'];
    }
}
