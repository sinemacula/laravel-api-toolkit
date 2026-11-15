<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Schema\DiscriminatorBuilder;

/**
 * Tests for the DiscriminatorBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DiscriminatorBuilder::class)]
final class DiscriminatorBuilderTest extends TestCase
{
    /** The reference of the first alternative */
    private const string FIRST = '#/components/schemas/First';

    /** The reference of the second alternative */
    private const string SECOND = '#/components/schemas/Second';

    /** The reference of the third alternative */
    private const string THIRD = '#/components/schemas/Third';

    /**
     * Test that only required string constants are pinned, in property order.
     *
     * @return void
     */
    public function testPinsRequiredStringConstantsInPropertyOrder(): void
    {
        $pins = (new DiscriminatorBuilder)->pinnedValues([
            'type'       => 'object',
            'properties' => [
                'kind'   => ['const' => 'card'],
                'label'  => ['type' => 'string'],
                'empty'  => ['const' => ''],
                'status' => ['type' => 'string', 'const' => 'active'],
            ],
            'required' => ['status', 'empty', 'label', 'kind'],
        ]);

        self::assertSame(['kind' => 'card', 'empty' => '', 'status' => 'active'], $pins);
    }

    /**
     * Test that non-string, enumerated and optional constants are skipped.
     *
     * @return void
     */
    public function testSkipsNonStringEnumeratedAndOptionalConstants(): void
    {
        $pins = (new DiscriminatorBuilder)->pinnedValues([
            'properties' => [
                'version'  => ['const' => 2],
                'kind'     => ['enum' => ['card']],
                'optional' => ['const' => 'x'],
                'flag'     => true,
            ],
            'required' => ['version', 'kind', 'flag'],
        ]);

        self::assertSame([], $pins);
    }

    /**
     * Test that a schema without properties or required pins nothing.
     *
     * @return void
     */
    public function testSchemaWithoutPropertiesOrRequiredPinsNothing(): void
    {
        $builder = new DiscriminatorBuilder;

        self::assertSame([], $builder->pinnedValues(['required' => ['status']]));
        self::assertSame([], $builder->pinnedValues(['properties' => ['status' => ['const' => 'x']]]));
        self::assertSame([], $builder->pinnedValues(['properties' => 'status', 'required' => 'status']));
    }

    /**
     * Test that the mapping lists each value against its reference, in
     * alternative order.
     *
     * @return void
     */
    public function testMappingFollowsAlternativeOrder(): void
    {
        $discriminator = (new DiscriminatorBuilder)->build([
            self::FIRST  => ['status' => 'signed_in'],
            self::SECOND => ['status' => 'pending'],
            self::THIRD  => ['status' => 'mfa_required'],
        ]);

        self::assertSame([
            'propertyName' => 'status',
            'mapping'      => ['signed_in' => self::FIRST, 'pending' => self::SECOND, 'mfa_required' => self::THIRD],
        ], $discriminator);
    }

    /**
     * Test that the first qualifying property in property order wins.
     *
     * @return void
     */
    public function testFirstQualifyingPropertyWins(): void
    {
        $discriminator = (new DiscriminatorBuilder)->build([
            self::FIRST  => ['kind' => 'a', 'status' => 'x'],
            self::SECOND => ['status' => 'y', 'kind' => 'b'],
        ]);

        self::assertSame(['propertyName' => 'kind', 'mapping' => ['a' => self::FIRST, 'b' => self::SECOND]], $discriminator);
    }

    /**
     * Test that a clashing first candidate gives way to a later distinct one.
     *
     * @return void
     */
    public function testClashingCandidateFallsThroughToALaterOne(): void
    {
        $discriminator = (new DiscriminatorBuilder)->build([
            self::FIRST  => ['kind' => 'a', 'status' => 'x'],
            self::SECOND => ['kind' => 'a', 'status' => 'y'],
        ]);

        self::assertSame(['propertyName' => 'status', 'mapping' => ['x' => self::FIRST, 'y' => self::SECOND]], $discriminator);
    }

    /**
     * Test that a numeric property name is pinned and reported as a string.
     *
     * @return void
     */
    public function testNumericPropertyNameDiscriminatesAsAString(): void
    {
        $builder = new DiscriminatorBuilder;

        $discriminator = $builder->build([
            self::FIRST  => $builder->pinnedValues(['properties' => ['0' => ['const' => 'a']], 'required' => ['0']]),
            self::SECOND => $builder->pinnedValues(['properties' => ['0' => ['const' => 'b']], 'required' => ['0']]),
        ]);

        self::assertSame(['propertyName' => '0', 'mapping' => ['a' => self::FIRST, 'b' => self::SECOND]], $discriminator);
    }

    /**
     * Test that a member not pinning the candidate leaves no discriminator.
     *
     * @return void
     */
    public function testMemberLackingThePropertyLeavesNoDiscriminator(): void
    {
        self::assertNull((new DiscriminatorBuilder)->build([
            self::FIRST  => ['status' => 'x'],
            self::SECOND => ['kind' => 'y'],
        ]));
    }

    /**
     * Test that two members sharing a value leave no discriminator.
     *
     * @return void
     */
    public function testSharedValueLeavesNoDiscriminator(): void
    {
        self::assertNull((new DiscriminatorBuilder)->build([
            self::FIRST  => ['status' => 'x'],
            self::SECOND => ['status' => 'y'],
            self::THIRD  => ['status' => 'x'],
        ]));
    }

    /**
     * Test that members pinning nothing leave no discriminator.
     *
     * @return void
     */
    public function testNoPinsLeaveNoDiscriminator(): void
    {
        $builder = new DiscriminatorBuilder;

        self::assertNull($builder->build([self::FIRST => [], self::SECOND => ['status' => 'x']]));
        self::assertNull($builder->build([]));
    }
}
