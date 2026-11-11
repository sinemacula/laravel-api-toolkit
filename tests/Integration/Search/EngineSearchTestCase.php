<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\EqualitySearchableUserResource;
use Tests\Fixtures\Resources\PrefixSearchableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\TestCase;

/**
 * Shared search integration suite for an engine that indexes what it declares.
 *
 * Rows are committed, not rolled back: one engine updates its full-text index
 * only when a transaction commits.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineSearchTestCase extends TestCase
{
    /** @var string The command validating the declared search surface */
    private const string COMMAND = 'api-toolkit:validate-schemas';

    /** @var string The defect an equality declaration draws once the ordinary index leading with its column is gone, which reads the same on either engine */
    private const string EQUALITY_DEFECT = 'Column "name" is declared searchable with the "exact" strategy, '
        . 'which needs an index leading with that column on table "users"';

    /**
     * Seed the rows every test searches, skipping other engines.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== $this->engine()) {
            static::markTestSkipped(sprintf('The %s search suite runs against its own engine only.', $this->engine()));
        }

        Config::set('api-toolkit.resources.resource_map', [
            User::class => SearchableFilterableUserResource::class,
        ]);

        User::create(['name' => 'Highsmith', 'email' => 'highsmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Blacksmith', 'email' => 'blacksmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Goldsmith', 'email' => 'goldsmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Smith', 'email' => 'smith@example.com', 'status' => 'active']);
        User::create(['name' => 'Jones', 'email' => 'jonathan@example.com', 'status' => 'inactive']);
    }

    /**
     * Remove the committed rows before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === $this->engine()) {
            DB::table('users')->delete();
        }

        parent::tearDown();
    }

    /**
     * Leave the rows a test writes committed.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that a term matches inside a value, not only at its start.
     *
     * @return void
     */
    public function testAnywhereMatchFindsTheTermInsideALongerValue(): void
    {
        static::assertEqualsCanonicalizing(
            ['Highsmith', 'Blacksmith', 'Goldsmith', 'Smith'],
            $this->search('smith'),
        );
    }

    /**
     * Test that a prefix-strategy column matches from the start of its value.
     *
     * @return void
     */
    public function testPrefixMatchFindsTheTermAtTheStartOfItsOwnColumn(): void
    {
        static::assertSame(['Jones'], $this->search('jonat', PrefixSearchableUserResource::class));
    }

    /**
     * Test that a prefix match does not find the term inside a value.
     *
     * Every seeded address carries the term, so an anywhere-match would return
     * them all.
     *
     * @return void
     */
    public function testPrefixMatchDoesNotFindTheTermInsideAValue(): void
    {
        static::assertSame([], $this->search('example', PrefixSearchableUserResource::class));
    }

    /**
     * Test that a prefix match finds a value whose case differs from the term.
     *
     * One engine folds case in the emitted operator, the other in the column's
     * collation; both must answer the same rows.
     *
     * @return void
     */
    public function testPrefixMatchFindsAValueWhoseCaseDiffersFromTheTerm(): void
    {
        User::create(['name' => 'Ivory', 'email' => 'Ivory@example.com', 'status' => 'active']);

        static::assertSame(['Ivory'], $this->search('ivory', PrefixSearchableUserResource::class));
    }

    /**
     * Test that an equality-strategy column matches its whole value only.
     *
     * Matching at all also proves the catalogue reports the ordinary index the
     * declaration needs.
     *
     * @return void
     */
    public function testEqualityMatchFindsTheWholeValueAndNoLongerOne(): void
    {
        User::create(['name' => 'Smithson', 'email' => 'smithson@example.com', 'status' => 'active']);

        static::assertSame(['Smith'], $this->search('Smith', EqualitySearchableUserResource::class));
    }

    /**
     * Test that a term matching nothing returns nothing, not the whole table.
     *
     * @return void
     */
    public function testATermMatchingNothingReturnsNothing(): void
    {
        static::assertSame([], $this->search('wright'));
    }

    /**
     * Test that a wildcard carried by the term matches itself, not every row.
     *
     * Whether the engine honours the ESCAPE clause is what compiled SQL cannot
     * settle.
     *
     * @return void
     */
    public function testAWildcardInTheTermMatchesItself(): void
    {
        User::create(['name' => 'Wild', 'email' => 'a%b@example.com', 'status' => 'active']);
        User::create(['name' => 'Decoy', 'email' => 'axb@example.com', 'status' => 'active']);

        static::assertSame(['Wild'], $this->search('a%b', PrefixSearchableUserResource::class));
    }

    /**
     * Test that the escape character in the term matches literally.
     *
     * It is doubled before it reaches the pattern, so the engine must read the
     * pair as the character.
     *
     * @return void
     */
    public function testTheEscapeCharacterInTheTermMatchesItself(): void
    {
        User::create(['name' => 'Banged', 'email' => 'c!d@example.com', 'status' => 'active']);
        User::create(['name' => 'Plain', 'email' => 'cd@example.com', 'status' => 'active']);

        static::assertSame(['Banged'], $this->search('c!d', PrefixSearchableUserResource::class));
    }

    /**
     * Test that the anywhere-match is answered from an index.
     *
     * A table scan returns the same rows, so the rows alone cannot show it.
     *
     * @return void
     */
    public function testTheAnywhereMatchIsAnsweredFromAnIndex(): void
    {
        $this->assertIndexBacked($this->searchQuery('smith'));
    }

    /**
     * Test that the anywhere-match keeps its index beside an ANDed filter.
     *
     * @return void
     */
    public function testTheAnywhereMatchKeepsItsIndexBesideAFilter(): void
    {
        $this->assertIndexBacked($this->searchQuery('smith')->where('status', 'active'));
    }

    /**
     * Test that the declared search surface passes live-catalogue validation.
     *
     * @return void
     */
    public function testValidationAcceptsTheDeclaredSearchSurface(): void
    {
        $this->assertValidationAccepts(SearchableFilterableUserResource::class);
    }

    /**
     * Test that validation refuses the declaration once its index is gone.
     *
     * @return void
     */
    public function testValidationRefusesTheDeclarationWithoutItsIndex(): void
    {
        $this->dropAnywhereMatchIndex();

        try {
            $this->assertValidationRefuses(SearchableFilterableUserResource::class, $this->anywhereMatchDefect());
        } finally {
            $this->createAnywhereMatchIndex();
        }
    }

    /**
     * Test that a leading-match declaration passes live-catalogue validation.
     *
     * The engines answer it from different index kinds: ordinary on one,
     * trigram on the other.
     *
     * @return void
     */
    public function testValidationAcceptsThePrefixSearchSurface(): void
    {
        $this->assertValidationAccepts(PrefixSearchableUserResource::class);
    }

    /**
     * Test that validation refuses a leading-match declaration once unindexed.
     *
     * @return void
     */
    public function testValidationRefusesThePrefixDeclarationWithoutItsIndex(): void
    {
        $this->dropPrefixMatchIndex();

        try {
            $this->assertValidationRefuses(PrefixSearchableUserResource::class, $this->prefixMatchDefect());
        } finally {
            $this->createPrefixMatchIndex();
        }
    }

    /**
     * Test that an equality declaration passes live-catalogue validation.
     *
     * @return void
     */
    public function testValidationAcceptsTheEqualitySearchSurface(): void
    {
        $this->assertValidationAccepts(EqualitySearchableUserResource::class);
    }

    /**
     * Test that validation refuses the equality declaration without its index.
     *
     * The refusal reads the same on either engine.
     *
     * @return void
     */
    public function testValidationRefusesTheEqualityDeclarationWithoutItsIndex(): void
    {
        $this->dropEqualityMatchIndex();

        try {
            $this->assertValidationRefuses(EqualitySearchableUserResource::class, self::EQUALITY_DEFECT);
        } finally {
            $this->createEqualityMatchIndex();
        }
    }

    /**
     * Test that validation refuses an equality index the planner will not use.
     *
     * The catalogue names such an index exactly as it names a usable one.
     *
     * @return void
     */
    public function testValidationRefusesTheEqualityDeclarationWhenItsIndexIsUnusable(): void
    {
        $this->makeEqualityMatchIndexUnusable();

        try {
            $this->assertValidationRefuses(EqualitySearchableUserResource::class, self::EQUALITY_DEFECT);
        } finally {
            $this->makeEqualityMatchIndexUsable();
        }
    }

    /**
     * Test that invalidating the metadata retires a request-time refusal.
     *
     * @return void
     */
    public function testARefusalIsRetiredByInvalidatingTheMetadata(): void
    {
        $this->dropAnywhereMatchIndex();

        try {
            static::assertFalse($this->serves());
        } finally {
            $this->createAnywhereMatchIndex();
        }

        $this->crossBoundary();

        static::assertFalse($this->serves());

        $this->cacheManager()->invalidateMetadata();

        static::assertTrue($this->serves());
    }

    /**
     * Test that a search in a later operation reads no catalogue.
     *
     * @return void
     */
    public function testALaterOperationReadsNoCatalogue(): void
    {
        static::assertTrue($this->serves());

        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->crossBoundary();

        static::assertTrue($this->serves());
        static::assertSame([], $statements);
    }

    /**
     * Test that an index dropped outside a migration is refused on expiry.
     *
     * @return void
     */
    public function testAnIndexDroppedOutsideAMigrationIsRefusedOnceTheAnswerExpires(): void
    {
        static::assertTrue($this->serves());

        $this->dropAnywhereMatchIndex();

        try {
            $this->crossBoundary();

            static::assertTrue($this->serves());

            $this->travel(IndexProof::DEFAULT_TTL + 1)->seconds();
            $this->crossBoundary();

            static::assertFalse($this->serves());
        } finally {
            $this->createAnywhereMatchIndex();
        }
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Assert that the engine answers the query from an index.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @return void
     */
    abstract protected function assertIndexBacked(Builder $query): void;

    /**
     * Drop the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    abstract protected function dropAnywhereMatchIndex(): void;

    /**
     * Recreate the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    abstract protected function createAnywhereMatchIndex(): void;

    /**
     * Return the defect reported once that index is gone.
     *
     * @return string
     */
    abstract protected function anywhereMatchDefect(): string;

    /**
     * Drop the index serving the leading match on the searched column.
     *
     * @return void
     */
    abstract protected function dropPrefixMatchIndex(): void;

    /**
     * Recreate the index serving the leading match on the searched column.
     *
     * @return void
     */
    abstract protected function createPrefixMatchIndex(): void;

    /**
     * Return the defect the leading match draws once that index is gone.
     *
     * @return string
     */
    abstract protected function prefixMatchDefect(): string;

    /**
     * Drop the ordinary index serving the equality match.
     *
     * @return void
     */
    abstract protected function dropEqualityMatchIndex(): void;

    /**
     * Recreate the ordinary index serving the equality match.
     *
     * @return void
     */
    abstract protected function createEqualityMatchIndex(): void;

    /**
     * Replace the equality index with one the engine reports but will not plan.
     *
     * @return void
     */
    abstract protected function makeEqualityMatchIndexUnusable(): void;

    /**
     * Restore the equality index to one the engine will plan against.
     *
     * @return void
     */
    abstract protected function makeEqualityMatchIndexUsable(): void;

    /**
     * Return the plan the engine reports for the query, as one string.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @param  string  $column
     * @return string
     */
    protected function plan(Builder $query, string $column): string
    {
        $rows = DB::select('explain ' . $query->toSql(), $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall, staticMethod.dynamicCall

        return implode("\n", array_map(
            static function (object $row) use ($column): string {

                $value = ((array) $row)[$column] ?? null;

                return is_scalar($value) ? (string) $value : '';
            },
            $rows,
        ));
    }

    /**
     * Build the query the applier emits for the term against the resource.
     *
     * @param  string  $term
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    private function searchQuery(string $term, ?string $resourceClass = null): Builder
    {
        assert($this->app !== null);

        $applier = new SearchApplier($this->app->make(SearchDriverRegistry::class), $this->app->make(IndexProof::class));
        $query   = User::query();

        $applier->apply($query, SearchTerm::from($term), $resourceClass ?? SearchableFilterableUserResource::class);

        return $query;
    }

    /**
     * Determine whether the applier serves the anywhere-match declaration.
     *
     * @return bool
     */
    private function serves(): bool
    {
        try {
            $this->searchQuery('smith');
        } catch (UnservableSearchException) {
            return false;
        }

        return true;
    }

    /**
     * Cross a lifecycle boundary the way a worker does.
     *
     * @return void
     */
    private function crossBoundary(): void
    {
        assert($this->app !== null);

        $this->cacheManager()->flush();
        $this->app->forgetScopedInstances();
    }

    /**
     * Resolve the cache manager a lifecycle boundary runs through.
     *
     * @return \SineMacula\ApiToolkit\Cache\CacheManager
     */
    private function cacheManager(): CacheManager
    {
        assert($this->app !== null);

        return $this->app->make(CacheManager::class);
    }

    /**
     * Apply the term through the connection's driver, returning matched names.
     *
     * @param  string  $term
     * @param  string|null  $resourceClass
     * @return array<int, string>
     */
    private function search(string $term, ?string $resourceClass = null): array
    {
        /** @var array<int, string> */
        return $this->searchQuery($term, $resourceClass)->orderBy('id')->pluck('name')->all(); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Assert that the resource's search surface passes catalogue validation.
     *
     * @param  string  $resourceClass
     * @return void
     */
    private function assertValidationAccepts(string $resourceClass): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => $resourceClass]);

        $this->runValidation()
            ->expectsOutputToContain('All 1 resource schema(s) validated successfully.')
            ->assertExitCode(0);
    }

    /**
     * Assert that validating the resource reports the given defect.
     *
     * @param  class-string  $resourceClass
     * @param  string  $defect
     * @return void
     */
    private function assertValidationRefuses(string $resourceClass, string $defect): void
    {
        try {
            $this->validator()->validate([User::class => $resourceClass]);

            static::fail('Validation accepted a declaration with no index behind it.');
        } catch (InvalidSchemaException $exception) {

            $defects = array_map(
                static fn (SchemaValidationError $error): string => $error->defect,
                $exception->getErrors(),
            );

            static::assertContains($defect, $defects);
        }
    }

    /**
     * Resolve the schema validator wired with the shipped rule set.
     *
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidator
     */
    private function validator(): SchemaValidator
    {
        assert($this->app !== null);

        return $this->app->make(SchemaValidator::class);
    }

    /**
     * Run the schema validation command.
     *
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runValidation(): PendingCommand
    {
        $command = $this->artisan(self::COMMAND);

        assert($command instanceof PendingCommand);

        return $command;
    }
}
