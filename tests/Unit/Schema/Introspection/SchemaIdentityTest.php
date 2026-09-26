<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIdentity;
use Tests\TestCase;

/**
 * Tests for the schema identity of a connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaIdentity::class)]
final class SchemaIdentityTest extends TestCase
{
    /** @var array<string, mixed> The configuration every variation departs from. */
    private const array BASE = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    /**
     * Test that the identity names the connection and stays the same for the
     * same connection, so every process reading one schema shares its keys.
     *
     * @return void
     */
    public function testIdentityNamesTheConnectionAndIsStable(): void
    {
        $connection = $this->connection('tenant', self::BASE);

        $identity = SchemaIdentity::of($connection);

        self::assertMatchesRegularExpression('/^tenant@[0-9a-f]{32}$/', $identity);
        self::assertSame($identity, SchemaIdentity::of($connection));
        self::assertSame($identity, SchemaIdentity::of($this->connection('tenant', self::BASE)));
    }

    /**
     * Test that the name is part of the hashed identity as well as its label,
     * so two names over the same schema never share a key.
     *
     * @return void
     */
    public function testTheNameIsPartOfTheHashedIdentity(): void
    {
        self::assertNotSame(
            substr(SchemaIdentity::of($this->connection('tenant_a', self::BASE)), strlen('tenant_a')),
            substr(SchemaIdentity::of($this->connection('tenant_b', self::BASE)), strlen('tenant_b')),
        );
    }

    /**
     * Provide one change per part of the connection the identity follows.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function schemaChanges(): iterable
    {
        yield 'database' => [['database' => 'tenant_b']];
        yield 'table prefix' => [['prefix' => 'tenant_b_']];
        yield 'search path' => [['search_path' => 'tenant_b']];
        yield 'schema' => [['schema' => 'tenant_b']];
        yield 'database user' => [['username' => 'tenant_b']];
    }

    /**
     * Test that two connections sharing a name but reading different schemas
     * have distinct identities, whichever part of the connection differs.
     *
     * @param  array<string, mixed>  $change
     * @return void
     */
    #[DataProvider('schemaChanges')]
    public function testConnectionsSharingANameButNotASchemaDiffer(array $change): void
    {
        self::assertNotSame(
            SchemaIdentity::of($this->connection('tenant', self::BASE)),
            SchemaIdentity::of($this->connection('tenant', [...self::BASE, ...$change])),
        );
    }

    /**
     * Provide the setters a switcher can repoint a resolved connection with.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function liveSwitches(): iterable
    {
        yield 'database' => ['setDatabaseName'];
        yield 'table prefix' => ['setTablePrefix'];
    }

    /**
     * Test that a connection is described by its own state, so a switcher that
     * repoints it without touching configuration still moves the identity.
     *
     * @param  string  $setter
     * @return void
     */
    #[DataProvider('liveSwitches')]
    public function testRepointingAResolvedConnectionChangesTheIdentity(string $setter): void
    {
        $connection = $this->connection('tenant', self::BASE);
        $before     = SchemaIdentity::of($connection);

        $connection->{$setter}('tenant_b'); // @phpstan-ignore method.dynamicName

        self::assertNotSame($before, SchemaIdentity::of($connection));
    }

    /**
     * Test that a connection configured by URL is identified by the database
     * the URL names, as the framework built it.
     *
     * @return void
     */
    public function testAUrlConfiguredConnectionIsIdentifiedByItsDatabase(): void
    {
        $explicit = SchemaIdentity::of($this->connection('tenant', ['driver' => 'pgsql', 'database' => 'tenant_a', 'username' => 'user', 'prefix' => '']));
        $urlA     = SchemaIdentity::of($this->connection('tenant', ['url' => 'pgsql://user:secret@db.internal:5432/tenant_a']));
        $urlB     = SchemaIdentity::of($this->connection('tenant', ['url' => 'pgsql://user:secret@db.internal:5432/tenant_b']));

        self::assertSame($explicit, $urlA);
        self::assertNotSame($urlA, $urlB);
    }

    /**
     * Test that a read and write connection whose database is set only under
     * its write options is identified by that database.
     *
     * @return void
     */
    public function testAReadWriteConnectionIsIdentifiedByItsWriteDatabase(): void
    {
        $split = static fn (string $database): array => [
            'driver' => 'pgsql',
            'prefix' => '',
            'read'   => ['host' => ['replica.internal']],
            'write'  => ['host' => ['primary.internal'], 'database' => $database],
        ];

        $tenantA = $this->connection('tenant', $split('tenant_a'));

        self::assertSame('tenant_a', $tenantA->getDatabaseName());
        self::assertSame(
            SchemaIdentity::of($this->connection('tenant', ['driver' => 'pgsql', 'database' => 'tenant_a', 'prefix' => ''])),
            SchemaIdentity::of($tenantA),
        );
        self::assertNotSame(SchemaIdentity::of($tenantA), SchemaIdentity::of($this->connection('tenant', $split('tenant_b'))));
    }

    /**
     * Test that a read alias of a connection is identified apart from the
     * connection itself.
     *
     * The alias reports the name, database, and options of its write side while
     * reading the catalogue of the server behind its read side, so sharing the
     * write side's identity would serve one server's schema for the other.
     *
     * @return void
     */
    public function testAReadAliasIsIdentifiedApartFromItsConnection(): void
    {
        Config::set('database.connections.tenant', [
            ...self::BASE,
            'read'  => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
        ]);

        DB::purge('tenant');

        $write = DB::connection('tenant');
        $read  = DB::connection('tenant::read');

        self::assertInstanceOf(Connection::class, $read);
        self::assertStringStartsWith('tenant::read@', SchemaIdentity::of($read));
        self::assertNotSame(SchemaIdentity::of($write), SchemaIdentity::of($read));
    }

    /**
     * Build the named connection from the given configuration, running no
     * query.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $config
     * @return \Illuminate\Database\Connection
     */
    private function connection(string $name, array $config): Connection
    {
        Config::set('database.connections.' . $name, $config);

        DB::purge($name);

        return DB::connection($name);
    }
}
