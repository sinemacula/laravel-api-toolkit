<?php

namespace Tests\Unit\Exceptions;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use Tests\Fixtures\RequestWithoutGet;

/**
 * API exception handler pretty option tests.
 *
 * @author      Michael Stivala <michaelstivala@me.com>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
class ApiExceptionHandlerPrettyTest extends TestCase
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

        $container->instance('config', new Repository(['app' => ['debug' => false]]));
        $container->instance('translator', new class {
            /**
             * Return the translation key untouched.
             *
             * @param  string  $key
             * @return string
             */
            public function get(string $key): string
            {
                return $key;
            }
        });
        $container->instance(ResponseFactoryContract::class, new ResponseFactory(
            static::createStub(ViewFactory::class),
            static::createStub(Redirector::class),
        ));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
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
     * Requests carrying the pretty option in each supported position.
     *
     * @return iterable<string, array{\Illuminate\Http\Request, bool}>
     */
    public static function prettyRequests(): iterable
    {
        yield from [
            'query string'       => [RequestWithoutGet::create('/?pretty=1'), true],
            'query string false' => [RequestWithoutGet::create('/?pretty=0'), false],
            'absent'             => [RequestWithoutGet::create('/'), false],
            'form body'          => [RequestWithoutGet::create('/', 'POST', ['pretty' => 1]), true],
            'json body'          => [RequestWithoutGet::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"pretty":true}'), true],
        ];
    }

    /**
     * The pretty option is read from the query string and the request body.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('prettyRequests')]
    #[Test]
    public function prettyOptionControlsTheJsonFormatting(Request $request, bool $expected): void
    {
        $method = new ReflectionMethod(ApiExceptionHandler::class, 'renderApiExceptionWithJson');

        /** @var JsonResponse $response */
        $response = $method->invoke(null, $request, new NotFoundException);

        static::assertSame($expected, (bool) ($response->getEncodingOptions() & JSON_PRETTY_PRINT));
        static::assertSame(404, $response->getStatusCode());
    }
}
