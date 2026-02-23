<?php

namespace Tests\Unit\Enums;

use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Tests for the CacheKeys enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheKeys::class)]
class CacheKeysTest extends TestCase
{
    /**
     * Test that resolveKey returns a prefixed key with no replacements.
     *
     * @param  \SineMacula\ApiToolkit\Enums\CacheKeys  $case
     * @param  string  $expectedValue
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testResolveKeyWithEmptyReplacementsReturnsPrefixedKey(CacheKeys $case, string $expectedValue): void
    {
        Config::set('api-toolkit.cache.prefix', 'test-prefix');

        $result = $case->resolveKey();

        static::assertStringStartsWith('test-prefix:', $result);
        static::assertSame('test-prefix:' . $expectedValue, $result);
    }

    /**
     * Test that resolveKey uses the default prefix when no config is set.
     *
     * @return void
     */
    public function testResolveKeyUsesDefaultPrefixWhenConfigMissing(): void
    {
        $result = CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey(['User']);

        static::assertStringStartsWith('sm-api-toolkit:', $result);
        static::assertSame('sm-api-toolkit:repository-model-casts:User', $result);
    }

    /**
     * Test that resolveKey replaces single placeholders.
     *
     * @return void
     */
    public function testResolveKeyWithSingleReplacement(): void
    {
        Config::set('api-toolkit.cache.prefix', 'app');

        $result = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey(['User']);

        static::assertSame('app:model-schema-columns:User', $result);
    }

    /**
     * Test that resolveKey replaces multiple placeholders.
     *
     * @return void
     */
    public function testResolveKeyWithMultipleReplacements(): void
    {
        Config::set('api-toolkit.cache.prefix', 'app');

        $result = CacheKeys::MODEL_RELATIONS->resolveKey(['User', 'posts']);

        static::assertSame('app:model-relations:User:posts', $result);
    }

    /**
     * Test that each case has the expected backing value.
     *
     * @param  \SineMacula\ApiToolkit\Enums\CacheKeys  $case
     * @param  string  $expectedValue
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testCaseHasExpectedValue(CacheKeys $case, string $expectedValue): void
    {
        static::assertSame($expectedValue, $case->value);
    }

    /**
     * Test that the expected number of cases exist.
     *
     * @return void
     */
    public function testExpectedCaseCount(): void
    {
        static::assertCount(6, CacheKeys::cases());
    }

    /**
     * Provide all CacheKeys cases with their expected values.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\CacheKeys, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'REPOSITORY_MODEL_CASTS' => [CacheKeys::REPOSITORY_MODEL_CASTS, 'repository-model-casts:%s'];
        yield 'MODEL_SCHEMA_COLUMNS' => [CacheKeys::MODEL_SCHEMA_COLUMNS, 'model-schema-columns:%s'];
        yield 'MODEL_RELATIONS' => [CacheKeys::MODEL_RELATIONS, 'model-relations:%s:%s'];
        yield 'MODEL_RESOURCES' => [CacheKeys::MODEL_RESOURCES, 'model-resources:%s'];
        yield 'MODEL_EAGER_LOADS' => [CacheKeys::MODEL_EAGER_LOADS, 'model-eager-loads:%s:%s'];
        yield 'MODEL_RELATION_INSTANCES' => [CacheKeys::MODEL_RELATION_INSTANCES, 'model-relation-instances:%s:%s'];
    }
}
