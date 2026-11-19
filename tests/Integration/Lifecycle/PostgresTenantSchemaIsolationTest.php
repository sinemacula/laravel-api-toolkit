<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use SineMacula\ApiToolkit\Search\IndexProof;
use Tests\Fixtures\Models\ReportingEvent;
use Tests\Fixtures\Models\TenantWidget;
use Tests\Fixtures\Resources\ReportingEventResource;

/**
 * Tenant isolation suite for the PostgreSQL engine.
 *
 * Schemas are created outside a transaction, so the tenant connection's own
 * session can see them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
#[CoversClass(PostgresTrigramSearchDriver::class)]
#[CoversClass(SchemaIntrospector::class)]
#[Group('pgsql')]
final class PostgresTenantSchemaIsolationTest extends TenantSchemaIsolationTestCase
{
    /** @var string The defect a trigram declaration over an unusable index draws */
    private const string TRIGRAM_DEFECT = 'Column "label" is declared searchable with the "substring" strategy, '
        . 'which needs a trigram index over that column on table "widgets"';

    /**
     * Drop the schemas a test created.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === $this->engine()) {
            DB::statement('drop schema if exists tenant_a, tenant_b, reporting cascade');
        }

        parent::tearDown();
    }

    /**
     * Leave the schemas a test creates visible to the tenant connection.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that a prefixed connection proves its own table's trigram index.
     *
     * @return void
     */
    public function testAPrefixedConnectionProvesItsOwnTablesTrigramIndex(): void
    {
        DB::connection('tenant')->statement('create index tenant_widgets_label_trgm on tenant_widgets using gin (label gin_trgm_ops)');

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['label'], 'widgets', DB::connection('tenant')));
    }

    /**
     * Test that switching the search path reads each tenant's own columns,
     * indexes, index facts and search proof, carrying nothing over.
     *
     * Neither tenant's path reaches the schema holding the trigram extension.
     *
     * @return void
     */
    public function testASearchPathSwitchReadsEachTenantsOwnSchema(): void
    {
        DB::statement('create schema tenant_a');
        DB::statement('create table tenant_a.widgets (id bigserial primary key, label varchar(255) not null)');
        DB::statement('create index widgets_label_trgm on tenant_a.widgets using gin (label gin_trgm_ops)');
        DB::statement('create schema tenant_b');
        DB::statement('create table tenant_b.widgets (id bigserial primary key, label varchar(255) not null, title varchar(255))');
        DB::statement('create index widgets_label_trgm on tenant_b.widgets using gin (label gin_trgm_ops) where label <> \'\'');
        DB::statement('create index widgets_title_index on tenant_b.widgets (title)');

        $this->useTenantConnection(['search_path' => 'tenant_a']);

        self::assertSame(['id', 'label'], $this->introspector()->getColumns(new TenantWidget));
        self::assertEqualsCanonicalizing(['widgets_pkey', 'widgets_label_trgm'], $this->indexNames());
        self::assertFalse((new IndexEligibilityInspector)->inspect('widgets', DB::connection('tenant'))->restricts('widgets_label_trgm'));
        self::assertSame([], $this->trigramProof());

        $this->useTenantConnection(['search_path' => 'tenant_b']);

        self::assertSame(['id', 'label', 'title'], $this->introspector()->getColumns(new TenantWidget));
        self::assertEqualsCanonicalizing(['widgets_pkey', 'widgets_label_trgm', 'widgets_title_index'], $this->indexNames());
        self::assertTrue((new IndexEligibilityInspector)->inspect('widgets', DB::connection('tenant'))->restricts('widgets_label_trgm'));
        self::assertSame([self::TRIGRAM_DEFECT], $this->trigramProof());
    }

    /**
     * Test that a model naming its table through another schema is narrowed and
     * validated against that schema's table.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testATableNamedThroughAnotherSchemaIsNarrowedAndValidated(): void
    {
        DB::statement('create schema reporting');
        DB::statement('create table reporting.events (id bigserial primary key, title varchar(255) not null, body text)');
        DB::statement('create index events_title_index on reporting.events (title)');

        Config::set(ResourceDiscovery::RESOURCES_KEY, [ReportingEventResource::class]);

        ApiQuery::parse(Request::create('/events', 'GET', ['fields' => [ReportingEventResource::RESOURCE_TYPE => 'title']]));

        assert($this->app !== null);

        $query = $this->app->make(ApiCriteria::class)->usingResource(ReportingEventResource::class)->apply(new ReportingEvent);

        self::assertEqualsCanonicalizing(['reporting.events.id', 'reporting.events.title'], $query->getQuery()->columns);
        self::assertSame([], $this->defects([ReportingEvent::class => ReportingEventResource::class]));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'pgsql';
    }

    /**
     * Return the names of the indexes the tenant's widgets table carries.
     *
     * @return array<int, string>
     */
    private function indexNames(): array
    {
        return array_map(
            static fn (IndexDefinition $index): string => $index->name,
            $this->introspector()->getIndexes(new TenantWidget) ?? [],
        );
    }

    /**
     * Take the request-time proof for an anywhere-match on the tenant's label.
     *
     * @return array<int, string>
     */
    private function trigramProof(): array
    {
        assert($this->app !== null);

        return $this->app->make(IndexProof::class)->defects(
            new PostgresTrigramSearchDriver,
            SearchStrategy::SUBSTRING,
            ['label'],
            'widgets',
            DB::connection('tenant'),
        );
    }
}
