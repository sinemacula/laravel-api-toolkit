<?php

namespace Tests\Unit\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Services\FakeDatabaseManager;
use Tests\Fixtures\Services\ServiceLog;
use Throwable;

/**
 * Base test case for running services against a logging database and an array
 * cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class ServiceTestCase extends TestCase
{
    /** @var \Tests\Fixtures\Services\ServiceLog The log shared by the services and the database */
    protected ServiceLog $log;

    /** @var \Tests\Fixtures\Services\FakeDatabaseManager The database the transactions run on */
    protected FakeDatabaseManager $db;

    /**
     * Bind a fresh container with a logging database, an array cache and an
     * empty config.
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
        $container->instance('config', new ConfigRepository());

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
     * Run the service and return the exception it throws.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @return \Throwable
     */
    protected function runExpectingFailure(Service $service): Throwable
    {
        try {
            $service->run();
        } catch (Throwable $exception) {
            return $exception;
        }

        static::fail('The service did not throw');
    }
}
