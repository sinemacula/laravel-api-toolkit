<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Service;
use stdClass;
use Tests\Fixtures\PayloadService;

/**
 * Service payload tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
class ServicePayloadTest extends TestCase
{
    /**
     * An array payload is wrapped in a collection.
     *
     * @return void
     */
    #[Test]
    public function arrayPayloadIsWrappedInACollection(): void
    {
        $payload = $this->payloadOf(['name' => 'Widget']);

        static::assertInstanceOf(Collection::class, $payload);
        static::assertSame(['name' => 'Widget'], $payload->all());
    }

    /**
     * An omitted payload becomes an empty collection.
     *
     * @return void
     */
    #[Test]
    public function omittedPayloadIsAnEmptyCollection(): void
    {
        $payload = (new PayloadService())->payload();

        static::assertInstanceOf(Collection::class, $payload);
        static::assertSame([], $payload->all());
    }

    /**
     * A collection payload is kept as the same instance.
     *
     * @return void
     */
    #[Test]
    public function collectionPayloadIsKept(): void
    {
        $collection = new Collection(['name' => 'Widget']);

        static::assertSame($collection, $this->payloadOf($collection));
    }

    /**
     * An object payload is kept as the same instance.
     *
     * @return void
     */
    #[Test]
    public function objectPayloadIsKept(): void
    {
        $object       = new stdClass();
        $object->name = 'Widget';

        static::assertSame($object, $this->payloadOf($object));
    }

    /**
     * Build a service with the given payload and return the payload it holds.
     *
     * @param  array<array-key, mixed>|\Illuminate\Support\Collection<array-key, mixed>|\stdClass  $payload
     * @return mixed
     */
    private function payloadOf(array|Collection|stdClass $payload): mixed
    {
        return (new PayloadService($payload))->payload();
    }
}
