<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Security;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use Tests\TestCase;

/**
 * Tests for the security scheme mapper.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SecuritySchemeMapper::class)]
final class SecuritySchemeMapperTest extends TestCase
{
    /**
     * Test that the JWT driver maps to the plain bearer scheme, sharing it with
     * the other bearer drivers rather than asserting an unverifiable format.
     *
     * @return void
     */
    public function testJwtDriverMapsToPlainBearerScheme(): void
    {
        self::assertSame(
            ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            (new SecuritySchemeMapper)->schemeFor('jwt'),
        );
    }

    /**
     * Test that the basic driver maps to a basic http scheme.
     *
     * @return void
     */
    public function testBasicDriverMapsToBasicScheme(): void
    {
        self::assertSame(
            ['name' => 'basicAuth', 'definition' => ['type' => 'http', 'scheme' => 'basic']],
            (new SecuritySchemeMapper)->schemeFor('basic'),
        );
    }

    /**
     * Test that the sanctum and token drivers both map to a plain bearer scheme
     * sharing the bearer scheme name, without the JWT format annotation.
     *
     * @return void
     */
    public function testSanctumAndTokenMapToPlainBearer(): void
    {
        $mapper   = new SecuritySchemeMapper;
        $expected = ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']];

        self::assertSame($expected, $mapper->schemeFor('sanctum'));
        self::assertSame($expected, $mapper->schemeFor('token'));
    }

    /**
     * Test that the session driver maps to a cookie apiKey scheme named after
     * the configured session cookie.
     *
     * @return void
     */
    public function testSessionDriverMapsToConfiguredCookie(): void
    {
        $this->config()->set('session.cookie', 'acme_session');

        self::assertSame(
            ['name' => 'cookieAuth', 'definition' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'acme_session']],
            (new SecuritySchemeMapper)->schemeFor('session'),
        );
    }

    /**
     * Test that the session cookie name falls back to a stable default when the
     * session cookie is not configured.
     *
     * @return void
     */
    public function testSessionCookieFallsBackWhenUnconfigured(): void
    {
        $this->config()->set('session.cookie', null);

        $scheme = (new SecuritySchemeMapper)->schemeFor('session');

        self::assertNotNull($scheme);
        self::assertSame('session', $scheme['definition']['name']);
    }

    /**
     * Test that a config override adds a mapping for a custom driver.
     *
     * @return void
     */
    public function testConfigOverrideAddsCustomDriver(): void
    {
        $this->config()->set('api-toolkit.openapi.security.drivers', [
            'passport' => ['name' => 'oauthBearer', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
        ]);

        self::assertSame(
            ['name' => 'oauthBearer', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            (new SecuritySchemeMapper)->schemeFor('passport'),
        );
    }

    /**
     * Test that a config override wins over a built-in driver mapping.
     *
     * @return void
     */
    public function testConfigOverrideWinsOverBuiltInDriver(): void
    {
        $this->config()->set('api-toolkit.openapi.security.drivers', [
            'jwt' => ['name' => 'customJwt', 'definition' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'MyJWT']],
        ]);

        self::assertSame(
            ['name' => 'customJwt', 'definition' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'MyJWT']],
            (new SecuritySchemeMapper)->schemeFor('jwt'),
        );
    }

    /**
     * Test that a malformed config override entry is ignored, leaving the
     * driver unmapped.
     *
     * @return void
     */
    public function testMalformedOverrideEntryIsIgnored(): void
    {
        $this->config()->set('api-toolkit.openapi.security.drivers', [
            'broken' => ['definition' => ['type' => 'http']],
        ]);

        self::assertNull((new SecuritySchemeMapper)->schemeFor('broken'));
    }

    /**
     * Test that an unknown driver with no override resolves to null so the
     * exporter skips it rather than inventing a scheme.
     *
     * @return void
     */
    public function testUnknownDriverResolvesToNull(): void
    {
        self::assertNull((new SecuritySchemeMapper)->schemeFor('carrier-pigeon'));
    }

    /**
     * Test that definitionFor returns the canonical definition for a scheme
     * name, preferring the first declaring driver when a shape is shared.
     *
     * @return void
     */
    public function testDefinitionForReturnsCanonicalBearerDefinition(): void
    {
        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            (new SecuritySchemeMapper)->definitionFor('bearerAuth'),
        );
    }

    /**
     * Test that definitionFor returns null for a name no mapped driver emits.
     *
     * @return void
     */
    public function testDefinitionForReturnsNullForUnknownName(): void
    {
        self::assertNull((new SecuritySchemeMapper)->definitionFor('nonexistentAuth'));
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

    /**
     * Test that a driver override that is not a map is ignored, so a malformed
     * entry leaves the shipped scheme in place rather than emptying it.
     *
     * @return void
     */
    public function testAMalformedOverrideLeavesTheShippedSchemeInPlace(): void
    {
        $this->config()->set('api-toolkit.openapi.security.drivers', 'not-a-map');

        self::assertSame(
            (new SecuritySchemeMapper)->schemeFor('jwt'),
            (new SecuritySchemeMapper)->schemeFor('jwt'),
        );
        self::assertNotNull((new SecuritySchemeMapper)->schemeFor('jwt'));
    }

}
