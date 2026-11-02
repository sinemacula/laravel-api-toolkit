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
     * Requests carrying the trashed options in each supported position.
     *
     * @return iterable<string, array{\Illuminate\Http\Request, bool, bool}>
     */
    public static function trashedRequests(): iterable
    {
        yield from [
            'absent'               => [RequestWithoutGet::create('/'), false, false],
            'include true'         => [RequestWithoutGet::create('/?include_trashed=true'), true, false],
            'include false'        => [RequestWithoutGet::create('/?include_trashed=false'), false, false],
            'include other truthy' => [RequestWithoutGet::create('/?include_trashed=1'), false, false],
            'only true'            => [RequestWithoutGet::create('/?only_trashed=true'), false, true],
            'only false'           => [RequestWithoutGet::create('/?only_trashed=false'), false, false],
            'only other truthy'    => [RequestWithoutGet::create('/?only_trashed=1'), false, false],
            'both true'            => [RequestWithoutGet::create('/?include_trashed=true&only_trashed=true'), true, true],
            'form include true'    => [RequestWithoutGet::create('/', 'POST', ['include_trashed' => 'true']), true, false],
            'form only true'       => [RequestWithoutGet::create('/', 'POST', ['only_trashed' => 'true']), false, true],
            'json include true'    => [self::jsonRequest('{"include_trashed":"true"}'), true, false],
            'json only true'       => [self::jsonRequest('{"only_trashed":"true"}'), false, true],
            'json include boolean' => [self::jsonRequest('{"include_trashed":true}'), false, false],
            'json only boolean'    => [self::jsonRequest('{"only_trashed":true}'), false, false],
        ];
    }

    /**
     * Only the literal string "true" switches a trashed macro on.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $include_trashed
     * @param  bool  $only_trashed
     * @return void
     */
    #[DataProvider('trashedRequests')]
    #[Test]
    public function trashedMacrosReadTheRequestInput(Request $request, bool $include_trashed, bool $only_trashed): void
    {
        static::assertSame($include_trashed, $request->includeTrashed());
        static::assertSame($only_trashed, $request->onlyTrashed());
    }

    /**
     * Build a JSON request the way Laravel does, so the body lands in the
     * request input bag.
     *
     * @param  string  $content
     * @return \Illuminate\Http\Request
     */
    private static function jsonRequest(string $content): Request
    {
        return RequestWithoutGet::createFromBase(
            RequestWithoutGet::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $content),
        );
    }
}
