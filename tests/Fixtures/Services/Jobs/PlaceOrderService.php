<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\Input\OrderPayload;

/**
 * Fixture lockable service that records the input, context and live actor of
 * its run, and fails it when told to.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\Tests\Fixtures\Services\Input\OrderPayload, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PlaceOrderService extends Service
{
    /** @var bool Whether the next run fails */
    public static bool $fails = false;

    /** @var \Tests\Fixtures\Services\Input\OrderPayload|null The input the last run read */
    public static ?OrderPayload $order = null;

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context the last run read */
    public static ?ServiceContext $context = null;

    /** @var string|null The name of the actor's model as the last run resolved it */
    public static ?string $actorName = null;

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = true;

    /**
     * Reset the recorded run between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$fails     = false;
        self::$order     = null;
        self::$context   = null;
        self::$actorName = null;
    }

    /**
     * Record the run, then complete or fail it.
     *
     * @return null
     *
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    protected function handle(): null
    {
        $user = $this->actor()->toAuthenticatable();

        self::$order     = $this->input;
        self::$context   = $this->context();
        self::$actorName = $user instanceof ActorUser ? $user->name : null;

        if (self::$fails) {
            throw new ServiceExecutionException('The order could not be placed.');
        }

        return null;
    }

    /**
     * Name the lock after the order reference.
     *
     * @return string
     */
    #[\Override]
    protected function lockId(): string
    {
        return $this->input->reference;
    }
}
