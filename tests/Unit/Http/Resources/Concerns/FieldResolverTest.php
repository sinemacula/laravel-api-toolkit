<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the FieldResolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldResolver::class)]
final class FieldResolverTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver */
    private FieldResolver $resolver;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.fixed_fields', []);

        $this->resolver = new FieldResolver;
    }

    /**
     * Test that the assembled field list is memoised across resolver instances,
     * so a homogeneous collection assembles it once rather than per row.
     *
     * @return void
     */
    public function testGetFieldsMemoisesTheAssembledListAcrossInstances(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'email']);

        $first = (new FieldResolver)->getFields(UserResource::class);

        // Tamper the cached entry so only a memo read can return the sentinel
        /** @var array<string, array<int, string>> $cache */
        $cache = $this->getStaticProperty(FieldResolver::class, 'resolvedCache');
        $key   = array_key_first($cache);

        self::assertNotNull($key);

        $this->setStaticProperty(FieldResolver::class, 'resolvedCache', [$key => ['sentinel']]);

        $second = (new FieldResolver)->getFields(UserResource::class);

        self::assertSame(['name', 'email'], $first);
        self::assertSame(['sentinel'], $second);
    }

    /**
     * Test that excluded fields form part of the memo key, so a resolver that
     * excludes a field is never handed a list cached for one that kept it.
     *
     * @return void
     */
    public function testExcludedFieldsAreDistinguishedInTheMemo(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        $full = (new FieldResolver)->getFields(UserResource::class);

        $resolver = new FieldResolver;
        $resolver->withoutFields(['email']);
        $trimmed = $resolver->getFields(UserResource::class);

        self::assertSame(['id', 'name', 'email'], $full);
        self::assertSame(['id', 'name'], $trimmed);
    }

    /**
     * Test that the selected fields form part of the memo key, so two resolvers
     * with different field sets never share a cached list.
     *
     * @return void
     */
    public function testSelectedFieldsAreDistinguishedInTheMemo(): void
    {
        $name = new FieldResolver;
        $name->withFields(['name']);

        $email = new FieldResolver;
        $email->withFields(['email']);

        self::assertSame(['name'], $name->getFields(UserResource::class));
        self::assertSame(['email'], $email->getFields(UserResource::class));
    }

    /**
     * Test that the fixed fields form part of the memo key.
     *
     * @return void
     */
    public function testFixedFieldsAreDistinguishedInTheMemo(): void
    {
        $this->resolver->withFields(['name']);

        $plain = $this->resolver->getFields(UserResource::class);

        Config::set('api-toolkit.resources.fixed_fields', ['uuid']);

        self::assertSame(['name'], $plain);
        self::assertSame(['name', 'uuid'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that getFields returns the defaults, then the fixed fields, when no
     * fields are requested.
     *
     * @return void
     */
    public function testGetFieldsReturnsDefaultFieldsWhenNoQueryFields(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['_type']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertSame(['id', 'name', 'email', '_type'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that getFields returns the requested fields when present.
     *
     * @return void
     */
    public function testGetFieldsReturnsQueryFieldsWhenPresent(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'status']);

        self::assertSame(['name', 'status'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that all-fields mode selects every field and the aggregates.
     *
     * @return void
     */
    public function testGetFieldsReturnsEveryFieldAndTheAggregatesInAllMode(): void
    {
        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name']);

        self::assertSame([...UserResource::getAllFields(), 'aggregates'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that all-fields mode set after a first resolution is honoured.
     *
     * @return void
     */
    public function testWithAllAfterAResolutionIsHonoured(): void
    {
        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        $defaults = $this->resolver->getFields(UserResource::class);

        $this->resolver->withAll();

        self::assertSame(['id', 'name', 'email'], $defaults);
        self::assertSame([...UserResource::getAllFields(), 'aggregates'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that fields set via withoutFields are excluded from the result.
     *
     * @return void
     */
    public function testGetFieldsExcludesFieldsSetViaWithoutFields(): void
    {
        $this->resolver->withoutFields(['email']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'email', 'status']);

        self::assertSame(['name', 'status'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that the fixed fields are appended even to an excluded field.
     *
     * @return void
     */
    public function testGetFieldsIncludesFixedFieldsAlways(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type', 'uuid']);

        $this->resolver->withoutFields(['id']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name']);

        self::assertSame(['name', 'id', '_type', 'uuid'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that duplicate fields from merging are removed.
     *
     * @return void
     */
    public function testGetFieldsDeduplicatesResult(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['id', 'name']);

        self::assertSame(['id', 'name'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that getFields returns a sequentially indexed list after
     * deduplication removes an interior duplicate.
     *
     * @return void
     */
    public function testGetFieldsReindexesAfterDeduplication(): void
    {
        $this->resolver->withFields(['name', 'id', 'name', 'email']);

        self::assertSame(['name', 'id', 'email'], $this->resolver->getFields(UserResource::class));
    }

    /**
     * Test that the selected fields drop exclusions and duplicates and never
     * gain the fixed fields.
     *
     * @return void
     */
    public function testGetSelectedFieldsOmitsExclusionsDuplicatesAndFixedFields(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type']);

        $this->resolver->withoutFields(['email']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'email', 'name', 'status']);

        self::assertSame(['name', 'status'], $this->resolver->getSelectedFields(UserResource::class));
    }

    /**
     * Test that the selected fields follow the defaults with nothing excluded.
     *
     * @return void
     */
    public function testGetSelectedFieldsReturnsTheDefaultsWithoutExclusions(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['_type']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertSame(['id', 'name', 'email'], $this->resolver->getSelectedFields(UserResource::class));
    }

    /**
     * Provide the selections and the fields each one resolves to.
     *
     * A null expectation stands for every field and the aggregates.
     *
     * @return iterable<string, array{array<int, string>|null, array<int, string>|null, bool, array<int, string>|null}>
     */
    public static function effectiveFieldsProvider(): iterable
    {
        yield 'defaults' => [null, null, false, ['id', 'name', 'email']];
        yield 'requested' => [['name', 'aggregates'], null, false, ['name', 'aggregates']];
        yield 'blank request' => [[''], null, false, ['']];
        yield 'requested all' => [['name', ':all'], null, false, null];
        yield 'all mode' => [['name'], null, true, null];
        yield 'pinned' => [['name'], ['email', 'aggregates'], true, ['email', 'aggregates']];
        yield 'pinned empty' => [[':all'], [], false, []];
    }

    /**
     * Test which fields a resource selects before exclusions and fixed fields.
     *
     * @param  array<int, string>|null  $requested
     * @param  array<int, string>|null  $pinned
     * @param  bool  $all
     * @param  array<int, string>|null  $expected
     * @return void
     */
    #[DataProvider('effectiveFieldsProvider')]
    public function testEffectiveFields(?array $requested, ?array $pinned, bool $all, ?array $expected): void
    {
        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn($requested);

        self::assertSame($expected ?? [...UserResource::getAllFields(), 'aggregates'], FieldResolver::effectiveFields(UserResource::class, $pinned, $all));
    }
}
