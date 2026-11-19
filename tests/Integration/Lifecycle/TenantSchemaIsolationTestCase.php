<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Tests\Fixtures\Models\TenantWidget;
use Tests\Fixtures\Resources\TenantWidgetResource;
use Tests\TestCase;

/**
 * Shared suite proving each catalogue read answers for the table a tenant's
 * connection actually reaches.
 *
 * The tenant connection reaches the default database under a table prefix, so
 * an unprefixed read would find no table at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TenantSchemaIsolationTestCase extends TestCase
{
    /** @var string The prefix the tenant connection puts before every table */
    protected const string PREFIX = 'tenant_';

    /**
     * Build the prefixed widgets table, skipping other engines.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== $this->engine()) {
            static::markTestSkipped(sprintf('The %s tenant isolation suite runs against its own engine only.', $this->engine()));
        }

        $this->useTenantConnection(['prefix' => self::PREFIX]);

        Schema::connection('tenant')->dropIfExists('widgets');
        Schema::connection('tenant')->create('widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('body')->nullable();
            $table->index('label', self::PREFIX . 'widgets_label_index');
        });
    }

    /**
     * Drop the prefixed table and close the tenant connection.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === $this->engine()) {
            $this->useTenantConnection(['prefix' => self::PREFIX]);

            Schema::connection('tenant')->dropIfExists('widgets');
            DB::purge('tenant');
        }

        parent::tearDown();
    }

    /**
     * Test that a prefixed connection reads its own table's columns and
     * indexes, and backs a sort declaration from them.
     *
     * @return void
     */
    public function testAPrefixedConnectionReadsItsOwnTable(): void
    {
        $introspector = $this->introspector();

        static::assertSame(['id', 'label', 'body'], $introspector->getColumns(new TenantWidget));
        static::assertContains(self::PREFIX . 'widgets_label_index', array_map(
            static fn (IndexDefinition $index): string => $index->name,
            $introspector->getIndexes(new TenantWidget) ?? [],
        ));
        static::assertSame([], $this->defects([TenantWidget::class => TenantWidgetResource::class]));
    }

    /**
     * Test that a prefixed connection reports its own table's index facts.
     *
     * @return void
     */
    public function testAPrefixedConnectionReportsItsOwnTablesIndexFacts(): void
    {
        DB::connection('tenant')->statement('create index tenant_widgets_label_lower on tenant_widgets ((lower(label)))');

        static::assertTrue((new IndexEligibilityInspector)->inspect('widgets', DB::connection('tenant'))->keysAnExpression('tenant_widgets_label_lower'));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Repoint the tenant connection at the default database with the given
     * settings, as a tenancy switcher does between jobs.
     *
     * @param  array<string, string>  $settings
     * @return void
     */
    protected function useTenantConnection(array $settings): void
    {
        $default = Config::get('database.connections.testing');

        assert(is_array($default));

        Config::set('database.connections.tenant', [...$default, ...$settings]);

        DB::purge('tenant');
    }

    /**
     * Resolve the schema introspector the toolkit reads the catalogue through.
     *
     * @return \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider
     */
    protected function introspector(): SchemaIntrospectionProvider
    {
        assert($this->app !== null);

        return $this->app->make(SchemaIntrospectionProvider::class);
    }

    /**
     * Validate the resource map and return its defects, if any.
     *
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $map
     * @return array<int, string>
     */
    protected function defects(array $map): array
    {
        assert($this->app !== null);

        try {
            $this->app->make(SchemaValidator::class)->validate($map);
        } catch (InvalidSchemaException $exception) {

            return array_values(array_map(
                static fn (SchemaValidationError $error): string => $error->defect,
                $exception->getErrors(),
            ));
        }

        return [];
    }
}
