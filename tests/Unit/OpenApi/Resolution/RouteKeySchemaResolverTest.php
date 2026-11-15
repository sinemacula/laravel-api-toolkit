<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Enums\TicketPriority;
use Tests\Fixtures\Models\Account;
use Tests\Fixtures\Models\Invoice;
use Tests\Fixtures\Models\Shipment;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Models\Voucher;
use Tests\Fixtures\Resources\TicketResource;

/**
 * Tests for the route key schema resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteKeySchemaResolver::class)]
final class RouteKeySchemaResolverTest extends TestCase
{
    /**
     * Clear the compiled schemas between tests.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that a UUID key is a uuid without reading a column.
     *
     * @return void
     */
    public function testUuidKeyIsAUuid(): void
    {
        self::assertSame(['type' => 'string', 'format' => 'uuid'], $this->resolver()->resolve(Ticket::class, null, false));
    }

    /**
     * Test that a ULID key carries the ULID pattern without reading a column.
     *
     * @return void
     */
    public function testUlidKeyIsAUlid(): void
    {
        self::assertSame((new RoutePatternTranslator)->ulidSchema(), $this->resolver()->resolve(Voucher::class, null, false));
    }

    /**
     * Test that a binding field outside the unique ids reads its column.
     *
     * @return void
     */
    public function testBindingFieldOutsideTheUniqueIdsReadsItsColumn(): void
    {
        $resolver = $this->resolver([
            'serial' => new ColumnDefinition(name: 'serial', typeName: 'bigint', nullable: false),
        ]);

        self::assertSame(['type' => 'integer'], $resolver->resolve(Ticket::class, 'serial', false));
    }

    /**
     * Test that a binding field in the unique ids is a uuid.
     *
     * @return void
     */
    public function testBindingFieldInTheUniqueIdsIsAUuid(): void
    {
        $resolver = $this->resolver([
            'id' => new ColumnDefinition(name: 'id', typeName: 'varchar', nullable: false),
        ]);

        self::assertSame(['type' => 'string', 'format' => 'uuid'], $resolver->resolve(Ticket::class, 'id', false));
    }

    /**
     * Test that a key of another unique id scheme reads its column.
     *
     * @return void
     */
    public function testKeyOfAnotherUniqueIdSchemeReadsItsColumn(): void
    {
        $model = new class extends Model {
            use HasUniqueStringIds;

            /**
             * Generate a new unique key for the model.
             *
             * @return string
             */
            #[\Override]
            public function newUniqueId(): string
            {
                return 'id';
            }

            /**
             * Determine if the given key is valid.
             *
             * @param  mixed  $value
             * @return bool
             */
            #[\Override]
            protected function isValidUniqueId(mixed $value): bool
            {
                return true;
            }
        };

        $resolver = $this->resolver([
            'id' => new ColumnDefinition(name: 'id', typeName: 'varchar', nullable: false),
        ]);

        self::assertSame(['type' => 'string'], $resolver->resolve($model::class, null, false));
    }

    /**
     * Test that a direct resource field's declaration wins over the column,
     * without null.
     *
     * @return void
     */
    public function testDirectResourceFieldDeclarationWinsWithoutNull(): void
    {
        $resolver = $this->resolver([
            'code' => new ColumnDefinition(name: 'code', typeName: 'bigint', nullable: true),
        ], [Ticket::class => TicketResource::class]);

        self::assertSame(['type' => 'string', 'format' => 'ticket-code'], $resolver->resolve(Ticket::class, 'code', false));
    }

    /**
     * Test that a transformed resource field's declaration is ignored.
     *
     * @return void
     */
    public function testTransformedResourceFieldDeclarationIsIgnored(): void
    {
        $resolver = $this->resolver([
            'reference' => new ColumnDefinition(name: 'reference', typeName: 'bigint', nullable: false),
        ], [Ticket::class => TicketResource::class]);

        self::assertSame(['type' => 'integer'], $resolver->resolve(Ticket::class, 'reference', false));
    }

    /**
     * Test that a nullable column's schema loses null.
     *
     * @return void
     */
    public function testNullableColumnLosesNull(): void
    {
        $resolver = $this->resolver([
            'slug' => new ColumnDefinition(name: 'slug', typeName: 'varchar', nullable: true),
        ]);

        self::assertSame(['type' => 'string'], $resolver->resolve(User::class, 'slug', false));
    }

    /**
     * Test that an enum-cast key references and registers its enum.
     *
     * @return void
     */
    public function testEnumCastKeyReferencesItsEnum(): void
    {
        $enums = new EnumSchemaRegistry;

        self::assertSame(['$ref' => '#/components/schemas/TicketPriority'], $this->resolver(enums: $enums)->resolve(Ticket::class, 'priority', false));
        self::assertSame([TicketPriority::class], $enums->classes());
    }

    /**
     * Test that a declared reference on an enum-cast key yields the cast enum.
     *
     * @return void
     */
    public function testDeclaredReferenceOnAnEnumCastKeyYieldsTheCastEnum(): void
    {
        $enums    = new EnumSchemaRegistry;
        $resolver = $this->resolver([], [Ticket::class => TicketResource::class], $enums);

        self::assertSame(['$ref' => '#/components/schemas/TicketPriority'], $resolver->resolve(Ticket::class, 'priority', false));
        self::assertSame([TicketPriority::class], $enums->classes());
    }

    /**
     * Test that a declared reference on a key without an enum cast yields
     * nothing, even to an enum the export registers.
     *
     * @return void
     */
    public function testDeclaredReferenceWithoutAnEnumCastYieldsNothing(): void
    {
        $enums = new EnumSchemaRegistry;

        $enums->register(TicketPriority::class);

        self::assertNull($this->resolver([], [Ticket::class => TicketResource::class], $enums)->resolve(Ticket::class, 'rank', false));
    }

    /**
     * Test that an abstract model yields nothing.
     *
     * @return void
     */
    public function testAbstractModelYieldsNothing(): void
    {
        self::assertNull($this->resolver()->resolve(Account::class, null, false));
    }

    /**
     * Test that an incrementing key is an integer without a database.
     *
     * @return void
     */
    public function testIncrementingKeyIsAnIntegerWithoutADatabase(): void
    {
        self::assertSame(['type' => 'integer'], $this->resolver()->resolve(User::class, null, false));
    }

    /**
     * Test that a key the schema cannot state as a scalar yields nothing.
     *
     * @return void
     */
    public function testNonScalarKeyYieldsNothing(): void
    {
        $resolver = $this->resolver([
            'active' => new ColumnDefinition(name: 'active', typeName: 'boolean', nullable: false),
        ]);

        self::assertNull($resolver->resolve(Ticket::class, 'meta', false));
        self::assertNull($resolver->resolve(Ticket::class, 'active', false));
        self::assertNull($resolver->resolve(Ticket::class, 'missing', false));
    }

    /**
     * Test that an overridden route binding yields nothing.
     *
     * @return void
     */
    public function testOverriddenRouteBindingYieldsNothing(): void
    {
        self::assertNull($this->resolver()->resolve(Invoice::class, null, false));
        self::assertNull($this->resolver()->resolve(Invoice::class, null, true));
    }

    /**
     * Test that an overridden trashed binding yields nothing only where Laravel
     * calls it.
     *
     * @return void
     */
    public function testOverriddenTrashedBindingYieldsNothingOnlyWhereCalled(): void
    {
        $unsoftDeleting = new class extends Model {
            /**
             * Retrieve the model for a bound value, including trashed models.
             *
             * @param  mixed  $value
             * @param  string|null  $field
             * @return \Illuminate\Database\Eloquent\Model|null
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            #[\Override]
            public function resolveSoftDeletableRouteBinding(mixed $value, $field = null): ?Model
            {
                return null;
            }
        };

        self::assertNull($this->resolver()->resolve(Shipment::class, null, true));
        self::assertSame(['type' => 'integer'], $this->resolver()->resolve(Shipment::class, null, false));
        self::assertSame(['type' => 'integer'], $this->resolver()->resolve($unsoftDeleting::class, null, true));
    }

    /**
     * Build a resolver over the given columns, resource map and registry.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>  $columns
     * @param  array<class-string, class-string>  $resourceMap
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver
     */
    private function resolver(array $columns = [], array $resourceMap = [], EnumSchemaRegistry $enums = new EnumSchemaRegistry): RouteKeySchemaResolver
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn($columns);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($resourceMap);

        return new RouteKeySchemaResolver(
            $catalogue,
            new FieldTypeResolver($introspector, new ColumnTypeMapper, $enums),
            new RoutePatternTranslator,
            $enums,
        );
    }
}
