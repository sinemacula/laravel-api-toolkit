<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use Tests\TestCase;

/**
 * Index eligibility inspector tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
final class IndexEligibilityInspectorTest extends TestCase
{
    /** @var array<int, string> The statements the connection was asked for */
    private array $statements = [];

    /** @var array<int, array<int, mixed>> The bindings each statement carried */
    private array $bindings = [];

    /**
     * Test that an engine the inspector cannot question is asked nothing and
     * reports nothing.
     *
     * A reader over such an engine has to be left exactly as strict as it was,
     * rather than refusing every index it cannot get an answer about.
     *
     * @return void
     */
    public function testAnEngineItCannotQuestionIsAskedNothing(): void
    {
        $eligibility = (new IndexEligibilityInspector)->inspect('users', $this->connection('sqlite'));

        self::assertSame([], $this->statements);
        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that a connection naming no engine at all is asked nothing.
     *
     * @return void
     */
    public function testAConnectionNamingNoEngineIsAskedNothing(): void
    {
        $eligibility = (new IndexEligibilityInspector)->inspect('users', $this->connection(null));

        self::assertSame([], $this->statements);
        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that an engine keeping index statistics is read for the two facts it
     * can answer.
     *
     * @return void
     */
    public function testReadsTheFactsAnEngineKeepingStatisticsCanAnswer(): void
    {
        $connection = $this->connection('mysql', [
            (object) ['name' => 'users_hidden_index', 'disregarded' => 1, 'expressed' => 0],
            (object) ['name' => 'users_lower_index', 'disregarded' => 0, 'expressed' => 1],
            (object) ['name' => 'users_name_index', 'disregarded' => 0, 'expressed' => 0],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->disregards('users_hidden_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));
        self::assertTrue($eligibility->describes('users_name_index'));
        self::assertFalse($eligibility->restricts('users_hidden_index'));

        self::assertStringContainsString('information_schema.statistics', $this->statements[0]);
        self::assertSame([[null, 'users']], $this->bindings);
    }

    /**
     * Test that an engine keeping an index catalogue is read for all three
     * facts.
     *
     * @return void
     */
    public function testReadsTheFactsAnEngineKeepingACatalogueCanAnswer(): void
    {
        $connection = $this->connection('pgsql', [
            (object) ['name' => 'users_invalid_index', 'disregarded' => 1, 'restricted' => 0, 'expressed' => 0],
            (object) ['name' => 'users_live_index', 'disregarded' => 0, 'restricted' => 1, 'expressed' => 0],
            (object) ['name' => 'users_lower_index', 'disregarded' => 0, 'restricted' => 0, 'expressed' => 1],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->disregards('users_invalid_index'));
        self::assertTrue($eligibility->restricts('users_live_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));

        self::assertStringContainsString('pg_index', $this->statements[0]);
    }

    /**
     * Test that the read is scoped to the prefixed table and the schema the
     * reference names.
     *
     * The catalogue holds the table under the name it was created with, which
     * carries the connection's prefix the model's own name does not.
     *
     * @return void
     */
    public function testScopesTheReadToThePrefixedTableAndItsSchema(): void
    {
        (new IndexEligibilityInspector)->inspect('reporting.users', $this->connection('pgsql', [], 'api_'));

        self::assertSame([['reporting', 'api_users']], $this->bindings);
    }

    /**
     * Test that a read the engine refuses reports nothing rather than refusing
     * every index.
     *
     * The column naming these facts is not offered by every server the
     * inspector is reachable on, and a read that fails there must not take a
     * proof down with it.
     *
     * @return void
     */
    public function testAReadTheEngineRefusesReportsNothing(): void
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getDriverName')->willReturn('mysql');
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('selectFromWriteConnection')->willThrowException(
            new QueryException('mysql', 'select', [], new \RuntimeException('access denied')),
        );

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that a row the engine returns without a readable name is passed over
     * rather than stopping the read.
     *
     * @return void
     */
    public function testReadsPastARowWithNoReadableName(): void
    {
        $connection = $this->connection('mysql', [
            (object) ['name' => null, 'disregarded' => 1, 'expressed' => 0],
            (object) ['name' => 'users_hidden_index', 'disregarded' => 1, 'expressed' => 0],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->disregards('users_hidden_index'));
    }

    /**
     * Test that a flag is read as the number it spells rather than as a
     * non-empty string.
     *
     * A driver may hand back a number as text, and any label would be truthy
     * read as one, so the negative answer has to survive the journey.
     *
     * @return void
     */
    public function testReadsAFlagAsTheNumberItSpells(): void
    {
        $connection = $this->connection('mysql', [
            (object) ['name' => 'users_name_index', 'disregarded' => '0', 'expressed' => '0'],
            (object) ['name' => 'users_hidden_index', 'disregarded' => '1', 'expressed' => '0'],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->describes('users_name_index'));
        self::assertTrue($eligibility->disregards('users_hidden_index'));
    }

    /**
     * Build a connection naming the given engine and returning the given rows.
     *
     * @param  string|null  $driver
     * @param  array<int, object>  $rows
     * @param  string  $prefix
     * @return \Illuminate\Database\Connection
     */
    private function connection(?string $driver, array $rows = [], string $prefix = ''): Connection
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getDriverName')->willReturn($driver);
        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('selectFromWriteConnection')->willReturnCallback(function (string $query, array $bindings = []) use ($rows): array {

            $this->statements[] = $query;
            $this->bindings[]   = $bindings;

            return $rows;
        });

        return $connection;
    }
}
