<?php

namespace Tests\Unit\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Services\FakeDatabaseManager;
use Tests\Fixtures\Services\HandlerlessService;
use Tests\Fixtures\Services\InjectedService;
use Tests\Fixtures\Services\OptionalDependencyService;
use Tests\Fixtures\Services\PrivateHandlerService;
use Tests\Fixtures\Services\RecordingService;
use Tests\Fixtures\Services\ServiceLog;
use Tests\Fixtures\Services\ServiceProbe;
use Tests\Fixtures\Services\UnresolvableService;
use Throwable;

/**
 * Service run tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
class ServiceRunTest extends TestCase
{
    /** @var \Tests\Fixtures\Services\ServiceLog The log shared by the services and the database */
    private ServiceLog $log;

    /** @var \Tests\Fixtures\Services\FakeDatabaseManager The database the transactions run on */
    private FakeDatabaseManager $db;

    /**
     * Bind a fresh container with a logging database and an array cache.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new ServiceLog();
        $this->db  = new FakeDatabaseManager($this->log);

        $container = new Container();
        $container->instance(ServiceLog::class, $this->log);
        $container->instance('db', $this->db);
        $container->instance('cache', new CacheRepository(new ArrayStore()));

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    /**
     * Drop the container and the resolved facades.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * A parameterless handler runs inside a transaction given three attempts.
     *
     * @return void
     */
    #[Test]
    public function parameterlessHandlerRunsInsideATransaction(): void
    {
        $service = new RecordingService(['log' => $this->log]);

        static::assertTrue($service->run());
        static::assertTrue($service->getStatus());
        static::assertSame(['prepare', 'begin', 'handle', 'commit', 'success'], $this->log->entries);
        static::assertSame([3], $this->db->attempts);
    }

    /**
     * A parameterless handler runs without a transaction when they are off.
     *
     * @return void
     */
    #[Test]
    public function parameterlessHandlerRunsWithoutATransaction(): void
    {
        $service = (new RecordingService(['log' => $this->log]))->dontUseTransaction();

        static::assertTrue($service->run());
        static::assertSame(['prepare', 'handle', 'success'], $this->log->entries);
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
        $service = new RecordingService(['log' => $this->log, 'result' => $result]);

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
     * A handler exception rolls back, runs the failed hook and propagates
     * unchanged without running the success hook.
     *
     * @return void
     */
    #[Test]
    public function handlerExceptionPropagatesAfterTheFailedHook(): void
    {
        $exception = new RuntimeException('boom');
        $service   = new RecordingService(['log' => $this->log, 'exception' => $exception]);

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame(['prepare', 'begin', 'handle', 'rollback', 'failed:boom'], $this->log->entries);
        static::assertNull($service->getStatus());
    }

    /**
     * A handler exception propagates unchanged when transactions are off.
     *
     * @return void
     */
    #[Test]
    public function handlerExceptionPropagatesWithoutATransaction(): void
    {
        $exception = new RuntimeException('no transaction');
        $service   = (new RecordingService(['log' => $this->log, 'exception' => $exception]))->dontUseTransaction();

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame(['prepare', 'handle', 'failed:no transaction'], $this->log->entries);
    }

    /**
     * The lock is held while the handler runs and released afterwards.
     *
     * @return void
     */
    #[Test]
    public function lockIsHeldWhileTheHandlerRuns(): void
    {
        $service = (new RecordingService(['log' => $this->log, 'lock_id' => 'record-1']))->useLock();

        static::assertTrue($service->run());
        static::assertSame(['prepare', 'begin', 'handle', 'locked', 'commit', 'success'], $this->log->entries);
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
        $service   = (new RecordingService(['log' => $this->log, 'lock_id' => 'record-1', 'exception' => $exception]))->useLock();

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame(['prepare', 'begin', 'handle', 'locked', 'rollback', 'failed:locked out'], $this->log->entries);
        static::assertTrue(Cache::lock($service->lockKey(), 1)->get());
    }

    /**
     * Handler parameters are resolved from the container and a scalar parameter
     * keeps its default.
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
        static::assertSame($probe, $service->probe);
        static::assertSame(['begin', 'handle:default', 'commit'], $this->log->entries);
    }

    /**
     * An injected handler's result is returned when transactions are off.
     *
     * @return void
     */
    #[Test]
    public function injectedHandlerRunsWithoutATransaction(): void
    {
        $service = (new InjectedService(['result' => false]))->dontUseTransaction();

        static::assertFalse($service->run());
        static::assertInstanceOf(ServiceProbe::class, $service->probe);
        static::assertSame(['handle:default'], $this->log->entries);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * A handler without required parameters is called without the container,
     * so its optional parameters keep their defaults.
     *
     * @return void
     */
    #[Test]
    public function optionalHandlerParametersKeepTheirDefaults(): void
    {
        $service = new OptionalDependencyService();

        static::assertTrue($service->run());
        static::assertSame([null, []], $service->received);
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
        $service = new HandlerlessService();

        try {
            $service->run();
            static::fail('The missing handler was not rejected');
        } catch (LogicException $exception) {
            static::assertSame('Service [' . HandlerlessService::class . '] must define a protected handle() method', $exception->getMessage());
        }

        static::assertFalse($service->prepared);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * A private handler the base class cannot call is rejected.
     *
     * @return void
     */
    #[Test]
    public function privateHandlerIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Service [' . PrivateHandlerService::class . '] must define a protected handle() method');

        (new PrivateHandlerService())->run();
    }

    /**
     * Run the service and return the exception it throws.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @return \Throwable
     */
    private function runExpectingFailure(Service $service): Throwable
    {
        try {
            $service->run();
        } catch (Throwable $exception) {
            return $exception;
        }

        static::fail('The service did not throw');
    }
}
