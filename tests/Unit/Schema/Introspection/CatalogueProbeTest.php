<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\Introspection\CatalogueProbe;
use Tests\Fixtures\Models\CatalogueRow;
use Tests\Fixtures\Models\UnconfiguredConnectionUser;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for explaining why a model's catalogue could not be read.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CatalogueProbe::class)]
final class CatalogueProbeTest extends TestCase
{
    /**
     * Test that a connection that cannot be built is named with its error.
     *
     * @return void
     */
    public function testNamesAConnectionThatCannotBeResolved(): void
    {
        self::assertSame(
            'connection "unconfigured" cannot be resolved: Database connection [unconfigured] not configured.',
            (new CatalogueProbe)->describe(new UnconfiguredConnectionUser),
        );
    }

    /**
     * Test that a failed catalogue read is named with its error, on the default
     * connection when the model names none.
     *
     * @return void
     */
    public function testNamesACatalogueReadThatFails(): void
    {
        $builder = self::createStub(SchemaBuilder::class);

        $builder->method('getColumns')->willThrowException(new \RuntimeException('Connection refused'));

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($builder);

        self::assertSame(
            'the catalogue of table "widgets" on connection "testing" cannot be read: Connection refused',
            (new CatalogueProbe)->describe($this->modelOn($connection)),
        );
    }

    /**
     * Test that a database file that does not exist fails the read rather than
     * resolving the connection.
     *
     * @return void
     */
    public function testNamesADatabaseFileThatDoesNotExistAsAnUnreadableCatalogue(): void
    {
        $this->useCatalogueConnection(['database' => sys_get_temp_dir() . '/api-toolkit-missing-' . uniqid('', true) . '.sqlite']);

        self::assertStringStartsWith(
            'the catalogue of table "catalogue_rows" on connection "catalogue" cannot be read: ',
            (new CatalogueProbe)->describe(new CatalogueRow),
        );
    }

    /**
     * Test that a table the connection does not carry is named as not found.
     *
     * @return void
     */
    public function testNamesATableThatWasNotFound(): void
    {
        $this->useCatalogueConnection();

        self::assertSame(
            'table "catalogue_rows" was not found on connection "catalogue"; it is not migrated, or not visible to this user',
            (new CatalogueProbe)->describe(new CatalogueRow),
        );
    }

    /**
     * Test that a readable catalogue leaves only the failed earlier read.
     *
     * @return void
     */
    public function testNamesAReadableCatalogueAsUnreadDuringValidation(): void
    {
        self::assertSame(
            'the catalogue of table "users" on connection "testing" could not be read during validation',
            (new CatalogueProbe)->describe(new User),
        );
    }

    /**
     * Test that a schema-qualified table is read in its own schema.
     *
     * @return void
     */
    public function testReadsASchemaQualifiedTableInItsSchema(): void
    {
        $this->useCatalogueConnection();
        $this->createTable('catalogue_rows');

        $probe = new CatalogueProbe;

        self::assertSame(
            'the catalogue of table "main.catalogue_rows" on connection "catalogue" could not be read during validation',
            $probe->describe($this->catalogueModel('main.catalogue_rows')),
        );

        self::assertSame(
            'table "main.missing_rows" was not found on connection "catalogue"; it is not migrated, or not visible to this user',
            $probe->describe($this->catalogueModel('main.missing_rows')),
        );
    }

    /**
     * Test that the connection's table prefix is applied to the read.
     *
     * @return void
     */
    public function testAppliesTheConnectionPrefixToTheRead(): void
    {
        $this->useCatalogueConnection(['prefix' => 'pre_']);
        $this->createTable('catalogue_rows');

        DB::connection('catalogue')->statement('create table plain_rows (id integer)');

        $probe = new CatalogueProbe;

        self::assertSame(
            'the catalogue of table "catalogue_rows" on connection "catalogue" could not be read during validation',
            $probe->describe(new CatalogueRow),
        );

        self::assertSame(
            'table "plain_rows" was not found on connection "catalogue"; it is not migrated, or not visible to this user',
            $probe->describe($this->catalogueModel('plain_rows')),
        );
    }

    /**
     * Point the catalogue connection at a fresh in-memory database.
     *
     * @param  array<string, string>  $config
     * @return void
     */
    private function useCatalogueConnection(array $config = []): void
    {
        Config::set('database.connections.catalogue', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', ...$config]);

        DB::purge('catalogue');

        $this->beforeApplicationDestroyed(static fn () => DB::purge('catalogue'));
    }

    /**
     * Create a minimal table on the catalogue connection.
     *
     * @param  string  $table
     * @return void
     */
    private function createTable(string $table): void
    {
        Schema::connection('catalogue')->create($table, static function (Blueprint $blueprint): void {
            $blueprint->id();
        });
    }

    /**
     * Build a model reading the given table through the catalogue connection.
     *
     * @param  string  $table
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function catalogueModel(string $table): Model
    {
        $model = new CatalogueRow;

        $model->setTable($table);

        return $model;
    }

    /**
     * Build a model reading the widgets table through the given connection.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelOn(Connection $connection): Model
    {
        $model = new #[Table('widgets')] class extends Model {
            /** @var \Illuminate\Database\Connection|null The connection this model reads its schema from */
            public ?Connection $reader = null;

            /**
             * Return the connection the model reads its schema from.
             *
             * @return \Illuminate\Database\Connection
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            #[\Override]
            public function getConnection(): Connection
            {
                assert($this->reader instanceof Connection);

                return $this->reader;
            }
        };

        $model->reader = $connection;

        return $model;
    }
}
