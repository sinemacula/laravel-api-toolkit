<?php

declare(strict_types = 1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Config\ToolkitConfigValidator;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use Tests\Concerns\AssertsRefusedSettings;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\SettingCases;
use Tests\TestCase;

/**
 * Tests for the validation of every toolkit setting at once.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitConfigValidator::class)]
final class ToolkitConfigValidatorTest extends TestCase
{
    use AssertsRefusedSettings;

    /**
     * Provide every setting with a value it refuses and the message the refusal
     * carries.
     *
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function refusedSettingProvider(): iterable
    {
        foreach (SettingCases::all() as $name => [, $key, , , , $refused, $message]) {
            yield $name => [$key, $refused, $message];
        }
    }

    /**
     * Test that the validation reports every setting's invalid value.
     *
     * @param  string  $key
     * @param  mixed  $refused
     * @param  string  $message
     * @return void
     */
    #[DataProvider('refusedSettingProvider')]
    public function testValidationReportsEachInvalidSetting(string $key, mixed $refused, string $message): void
    {
        Config::set('api-toolkit.' . $key, $refused);

        try {
            ToolkitConfigValidator::validate();

            self::fail('The validation passed.');
        } catch (InvalidConfigurationException $exception) {
            self::assertContains($message, preg_split('/(?<=\.) (?=The api-toolkit\.)/', $exception->getMessage()) ?: []);
        }
    }

    /**
     * Test that the shipped configuration passes validation.
     *
     * @return void
     */
    public function testShippedConfigurationPasses(): void
    {
        ToolkitConfigValidator::validate();

        $this->addToAssertionCount(1);
    }

    /**
     * Test that every problem is reported in one message, in check order.
     *
     * @return void
     */
    public function testValidationReportsEveryProblemAtOnce(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', '-1');
        Config::set('api-toolkit.parser.alias', '');
        Config::set('api-toolkit.exceptions.map', [\stdClass::class => GoneException::class]);

        $this->assertValidationFails(
            'The api-toolkit.parser.alias setting must be a non-empty string; [\'\'] given. '
            . 'The api-toolkit.query_cost.max_depth setting must be an integer of at least 0, or null; [\'-1\'] given. '
            . 'The api-toolkit.exceptions.map key [stdClass] must be an interface or a Throwable class, and not an ApiException.',
        );
    }

    /**
     * Test that a setting a cross check reads is reported even when another
     * setting that check reads first fails, and each problem only once.
     *
     * @return void
     */
    public function testValidationReportsEachSettingACrossCheckReads(): void
    {
        Config::set('api-toolkit.parser.max_limit', '-1');
        Config::set('api-toolkit.parser.defaults.limit', '0');
        Config::set('api-toolkit.openapi.default_audience', '');
        Config::set('api-toolkit.openapi.audiences', ['public' => ['posture' => 'open']]);

        $this->assertValidationFails(
            'The api-toolkit.openapi.default_audience setting must be a non-empty string; [\'\'] given. '
            . 'The api-toolkit.parser.max_limit setting must be an integer of at least 0, or null; [\'-1\'] given. '
            . 'The api-toolkit.parser.defaults.limit setting must be an integer of at least 1; [\'0\'] given. '
            . 'The api-toolkit.openapi.audiences.public.posture setting must be one of allowlist, blocklist; [\'open\'] given.',
        );
    }

    /**
     * Test that an audience's malformed servers do not hide an undeclared
     * default audience.
     *
     * @return void
     */
    public function testValidationReportsAnUndeclaredDefaultBesideMalformedServers(): void
    {
        Config::set('api-toolkit.openapi.default_audience', 'partner');
        Config::set('api-toolkit.openapi.audiences', ['public' => ['servers' => 'https://api.example.com']]);

        $this->assertValidationFails(
            'Unable to export the OpenAPI servers of audience [public]: they must be a list of server objects. '
            . 'The api-toolkit.openapi.default_audience setting must be an audience declared in api-toolkit.openapi.audiences; [\'partner\'] given.',
        );
    }

    /**
     * Test that a default page size above the ceiling fails, and passes once
     * the ceiling is off.
     *
     * @return void
     */
    public function testDefaultPageSizeMustBeWithinTheCeiling(): void
    {
        Config::set('api-toolkit.parser.max_limit', 100);
        Config::set('api-toolkit.parser.defaults.limit', 100);

        ToolkitConfigValidator::validate();

        Config::set('api-toolkit.parser.defaults.limit', 200);

        try {
            ToolkitConfigValidator::validate();

            self::fail('A default above the ceiling passed.');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame('The api-toolkit.parser.defaults.limit setting must be at most the api-toolkit.parser.max_limit of 100; [200] given.', $exception->getMessage());
        }

        Config::set('api-toolkit.parser.max_limit', null);

        ToolkitConfigValidator::validate();
    }

    /**
     * Provide audience settings that fail validation, with the message each
     * fails with.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidAudienceProvider(): iterable
    {
        yield 'an undeclared default' => [
            ['openapi.default_audience' => 'partner'],
            'The api-toolkit.openapi.default_audience setting must be an audience declared in api-toolkit.openapi.audiences; [\'partner\'] given.',
        ];

        yield 'a misspelt posture' => [
            ['openapi.audiences' => ['public' => ['posture' => 'allow_list']]],
            'The api-toolkit.openapi.audiences.public.posture setting must be one of allowlist, blocklist; [\'allow_list\'] given.',
        ];

        yield 'an unknown audience key' => [
            ['openapi.audiences' => ['public' => ['postures' => 'allowlist']]],
            'The api-toolkit.openapi.audiences.public.postures setting is not a toolkit setting; remove it or correct its name.',
        ];

        yield 'a malformed audience info' => [
            ['openapi.audiences' => ['public' => ['info' => ['version' => '']]]],
            'The api-toolkit.openapi.audiences.public.info.version setting must be a non-empty string; [\'\'] given.',
        ];

        yield 'a malformed global info' => [
            ['openapi.info' => ['summary' => 'Ours']],
            'The api-toolkit.openapi.info.summary setting is not a toolkit setting; remove it or correct its name.',
        ];

        yield 'a malformed audience server' => [
            ['openapi.audiences' => ['public' => [], 'partner' => ['servers' => [['description' => 'No URL']]]]],
            'Unable to export the OpenAPI servers of audience [partner]: server [0] must declare a non-empty string url.',
        ];

        yield 'a malformed server list of a numbered audience' => [
            ['openapi.audiences' => ['public' => [], '7' => ['servers' => [['description' => 'No URL']]]]],
            'Unable to export the OpenAPI servers of audience [7]: server [0] must declare a non-empty string url.',
        ];

        yield 'a malformed global server list' => [
            ['openapi.servers' => ['url' => 'https://api.example.com']],
            'Unable to export the global OpenAPI servers: they must be a list of server objects.',
        ];

        yield 'a malformed driver' => [
            ['openapi.security.drivers' => ['passport' => ['name' => 'oauth']]],
            'The api-toolkit.openapi.security.drivers.passport setting must be an array with a non-empty string name and an array definition; [array] given.',
        ];
    }

    /**
     * Test that the OpenAPI settings are checked as a whole.
     *
     * @param  array<string, mixed>  $settings
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidAudienceProvider')]
    public function testValidationChecksTheOpenApiSettings(array $settings, string $message): void
    {
        foreach ($settings as $key => $value) {
            Config::set('api-toolkit.' . $key, $value);
        }

        $this->assertValidationFails($message);
    }

    /**
     * Provide settings whose classes or paths must exist, with the message each
     * fails with.
     *
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function unresolvableSettingProvider(): iterable
    {
        yield 'a missing throttle class' => [
            'middleware.throttle.class',
            'App\Missing\Throttle',
            'The api-toolkit.middleware.throttle.class setting must be an existing class, or null; [\'App\Missing\Throttle\'] given.',
        ];
        yield 'a missing resource directory' => [
            'resources.paths',
            ['/nonexistent/resources'],
            'The api-toolkit.resources.paths.0 setting must be an existing directory; [\'/nonexistent/resources\'] given.',
        ];
        yield 'a non-model binding' => [
            'resources.resource_map',
            [\stdClass::class => UserResource::class],
            sprintf(
                'The api-toolkit.resources.resource_map setting must be a map of Eloquent model classes to API resource classes; [\'stdClass => %s\'] given.',
                UserResource::class,
            ),
        ];
        yield 'a misspelt flush strategy' => [
            'deferred_writes.on_failure',
            'Collect',
            'The api-toolkit.deferred_writes.on_failure setting must be one of log, throw, collect; [\'Collect\'] given.',
        ];
    }

    /**
     * Test that the classes and paths a setting names are checked.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $message
     * @return void
     */
    #[DataProvider('unresolvableSettingProvider')]
    public function testValidationChecksWhatASettingNames(string $key, mixed $value, string $message): void
    {
        Config::set('api-toolkit.' . $key, $value);

        $this->assertValidationFails($message);
    }

    /**
     * Test that existing classes and directories pass.
     *
     * @return void
     */
    public function testValidationAcceptsExistingClassesAndDirectories(): void
    {
        Config::set('api-toolkit.middleware.throttle.class', ThrottleRequests::class);
        Config::set('api-toolkit.resources.paths', [__DIR__]);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        ToolkitConfigValidator::validate();

        $this->addToAssertionCount(1);
    }

    /**
     * Test that a misspelt key and a key the toolkit no longer reads are both
     * reported as unknown.
     *
     * @return void
     */
    public function testValidationReportsUnknownKeys(): void
    {
        Config::set('api-toolkit.resources.narrow_colums', true);
        Config::set('api-toolkit.repositories.searchable_exclusions', []);
        Config::set('api-toolkit.exports', ['enabled' => true]);

        $this->assertValidationFails(
            'The api-toolkit.resources.narrow_colums setting is not a toolkit setting; remove it or correct its name. '
            . 'The api-toolkit.repositories.searchable_exclusions setting is not a toolkit setting; remove it or correct its name. '
            . 'The api-toolkit.exports setting is not a toolkit setting; remove it or correct its name.',
        );
    }

    /**
     * Test that the contents of settings taken whole, and the keys the
     * discovery merge writes, are never reported as unknown.
     *
     * @return void
     */
    public function testValidationLeavesOpaqueAndInternalKeysAlone(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.discovered_bindings', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.discovered_resources', [UserResource::class]);
        Config::set('api-toolkit.openapi.servers', [['url' => 'https://api.example.com', 'x-region' => 'eu']]);
        Config::set('api-toolkit.openapi.security.drivers', ['passport' => ['name' => 'oauth', 'definition' => ['type' => 'oauth2']]]);

        ToolkitConfigValidator::validate();

        $this->addToAssertionCount(1);
    }

    /**
     * Test that a toolkit configuration that is not an array fails.
     *
     * @return void
     */
    public function testValidationRefusesAConfigurationThatIsNotAnArray(): void
    {
        Config::set('api-toolkit', false);

        try {
            ToolkitConfigValidator::validate();

            self::fail('A configuration that is not an array passed.');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame('The api-toolkit setting must be an array; [false] given.', $exception->getMessage());
        }
    }

    /**
     * Assert that validation fails with the given message.
     *
     * @param  string  $message
     * @return void
     */
    private function assertValidationFails(string $message): void
    {
        $this->assertRefused(static fn () => ToolkitConfigValidator::validate(), $message);
    }
}
