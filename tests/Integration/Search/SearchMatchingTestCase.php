<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\EqualitySearchableUserResource;
use Tests\Fixtures\Resources\PrefixSearchableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\TestCase;

/**
 * Shared suite pinning which rows each engine's search driver matches.
 *
 * Where engines compare text differently, each subclass declares the rows its
 * engine returns, so every divergence is named. Rows are committed, not rolled
 * back: one engine updates its full-text index only when a transaction commits.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class SearchMatchingTestCase extends TestCase
{
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

        $this->serveSearches();

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
     * Test that a multi-word term matches its words in order, as the engine
     * separates them.
     *
     * @return void
     */
    public function testAMultiWordTermMatchesItsWordsInOrder(): void
    {
        User::create(['name' => 'Ann Smith', 'email' => 'first@example.org', 'status' => 'active']);
        User::create(['name' => 'ANN SMITH', 'email' => 'second@example.org', 'status' => 'active']);
        User::create(['name' => 'Smith Ann', 'email' => 'third@example.org', 'status' => 'active']);
        User::create(['name' => 'Annsmith', 'email' => 'fourth@example.org', 'status' => 'active']);
        User::create(['name' => 'Ann  Smith', 'email' => 'fifth@example.org', 'status' => 'active']);

        static::assertSame($this->multiWordMatches(), $this->search('ann smith'));
    }

    /**
     * Test that accents and case fold as the engine compares text.
     *
     * @return void
     */
    public function testAccentsAndCaseFoldAsTheEngineComparesText(): void
    {
        User::create(['name' => 'Élise Müller', 'email' => 'em@example.org', 'status' => 'active']);
        User::create(['name' => 'Elise Martin', 'email' => 'martin@example.org', 'status' => 'active']);

        $expected = $this->foldedMatches();
        $matches  = [];

        foreach (array_keys($expected) as $term) {
            $matches[$term] = $this->search($term);
        }

        static::assertSame($expected, $matches);
    }

    /**
     * Test that a term carrying punctuation matches the same rows on every
     * engine.
     *
     * @return void
     */
    public function testAPunctuatedTermMatchesTheSameRowsOnEveryEngine(): void
    {
        static::assertSame(['Highsmith', 'Blacksmith', 'Goldsmith', 'Smith'], $this->search('smith@exa'));
    }

    /**
     * Test that a term in a script without spaces between words matches the
     * same rows on every engine.
     *
     * @return void
     */
    public function testAMultibyteTermMatchesTheSameRowsOnEveryEngine(): void
    {
        User::create(['name' => '東京都庁', 'email' => 'tocho@example.org', 'status' => 'active']);
        User::create(['name' => '京都府', 'email' => 'kyoto@example.org', 'status' => 'active']);

        static::assertSame(['東京都庁'], $this->search('東京都'));
    }

    /**
     * Test that an equality match folds case only where the engine compares
     * text case-insensitively.
     *
     * @return void
     */
    public function testAnEqualityMatchFoldsCaseAsTheEngineComparesText(): void
    {
        static::assertSame($this->caseFoldedEqualityMatches(), $this->search('smith', EqualitySearchableUserResource::class));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Return the rows the anywhere-match finds for "ann smith".
     *
     * @return array<int, string>
     */
    abstract protected function multiWordMatches(): array;

    /**
     * Return, keyed by term, the rows each accented or upper-case term finds.
     *
     * @return array<string, array<int, string>>
     */
    abstract protected function foldedMatches(): array;

    /**
     * Return the rows an equality match finds for "smith" beside "Smith".
     *
     * @return array<int, string>
     */
    abstract protected function caseFoldedEqualityMatches(): array;

    /**
     * Let the connection serve every surface these tests search.
     *
     * @return void
     */
    protected function serveSearches(): void {}

    /**
     * Build the query the applier emits for the term against the resource.
     *
     * @param  string  $term
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    protected function searchQuery(string $term, ?string $resourceClass = null): Builder
    {
        assert($this->app !== null);

        $applier = new SearchApplier($this->app->make(SearchDriverRegistry::class), $this->app->make(IndexProof::class));
        $query   = User::query();

        $applier->apply($query, SearchTerm::from($term), $resourceClass ?? SearchableFilterableUserResource::class);

        return $query;
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
}
