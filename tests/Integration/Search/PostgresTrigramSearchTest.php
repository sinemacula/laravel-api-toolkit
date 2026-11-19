<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;

/**
 * Search integration suite for the PostgreSQL driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PostgresTrigramSearchDriver::class)]
#[Group('pgsql')]
final class PostgresTrigramSearchTest extends EngineSearchTestCase
{
    /**
     * Test that validation and the request-time proof refuse every trigram
     * declaration once the extension is gone.
     *
     * @return void
     */
    public function testTheDeclarationIsRefusedWithoutTheTrigramExtension(): void
    {
        DB::statement('drop extension pg_trgm cascade');

        try {
            self::assertFalse($this->serves());

            $this->assertValidationRefuses(
                SearchableFilterableUserResource::class,
                'The "substring" strategy is served by the pg_trgm extension, and that extension is not installed on this connection',
            );
        } finally {
            DB::statement('create extension pg_trgm');
            $this->createAnywhereMatchIndex();
            $this->createPrefixMatchIndex();
        }
    }

    /**
     * Assert that the engine can answer the query from an index.
     *
     * The seeded table is small enough that a scan always wins on cost, so the
     * scan is penalised to ask only whether an index can serve the predicate.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @return void
     */
    #[\Override]
    protected function assertIndexBacked(Builder $query): void
    {
        DB::statement('set enable_seqscan = off');

        try {
            $plan = $this->plan($query, 'QUERY PLAN');
        } finally {
            DB::statement('set enable_seqscan = on');
        }

        self::assertStringNotContainsString('Seq Scan on users', $plan);
        self::assertStringContainsString('Index Scan', $plan);
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
     * Return the rows the anywhere-match finds for "ann smith".
     *
     * The pattern matches the term's characters, whitespace included.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function multiWordMatches(): array
    {
        return ['Ann Smith', 'ANN SMITH'];
    }

    /**
     * Return, keyed by term, the rows each accented or upper-case term finds.
     *
     * The case-insensitive operator folds every letter's case but no accent.
     *
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function foldedMatches(): array
    {
        return [
            'élise' => ['Élise Müller'],
            'ÉLISE' => ['Élise Müller'],
            'ELISE' => ['Elise Martin'],
        ];
    }

    /**
     * Return the rows an equality match finds for "smith" beside "Smith".
     *
     * The column's deterministic collation compares case.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function caseFoldedEqualityMatches(): array
    {
        return [];
    }

    /**
     * Drop the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    #[\Override]
    protected function dropAnywhereMatchIndex(): void
    {
        DB::statement('drop index users_name_trgm');
    }

    /**
     * Recreate the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    #[\Override]
    protected function createAnywhereMatchIndex(): void
    {
        DB::statement('create index users_name_trgm on users using gin (name gin_trgm_ops)');
    }

    /**
     * Return the defect reported once that index is gone.
     *
     * @return string
     */
    #[\Override]
    protected function anywhereMatchDefect(): string
    {
        return 'Column "name" is declared searchable with the "substring" strategy, which needs a trigram index over that column on table "users"';
    }

    /**
     * Replace the trigram index with a partial one, which an unqualified match
     * cannot use.
     *
     * @return void
     */
    #[\Override]
    protected function makeAnywhereMatchIndexUnusable(): void
    {
        $this->dropAnywhereMatchIndex();

        DB::statement('create index users_name_trgm on users using gin (name gin_trgm_ops) where name is not null');
    }

    /**
     * Restore the unqualified trigram index.
     *
     * @return void
     */
    #[\Override]
    protected function makeAnywhereMatchIndexUsable(): void
    {
        $this->dropAnywhereMatchIndex();
        $this->createAnywhereMatchIndex();
    }

    /**
     * Drop the index serving the leading match on the searched column.
     *
     * This engine reads a leading match from the trigram index, not the
     * ordinary one.
     *
     * @return void
     */
    #[\Override]
    protected function dropPrefixMatchIndex(): void
    {
        DB::statement('drop index users_email_trgm');
    }

    /**
     * Recreate the index serving the leading match on the searched column.
     *
     * @return void
     */
    #[\Override]
    protected function createPrefixMatchIndex(): void
    {
        DB::statement('create index users_email_trgm on users using gin (email gin_trgm_ops)');
    }

    /**
     * Return the defect the leading match draws once that index is gone.
     *
     * @return string
     */
    #[\Override]
    protected function prefixMatchDefect(): string
    {
        return 'Column "email" is declared searchable with the "prefix" strategy, which needs a trigram index over that column on table "users"';
    }

    /**
     * Drop the ordinary index serving the equality match.
     *
     * @return void
     */
    #[\Override]
    protected function dropEqualityMatchIndex(): void
    {
        DB::statement('drop index users_name_index');
    }

    /**
     * Recreate the ordinary index serving the equality match.
     *
     * @return void
     */
    #[\Override]
    protected function createEqualityMatchIndex(): void
    {
        DB::statement('create index users_name_index on users (name)');
    }

    /**
     * Replace the ordinary index with a partial one the query cannot use.
     *
     * @return void
     */
    #[\Override]
    protected function makeEqualityMatchIndexUnusable(): void
    {
        DB::statement('drop index users_name_index');
        DB::statement('create index users_name_index on users (name) where name is not null');
    }

    /**
     * Restore the unqualified index.
     *
     * @return void
     */
    #[\Override]
    protected function makeEqualityMatchIndexUsable(): void
    {
        DB::statement('drop index users_name_index');
        DB::statement('create index users_name_index on users (name)');
    }
}
