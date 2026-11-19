<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;
use Tests\Fixtures\Models\DatabaseQualifiedUser;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;

/**
 * Tenant isolation suite for the MySQL engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
#[CoversClass(MySqlNgramSearchDriver::class)]
#[CoversClass(SchemaIntrospector::class)]
#[Group('mysql')]
final class MySqlTenantSchemaIsolationTest extends TenantSchemaIsolationTestCase
{
    /**
     * Test that a prefixed connection proves its own table's n-gram index.
     *
     * The parser is read from the table definition, which must name the
     * prefixed table.
     *
     * @return void
     */
    public function testAPrefixedConnectionProvesItsOwnTablesNgramIndex(): void
    {
        DB::connection('tenant')->statement('alter table `tenant_widgets` add fulltext index `tenant_widgets_label_ngram` (`label`) with parser ngram');

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['label'], 'widgets', DB::connection('tenant')));
    }

    /**
     * Test that a table named through its database resolves its index facts,
     * its sort backing and its search proof.
     *
     * @return void
     */
    public function testATableNamedThroughItsDatabaseResolvesItsCatalogue(): void
    {
        DB::statement('alter table `users` add index `users_status_hidden` (`status`) invisible');

        $table = (new DatabaseQualifiedUser)->getTable();

        self::assertTrue((new IndexEligibilityInspector)->inspect($table, DB::connection())->disregards('users_status_hidden'));
        self::assertSame([], $this->defects([DatabaseQualifiedUser::class => SearchableFilterableUserResource::class]));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'mysql';
    }
}
