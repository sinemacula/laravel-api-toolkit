<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SineMacula\ApiToolkit\ApiServiceProvider;
use Tests\Fixtures\RequestWithoutGet;

/**
 * API service provider trashed macro tests.
 *
 * @author      Michael Stivala <michaelstivala@me.com>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
class ApiServiceProviderTrashedMacrosTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;

        $container->instance('request', new Request);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $provider = new ApiServiceProvider($container);

        (new ReflectionMethod($provider, 'registerTrashedMacros'))->invoke($provider);
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Request::flushMacros();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Query strings for the trashed macros.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function trashedQueries(): iterable
    {
        yield from [
            'absent'               => ['/', false, false],
            'include true'         => ['/?include_trashed=true', true, false],
            'include false'        => ['/?include_trashed=false', false, false],
            'include other truthy' => ['/?include_trashed=1', false, false],
            'only true'            => ['/?only_trashed=true', false, true],
            'only false'           => ['/?only_trashed=false', false, false],
            'both true'            => ['/?include_trashed=true&only_trashed=true', true, true],
        ];
    }

    /**
     * Only the literal string "true" switches a trashed macro on.
     *
     * @param  string  $uri
     * @param  bool  $include_trashed
     * @param  bool  $only_trashed
     * @return void
     */
    #[DataProvider('trashedQueries')]
    #[Test]
    public function trashedMacrosReadTheQueryString(string $uri, bool $include_trashed, bool $only_trashed): void
    {
        $request = RequestWithoutGet::create($uri);

        static::assertSame($include_trashed, $request->includeTrashed());
        static::assertSame($only_trashed, $request->onlyTrashed());
    }
}
