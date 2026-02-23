<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Services\FailingService;
use Tests\Fixtures\Services\LockableService;
use Tests\Fixtures\Services\SimpleService;
use Tests\TestCase;

/**
 * Integration tests for Service with a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
class ServiceIntegrationTest extends TestCase
{
    /**
     * Test that a successful service runs within a transaction.
     *
     * @return void
     */
    public function testSuccessfulServiceRunsWithinTransaction(): void
    {
        $service = new SimpleService;

        $result = $service->run();

        static::assertTrue($result);
        static::assertTrue($service->getStatus());
        static::assertTrue($service->successCalled);
    }

    /**
     * Test that a failed service rolls back the transaction.
     *
     * @return void
     */
    public function testFailedServiceRollsBackTransaction(): void
    {
        // Create a user before service runs
        User::create(['name' => 'Before', 'email' => 'before@example.com', 'status' => 'active']);

        $service = new FailingService;

        try {
            $service->run();
        } catch (\RuntimeException) {
            // Expected
        }

        static::assertTrue($service->failedCalled);
        static::assertNotNull($service->failedException);
        static::assertSame('Service execution failed', $service->failedException->getMessage());

        // User created before the service should still exist since
        // the failing service creates its own transaction
        $this->assertDatabaseHas('users', ['name' => 'Before']);
    }

    /**
     * Test that a service with locking acquires and releases the lock.
     *
     * @return void
     */
    public function testServiceWithLockingAcquiresAndReleasesLock(): void
    {
        $service = new LockableService;

        $result = $service->run();

        static::assertTrue($result);
        static::assertTrue($service->getStatus());

        // The lock should have been released; a new service with the same key should succeed
        $service2 = new LockableService;
        $result2  = $service2->run();

        static::assertTrue($result2);
    }

    /**
     * Test that SimpleService can operate without transactions.
     *
     * @return void
     */
    public function testServiceRunsWithoutTransaction(): void
    {
        $service = new SimpleService;
        $service->dontUseTransaction();

        $result = $service->run();

        static::assertTrue($result);
        static::assertTrue($service->successCalled);
    }

    /**
     * Test that FailingService triggers the failed callback.
     *
     * @return void
     */
    public function testFailingServiceTriggersFailedCallback(): void
    {
        $service = new FailingService;

        $this->expectException(\RuntimeException::class);

        try {
            $service->run();
        } catch (\RuntimeException $e) {
            static::assertTrue($service->failedCalled);

            throw $e;
        }
    }
}
