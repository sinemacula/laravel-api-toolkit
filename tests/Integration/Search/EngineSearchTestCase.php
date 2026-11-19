<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\EqualitySearchableUserResource;
use Tests\Fixtures\Resources\PrefixSearchableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;

/**
 * Shared search integration suite for an engine that indexes what it declares.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineSearchTestCase extends SearchMatchingTestCase
{
    /** @var string The command validating the declared search surface */
    private const string COMMAND = 'api-toolkit:validate-schemas';

    /** @var string The defect an equality declaration draws once the ordinary index leading with its column is gone, which reads the same on either engine */
    private const string EQUALITY_DEFECT = 'Column "name" is declared searchable with the "exact" strategy, '
        . 'which needs an index leading with that column on table "users"';

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
     * Test that the anywhere-match on a multibyte term is answered from an
     * index.
     *
     * @return void
     */
    public function testTheAnywhereMatchOnAMultibyteTermIsAnsweredFromAnIndex(): void
    {
        $this->assertIndexBacked($this->searchQuery('東京都'));
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
     * Test that validation and the request-time proof refuse the declaration
     * over an index of the right columns that cannot serve the match.
     *
     * @return void
     */
    public function testTheDeclarationIsRefusedOverAnIndexThatCannotServeTheMatch(): void
    {
        $this->makeAnywhereMatchIndexUnusable();

        try {
            static::assertFalse($this->serves());

            $this->assertValidationRefuses(SearchableFilterableUserResource::class, $this->anywhereMatchDefect());
        } finally {
            $this->makeAnywhereMatchIndexUsable();
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

            $this->travel(QuerySettings::indexProofTtl() + 1)->seconds();
            $this->crossBoundary();

            static::assertFalse($this->serves());
        } finally {
            $this->createAnywhereMatchIndex();
        }
    }

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
     * Replace the anywhere-match index with one over the same columns that
     * cannot serve the match.
     *
     * @return void
     */
    abstract protected function makeAnywhereMatchIndexUnusable(): void;

    /**
     * Restore the anywhere-match index the driver proves.
     *
     * @return void
     */
    abstract protected function makeAnywhereMatchIndexUsable(): void;

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
     * Determine whether the applier serves the anywhere-match declaration.
     *
     * @return bool
     */
    protected function serves(): bool
    {
        try {
            $this->searchQuery('smith');
        } catch (UnservableSearchException) {
            return false;
        }

        return true;
    }

    /**
     * Assert that validating the resource reports the given defect.
     *
     * @param  class-string  $resourceClass
     * @param  string  $defect
     * @return void
     */
    protected function assertValidationRefuses(string $resourceClass, string $defect): void
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
