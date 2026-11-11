<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ServerListValidator;

/**
 * Tests for the ServerListValidator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServerListValidator::class)]
final class ServerListValidatorTest extends TestCase
{
    /**
     * Test that a null value configures no servers.
     *
     * @return void
     */
    public function testNullConfiguresNoServers(): void
    {
        self::assertSame([], (new ServerListValidator)->validate(null));
    }

    /**
     * Test that an empty list configures no servers.
     *
     * @return void
     */
    public function testEmptyListConfiguresNoServers(): void
    {
        self::assertSame([], (new ServerListValidator)->validate([], 'public'));
    }

    /**
     * Test that valid servers pass through in order, intact.
     *
     * @return void
     */
    public function testValidServersPassThroughInOrder(): void
    {
        $servers = [
            ['url' => 'https://api.example.com', 'description' => 'Production', 'x-environment' => 'production'],
            [
                'url'       => 'https://{region}.api.example.com/{version}',
                'variables' => [
                    'region' => [
                        'default'     => 'eu',
                        'enum'        => ['eu', 'us'],
                        'description' => 'The hosting region.',
                        'x-internal'  => false,
                    ],
                    'version' => ['default' => 'v1'],
                ],
            ],
            ['url' => '/'],
        ];

        self::assertSame($servers, (new ServerListValidator)->validate($servers));
    }

    /**
     * Test that an empty variables map is exported as an object.
     *
     * @return void
     */
    public function testEmptyVariablesAreExportedAsAnObject(): void
    {
        $servers = (new ServerListValidator)->validate([
            ['url' => 'https://api.example.com', 'description' => 'Production', 'variables' => []],
        ]);

        self::assertCount(1, $servers);
        self::assertSame('https://api.example.com', $servers[0]['url']);
        self::assertSame('Production', $servers[0]['description']);
        self::assertEquals((object) [], $servers[0]['variables']);
        self::assertIsObject($servers[0]['variables']);
    }

    /**
     * Provide servers values that are not lists.
     *
     * @return iterable<string, array{mixed, string|null, string}>
     */
    public static function invalidListProvider(): iterable
    {
        yield 'a string, globally' => [
            'https://api.example.com',
            null,
            'Unable to export the global OpenAPI servers: they must be a list of server objects.',
        ];

        yield 'a keyed array, for an audience' => [
            ['production' => ['url' => 'https://api.example.com']],
            'partner',
            'Unable to export the OpenAPI servers of audience [partner]: they must be a list of server objects.',
        ];
    }

    /**
     * Test that a value that is not a list of servers fails, naming the list.
     *
     * @param  mixed  $servers
     * @param  string|null  $audience
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidListProvider')]
    public function testNonListFailsNamingTheList(mixed $servers, ?string $audience, string $message): void
    {
        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ServerListValidator)->validate($servers, $audience);
    }

    /**
     * Provide invalid server entries and the problem each is reported with.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidServerProvider(): iterable
    {
        $url        = 'https://{region}.api.example.com';
        $urlMessage = 'must declare a non-empty string url';
        $variables  = 'must declare its variables as a map of names to server variables, each with a string default, listed in its enum when it has one';

        yield 'not an array' => ['https://api.example.com', 'must be an array'];
        yield 'missing url' => [['description' => 'Production'], $urlMessage];
        yield 'non-string url' => [['url' => 42], $urlMessage];
        yield 'empty url' => [['url' => ''], $urlMessage];
        yield 'non-string description' => [['url' => $url, 'description' => ['Production']], 'must declare its description as a string'];
        yield 'null description' => [['url' => $url, 'description' => null], 'must declare its description as a string'];
        yield 'non-array variables' => [['url' => $url, 'variables' => 'region'], $variables];
        yield 'null variables' => [['url' => $url, 'variables' => null], $variables];
        yield 'listed variables' => [['url' => $url, 'variables' => [['default' => 'eu']]], $variables];
        yield 'non-array variable' => [['url' => $url, 'variables' => ['region' => 'eu']], $variables];
        yield 'variable without a default' => [['url' => $url, 'variables' => ['region' => ['enum' => ['eu']]]], $variables];
        yield 'non-string default' => [['url' => $url, 'variables' => ['region' => ['default' => 1]]], $variables];
        yield 'non-array enum' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu', 'enum' => 'eu']]], $variables];
        yield 'empty enum' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu', 'enum' => []]]], $variables];
        yield 'keyed enum' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu', 'enum' => ['a' => 'eu']]]], $variables];
        yield 'non-string enum value' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu', 'enum' => ['eu', 1]]]], $variables];
        yield 'default outside the enum' => [['url' => $url, 'variables' => ['region' => ['default' => 'ap', 'enum' => ['eu', 'us']]]], $variables];
        yield 'default only loosely in the enum' => [['url' => $url, 'variables' => ['region' => ['default' => '1', 'enum' => ['1.0']]]], $variables];
        yield 'non-string variable description' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu', 'description' => 1]]], $variables];
        yield 'unsupported variable key' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu', 'example' => 'us']]], $variables];
        yield 'second variable invalid' => [['url' => $url, 'variables' => ['region' => ['default' => 'eu'], 'version' => []]], $variables];
        yield 'invalid field before unsupported key' => [['url' => $url, 'name' => 'Production', 'description' => 1], 'must declare its description as a string'];
        yield 'unsupported key' => [['url' => $url, 'name' => 'Production'], 'declares the unsupported key [name]'];
        yield 'integer key' => [['url' => $url, 0 => 'Production'], 'declares the unsupported key [0]'];
    }

    /**
     * Test that an invalid entry fails, naming the entry, list and problem.
     *
     * @param  mixed  $server
     * @param  string  $problem
     * @return void
     */
    #[DataProvider('invalidServerProvider')]
    public function testInvalidServerFailsNamingTheEntry(mixed $server, string $problem): void
    {
        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage('Unable to export the OpenAPI servers of audience [partner]: server [1] ' . $problem . '.');

        (new ServerListValidator)->validate([['url' => 'https://api.example.com'], $server], 'partner');
    }

    /**
     * Test that an invalid entry in the global list is named as global.
     *
     * @return void
     */
    public function testInvalidGlobalServerIsNamedAsGlobal(): void
    {
        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage('Unable to export the global OpenAPI servers: server [0] must declare a non-empty string url.');

        (new ServerListValidator)->validate([['description' => 'Production']]);
    }
}
