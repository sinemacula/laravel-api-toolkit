<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\DomainServerBuilder;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use Tests\Fixtures\OpenApi\PathPlainController;

/**
 * Tests for the domain server builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DomainServerBuilder::class)]
final class DomainServerBuilderTest extends TestCase
{
    /** The variable of the account domain's parameter */
    private const array ACCOUNT = ['account' => ['default' => 'account']];

    /**
     * Test that a domain route with no audience servers is served over https
     * from its domain alone.
     *
     * @return void
     */
    public function testWithoutServersTheDomainIsServedOverHttps(): void
    {
        self::assertSame(
            [['url' => 'https://{account}.example.com', 'variables' => self::ACCOUNT]],
            $this->build('{account?}.example.com', self::ACCOUNT, []),
        );
    }

    /**
     * Provide a server URL and the URL it becomes on the domain.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rehostedUrlProvider(): iterable
    {
        yield 'scheme and host' => ['https://api.example.com', 'https://{account}.example.com'];
        yield 'port and path' => ['http://localhost:8000/base', 'http://{account}.example.com:8000/base'];
        yield 'query and fragment' => ['https://api.example.com?v=1#top', 'https://{account}.example.com?v=1#top'];
        yield 'userinfo' => ['https://user@api.example.com/v1', 'https://user@{account}.example.com/v1'];
        yield 'bracketed host' => ['http://[::1]:8000', 'http://{account}.example.com:8000'];
        yield 'no scheme' => ['//api.example.com/v1', '//{account}.example.com/v1'];
        yield 'absolute path' => ['/base', 'https://{account}.example.com/base'];
        yield 'relative path' => ['v1', 'https://{account}.example.com'];
        yield 'path with an empty segment' => ['/base//v1', 'https://{account}.example.com/base//v1'];
    }

    /**
     * Test that each server keeps all but its host, which becomes the domain.
     *
     * @param  string  $url
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('rehostedUrlProvider')]
    public function testServerHostIsReplacedByTheDomain(string $url, string $expected): void
    {
        self::assertSame([['url' => $expected, 'variables' => self::ACCOUNT]], $this->build('{account}.example.com', self::ACCOUNT, [['url' => $url]]));
    }

    /**
     * Test that the variables the rest of the URL uses are kept before the
     * domain's, and the host's are dropped with it.
     *
     * @return void
     */
    public function testKeepsTheVariablesOutsideTheHost(): void
    {
        $server = [
            'url'         => '{scheme}://{region}.api.example.com:{port}',
            'description' => 'Regional',
            'variables'   => [
                'scheme' => ['enum' => ['https', 'http'], 'default' => 'https'],
                'region' => ['default' => 'eu'],
                'port'   => ['default' => '443'],
            ],
            'x-environment' => 'production',
        ];

        self::assertSame([[
            'url'         => '{scheme}://{account}.example.com:{port}',
            'description' => 'Regional',
            'variables'   => [
                'scheme'  => ['enum' => ['https', 'http'], 'default' => 'https'],
                'port'    => ['default' => '443'],
                'account' => ['default' => 'account'],
            ],
            'x-environment' => 'production',
        ]], $this->build('{account}.example.com', self::ACCOUNT, [$server]));
    }

    /**
     * Test that a domain without parameters exports no variables, whether the
     * server declared none or an empty map.
     *
     * @return void
     */
    public function testDomainWithoutParametersExportsNoVariables(): void
    {
        self::assertSame(
            [
                ['url' => 'https://admin.example.com'],
                ['url' => 'https://admin.example.com/v1', 'description' => 'Regional'],
                ['url' => 'https://admin.example.com/v1', 'description' => 'Staging'],
            ],
            $this->build('admin.example.com', [], [
                ['url' => 'https://api.example.com'],
                ['url' => 'https://{host}/v1', 'description' => 'Regional', 'variables' => ['host' => ['default' => 'eu.example.com']]],
                ['url' => 'https://api.example.com/v1', 'description' => 'Staging', 'variables' => (object) []],
            ]),
        );
    }

    /**
     * Test that servers differing only by their host are exported once.
     *
     * @return void
     */
    public function testIdenticalEntriesAreKeptOnce(): void
    {
        self::assertSame(
            [['url' => 'https://admin.example.com/v1'], ['url' => 'https://admin.example.com/v2']],
            $this->build('admin.example.com', [], [
                ['url' => 'https://api.example.com/v1'],
                ['url' => 'https://api.example.net/v1'],
                ['url' => 'https://api.example.com/v2'],
            ]),
        );
    }

    /**
     * Provide a route's scheme restrictions, a server URL and the URL it
     * becomes.
     *
     * @return iterable<string, array{0: list<string>, 1: string, 2: string}>
     */
    public static function schemeProvider(): iterable
    {
        yield 'http' => [['http'], 'https://api.example.com', 'http://admin.example.com'];
        yield 'https' => [['https'], 'http://localhost:8000', 'https://admin.example.com:8000'];
        yield 'http and https' => [['https', 'http'], 'https://api.example.com', 'http://admin.example.com'];
        yield 'templated scheme' => [['http'], '{scheme}://api.example.com', 'http://admin.example.com'];
        yield 'no scheme' => [['https'], '//api.example.com', 'https://admin.example.com'];
        yield 'no authority' => [['http'], '/v1', 'http://admin.example.com/v1'];
        yield 'no server' => [['http'], '', 'http://admin.example.com'];
    }

    /**
     * Test that a route restricted to http or https forces that scheme, http
     * first, dropping a scheme variable it replaces.
     *
     * @param  list<string>  $schemes
     * @param  string  $url
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('schemeProvider')]
    public function testRouteSchemeRestrictionIsForced(array $schemes, string $url, string $expected): void
    {
        $servers = $url === '' ? [] : [['url' => $url, 'variables' => ['scheme' => ['default' => 'https']]]];

        self::assertSame([['url' => $expected]], $this->build('admin.example.com', [], $servers, $schemes));
    }

    /**
     * Provide a server URL that uses the domain's parameter outside its host.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function conflictProvider(): iterable
    {
        yield 'path' => ['https://api.example.com/{account}'];
        yield 'port' => ['https://api.example.com:{account}'];
        yield 'scheme' => ['{account}://api.example.com'];
        yield 'userinfo' => ['https://{account}@api.example.com'];
        yield 'no authority' => ['/{account}'];
    }

    /**
     * Test that a domain parameter named like a variable the server uses
     * outside its host fails the build, naming the audience, server and route.
     *
     * @param  string  $url
     * @return void
     */
    #[DataProvider('conflictProvider')]
    public function testDomainParameterRebindingAServerVariableFails(string $url): void
    {
        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            'Unable to export audience [partner]: server [%s] uses the variable [account] outside its host,'
                . ' which the domain of [GET {account}.example.com/projects (%s::index)] binds.',
            $url,
            PathPlainController::class,
        ));

        $this->build('{account}.example.com', self::ACCOUNT, [['url' => $url, 'variables' => self::ACCOUNT]]);
    }

    /**
     * Test that a variable whose name only overlaps a domain parameter or
     * another variable used outside the host is no conflict, and a variable
     * used only in the host is dropped.
     *
     * @return void
     */
    public function testOnlyWholeVariableNamesCount(): void
    {
        $server = [
            'url'       => 'https://{sub}-{count}.api.example.com/{subaccount}/{accountId}',
            'variables' => [
                'sub'        => ['default' => 'eu'],
                'count'      => ['default' => '1'],
                'subaccount' => ['default' => 'main'],
                'accountId'  => ['default' => '42'],
            ],
        ];

        self::assertSame([[
            'url'       => 'https://{account}.example.com/{subaccount}/{accountId}',
            'variables' => ['subaccount' => ['default' => 'main'], 'accountId' => ['default' => '42'], 'account' => ['default' => 'account']],
        ]], $this->build('{account}.example.com', self::ACCOUNT, [$server]));
    }

    /**
     * Test that a server using the domain's parameter only in its host is
     * rehosted, its own variable replaced by the domain's.
     *
     * @return void
     */
    public function testServerVariableInTheReplacedHostIsNoConflict(): void
    {
        self::assertSame(
            [['url' => 'https://{account}.example.com', 'variables' => self::ACCOUNT]],
            $this->build('{account}.example.com', self::ACCOUNT, [['url' => 'https://{account}.api.example.com', 'variables' => ['account' => ['default' => 'acme']]]]),
        );
    }

    /**
     * Build the servers of a GET /projects route on the domain.
     *
     * @param  string  $domain
     * @param  array<string, array<string, mixed>>  $variables
     * @param  list<array<string, mixed>>  $servers
     * @param  list<string>  $schemes
     * @return list<array<string, mixed>>
     */
    private function build(string $domain, array $variables, array $servers, array $schemes = []): array
    {
        $route = new Route(['GET'], 'projects', [PathPlainController::class, 'index']);

        $route->setAction([...$route->action, ...$schemes])->domain($domain);

        return (new DomainServerBuilder)->build(
            new DocumentedRoute($route, PathPlainController::class, 'index', '/projects', ['get'], null, 'Project', [], null, false),
            $variables,
            $servers,
            'partner',
        );
    }
}
