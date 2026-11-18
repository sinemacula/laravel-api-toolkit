<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\AudiencePosture;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use Tests\TestCase;

/**
 * Tests for the audience configuration reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AudienceConfiguration::class)]
final class AudienceConfigurationTest extends TestCase
{
    /**
     * Test that audience names are returned in declaration order.
     *
     * @return void
     */
    public function testReturnsConfiguredNamesInOrder(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'partner'  => ['posture' => 'allowlist'],
            'internal' => [],
        ]);

        self::assertSame(['public', 'partner', 'internal'], (new AudienceConfiguration)->names());
    }

    /**
     * Test that the exported audiences are the configured ones, then the
     * default and the assembled audience, each once.
     *
     * @return void
     */
    public function testExportedAudiencesAddTheDefaultAndTheAssembledAudience(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', 'public');
        $this->config()->set('api-toolkit.openapi.audiences', ['internal' => [], 'partner' => []]);

        $configuration = new AudienceConfiguration;

        self::assertSame(['internal', 'partner', 'public'], $configuration->exportedAudiences('partner'));
        self::assertSame(['internal', 'partner', 'public', 'staff'], $configuration->exportedAudiences('staff'));

        $this->config()->set('api-toolkit.openapi.audiences', ['public' => [], 'internal' => []]);

        self::assertSame(['public', 'internal'], $configuration->exportedAudiences('public'));
        self::assertSame(['public', 'internal', 'staff'], $configuration->exportedAudiences('staff'));
    }

    /**
     * Test that membership is reported for configured and unconfigured names.
     *
     * @return void
     */
    public function testReportsMembership(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        $configuration = new AudienceConfiguration;

        self::assertTrue($configuration->has('public'));
        self::assertFalse($configuration->has('partner'));
    }

    /**
     * Test that the default audience is read from config.
     *
     * @return void
     */
    public function testResolvesConfiguredDefaultAudience(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', 'internal');

        self::assertSame('internal', (new AudienceConfiguration)->defaultAudience());
    }

    /**
     * Test that an unset default audience fails rather than assuming public.
     *
     * @return void
     */
    public function testUnsetDefaultAudienceFails(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.openapi.default_audience setting must be a non-empty string; [null] given.');

        (new AudienceConfiguration)->defaultAudience();
    }

    /**
     * Test that an explicit posture is returned for the named audience.
     *
     * @return void
     */
    public function testReturnsExplicitPosture(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', [
            'partner' => ['posture' => AudiencePosture::ALLOWLIST->value],
        ]);

        self::assertSame(AudiencePosture::ALLOWLIST, (new AudienceConfiguration)->postureFor('partner'));
    }

    /**
     * Test that a missing or unconfigured posture defaults to blocklist.
     *
     * @return void
     */
    public function testPostureDefaultsToBlocklist(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        $configuration = new AudienceConfiguration;

        self::assertSame(AudiencePosture::BLOCKLIST, $configuration->postureFor('public'));
        self::assertSame(AudiencePosture::BLOCKLIST, $configuration->postureFor('unconfigured'));
    }

    /**
     * Test that a misspelt posture fails rather than documenting every route.
     *
     * @return void
     */
    public function testMisspeltPostureFails(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['partner' => ['posture' => 'allow_list']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.openapi.audiences.partner.posture setting must be one of allowlist, blocklist; [\'allow_list\'] given.');

        (new AudienceConfiguration)->postureFor('partner');
    }

    /**
     * Test that a per-audience info block wins key by key.
     *
     * @return void
     */
    public function testInfoPrefersPerAudienceOverride(): void
    {
        $this->config()->set('api-toolkit.openapi.info', [
            'title'   => 'Top Level',
            'version' => '9.9.9',
        ]);
        $this->config()->set('api-toolkit.openapi.audiences', [
            'internal' => [
                'info' => [
                    'title'       => 'Internal API',
                    'description' => 'For staff only.',
                ],
            ],
        ]);

        self::assertSame([
            'title'       => 'Internal API',
            'version'     => '9.9.9',
            'description' => 'For staff only.',
        ], (new AudienceConfiguration)->infoFor('internal'));
    }

    /**
     * Test that keys absent per audience fall back to the top-level info.
     *
     * @return void
     */
    public function testInfoFallsBackToTopLevelDefault(): void
    {
        $this->config()->set('api-toolkit.openapi.info', [
            'title'       => 'Acme API',
            'version'     => '2.0.0',
            'description' => 'Shared description.',
        ]);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        self::assertSame([
            'title'       => 'Acme API',
            'version'     => '2.0.0',
            'description' => 'Shared description.',
        ], (new AudienceConfiguration)->infoFor('public'));
    }

    /**
     * Test that title and version fall back to hard defaults.
     *
     * @return void
     */
    public function testInfoFallsBackToHardDefaults(): void
    {
        $this->config()->set('api-toolkit.openapi.info', []);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        self::assertSame([
            'title'   => 'API Components',
            'version' => '1.0.0',
        ], (new AudienceConfiguration)->infoFor('public'));
    }

    /**
     * Provide malformed info settings and the message each fails with.
     *
     * @return iterable<string, array{0: mixed, 1: mixed, 2: string}>
     */
    public static function malformedInfoProvider(): iterable
    {
        yield 'top-level info not an array' => ['not-an-array', [], 'The api-toolkit.openapi.info setting must be an array; [\'not-an-array\'] given.'];
        yield 'empty title' => [[], ['title' => ''], 'The api-toolkit.openapi.audiences.public.info.title setting must be a non-empty string; [\'\'] given.'];
        yield 'nested version' => [[], ['version' => ['nested']], 'The api-toolkit.openapi.audiences.public.info.version setting must be a non-empty string; [array] given.'];
        yield 'unknown key' => [[], ['contact' => 'ops'], 'The api-toolkit.openapi.audiences.public.info.contact setting is not a toolkit setting; remove it or correct its name.'];
    }

    /**
     * Test that a malformed info block fails rather than falling back.
     *
     * @param  mixed  $info
     * @param  mixed  $audienceInfo
     * @param  string  $message
     * @return void
     */
    #[DataProvider('malformedInfoProvider')]
    public function testMalformedInfoFails(mixed $info, mixed $audienceInfo, string $message): void
    {
        $this->config()->set('api-toolkit.openapi.info', $info);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => ['info' => $audienceInfo]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new AudienceConfiguration)->infoFor('public');
    }

    /**
     * Test that an audience without servers inherits the global list.
     *
     * @return void
     */
    public function testServersInheritTheGlobalList(): void
    {
        $servers = [
            ['url' => 'https://api.example.com', 'description' => 'Production'],
            ['url' => 'https://api.staging.example.com', 'description' => 'Staging'],
        ];

        $this->config()->set('api-toolkit.openapi.servers', $servers);
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'  => [],
            'partner' => ['servers' => null],
        ]);

        $configuration = new AudienceConfiguration;

        self::assertSame($servers, $configuration->serversFor('public'));
        self::assertSame($servers, $configuration->serversFor('partner'));
        self::assertSame($servers, $configuration->serversFor('unconfigured'));
    }

    /**
     * Test that an audience's own servers replace the global list.
     *
     * @return void
     */
    public function testAudienceServersReplaceTheGlobalList(): void
    {
        $global = [['url' => 'https://api.example.com'], ['url' => 'https://internal.example.com']];
        $public = [['url' => 'https://api.example.com', 'description' => 'Public']];

        $this->config()->set('api-toolkit.openapi.servers', $global);
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'   => ['servers' => $public],
            'internal' => [],
        ]);

        $configuration = new AudienceConfiguration;

        self::assertSame($public, $configuration->serversFor('public'));
        self::assertSame($global, $configuration->serversFor('internal'));
    }

    /**
     * Test that an empty audience servers list exports none.
     *
     * @return void
     */
    public function testAudienceEmptyServersOverrideTheGlobalList(): void
    {
        $this->config()->set('api-toolkit.openapi.servers', [['url' => 'https://api.example.com']]);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => ['servers' => []]]);

        self::assertSame([], (new AudienceConfiguration)->serversFor('public'));
    }

    /**
     * Test that no servers resolve none.
     *
     * @return void
     */
    public function testServersDefaultToNone(): void
    {
        $this->config()->set('api-toolkit.openapi.servers', []);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        self::assertSame([], (new AudienceConfiguration)->serversFor('public'));
    }

    /**
     * Test that an audience that is not an array fails rather than inheriting.
     *
     * @return void
     */
    public function testMalformedAudienceFails(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => 'not-an-array']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.openapi.audiences.public setting must be an array; [\'not-an-array\'] given.');

        (new AudienceConfiguration)->serversFor('public');
    }

    /**
     * Test that an invalid global server entry fails, naming it.
     *
     * @return void
     */
    public function testInvalidGlobalServerFailsNamingGlobal(): void
    {
        $this->config()->set('api-toolkit.openapi.servers', [['url' => 'https://api.example.com'], ['url' => 42]]);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage('Unable to export the global OpenAPI servers: server [1] must declare a non-empty string url.');

        (new AudienceConfiguration)->serversFor('public');
    }

    /**
     * Test that an invalid audience server entry fails, naming it.
     *
     * @return void
     */
    public function testInvalidAudienceServerFailsNamingTheAudience(): void
    {
        $this->config()->set('api-toolkit.openapi.servers', [['url' => 'https://api.example.com']]);
        $this->config()->set('api-toolkit.openapi.audiences', ['partner' => ['servers' => [['description' => 'Partner']]]]);

        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage('Unable to export the OpenAPI servers of audience [partner]: server [0] must declare a non-empty string url.');

        (new AudienceConfiguration)->serversFor('partner');
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }
}
