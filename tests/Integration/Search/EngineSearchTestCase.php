<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
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
 * Runs only against the engine the concrete case names, and proves the three
 * things that cannot be proven anywhere else: that the engine answers the
 * emitted predicate with the rows the declaration promises - a term inside a
 * longer word among them - that it answers it from an index rather than by
 * reading the table, and that the index proof reads the live catalogue,
 * accepting the index the fixture creates and refusing its absence. Every match
 * shape a resource may declare is carried through the rows and the catalogue
 * alike, so an equality or a leading match is answered by an engine rather than
 * by a hand-written index definition.
 *
 * The rows are committed rather than rolled back at the end of the test. One of
 * the supported engines updates its full-text index when a transaction commits,
 * so a row written inside an open one is invisible to the match that is the
 * point of the suite.
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
     * Seed the rows every test searches, having skipped the suite on any engine
     * but the one under test.
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
     * Test that a term matches inside a value rather than only at its start,
     * which is the whole reason the anywhere-match exists.
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
     * Test that a column declared under the prefix strategy matches from the
     * start of its own value, so every declared strategy reaches the engine.
     *
     * @return void
     */
    public function testPrefixMatchFindsTheTermAtTheStartOfItsOwnColumn(): void
    {
        static::assertSame(['Jones'], $this->search('jonat', PrefixSearchableUserResource::class));
    }

    /**
     * Test that a prefix match does not find the term inside a value, which is
     * the whole difference between the two pattern strategies. Every seeded
     * address carries the term, so an anywhere-match here would return all of
     * them.
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
     * The two engines fold that case in different places - one in the operator
     * the driver emits, the other in the column's collation - and the same
     * declaration is meant to answer the same rows on both. Every other term in
     * this suite matches a value in its own case, so nothing else would move if
     * one engine narrowed to the case-sensitive set.
     *
     * @return void
     */
    public function testPrefixMatchFindsAValueWhoseCaseDiffersFromTheTerm(): void
    {
        User::create(['name' => 'Ivory', 'email' => 'Ivory@example.com', 'status' => 'active']);

        static::assertSame(['Ivory'], $this->search('ivory', PrefixSearchableUserResource::class));
    }

    /**
     * Test that a column declared under the equality strategy matches its whole
     * value only.
     *
     * The row beside it carries the whole term at the start of a longer value
     * and in the same case, so a comparison that widened into a pattern of
     * either shape would return it too. Matching at all also proves the
     * connection's catalogue reports the ordinary index the declaration needs,
     * since a search no index can be shown to serve is refused before it
     * reaches the engine.
     *
     * @return void
     */
    public function testEqualityMatchFindsTheWholeValueAndNoLongerOne(): void
    {
        User::create(['name' => 'Smithson', 'email' => 'smithson@example.com', 'status' => 'active']);

        static::assertSame(['Smith'], $this->search('Smith', EqualitySearchableUserResource::class));
    }

    /**
     * Test that a term matching nothing narrows the result to nothing, rather
     * than falling back to the unnarrowed table.
     *
     * @return void
     */
    public function testATermMatchingNothingReturnsNothing(): void
    {
        static::assertSame([], $this->search('wright'));
    }

    /**
     * Test that a wildcard carried by the term matches itself rather than every
     * row. Whether the engine reads the emitted ESCAPE clause the way the
     * escaping assumes is the one part of the pattern a compiled-SQL assertion
     * cannot settle.
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
     * Test that the escape character itself matches literally when the term
     * carries one. It is doubled before it reaches the pattern, so the engine
     * has to read the pair as the character rather than as an escape of the
     * character beside it.
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
     * Test that the anywhere-match is answered from an index rather than by
     * reading the table, which the returned rows alone cannot show: a scan
     * answers them just as correctly, and far more slowly, which is the outcome
     * the declaration exists to make impossible.
     *
     * @return void
     */
    public function testTheAnywhereMatchIsAnsweredFromAnIndex(): void
    {
        $this->assertIndexBacked($this->searchQuery('smith'));
    }

    /**
     * Test that the anywhere-match keeps its index once a filter is ANDed
     * alongside it, which is the shape a real request produces.
     *
     * @return void
     */
    public function testTheAnywhereMatchKeepsItsIndexBesideAFilter(): void
    {
        $this->assertIndexBacked($this->searchQuery('smith')->where('status', 'active'));
    }

    /**
     * Test that the declared search surface passes validation against the live
     * catalogue, which is the gate a deployment runs before its first search.
     *
     * @return void
     */
    public function testValidationAcceptsTheDeclaredSearchSurface(): void
    {
        $this->assertValidationAccepts(SearchableFilterableUserResource::class);
    }

    /**
     * Test that validation refuses the declaration once the index behind it is
     * gone, so the missing index fails a build rather than a request.
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
     * Test that a leading-match declaration passes validation against the live
     * catalogue, which is the read the two engines answer from different index
     * kinds: an ordinary one here, a trigram one there.
     *
     * @return void
     */
    public function testValidationAcceptsThePrefixSearchSurface(): void
    {
        $this->assertValidationAccepts(PrefixSearchableUserResource::class);
    }

    /**
     * Test that validation refuses the leading-match declaration once the index
     * behind it is gone, so the acceptance above is the catalogue answering
     * rather than the rule admitting whatever it is shown.
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
     * Test that an equality declaration passes validation against the live
     * catalogue, which admits it only where that catalogue names an ordinary
     * index leading with the column.
     *
     * @return void
     */
    public function testValidationAcceptsTheEqualitySearchSurface(): void
    {
        $this->assertValidationAccepts(EqualitySearchableUserResource::class);
    }

    /**
     * Test that validation refuses the equality declaration once that ordinary
     * index is gone, and refuses it in the same words on either engine, which
     * is the arrangement the strategy documents.
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
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Assert that the engine answers the query from an index rather than by
     * reading the table.
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
     * Drop the ordinary index serving the equality match on the searched
     * column.
     *
     * @return void
     */
    abstract protected function dropEqualityMatchIndex(): void;

    /**
     * Recreate the ordinary index serving the equality match on the searched
     * column.
     *
     * @return void
     */
    abstract protected function createEqualityMatchIndex(): void;

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
     * Build the query the applier emits for the term against the given
     * resource.
     *
     * @param  string  $term
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function searchQuery(string $term, ?string $resourceClass = null): Builder
    {
        assert($this->app !== null);

        $applier = new SearchApplier($this->app->make(SearchDriverRegistry::class));
        $query   = User::query();

        $applier->apply($query, SearchTerm::from($term), $resourceClass ?? SearchableFilterableUserResource::class);

        return $query;
    }

    /**
     * Apply the term through the connection's registered driver and return the
     * names it matched.
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
     * Assert that the resource's declared search surface passes validation
     * against the live catalogue.
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
     * @param  string  $resourceClass
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
