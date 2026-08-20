<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
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
     * Test that the configured audience names are returned in declaration
     * order.
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
     * Test that the default audience falls back to public when unconfigured.
     *
     * @return void
     */
    public function testDefaultAudienceFallsBackToPublic(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', null);

        self::assertSame('public', (new AudienceConfiguration)->defaultAudience());
    }

    /**
     * Test that an explicit posture is returned for the named audience.
     *
     * @return void
     */
    public function testReturnsExplicitPosture(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', [
            'partner' => ['posture' => AudienceResolver::POSTURE_ALLOWLIST],
        ]);

        self::assertSame(AudienceResolver::POSTURE_ALLOWLIST, (new AudienceConfiguration)->postureFor('partner'));
    }

    /**
     * Test that an audience with no declared posture, and an unconfigured
     * audience, both default to the blocklist posture.
     *
     * @return void
     */
    public function testPostureDefaultsToBlocklist(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        $configuration = new AudienceConfiguration;

        self::assertSame(AudienceResolver::POSTURE_BLOCKLIST, $configuration->postureFor('public'));
        self::assertSame(AudienceResolver::POSTURE_BLOCKLIST, $configuration->postureFor('unconfigured'));
    }

    /**
     * Test that a per-audience info block wins over the top-level default and
     * the hard default, key by key.
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
     * Test that keys absent from the per-audience block fall back to the
     * top-level info default.
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
     * Test that title and version fall back to the shipped hard defaults, and
     * that an unset description is omitted entirely.
     *
     * @return void
     */
    public function testInfoFallsBackToHardDefaults(): void
    {
        $this->config()->set('api-toolkit.openapi.info', null);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        self::assertSame([
            'title'   => 'API Components',
            'version' => '1.0.0',
        ], (new AudienceConfiguration)->infoFor('public'));
    }

    /**
     * Test that malformed info config (non-array blocks and non-string,
     * empty-string values) is guarded and falls through to the hard defaults.
     *
     * @return void
     */
    public function testInfoGuardsMalformedConfig(): void
    {
        $this->config()->set('api-toolkit.openapi.info', 'not-an-array');
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public' => [
                'info' => [
                    'title'       => '',
                    'version'     => ['nested'],
                    'description' => 42,
                ],
            ],
        ]);

        self::assertSame([
            'title'   => 'API Components',
            'version' => '1.0.0',
        ], (new AudienceConfiguration)->infoFor('public'));
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
