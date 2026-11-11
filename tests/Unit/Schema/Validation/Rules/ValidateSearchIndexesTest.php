<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
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
 * Drivers are swapped per test and the catalogue is stubbed.
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
     * Set up an empty driver registry and waive the index proof.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->drivers = new SearchDriverRegistry;

        Config::set('api-toolkit.search.unverified_connections', [$this->connectionName()]);
    }

    /**
     * Test that a resource with nothing searchable is passed over.
     *
     * @return void
     */
    public function testPassesOverAResourceDeclaringNothingSearchable(): void
    {
        $schema = new CompiledSchema(fields: ['name' => $this->makeField()], counts: []);

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $schema));
    }

    /**
     * Test that a resource with no model behind it is passed over.
     *
     * @return void
     */
    public function testPassesOverAResourceWithNoModel(): void
    {
        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, null, $this->schema()));
    }

    /**
     * Test that a mapped class that is not a model is passed over.
     *
     * @return void
     */
    public function testPassesOverAMappedClassThatIsNotAModel(): void
    {
        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, SearchableUserResource::class, $this->schema()));
    }

    /**
     * Test that a connection with no registered driver is reported.
     *
     * @return void
     */
    public function testReportsAConnectionWithNoDriverRegistered(): void
    {
        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            sprintf('Field is declared searchable against "name", and no search driver is registered for the "%s" connection to serve it', $this->driver()),
            $errors[0]->defect,
        );
    }

    /**
     * Test that every field is reported against a connection no driver serves.
     *
     * @return void
     */
    public function testReportsEveryFieldAgainstAConnectionWithNoDriver(): void
    {
        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->surface());

        self::assertCount(2, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('email', $errors[1]->fieldKey);
    }

    /**
     * Test that a strategy the driver does not implement is reported.
     *
     * @return void
     */
    public function testReportsAStrategyTheDriverDoesNotImplement(): void
    {
        $this->register(new PatternSearchDriver([SearchStrategy::EXACT], true));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame(
            sprintf('Field is declared searchable with the "substring" strategy, which the driver serving the "%s" engine does not implement', $this->driver()),
            $errors[0]->defect,
        );
    }

    /**
     * Test that an unproving driver is accepted where the proof is waived.
     *
     * @return void
     */
    public function testAcceptsAnUnprovableDeclarationOnAWaivedConnection(): void
    {
        $this->register(new PatternSearchDriver);

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema()));
    }

    /**
     * Test that an unproving driver is reported where the proof is not waived.
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
                'Field is declared searchable with the "substring" strategy, and the driver serving the "%s" connection '
                . 'cannot prove an index serves it. '
                . 'List that connection under api-toolkit.search.unverified_connections to serve it regardless',
                $this->connectionName(),
            ),
            $errors[0]->defect,
        );
    }

    /**
     * Test that a driver-reported defect is carried against its field.
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
     * Test that a connection the driver could not read is passed over.
     *
     * A failed read proves nothing, and the request path proves it again.
     *
     * @return void
     */
    public function testSkipsAConnectionTheDriverCouldNotRead(): void
    {
        $driver = self::createStub(SearchDriver::class);

        $driver->method('supportedStrategies')->willReturn(SearchStrategy::cases());
        $driver->method('canVerifyIndexBacking')->willReturn(true);
        $driver->method('indexDefects')->willThrowException(new \RuntimeException('Connection refused'));

        $this->register($driver);

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema()));
    }

    /**
     * Test that an unread catalogue is passed over and an empty one reported.
     *
     * @return void
     */
    public function testTellsAnUnreadableCatalogueApartFromOneCarryingNoIndex(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column']));

        self::assertSame([], $this->rule(null)->validate(SearchableUserResource::class, User::class, $this->schema()));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame('No index serves this column', $errors[0]->defect);
    }

    /**
     * Test that an unimplemented strategy is reported with no catalogue.
     *
     * @return void
     */
    public function testReportsAnUnservableDeclarationWhereTheCatalogueCouldNotBeRead(): void
    {
        $this->register(new PatternSearchDriver([SearchStrategy::EXACT], true));

        $errors = $this->rule(null)->validate(SearchableUserResource::class, User::class, $this->schema());

        self::assertCount(1, $errors);
        self::assertSame(
            sprintf('Field is declared searchable with the "substring" strategy, which the driver serving the "%s" engine does not implement', $this->driver()),
            $errors[0]->defect,
        );
    }

    /**
     * Test that every declared field is reported, past undeclared fields.
     *
     * @return void
     */
    public function testReportsEveryDeclaredField(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column']));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->surface());

        self::assertCount(2, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('email', $errors[1]->fieldKey);
    }

    /**
     * Test that a searchable column with no strategy is passed over.
     *
     * @return void
     */
    public function testPassesOverAFieldDeclaringAColumnWithNoStrategy(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column']));

        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField('name')],
            counts: [],
            searchableColumns: ['name' => SearchStrategy::SUBSTRING],
        );

        self::assertSame([], $this->rule()->validate(SearchableUserResource::class, User::class, $schema));
    }

    /**
     * Test that a combination defect is reported against the first field.
     *
     * @return void
     */
    public function testReportsStrategiesTheDriverCannotServeTogether(): void
    {
        $this->register(new PatternSearchDriver(null, true, [], 'they cannot share a disjunction here'));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->surface());

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            sprintf(
                'The search surface cannot be served from an index on the "%s" connection, because they cannot share a disjunction here',
                $this->driver(),
            ),
            $errors[0]->defect,
        );
    }

    /**
     * Test that a field reports a combination defect and its own defect.
     *
     * @return void
     */
    public function testReportsBothTheCombinationDefectAndTheColumnDefect(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column'], 'they cannot share a disjunction here'));

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $this->surface());

        self::assertCount(3, $errors);
        self::assertSame(['name', 'name', 'email'], array_map(static fn ($error): string => $error->fieldKey, $errors));
    }

    /**
     * Test that every column under one strategy reaches the proof together.
     *
     * @return void
     */
    public function testProvesEveryColumnDeclaredWithOneStrategy(): void
    {
        $this->register(new PatternSearchDriver(null, true, ['No index serves this column']));

        $schema = new CompiledSchema(
            fields: [
                'name'  => $this->makeField('name', SearchStrategy::SUBSTRING),
                'email' => $this->makeField('email', SearchStrategy::SUBSTRING),
            ],
            counts: [],
            searchableColumns: ['name' => SearchStrategy::SUBSTRING, 'email' => SearchStrategy::SUBSTRING],
        );

        $errors = $this->rule()->validate(SearchableUserResource::class, User::class, $schema);

        self::assertSame(['name', 'email'], array_map(static fn ($error): string => $error->fieldKey, $errors));
    }

    /**
     * Build the rule with the catalogue as given, null meaning an unread one.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null  $indexes
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchIndexes
     */
    private function rule(?array $indexes = []): ValidateSearchIndexes
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getIndexes')->willReturn($indexes);

        return new ValidateSearchIndexes($this->drivers, $introspector);
    }

    /**
     * Register the given driver for the connection the suite runs against.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @return void
     */
    private function register(SearchDriver $driver): void
    {
        $this->drivers->register($this->driver(), $driver);
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
     * Build a schema with two searchable columns led by an unsearchable field.
     *
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function surface(): CompiledSchema
    {
        return new CompiledSchema(
            fields: [
                'status' => $this->makeField(),
                'name'   => $this->makeField('name', SearchStrategy::SUBSTRING),
                'email'  => $this->makeField('email', SearchStrategy::PREFIX),
            ],
            counts: [],
            searchableColumns: ['name' => SearchStrategy::SUBSTRING, 'email' => SearchStrategy::PREFIX],
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
     * Return the driver name of the connection under test.
     *
     * @return string
     */
    private function driver(): string
    {
        return (new User)->getConnection()->getDriverName();
    }

    /**
     * Return the name of the connection under test.
     *
     * @return string
     */
    private function connectionName(): string
    {
        return (new User)->getConnection()->getName() ?? '';
    }
}
