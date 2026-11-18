<?php

declare(strict_types = 1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Config\ToolkitConfig;
use SineMacula\ApiToolkit\Enums\RenderStrategy;
use Tests\Concerns\AssertsRefusedSettings;
use Tests\TestCase;

/**
 * Tests for the typed toolkit configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitConfig::class)]
final class ToolkitConfigTest extends TestCase
{
    use AssertsRefusedSettings;

    /** @var string What a boolean setting accepts */
    private const string BOOLEAN = 'a boolean, or one of 1, 0, true, false, on, off, yes, no';

    /** @var string What a cap setting accepts */
    private const string CAP = 'an integer of at least 0, or null';

    /**
     * Provide the accepted spellings of a boolean and the value each reads as.
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function acceptedBooleanProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
        yield 'the word true' => ['true', true];
        yield 'the word false' => ['false', false];
        yield 'on' => ['on', true];
        yield 'off' => ['off', false];
        yield 'yes' => ['yes', true];
        yield 'no' => ['no', false];
        yield 'upper case' => ['TRUE', true];
        yield 'padded' => [' No ', false];
    }

    /**
     * Test that every accepted spelling of a boolean reads as its value.
     *
     * @param  mixed  $value
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('acceptedBooleanProvider')]
    public function testBooleanAcceptsItsSpellings(mixed $value, bool $expected): void
    {
        Config::set('api-toolkit.resources.narrow_columns', $value);

        self::assertSame($expected, ToolkitConfig::readBoolean('resources.narrow_columns'));
    }

    /**
     * Provide values a boolean refuses, with how the message names each.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedBooleanProvider(): iterable
    {
        yield 'empty string' => ['', '\'\''];
        yield 'null' => [null, 'null'];
        yield 'another word' => ['maybe', '\'maybe\''];
        yield 'another integer' => [2, '2'];
        yield 'a float' => [1.0, '1'];
        yield 'an array' => [[], 'array'];
    }

    /**
     * Test that a boolean refuses any other value.
     *
     * @param  mixed  $value
     * @param  string  $given
     * @return void
     */
    #[DataProvider('refusedBooleanProvider')]
    public function testBooleanRefusesOtherValues(mixed $value, string $given): void
    {
        Config::set('api-toolkit.resources.narrow_columns', $value);

        $this->assertRefused(
            static fn (): bool => ToolkitConfig::readBoolean('resources.narrow_columns'),
            sprintf('The api-toolkit.resources.narrow_columns setting must be %s; [%s] given.', self::BOOLEAN, $given),
        );
    }

    /**
     * Provide accepted integers and the value each reads as.
     *
     * @return iterable<string, array{mixed, int}>
     */
    public static function acceptedIntegerProvider(): iterable
    {
        yield 'an integer' => [25, 25];
        yield 'digits' => ['25', 25];
        yield 'padded digits' => [' 25 ', 25];
        yield 'the minimum' => ['1', 1];
    }

    /**
     * Test that an integer accepts an int or a string of digits.
     *
     * @param  mixed  $value
     * @param  int  $expected
     * @return void
     */
    #[DataProvider('acceptedIntegerProvider')]
    public function testIntegerAcceptsDigits(mixed $value, int $expected): void
    {
        Config::set('api-toolkit.parser.defaults.limit', $value);

        self::assertSame($expected, ToolkitConfig::readInteger('parser.defaults.limit', 1));
    }

    /**
     * Provide values an integer refuses, with how the message names each.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedIntegerProvider(): iterable
    {
        yield 'below the minimum' => ['0', '\'0\''];
        yield 'negative' => [-5, '-5'];
        yield 'a decimal string' => ['10.0', '\'10.0\''];
        yield 'an exponent' => ['1e3', '\'1e3\''];
        yield 'a suffix' => ['8k', '\'8k\''];
        yield 'empty string' => ['', '\'\''];
        yield 'null' => [null, 'null'];
        yield 'a float' => [10.5, '10.5'];
        yield 'a boolean' => [true, 'true'];
    }

    /**
     * Test that an integer refuses anything but whole numbers at or above its
     * minimum, rather than clamping or truncating.
     *
     * @param  mixed  $value
     * @param  string  $given
     * @return void
     */
    #[DataProvider('refusedIntegerProvider')]
    public function testIntegerRefusesOtherValues(mixed $value, string $given): void
    {
        Config::set('api-toolkit.parser.defaults.limit', $value);

        $this->assertRefused(
            static fn (): int => ToolkitConfig::readInteger('parser.defaults.limit', 1),
            sprintf('The api-toolkit.parser.defaults.limit setting must be an integer of at least 1; [%s] given.', $given),
        );
    }

    /**
     * Test that a cap reads null and zero as disabled and refuses a negative.
     *
     * @return void
     */
    public function testCapReadsNullAsDisabled(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', null);

        self::assertSame(0, ToolkitConfig::readCap('query_cost.max_depth'));

        Config::set('api-toolkit.query_cost.max_depth', '0');

        self::assertSame(0, ToolkitConfig::readCap('query_cost.max_depth'));

        Config::set('api-toolkit.query_cost.max_depth', -1);

        $this->assertRefused(
            static fn (): int => ToolkitConfig::readCap('query_cost.max_depth'),
            sprintf('The api-toolkit.query_cost.max_depth setting must be %s; [-1] given.', self::CAP),
        );
    }

    /**
     * Test that a missing setting fails rather than reading as null, which
     * would disable a cap.
     *
     * @return void
     */
    public function testMissingSettingFails(): void
    {
        Config::set('api-toolkit.query_cost', []);

        $this->assertRefused(
            static fn (): int => ToolkitConfig::readCap('query_cost.max_depth'),
            'The api-toolkit.query_cost.max_depth setting is missing; add it from the published config.',
        );
    }

    /**
     * Provide every other reader with a value it accepts, the value that reads
     * as, a value it refuses and the message the refusal carries.
     *
     * @return iterable<string, array{string, \Closure(string): mixed, mixed, mixed, mixed, string}>
     */
    public static function readerProvider(): iterable
    {
        yield 'string' => ['parser.alias', ToolkitConfig::readString(...), 'query', 'query', 5, 'must be a non-empty string; [5] given.'];
        yield 'empty string' => ['parser.alias', ToolkitConfig::readString(...), 'q', 'q', '', 'must be a non-empty string; [\'\'] given.'];
        yield 'nullable string' => ['notifications.log_channel', ToolkitConfig::readNullableString(...), 'audit', 'audit', 5, 'must be a string, or null; [5] given.'];
        yield 'nullable empty string' => ['notifications.log_channel', ToolkitConfig::readNullableString(...), '', null, false, 'must be a string, or null; [false] given.'];
        yield 'nullable null' => ['notifications.log_channel', ToolkitConfig::readNullableString(...), null, null, [], 'must be a string, or null; [array] given.'];
        yield 'list' => [
            'resources.fixed_fields',
            ToolkitConfig::readList(...),
            ['id', '_type'],
            ['id', '_type'],
            ['id' => 'id'],
            'must be a list of non-empty strings; [array] given.',
        ];
        yield 'nullable list' => ['resources.paths', ToolkitConfig::readNullableList(...), ['app'], ['app'], false, 'must be a list of non-empty strings, or null; [false] given.'];
        yield 'nullable list null' => [
            'resources.paths',
            ToolkitConfig::readNullableList(...),
            null,
            null,
            'app',
            'must be a list of non-empty strings, or null; [\'app\'] given.',
        ];
        yield 'nullable boolean' => [
            'exceptions.include_debug_info',
            ToolkitConfig::readNullableBoolean(...),
            'off',
            false,
            'maybe',
            'must be a boolean, or one of 1, 0, true, false, on, off, yes, no, or null; [\'maybe\'] given.',
        ];
        yield 'nullable boolean null' => [
            'exceptions.include_debug_info',
            ToolkitConfig::readNullableBoolean(...),
            null,
            null,
            '',
            'must be a boolean, or one of 1, 0, true, false, on, off, yes, no, or null; [\'\'] given.',
        ];
        yield 'map' => ['exceptions.map', ToolkitConfig::readMap(...), ['a' => 'b', 'c' => 'd'], ['a' => 'b', 'c' => 'd'], 'none', 'must be an array; [\'none\'] given.'];
    }

    /**
     * Test that each reader returns an accepted value and refuses an invalid
     * one, naming the key.
     *
     * @param  string  $key
     * @param  \Closure(string): mixed  $read
     * @param  mixed  $accepted
     * @param  mixed  $expected
     * @param  mixed  $refused
     * @param  string  $refusal
     * @return void
     */
    #[DataProvider('readerProvider')]
    public function testReaderReturnsAnAcceptedValueAndRefusesAnInvalidOne(string $key, \Closure $read, mixed $accepted, mixed $expected, mixed $refused, string $refusal): void
    {
        Config::set('api-toolkit.' . $key, $accepted);

        self::assertSame($expected, $read($key));

        Config::set('api-toolkit.' . $key, $refused);

        $this->assertRefused(static fn (): mixed => $read($key), sprintf('The api-toolkit.%s setting %s', $key, $refusal));
    }

    /**
     * Test that a list names the entry it refuses.
     *
     * @return void
     */
    public function testListNamesTheEntryItRefuses(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id', 5]);

        $this->assertRefused(
            static fn (): array => ToolkitConfig::readList('resources.fixed_fields'),
            'The api-toolkit.resources.fixed_fields.1 setting must be a non-empty string; [5] given.',
        );
    }

    /**
     * Test that an enum reads its case and refuses any other value, listing the
     * cases.
     *
     * @return void
     */
    public function testEnumReadsItsCaseAndListsTheCasesWhenRefusing(): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', 'auto');

        self::assertSame(RenderStrategy::AUTO, ToolkitConfig::readEnum('exceptions.render_strategy', RenderStrategy::class));

        foreach (['json-when-expected' => '\'json-when-expected\'', 1 => '1'] as $refused => $given) {

            Config::set('api-toolkit.exceptions.render_strategy', $refused);

            $this->assertRefused(
                static fn (): RenderStrategy => ToolkitConfig::readEnum('exceptions.render_strategy', RenderStrategy::class),
                sprintf('The api-toolkit.exceptions.render_strategy setting must be one of always_json, json_when_expected, auto; [%s] given.', $given),
            );
        }
    }

    /**
     * Test that a setting's full key sits under the toolkit's root.
     *
     * @return void
     */
    public function testKeyPrefixesTheRoot(): void
    {
        self::assertSame('api-toolkit.parser.alias', ToolkitConfig::key('parser.alias'));
    }

    /**
     * Test that the shipped settings are the package config file's.
     *
     * @return void
     */
    public function testDefaultsAreTheShippedConfigFile(): void
    {
        self::assertSame(require dirname(__DIR__, 3) . '/config/api-toolkit.php', ToolkitConfig::defaults());
    }

    /**
     * Test that only a non-empty map outside the opaque settings is a section.
     *
     * @return void
     */
    public function testSectionIsANonEmptyMapThatIsNotOpaque(): void
    {
        self::assertTrue(ToolkitConfig::isSection('parser', ['alias' => 'api.query']));
        self::assertFalse(ToolkitConfig::isSection('resources.fixed_fields', ['id']));
        self::assertFalse(ToolkitConfig::isSection('openapi.tags', []));
        self::assertFalse(ToolkitConfig::isSection('openapi.audiences', ['public' => []]));
    }

    /**
     * Test that the merge fills a leaf a published section predates, keeping
     * every leaf the application set.
     *
     * @return void
     */
    public function testMergeFillsALeafThePublishedSectionLacks(): void
    {
        Config::set('api-toolkit.resources', ['enable_dynamic_morph_mapping' => false, 'fixed_fields' => ['id']]);

        ToolkitConfig::merge();

        self::assertFalse(Config::get('api-toolkit.resources.enable_dynamic_morph_mapping'));
        self::assertSame(['id'], Config::get('api-toolkit.resources.fixed_fields'));
        self::assertTrue(Config::get('api-toolkit.resources.narrow_columns'));
        self::assertSame(25, Config::get('api-toolkit.resources.relation_limit'));
    }

    /**
     * Test that the merge fills nested sections too.
     *
     * @return void
     */
    public function testMergeFillsNestedSections(): void
    {
        Config::set('api-toolkit.middleware', ['throttle' => ['enabled' => false]]);
        Config::set('api-toolkit.parser', ['alias' => 'query']);

        ToolkitConfig::merge();

        self::assertSame(['enabled' => false, 'class' => null], Config::get('api-toolkit.middleware.throttle'));
        self::assertSame(['enabled' => true, 'scope' => 'global'], Config::get('api-toolkit.middleware.json_pretty_print'));
        self::assertSame('query', Config::get('api-toolkit.parser.alias'));
        self::assertSame(50, Config::get('api-toolkit.parser.defaults.limit'));
    }

    /**
     * Test that a configured list or opaque setting replaces the shipped one
     * whole.
     *
     * @return void
     */
    public function testMergeTakesListsAndOpaqueSettingsWhole(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', ['api_secret']);
        Config::set('api-toolkit.openapi.audiences', ['partner' => ['posture' => 'allowlist']]);

        ToolkitConfig::merge();

        self::assertSame(['api_secret'], Config::get('api-toolkit.resources.sensitive_columns'));
        self::assertSame(['partner' => ['posture' => 'allowlist']], Config::get('api-toolkit.openapi.audiences'));
    }

    /**
     * Test that the merge leaves a section set to a non-array for validation to
     * refuse.
     *
     * @return void
     */
    public function testMergeLeavesANonArraySectionForValidation(): void
    {
        Config::set('api-toolkit.resources', false);

        ToolkitConfig::merge();

        self::assertFalse(Config::get('api-toolkit.resources'));

        Config::set('api-toolkit', 'off');

        ToolkitConfig::merge();

        self::assertSame('off', Config::get('api-toolkit'));
    }

    /**
     * Test that an unpublished configuration takes every shipped setting.
     *
     * @return void
     */
    public function testMergeShipsEverySettingToAnUnpublishedConfiguration(): void
    {
        Config::set('api-toolkit', null);

        ToolkitConfig::merge();

        self::assertSame(require dirname(__DIR__, 3) . '/config/api-toolkit.php', Config::get('api-toolkit'));
    }
}
