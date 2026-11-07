<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\Traits\HasServiceLifecycle;
use Tests\Fixtures\PayloadService;
use Tests\Fixtures\Services\NamesakeTraitService;
use Tests\Fixtures\Services\OptionalDependencyService;
use Tests\Fixtures\Services\OverridingService;
use Tests\Fixtures\Services\RecordingService;

/**
 * Service run tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversTrait(HasServiceLifecycle::class)]
class ServiceRunTest extends ServiceTestCase
{
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
     * A handler's optional parameters keep their defaults rather than being
     * resolved from the container.
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
     * A service that marks its overridden hooks with the override attribute
     * loads and runs each of them.
     *
     * @return void
     */
    #[Test]
    public function overrideAttributedHooksRun(): void
    {
        $service = (new OverridingService())->useLock();

        static::assertTrue($service->run());
        static::assertSame(['prepare', 'handle', 'success'], $service->ran);
    }

    /**
     * The prepare hook can be called directly and can call its parent.
     *
     * @return void
     */
    #[Test]
    public function prepareCanBeCalledDirectly(): void
    {
        (new RecordingService(['log' => $this->log]))->prepare();

        static::assertSame(['prepare'], $this->log->entries);
    }

    /**
     * The prepare hook still exists on a service that does not override it.
     *
     * @return void
     */
    #[Test]
    public function inheritedPrepareDoesNothing(): void
    {
        $service = new PayloadService();

        $service->prepare();

        static::assertTrue(method_exists($service, 'prepare'));
        static::assertTrue($service->run());
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
        $service   = new RecordingService(['log' => $this->log, 'prepare_exception' => $exception]);

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame(['prepare', 'failed:not ready'], $this->log->entries);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * A service trait's initializer and success callback each run once, even
     * when the trait shares its name with the toolkit's own lifecycle trait.
     *
     * @return void
     */
    #[Test]
    public function serviceTraitCallbacksRunOnce(): void
    {
        NamesakeTraitService::$initializations = 0;

        $service = new NamesakeTraitService();

        static::assertTrue($service->run());
        static::assertSame(1, NamesakeTraitService::$initializations);
        static::assertSame(1, $service->successes);
    }
}
