<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use Tests\TestCase;

/**
 * Index eligibility tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibility::class)]
final class IndexEligibilityTest extends TestCase
{
    /**
     * Test that an empty report leaves every index usable.
     *
     * @return void
     */
    public function testAReportNamingNothingLeavesEveryIndexUsable(): void
    {
        $eligibility = new IndexEligibility;

        self::assertFalse($eligibility->disregards('users_name_index'));
        self::assertFalse($eligibility->restricts('users_name_index'));
        self::assertFalse($eligibility->keysAnExpression('users_name_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_name_index'));
        self::assertFalse($eligibility->collatesApart('users_name_index'));
        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that each fact is reported independently of the others.
     *
     * @return void
     */
    public function testEachFactIsReportedApartFromTheOthers(): void
    {
        $eligibility = new IndexEligibility(['hidden'], ['partial'], ['expression'], ['prefix'], ['collated']);

        self::assertTrue($eligibility->disregards('hidden'));
        self::assertFalse($eligibility->restricts('hidden'));
        self::assertFalse($eligibility->keysAnExpression('hidden'));

        self::assertTrue($eligibility->restricts('partial'));
        self::assertFalse($eligibility->disregards('partial'));
        self::assertFalse($eligibility->keysAnExpression('partial'));

        self::assertTrue($eligibility->keysAnExpression('expression'));
        self::assertFalse($eligibility->disregards('expression'));
        self::assertFalse($eligibility->restricts('expression'));
        self::assertFalse($eligibility->lacksColumnOrder('expression'));

        self::assertTrue($eligibility->lacksColumnOrder('prefix'));
        self::assertFalse($eligibility->disregards('prefix'));
        self::assertFalse($eligibility->restricts('prefix'));
        self::assertFalse($eligibility->keysAnExpression('prefix'));
        self::assertFalse($eligibility->collatesApart('prefix'));

        self::assertTrue($eligibility->collatesApart('collated'));
        self::assertFalse($eligibility->disregards('collated'));
        self::assertFalse($eligibility->restricts('collated'));
        self::assertFalse($eligibility->keysAnExpression('collated'));
        self::assertFalse($eligibility->describes('collated'));

        self::assertFalse($eligibility->collatesApart('hidden'));
        self::assertFalse($eligibility->collatesApart('expression'));
    }

    /**
     * Test that a leading key lacking its column's order is still described.
     *
     * A truncated or pattern-class key still finds rows by its column.
     *
     * @return void
     */
    public function testALeadingKeyLackingItsColumnsOrderIsStillDescribed(): void
    {
        $eligibility = new IndexEligibility([], [], [], ['users_name_prefix_index']);

        self::assertTrue($eligibility->lacksColumnOrder('users_name_prefix_index'));
        self::assertTrue($eligibility->describes('users_name_prefix_index'));
    }

    /**
     * Test that a key collated apart from its column is not described.
     *
     * Such a key answers no comparison on the column and holds no order for it.
     *
     * @return void
     */
    public function testAKeyCollatedApartIsNotDescribed(): void
    {
        $eligibility = new IndexEligibility([], [], [], [], ['x']);

        self::assertTrue($eligibility->collatesApart('x'));
        self::assertFalse($eligibility->describes('x'));
        self::assertTrue($eligibility->lacksColumnOrder('x'));
        self::assertTrue($eligibility->describes('y'));
        self::assertFalse($eligibility->lacksColumnOrder('y'));
    }

    /**
     * Test that any one of the four facts defeats a column-reading proof.
     *
     * @return void
     */
    public function testAnyOneFactDefeatsAProofReadingTheReportedColumns(): void
    {
        self::assertFalse((new IndexEligibility(['one']))->describes('one'));
        self::assertFalse((new IndexEligibility([], ['two']))->describes('two'));
        self::assertFalse((new IndexEligibility([], [], ['three']))->describes('three'));
        self::assertFalse((new IndexEligibility([], [], [], [], ['four']))->describes('four'));
        self::assertTrue((new IndexEligibility(['one'], ['two'], ['three'], [], ['four']))->describes('five'));
    }

    /**
     * Test that a name is matched case-insensitively on either side.
     *
     * The catalogue folds names while a declaration may not.
     *
     * @return void
     */
    public function testMatchesANameWhateverCaseEitherSideNamesItIn(): void
    {
        $eligibility = new IndexEligibility(['USERS_Name_Index'], ['Users_Live_Index'], ['USERS_LOWER_INDEX'], ['Users_Prefix_INDEX'], ['Users_Collated_INDEX']);

        self::assertTrue($eligibility->disregards('users_name_index'));
        self::assertTrue($eligibility->restricts('users_live_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));
        self::assertFalse($eligibility->describes('Users_Name_INDEX'));
        self::assertTrue($eligibility->lacksColumnOrder('USERS_prefix_index'));
        self::assertTrue($eligibility->collatesApart('users_COLLATED_index'));
    }
}
