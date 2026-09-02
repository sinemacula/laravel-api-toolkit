<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

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
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\TestCase;

/**
 * Shared search integration suite for an engine that indexes what it declares.
 *
 * Runs only against the engine the concrete case names, and proves the two
 * things that cannot be proven anywhere else: that the engine answers the
 * emitted predicate with the rows the declaration promises - a term inside a
 * longer word among them - and that the index proof reads the live catalogue,
 * accepting the index the fixture creates and refusing its absence.
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
     * start of its own value, so one term reaches every declared strategy.
     *
     * @return void
     */
    public function testPrefixMatchFindsTheTermAtTheStartOfItsOwnColumn(): void
    {
        static::assertSame(['Jones'], $this->search('jonat'));
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
     * Test that the declared search surface passes validation against the live
     * catalogue, which is the gate a deployment runs before its first search.
     *
     * @return void
     */
    public function testValidationAcceptsTheDeclaredSearchSurface(): void
    {
        $this->runValidation()
            ->expectsOutputToContain('All 1 resource schema(s) validated successfully.')
            ->assertExitCode(0);
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
            $this->validator()->validate([User::class => SearchableFilterableUserResource::class]);

            static::fail('Validation accepted a declaration with no index behind it.');
        } catch (InvalidSchemaException $exception) {

            $defects = array_map(
                static fn (SchemaValidationError $error): string => $error->defect,
                $exception->getErrors(),
            );

            static::assertContains($this->anywhereMatchDefect(), $defects);
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
     * Drop the index serving the anywhere-match on the searched column.
     *
     * @return void
     */
    abstract protected function dropAnywhereMatchIndex(): void;

    /**
     * Recreate the index serving the anywhere-match on the searched column.
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
     * Apply the term through the connection's registered driver and return the
     * names it matched.
     *
     * @param  string  $term
     * @return array<int, string>
     */
    private function search(string $term): array
    {
        assert($this->app !== null);

        $applier = new SearchApplier($this->app->make(SearchDriverRegistry::class));
        $query   = User::query();

        $applier->apply($query, SearchTerm::from($term), SearchableFilterableUserResource::class);

        /** @var array<int, string> */
        return $query->orderBy('id')->pluck('name')->all();
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
