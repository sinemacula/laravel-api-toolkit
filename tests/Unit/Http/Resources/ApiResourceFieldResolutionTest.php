<?php

namespace Tests\Unit\Http\Resources;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use Tests\Fixtures\Record;
use Tests\Fixtures\RecordResource;

/**
 * API resource field resolution tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
class ApiResourceFieldResolutionTest extends TestCase
{
    /** @var \Tests\Fixtures\Record */
    private Record $record;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();

        $container->instance('config', new Repository([
            'api-toolkit' => [
                'parser'    => ['alias' => 'api.query'],
                'resources' => ['fixed_fields' => ['id', '_type']],
            ],
        ]));

        $container->instance('api.query', new class () {
            /**
             * Return the requested fields.
             *
             * @return array<int, string>|null
             */
            public function getFields(): ?array
            {
                return null;
            }

            /**
             * Return the requested counts.
             *
             * @return array<int, string>|null
             */
            public function getCounts(): ?array
            {
                return null;
            }
        });

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->record = (new Record())->setRawAttributes([
            'id'     => 7,
            'name'   => 'Example',
            'secret' => 'hidden-value',
        ]);
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Test that a requested attribute missing from the schema is not returned.
     *
     * @return void
     */
    #[Test]
    public function requestedFieldOutsideSchemaIsNotReturned(): void
    {
        $resource = new RecordResource($this->record, false, ['name', 'secret']);
        $data     = $resource->resolve();

        static::assertSame('Example', $data['name']);
        static::assertArrayNotHasKey('secret', $data);
    }

    /**
     * Test that fixed fields are returned even when missing from the schema.
     *
     * @return void
     */
    #[Test]
    public function fixedFieldOutsideSchemaIsReturned(): void
    {
        $data = (new RecordResource($this->record))->resolve();

        static::assertSame(7, $data['id']);
        static::assertSame('record', $data['_type']);
        static::assertSame('Example', $data['name']);
        static::assertArrayNotHasKey('secret', $data);
    }

    /**
     * Test that requesting all fields only returns schema and fixed fields.
     *
     * @return void
     */
    #[Test]
    public function allFieldsOnlyReturnsSchemaAndFixedFields(): void
    {
        $data = (new RecordResource($this->record, false, ':all'))->resolve();

        static::assertEqualsCanonicalizing(
            ['_type', 'id', 'name'],
            array_keys($data),
        );
    }
}
