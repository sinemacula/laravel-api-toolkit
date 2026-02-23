<?php

namespace Tests\Unit\Facades;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use Tests\TestCase;

/**
 * Tests for the ApiQuery facade.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQuery::class)]
class ApiQueryTest extends TestCase
{
    /**
     * Test that the facade accessor returns the config-based alias.
     *
     * @return void
     */
    public function testFacadeAccessorReturnsConfigAlias(): void
    {
        $reflection = new \ReflectionMethod(ApiQuery::class, 'getFacadeAccessor');
        $accessor   = $reflection->invoke(null);

        static::assertSame('api.query', $accessor);
    }

    /**
     * Test that the facade proxies parse method calls.
     *
     * @return void
     */
    public function testFacadeProxiesParseMethodCalls(): void
    {
        $request = Request::create('/test', 'GET');

        ApiQuery::shouldReceive('parse')
            ->once()
            ->with($request);

        ApiQuery::parse($request);
    }

    /**
     * Test that the facade proxies getFields method calls.
     *
     * @return void
     */
    public function testFacadeProxiesGetFieldsCalls(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->once()
            ->andReturn(null);

        $result = ApiQuery::getFields();

        static::assertNull($result);
    }

    /**
     * Test that the facade proxies getPage method calls.
     *
     * @return void
     */
    public function testFacadeProxiesGetPageCalls(): void
    {
        ApiQuery::shouldReceive('getPage')
            ->once()
            ->andReturn(3);

        $result = ApiQuery::getPage();

        static::assertSame(3, $result);
    }

    /**
     * Test that the facade proxies getFilters method calls.
     *
     * @return void
     */
    public function testFacadeProxiesGetFiltersCalls(): void
    {
        $filters = ['status' => 'active'];

        ApiQuery::shouldReceive('getFilters')
            ->once()
            ->andReturn($filters);

        $result = ApiQuery::getFilters();

        static::assertSame($filters, $result);
    }
}
