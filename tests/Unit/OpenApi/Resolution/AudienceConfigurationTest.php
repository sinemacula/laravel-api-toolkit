<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
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
            'partner' => ['posture' => QuerySurface::POSTURE_ALLOWLIST],
        ]);

        self::assertSame(QuerySurface::POSTURE_ALLOWLIST, (new AudienceConfiguration)->postureFor('partner'));
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

        self::assertSame(QuerySurface::POSTURE_BLOCKLIST, $configuration->postureFor('public'));
        self::assertSame(QuerySurface::POSTURE_BLOCKLIST, $configuration->postureFor('unconfigured'));
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
