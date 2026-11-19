<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Security;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
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
     * Test that the JWT driver maps to the plain bearer scheme.
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
     * Test that the sanctum and token drivers map to plain bearer.
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
     * Test that the session driver maps to a cookie apiKey scheme.
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
     * Test that the session cookie name falls back to a stable default.
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
     * Provide malformed driver overrides, with the message each fails with.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedOverrideProvider(): iterable
    {
        yield 'an entry without a name' => [
            ['broken' => ['definition' => ['type' => 'http']]],
            'The api-toolkit.openapi.security.drivers.broken setting must be an array with a non-empty string name and an array definition; [array] given.',
        ];
        yield 'an entry without a definition' => [
            ['broken' => ['name' => 'brokenAuth']],
            'The api-toolkit.openapi.security.drivers.broken setting must be an array with a non-empty string name and an array definition; [array] given.',
        ];
        yield 'not a map' => ['not-a-map', 'The api-toolkit.openapi.security.drivers setting must be an array; [\'not-a-map\'] given.'];
    }

    /**
     * Test that a malformed override fails rather than being skipped, which
     * would document the shipped scheme or none.
     *
     * @param  mixed  $drivers
     * @param  string  $message
     * @return void
     */
    #[DataProvider('malformedOverrideProvider')]
    public function testMalformedOverrideFails(mixed $drivers, string $message): void
    {
        $this->config()->set('api-toolkit.openapi.security.drivers', $drivers);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new SecuritySchemeMapper)->schemeFor('jwt');
    }

    /**
     * Test that an unknown driver without an override resolves to null.
     *
     * @return void
     */
    public function testUnknownDriverResolvesToNull(): void
    {
        self::assertNull((new SecuritySchemeMapper)->schemeFor('carrier-pigeon'));
    }

    /**
     * Provide the built-in bearer drivers a configured mapping may override
     * while keeping their scheme name.
     *
     * @return iterable<string, array{string}>
     */
    public static function bearerDriverProvider(): iterable
    {
        yield 'jwt' => ['jwt'];
        yield 'sanctum' => ['sanctum'];
        yield 'token' => ['token'];
    }

    /**
     * Test that a configured driver keeping a built-in scheme name supplies
     * that scheme's definition.
     *
     * @param  string  $driver
     * @return void
     */
    #[DataProvider('bearerDriverProvider')]
    public function testConfiguredDriverKeepingTheSchemeNameWinsItsDefinition(string $driver): void
    {
        $definition = ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum'];

        $this->config()->set('api-toolkit.openapi.security.drivers', [
            $driver => ['name' => 'bearerAuth', 'definition' => $definition],
        ]);

        self::assertSame($definition, (new SecuritySchemeMapper)->definitionFor('bearerAuth'));
    }

    /**
     * Test that two configured drivers giving one scheme name different
     * definitions are refused rather than one being dropped.
     *
     * @return void
     */
    public function testConfiguredDriversSharingANameWithDifferentDefinitionsAreRefused(): void
    {
        $this->config()->set('api-toolkit.openapi.security.drivers', [
            'passport' => ['name' => 'sharedAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            'custom'   => ['name' => 'sharedAuth', 'definition' => ['type' => 'http', 'scheme' => 'basic']],
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'The api-toolkit.openapi.security.drivers.custom.definition setting must be the sharedAuth definition the passport driver declares; [array] given.',
        );

        (new SecuritySchemeMapper)->definitionFor('sharedAuth');
    }

    /**
     * Test that definitionFor prefers the first declaring driver.
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
}
