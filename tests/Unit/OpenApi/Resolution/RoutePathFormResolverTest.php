<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathFormResolver;

/**
 * Tests for the route path form resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RoutePathFormResolver::class)]
final class RoutePathFormResolverTest extends TestCase
{
    /**
     * Provide each route URI with the path forms it matches, shortest first,
     * each as its path, parameters, included optional parameters and fullness.
     *
     * @return iterable<string, array{string, list<array{string, list<string>, list<string>, bool}>}>
     */
    public static function provideForms(): iterable
    {
        yield 'no parameters' => ['reports', [['/reports', [], [], true]]];

        yield 'required parameter' => ['reports/{period}', [['/reports/{period}', ['period'], [], true]]];

        yield 'one optional parameter' => ['reports/{period?}', [
            ['/reports', [], [], false],
            ['/reports/{period}', ['period'], ['period'], true],
        ]];

        yield 'two optional parameters' => ['reports/{a?}/{b?}', [
            ['/reports', [], [], false],
            ['/reports/{a}', ['a'], ['a'], false],
            ['/reports/{a}/{b}', ['a', 'b'], ['a', 'b'], true],
        ]];

        yield 'optional root parameter' => ['{page?}', [
            ['/', [], [], false],
            ['/{page}', ['page'], ['page'], true],
        ]];

        yield 'dot separator' => ['files/{name}.{ext?}', [
            ['/files/{name}', ['name'], [], false],
            ['/files/{name}.{ext}', ['name', 'ext'], ['ext'], true],
        ]];

        yield 'hyphen separator' => ['reports-{period?}', [
            ['/reports', [], [], false],
            ['/reports-{period}', ['period'], ['period'], true],
        ]];

        yield 'no separator' => ['reports{period?}', [
            ['/reports', [], [], false],
            ['/reports{period}', ['period'], ['period'], true],
        ]];

        yield 'optional parameter ahead of text' => ['{locale?}/reports', [['/{locale}/reports', ['locale'], [], true]]];

        yield 'optional parameter ahead of the trailing run' => ['{a?}/x/{b?}', [
            ['/{a}/x', ['a'], [], false],
            ['/{a}/x/{b}', ['a', 'b'], ['b'], true],
        ]];

        yield 'binding field' => ['posts/{post:slug?}', [
            ['/posts', [], [], false],
            ['/posts/{post}', ['post'], ['post'], true],
        ]];
    }

    /**
     * Test that a route resolves to one path form per URL shape it matches,
     * shortest first.
     *
     * @param  string  $uri
     * @param  list<array{string, list<string>, list<string>, bool}>  $expected
     * @return void
     */
    #[DataProvider('provideForms')]
    public function testResolvesOneFormPerUrlShape(string $uri, array $expected): void
    {
        self::assertSame($expected, $this->formsOf(new Route(['GET'], $uri, static fn (): null => null)));
    }

    /**
     * Test that a domain's parameters are not path parameters of any form.
     *
     * @return void
     */
    public function testDomainParametersAreNotPathParameters(): void
    {
        $route = (new Route(['GET'], 'reports/{period?}', static fn (): null => null))->domain('{account}.example.com');

        self::assertSame([
            ['/reports', [], [], false],
            ['/reports/{period}', ['period'], ['period'], true],
        ], $this->formsOf($route));
    }

    /**
     * Resolve the route's forms, each as its path, parameters, included
     * optional parameters and fullness.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<array{string, list<string>, list<string>, bool}>
     */
    private function formsOf(Route $route): array
    {
        return array_map(
            static fn (RoutePathForm $form): array => [$form->path, $form->parameters, $form->optional, $form->full],
            (new RoutePathFormResolver)->resolve($route),
        );
    }
}
