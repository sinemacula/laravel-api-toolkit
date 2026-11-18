<?php

declare(strict_types = 1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Config\OpenApiSettings;
use Tests\Concerns\ReadsSettings;
use Tests\TestCase;

/**
 * Tests for the typed OpenAPI exporter settings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiSettings::class)]
final class OpenApiSettingsTest extends TestCase
{
    use ReadsSettings;

    /**
     * Test that a security driver mapping is read as its name and definition.
     *
     * @return void
     */
    public function testSecurityDriversKeepOnlyTheNameAndDefinition(): void
    {
        Config::set('api-toolkit.openapi.security.drivers', [
            'passport' => ['name' => 'oauth', 'definition' => ['type' => 'oauth2'], 'note' => 'ignored'],
        ]);

        self::assertSame(['passport' => ['name' => 'oauth', 'definition' => ['type' => 'oauth2']]], OpenApiSettings::securityDrivers());
    }

    /**
     * Provide driver mappings that fail, with how the message names each.
     *
     * @return iterable<string, array{array<array-key, mixed>, string, string}>
     */
    public static function malformedDriverProvider(): iterable
    {
        yield 'a numeric driver key' => [[['name' => 'oauth', 'definition' => []]], '0', 'array'];
        yield 'a name that is not a string' => [['passport' => ['name' => 5, 'definition' => []]], 'passport', 'array'];
        yield 'a scheme that is not an array' => [['passport' => 'oauth'], 'passport', '\'oauth\''];
        yield 'a scheme without a definition' => [['passport' => ['name' => 'oauth', 'definition' => 'oauth2']], 'passport', 'array'];
    }

    /**
     * Test that a driver mapping must key a name and a definition by driver.
     *
     * @param  array<array-key, mixed>  $drivers
     * @param  string  $driver
     * @param  string  $given
     * @return void
     */
    #[DataProvider('malformedDriverProvider')]
    public function testSecurityDriversRefuseMalformedMappings(array $drivers, string $driver, string $given): void
    {
        Config::set('api-toolkit.openapi.security.drivers', $drivers);

        $this->assertRefused(
            OpenApiSettings::securityDrivers(...),
            sprintf('The api-toolkit.openapi.security.drivers.%s setting must be an array with a non-empty string name and an array definition; [%s] given.', $driver, $given),
        );
    }

    /**
     * Provide malformed audiences, with the message each fails with.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedAudienceProvider(): iterable
    {
        yield 'not an array' => [['partner' => 'allowlist'], 'The api-toolkit.openapi.audiences.partner setting must be an array; [\'allowlist\'] given.'];
        yield 'an unknown key' => [
            ['partner' => ['postures' => 'allowlist']],
            'The api-toolkit.openapi.audiences.partner.postures setting is not a toolkit setting; remove it or correct its name.',
        ];
        yield 'a posture that is not a string' => [
            ['partner' => ['posture' => true]],
            'The api-toolkit.openapi.audiences.partner.posture setting must be one of allowlist, blocklist; [true] given.',
        ];
        yield 'an info that is not an array' => [
            ['partner' => ['info' => 'Partner API']],
            'The api-toolkit.openapi.audiences.partner.info setting must be an array; [\'Partner API\'] given.',
        ];
        yield 'an unknown info key' => [
            ['partner' => ['info' => ['summary' => 'Ours']]],
            'The api-toolkit.openapi.audiences.partner.info.summary setting is not a toolkit setting; remove it or correct its name.',
        ];
        yield 'an empty info value' => [
            ['partner' => ['info' => ['title' => '']]],
            'The api-toolkit.openapi.audiences.partner.info.title setting must be a non-empty string; [\'\'] given.',
        ];
    }

    /**
     * Test that a malformed audience fails naming the key.
     *
     * @param  mixed  $audiences
     * @param  string  $message
     * @return void
     */
    #[DataProvider('malformedAudienceProvider')]
    public function testAudiencesRefuseMalformedDefinitions(mixed $audiences, string $message): void
    {
        Config::set('api-toolkit.openapi.audiences', $audiences);

        $this->assertRefused(OpenApiSettings::audiences(...), $message);
    }

    /**
     * Test that a global info block that is not an array fails.
     *
     * @return void
     */
    public function testInfoRefusesANonArray(): void
    {
        Config::set('api-toolkit.openapi.info', 'API');

        $this->assertRefused(OpenApiSettings::info(...), 'The api-toolkit.openapi.info setting must be an array; [\'API\'] given.');
    }

    /**
     * Return the settings class under test.
     *
     * @return class-string
     */
    #[\Override]
    protected static function settings(): string
    {
        return OpenApiSettings::class;
    }
}
