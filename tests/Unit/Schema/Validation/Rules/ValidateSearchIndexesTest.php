<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchIndexes;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\SearchableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Tests for the ValidateSearchIndexes validation rule.
 *
 * The rule is driven against the connection the suite runs on, with the driver
 * serving it replaced per test, so every way a declaration can fail to be
 * served from an index is exercised without an engine carrying that index kind.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateSearchIndexes::class)]
final class ValidateSearchIndexesTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Search\SearchDriverRegistry */
    private SearchDriverRegistry $drivers;

    /**
     * Set up an empty driver registry and waive the index proof on the
     * connection the suite runs against.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->drivers = new SearchDriverRegistry;

        Config::set('api-toolkit.search.unverified_connections', [$this->connection()]);
    }

    /**
     * Test that a resource declaring nothing searchable is passed over, so the
     * connection is never read for a resource with no search surface.
     *
     * @return void
     */
    public function testPassesOverAResourceDeclaringNothingSearchable(): void
    {
        $schema = new CompiledSchema(fields: ['name' => $this->makeField()], counts: []);

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $schema));
    }

    /**
     * Test that a resource with no model behind it is passed over, since there
     * is no table whose indexes could be read.
     *
     * @return void
     */
    public function testPassesOverAResourceWithNoModel(): void
    {
        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, null, $this->schema()));
    }

    /**
     * Test that a mapped class that is not an Eloquent model is passed over
     * rather than instantiated.
     *
     * @return void
     */
    public function testPassesOverAMappedClassThatIsNotAModel(): void
    {
        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, SearchableUserResource::class, $this->schema()));
    }

    /**
     * Test that a connection with no driver registered is reported, since a
     * search reaching it has nothing to serve it.
     *
     * @return void
     */
    public function testReportsAConnectionWithNoDriverRegistered(): void
    {
        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            sprintf('Field is declared searchable against "name", and no search driver is registered for the "%s" connection to serve it', $this->connection()),
            $errors[0]->defect,
        );
    }

    /**
     * Test that a strategy the registered driver does not implement is
     * reported.
     *
     * @return void
     */
    public function testReportsAStrategyTheDriverDoesNotImplement(): void
    {
        $this->register(new PatternSearchDriver([SearchStrategy::EXACT], true));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame(
            sprintf('Field is declared searchable with the "substring" strategy, which the driver registered for the "%s" connection does not implement', $this->connection()),
            $errors[0]->defect,
        );
    }

    /**
     * Test that a driver that can prove nothing is accepted on a connection
     * where the proof is waived, so a development connection stays quiet.
     *
     * @return void
     */
    public function testAcceptsAnUnprovableDeclarationOnAWaivedConnection(): void
    {
        $this->register(new PatternSearchDriver);

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema()));
    }

    /**
     * Test that a driver that can prove nothing is reported on a connection
     * where the proof is not waived, rather than serving a predicate that may
     * be reading the whole table.
     *
     * @return void
     */
    public function testReportsAnUnprovableDeclarationOnAConnectionThatDoesNotWaiveTheProof(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->register(new PatternSearchDriver);

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame(
            sprintf(
                'Field is declared searchable with the "substring" strategy, and the driver registered for the "%s" connection '
                . 'cannot prove an index serves it',
                $this->connection(),
            ),
            $errors[0]->defect,
        );
    }

    /**
     * Test that a defect the driver reports is carried through against the
     * field that declared it.
     *
     * @return void
     */
    public function testReportsTheDefectsTheDriverFinds(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column']));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame(SearchableUserResource::class, $errors[0]->resourceClass);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('No index serves this column', $errors[0]->defect);
    }

    /**
     * Test that a declaration the driver proves is accepted.
     *
     * @return void
     */
    public function testAcceptsADeclarationTheDriverProves(): void
    {
        $this->register(new PatternSearchDriver(null, true));

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema()));
    }

    /**
     * Test that a connection that cannot be read is reported rather than
     * passed, so an unreachable catalogue never reads as a proof.
     *
     * @return void
     */
    public function testReportsAConnectionThatCannotBeRead(): void
    {
        $driver = self::createStub(SearchDriver::class);

        $driver->method('supportedStrategies')->willReturn(SearchStrategy::cases());
        $driver->method('canVerifyIndexBacking')->willReturn(true);
        $driver->method('indexDefects')->willThrowException(new \RuntimeException('Connection refused'));

        $this->register($driver);

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame(
            sprintf('Field is declared searchable against "name", and the "%s" connection could not be read to prove an index serves it: Connection refused', $this->connection()),
            $errors[0]->defect,
        );
    }

    /**
     * Test that every declared field is reported, not just the first.
     *
     * @return void
     */
    public function testReportsEveryDeclaredField(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column']));

        $schema = new CompiledSchema(
            fields: [
                'name'   => $this->makeField('name', SearchStrategy::SUBSTRING),
                'email'  => $this->makeField('email', SearchStrategy::PREFIX),
                'status' => $this->makeField(),
            ],
            counts: [],
            searchableColumns: ['name' => SearchStrategy::SUBSTRING, 'email' => SearchStrategy::PREFIX],
        );

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $schema);

        self::assertCount(2, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('email', $errors[1]->fieldKey);
    }

    /**
     * Build the rule under test over the registry the tests populate.
     *
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchIndexes
     */
    private function rule(): ValidateSearchIndexes
    {
        return new ValidateSearchIndexes($this->drivers);
    }

    /**
     * Register the given driver for the connection the suite runs against.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @return void
     */
    private function register(SearchDriver $driver): void
    {
        $this->drivers->register($this->connection(), $driver);
    }

    /**
     * Build a compiled schema declaring one searchable column.
     *
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function schema(): CompiledSchema
    {
        return new CompiledSchema(
            fields: ['name' => $this->makeField('name', SearchStrategy::SUBSTRING)],
            counts: [],
            searchableColumns: ['name' => SearchStrategy::SUBSTRING],
        );
    }

    /**
     * Create a compiled field definition with the given search declaration.
     *
     * @param  string|null  $searchable
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy|null  $strategy
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(?string $searchable = null, ?SearchStrategy $strategy = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor      : null,
            compute       : null,
            relation      : null,
            resource      : null,
            fields        : null,
            constraint    : null,
            extras        : [],
            needs         : [],
            guards        : [],
            transformers  : [],
            searchable    : $searchable,
            searchStrategy: $strategy,
        );
    }

    /**
     * Return the driver name of the connection the suite runs against.
     *
     * @return string
     */
    private function connection(): string
    {
        return (new User)->getConnection()->getDriverName();
    }
}
