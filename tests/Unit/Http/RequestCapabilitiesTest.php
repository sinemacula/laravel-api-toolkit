<?php

declare(strict_types = 1);

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use Tests\TestCase;

/**
 * Unit tests for the RequestCapabilities value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestCapabilities::class)]
final class RequestCapabilitiesTest extends TestCase
{
    /** @var string */
    private const string TEST_URL = '/test';

    /**
     * Test that fromRequest returns the stored instance.
     *
     * @return void
     */
    public function testFromRequestReturnsStoredInstance(): void
    {
        $request      = Request::create(self::TEST_URL);
        $capabilities = $this->createCapabilities(includeTrashed: true);

        RequestCapabilities::storeOnRequest($request, $capabilities);

        $retrieved = RequestCapabilities::fromRequest($request);

        self::assertSame($capabilities, $retrieved);
        self::assertTrue($retrieved->includeTrashed());
    }

    /**
     * Test that fromRequest resolves lazily when no attribute is stored.
     *
     * @return void
     */
    public function testFromRequestResolvesLazilyWhenAttributeNotSet(): void
    {
        $request      = Request::create(self::TEST_URL);
        $capabilities = RequestCapabilities::fromRequest($request);

        self::assertFalse($capabilities->includeTrashed());
        self::assertFalse($capabilities->onlyTrashed());
    }

    /**
     * Test that fromRequest reflects the request state when resolving lazily,
     * and caches the resolved instance on the request.
     *
     * @return void
     */
    public function testFromRequestResolvesFromRequestStateAndCaches(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', ['include_trashed' => 'true']);

        $capabilities = RequestCapabilities::fromRequest($request);

        self::assertTrue($capabilities->includeTrashed());
        self::assertSame($capabilities, RequestCapabilities::fromRequest($request));
    }

    /**
     * Test that resolve detects include_trashed query parameter.
     *
     * @return void
     */
    public function testResolveDetectsIncludeTrashed(): void
    {
        $request      = Request::create(self::TEST_URL, 'GET', ['include_trashed' => 'true']);
        $capabilities = RequestCapabilities::resolve($request);

        self::assertTrue($capabilities->includeTrashed());
        self::assertFalse($capabilities->onlyTrashed());
    }

    /**
     * Test that resolve detects only_trashed query parameter.
     *
     * @return void
     */
    public function testResolveDetectsOnlyTrashed(): void
    {
        $request      = Request::create(self::TEST_URL, 'GET', ['only_trashed' => 'true']);
        $capabilities = RequestCapabilities::resolve($request);

        self::assertTrue($capabilities->onlyTrashed());
        self::assertFalse($capabilities->includeTrashed());
    }

    /**
     * Test that storeOnRequest sets the attribute on the request.
     *
     * @return void
     */
    public function testStoreOnRequestSetsAttribute(): void
    {
        $request      = Request::create(self::TEST_URL);
        $capabilities = $this->createCapabilities(includeTrashed: true);

        RequestCapabilities::storeOnRequest($request, $capabilities);

        $stored = $request->attributes->get(RequestCapabilities::class);

        self::assertInstanceOf(RequestCapabilities::class, $stored);
        self::assertTrue($stored->includeTrashed());
    }

    /**
     * Test that all 2 accessor methods return the correct corresponding values.
     *
     * @return void
     */
    public function testAllAccessorsReturnCorrectValues(): void
    {
        $capabilities = $this->createCapabilities(
            includeTrashed: true,
            onlyTrashed: true,
        );

        self::assertTrue($capabilities->includeTrashed());
        self::assertTrue($capabilities->onlyTrashed());
    }

    /**
     * Create a RequestCapabilities instance via reflection for testing.
     *
     * @param  bool  $includeTrashed
     * @param  bool  $onlyTrashed
     * @return \SineMacula\ApiToolkit\Http\RequestCapabilities
     */
    private function createCapabilities(bool $includeTrashed = false, bool $onlyTrashed = false): RequestCapabilities
    {

        $reflection  = new \ReflectionClass(RequestCapabilities::class);
        $constructor = $reflection->getConstructor();

        assert($constructor !== null);

        $constructor->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();

        $constructor->invoke(
            $instance,
            $includeTrashed,
            $onlyTrashed,
        );

        return $instance;
    }
}
