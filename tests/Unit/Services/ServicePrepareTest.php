<?php

namespace Tests\Unit\Services;

use Error;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\PayloadService;
use Tests\Fixtures\Services\InjectedPreparationService;
use Tests\Fixtures\Services\PrivatePreparationService;
use Tests\Fixtures\Services\RecordingService;
use Tests\Fixtures\Services\RepositoryAwareService;

/**
 * Service prepare hook tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
class ServicePrepareTest extends ServiceTestCase
{
    /**
     * Prepare hook parameters are resolved from the container, and the hook
     * runs before the transaction opens.
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
        $service   = new RecordingService(['log' => $this->log, 'prepare_exception' => $exception]);

        static::assertSame($exception, $this->runExpectingFailure($service));
        static::assertSame(['prepare', 'failed:not ready'], $this->log->entries);
        static::assertSame([], $this->db->attempts);
    }

    /**
     * An overridden prepare hook can still be called directly and can still
     * call its parent.
     *
     * @return void
     */
    #[Test]
    public function overriddenPrepareCanBeCalledDirectly(): void
    {
        (new RecordingService(['log' => $this->log]))->prepare();

        static::assertSame(['prepare'], $this->log->entries);
    }

    /**
     * Calling prepare() on a service that does not define it does nothing,
     * including on a service with its own magic calls.
     *
     * @return void
     */
    #[Test]
    public function undefinedPrepareIsANoOp(): void
    {
        static::assertNull((new PayloadService())->prepare());
        static::assertNull((new RepositoryAwareService())->prepare());
    }

    /**
     * Calling any other undefined method fails as it would without the
     * prepare fallback.
     *
     * @return void
     */
    #[Test]
    public function undefinedMethodStillFails(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to undefined method ' . PayloadService::class . '::missing()');

        (new PayloadService())->missing();
    }

    /**
     * A private prepare hook the base class cannot call is rejected.
     *
     * @return void
     */
    #[Test]
    public function privatePrepareIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Service [' . PrivatePreparationService::class . '] must not define prepare() as private');

        (new PrivatePreparationService())->run();
    }
}
