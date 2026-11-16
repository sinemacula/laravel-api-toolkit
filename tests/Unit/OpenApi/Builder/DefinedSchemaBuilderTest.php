<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\DefinedSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use Tests\Fixtures\Enums\SelfDescribingPlan;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\OpenApi\Alternate\SignedInShape as AlternateSignedInShape;
use Tests\Fixtures\OpenApi\MfaChallengeShape;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\OpenApi\TitledShape;
use Tests\Fixtures\OpenApi\UserNamedShape;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the DefinedSchemaBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DefinedSchemaBuilder::class)]
final class DefinedSchemaBuilderTest extends TestCase
{
    /**
     * Test that each class is keyed and titled by its short name, title first.
     *
     * @return void
     */
    public function testComponentIsKeyedAndTitledByShortName(): void
    {
        $claimed = [];

        $schemas = (new DefinedSchemaBuilder)->build([WidgetShape::class, SignedInShape::class], [], $claimed);

        self::assertSame([
            'WidgetShape'   => ['title' => 'WidgetShape', ...WidgetShape::schema()],
            'SignedInShape' => ['title' => 'SignedInShape', ...SignedInShape::schema()],
        ], $schemas);
        self::assertSame('title', array_key_first($schemas['WidgetShape']));
    }

    /**
     * Test that a #[SchemaName] override names and titles the component.
     *
     * @return void
     */
    public function testSchemaNameOverrideNamesAndTitlesTheComponent(): void
    {
        $claimed = [];

        $schemas = (new DefinedSchemaBuilder)->build([MfaChallengeShape::class], [], $claimed);

        self::assertSame(['MfaChallenge' => ['title' => 'MfaChallenge', ...MfaChallengeShape::schema()]], $schemas);
    }

    /**
     * Test that a title the fragment declares wins and stays the first key.
     *
     * @return void
     */
    public function testFragmentTitleWins(): void
    {
        $claimed = [];

        $schemas = (new DefinedSchemaBuilder)->build([TitledShape::class], [], $claimed);

        self::assertSame(['TitledShape' => ['title' => 'Titled shape', 'type' => 'object']], $schemas);
    }

    /**
     * Test that every built name is written into the claimed map.
     *
     * @return void
     */
    public function testNamesAreClaimed(): void
    {
        $claimed = ['User' => UserResource::class];

        (new DefinedSchemaBuilder)->build([WidgetShape::class, MfaChallengeShape::class], [], $claimed);

        self::assertSame([
            'User'         => UserResource::class,
            'WidgetShape'  => WidgetShape::class,
            'MfaChallenge' => MfaChallengeShape::class,
        ], $claimed);
    }

    /**
     * Test that a name already reserved by another source fails loud.
     *
     * @return void
     */
    public function testReservedNameFailsLoud(): void
    {
        $claimed = ['User' => UserResource::class];

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "User" is derived by both [%s] and [%s].'
                . ' Disambiguate one with the #[SchemaName] attribute.',
            UserResource::class,
            UserNamedShape::class,
        ));

        (new DefinedSchemaBuilder)->build([UserNamedShape::class], [], $claimed);
    }

    /**
     * Test that two classes sharing a short name fail loud.
     *
     * @return void
     */
    public function testSharedShortNameFailsLoud(): void
    {
        $claimed = [];

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "SignedInShape" is derived by both [%s] and [%s].'
                . ' Disambiguate one with the #[SchemaName] attribute.',
            SignedInShape::class,
            AlternateSignedInShape::class,
        ));

        (new DefinedSchemaBuilder)->build([SignedInShape::class, AlternateSignedInShape::class], [], $claimed);
    }

    /**
     * Test that an enum shape no enum reference claims is built as a component.
     *
     * @return void
     */
    public function testEnumShapeIsBuiltWhenNotReferencedAsAnEnum(): void
    {
        $claimed = [];

        $schemas = (new DefinedSchemaBuilder)->build([SelfDescribingPlan::class], [UserStatus::class], $claimed);

        self::assertSame(['SelfDescribingPlan' => ['title' => 'SelfDescribingPlan', ...SelfDescribingPlan::schema()]], $schemas);
        self::assertSame(['SelfDescribingPlan' => SelfDescribingPlan::class], $claimed);
    }

    /**
     * Test that an enum shape also referenced as an enum fails loud.
     *
     * @return void
     */
    public function testEnumShapeReferencedAsAnEnumFailsLoud(): void
    {
        $claimed = [];

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "SelfDescribingPlan" is derived by [%s] both as an enum and as a'
                . ' #[ResponseSchema] shape. Declare the response shape on a class that is not an enum.',
            SelfDescribingPlan::class,
        ));

        (new DefinedSchemaBuilder)->build([WidgetShape::class, SelfDescribingPlan::class], [UserStatus::class, SelfDescribingPlan::class], $claimed);
    }
}
