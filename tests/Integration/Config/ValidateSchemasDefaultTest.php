<?php

declare(strict_types = 1);

namespace Tests\Integration\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\BrokenResource;
use Tests\ShippedDefaultsTestCase;

/**
 * Tests for the shipped default of boot schema validation, which follows the
 * application environment unless its variable is set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceSettings::class)]
#[CoversClass(ApiServiceProvider::class)]
final class ValidateSchemasDefaultTest extends ShippedDefaultsTestCase
{
    /** @var string The variable that sets boot schema validation */
    private const string VARIABLE = 'API_TOOLKIT_RESOURCES_VALIDATE_SCHEMAS';

    /**
     * Provide application environments with whether each validates by default.
     *
     * @return iterable<string, array{string|null, bool}>
     */
    public static function environmentProvider(): iterable
    {
        yield 'production' => ['production', false];
        yield 'unset, read as production' => [null, false];
        yield 'local' => ['local', true];
        yield 'staging' => ['staging', true];
        yield 'testing' => ['testing', true];
    }

    /**
     * Test that, with its variable unset, validation is on everywhere but
     * production.
     *
     * @param  string|null  $environment
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('environmentProvider')]
    public function testDefaultFollowsTheApplicationEnvironment(?string $environment, bool $expected): void
    {
        $this->useShippedConfiguration(['APP_ENV' => $environment, self::VARIABLE => null]);

        self::assertSame($expected, ResourceSettings::shouldValidateSchemas());
    }

    /**
     * Provide variable values with whether each turns validation on.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function variableProvider(): iterable
    {
        yield 'true' => ['true', true];
        yield 'parenthesised true' => ['(true)', true];
        yield 'one' => ['1', true];
        yield 'on' => ['on', true];
        yield 'false' => ['false', false];
        yield 'zero' => ['0', false];
    }

    /**
     * Test that the variable overrides the application environment either way.
     *
     * @param  string  $value
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('variableProvider')]
    public function testVariableOverridesTheApplicationEnvironment(string $value, bool $expected): void
    {
        foreach (['production', 'local'] as $environment) {

            $this->useShippedConfiguration(['APP_ENV' => $environment, self::VARIABLE => $value]);

            self::assertSame($expected, ResourceSettings::shouldValidateSchemas());
        }
    }

    /**
     * Test that validation the environment turns on fails the boot over an
     * invalid resource.
     *
     * @return void
     */
    public function testEnvironmentDerivedValidationFailsTheBoot(): void
    {
        $app = $this->app;

        assert($app !== null);

        $this->useShippedConfiguration(['APP_ENV' => 'local', self::VARIABLE => null]);

        Config::set('api-toolkit.resources.resource_map', [User::class => BrokenResource::class]);

        $this->expectException(InvalidSchemaException::class);

        (new ApiServiceProvider($app))->boot();
    }

    /**
     * Replace the toolkit configuration with the shipped one, loaded with the
     * given environment variables.
     *
     * @param  array<string, string|null>  $variables
     * @return void
     */
    private function useShippedConfiguration(array $variables): void
    {
        Config::set('api-toolkit', $this->shippedConfiguration($variables));
    }
}
