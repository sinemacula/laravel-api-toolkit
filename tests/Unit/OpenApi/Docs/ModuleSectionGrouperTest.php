<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;
use SineMacula\ApiToolkit\OpenApi\Docs\ModuleSectionGrouper;
use Tests\Fixtures\OpenApi\MappedModuleResolver;
use Tests\TestCase;

/**
 * Tests for the module section grouper.
 *
 * The items are plain strings standing in for whatever a generated document
 * lists, since the grouping reads nothing of an item beyond the class the
 * caller names for it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ModuleSectionGrouper::class)]
final class ModuleSectionGrouperTest extends TestCase
{
    /**
     * Test that items belonging to no module gather under the one shared
     * section.
     *
     * @return void
     */
    public function testItemsBelongingToNoModuleGatherUnderTheSharedSection(): void
    {
        $sections = $this->grouper()->group(['Alpha', 'Beta'], $this->subject());

        self::assertSame(['Common'], $this->headings($sections));
        self::assertSame(['Alpha', 'Beta'], $sections[0]['items']);
    }

    /**
     * Test that the module sections follow the shared section and are ordered
     * by module name rather than by the order the items arrived in.
     *
     * @return void
     */
    public function testModuleSectionsFollowTheSharedSectionOrderedByName(): void
    {
        $sections = $this->grouper([
            'Beta'  => new Module('App\Billing', 'Billing'),
            'Gamma' => new Module('App\Account', 'Account'),
        ])->group(['Beta', 'Alpha', 'Gamma'], $this->subject());

        self::assertSame(['Common', 'Account', 'Billing'], $this->headings($sections));
        self::assertSame(['Alpha'], $sections[0]['items']);
        self::assertSame(['Gamma'], $sections[1]['items']);
        self::assertSame(['Beta'], $sections[2]['items']);
    }

    /**
     * Test that the shared section is omitted where every item belongs to a
     * module.
     *
     * @return void
     */
    public function testSharedSectionIsOmittedWhereEveryItemBelongsToAModule(): void
    {
        $sections = $this->grouper(['Alpha' => new Module('App\Account', 'Account')])
            ->group(['Alpha'], $this->subject());

        self::assertSame(['Account'], $this->headings($sections));
        self::assertSame(['Alpha'], $sections[0]['items']);
    }

    /**
     * Test that a module gathers every item resolving to it rather than only
     * the first.
     *
     * @return void
     */
    public function testModuleGathersEveryItemResolvingToIt(): void
    {
        $sections = $this->grouper([
            'Alpha' => new Module('App\Account', 'Account'),
            'Beta'  => new Module('App\Account', 'Account'),
        ])->group(['Alpha', 'Beta'], $this->subject());

        self::assertSame(['Account'], $this->headings($sections));
        self::assertSame(['Alpha', 'Beta'], $sections[0]['items']);
    }

    /**
     * Test that the supplied comparator orders the items of the shared section
     * and of every module section alike.
     *
     * @return void
     */
    public function testComparatorOrdersTheItemsOfEverySection(): void
    {
        $sections = $this->grouper([
            'Beta'  => new Module('App\Account', 'Account'),
            'Alpha' => new Module('App\Account', 'Account'),
        ])->group(
            ['Delta', 'Charlie', 'Beta', 'Alpha'],
            $this->subject(),
            static fn (string $a, string $b): int => $a <=> $b,
        );

        self::assertSame(['Charlie', 'Delta'], $sections[0]['items']);
        self::assertSame(['Alpha', 'Beta'], $sections[1]['items']);
    }

    /**
     * Test that the items keep the order they arrived in where the caller
     * supplies no comparator, leaving a document free to order its own rows.
     *
     * @return void
     */
    public function testItemsKeepTheirArrivalOrderWhereNoComparatorIsSupplied(): void
    {
        $sections = $this->grouper([
            'Beta'  => new Module('App\Account', 'Account'),
            'Alpha' => new Module('App\Account', 'Account'),
        ])->group(['Delta', 'Charlie', 'Beta', 'Alpha'], $this->subject());

        self::assertSame(['Delta', 'Charlie'], $sections[0]['items']);
        self::assertSame(['Beta', 'Alpha'], $sections[1]['items']);
    }

    /**
     * Test that an item the caller names no owning class for gathers under the
     * shared section without the resolver being consulted.
     *
     * @return void
     */
    public function testItemWithNoOwningClassGathersUnderTheSharedSection(): void
    {
        $sections = $this->grouper(['Alpha' => new Module('App\Account', 'Account')])->group(
            ['Alpha', 'Beta'],
            static fn (string $item): ?string => $item === 'Beta' ? null : $item,
        );

        self::assertSame(['Common', 'Account'], $this->headings($sections));
        self::assertSame(['Beta'], $sections[0]['items']);
    }

    /**
     * Test that no items yield no sections at all.
     *
     * @return void
     */
    public function testNoItemsYieldNoSections(): void
    {
        self::assertSame([], $this->grouper()->group([], $this->subject()));
    }

    /**
     * Test that no sections read as combined, so an empty document renders
     * without module headings.
     *
     * @return void
     */
    public function testNoSectionsReadAsCombined(): void
    {
        self::assertTrue($this->grouper()->isCombined([]));
    }

    /**
     * Test that a lone shared section reads as combined.
     *
     * @return void
     */
    public function testLoneSharedSectionReadsAsCombined(): void
    {
        self::assertTrue($this->grouper()->isCombined([['heading' => 'Common', 'items' => ['Alpha']]]));
    }

    /**
     * Test that a lone module section does not read as combined, so its heading
     * is rendered.
     *
     * @return void
     */
    public function testLoneModuleSectionDoesNotReadAsCombined(): void
    {
        self::assertFalse($this->grouper()->isCombined([['heading' => 'Account', 'items' => ['Alpha']]]));
    }

    /**
     * Test that a shared section beside a module section does not read as
     * combined.
     *
     * @return void
     */
    public function testSharedSectionBesideAModuleDoesNotReadAsCombined(): void
    {
        self::assertFalse($this->grouper()->isCombined([
            ['heading' => 'Common', 'items' => ['Alpha']],
            ['heading' => 'Account', 'items' => ['Beta']],
        ]));
    }

    /**
     * Build a grouper whose resolver maps the given classes to modules.
     *
     * @param  array<string, \SineMacula\ApiToolkit\OpenApi\Docs\Module>  $map
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\ModuleSectionGrouper
     */
    private function grouper(array $map = []): ModuleSectionGrouper
    {
        return new ModuleSectionGrouper(new MappedModuleResolver($map));
    }

    /**
     * Name each item's owning class, the item itself standing in for it.
     *
     * @return callable(string): string
     */
    private function subject(): callable
    {
        return static fn (string $item): string => $item;
    }

    /**
     * List the headings of the given sections in order.
     *
     * @param  list<array{heading: string, items: list<string>}>  $sections
     * @return list<string>
     */
    private function headings(array $sections): array
    {
        return array_map(static fn (array $section): string => $section['heading'], $sections);
    }
}
