<?php

namespace Tests\Unit\RouteLinting\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry;
use Tests\TestCase;

/**
 * Tests for the AllowlistEntry DTO.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AllowlistEntry::class)]
class AllowlistEntryTest extends TestCase
{
    /**
     * Test that covers() returns true for any rule ID when the rules list
     * defaults to empty, i.e. the entry covers all rules.
     *
     * @return void
     */
    public function testCoversAllRulesWhenRulesListIsEmpty(): void
    {
        $entry = new AllowlistEntry('users.store', 'Legacy endpoint.');

        static::assertTrue($entry->covers('R1'));
        static::assertTrue($entry->covers('R9'));
        static::assertTrue($entry->covers('PLURAL_COLLECTIONS'));
    }

    /**
     * Test that covers() returns true when the given rule ID is explicitly
     * present in a non-empty rules list.
     *
     * @return void
     */
    public function testCoversReturnsTrueForExplicitlyListedRule(): void
    {
        $entry = new AllowlistEntry('users.store', 'Scoped waiver.', ['R9', 'R3']);

        static::assertTrue($entry->covers('R9'));
        static::assertTrue($entry->covers('R3'));
    }

    /**
     * Test that covers() returns false when the given rule ID is absent from
     * a non-empty rules list.
     *
     * @return void
     */
    public function testCoversReturnsFalseForRuleNotInList(): void
    {
        $entry = new AllowlistEntry('users.store', 'Scoped waiver.', ['R9']);

        static::assertFalse($entry->covers('R1'));
        static::assertFalse($entry->covers('PLURAL_COLLECTIONS'));
    }

    /**
     * Test that all three properties round-trip correctly and that the default
     * rules value is an empty array.
     *
     * @return void
     */
    public function testExposesMatchReasonAndRulesWithCorrectDefaults(): void
    {
        $defaultEntry = new AllowlistEntry('api/*', 'All API routes waived.');

        static::assertSame('api/*', $defaultEntry->match);
        static::assertSame('All API routes waived.', $defaultEntry->reason);
        static::assertSame([], $defaultEntry->rules);

        $scopedEntry = new AllowlistEntry('orders.index', 'Scoped.', ['R9']);

        static::assertSame(['R9'], $scopedEntry->rules);
    }
}
