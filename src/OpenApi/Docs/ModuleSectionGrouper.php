<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;

/**
 * Groups a generated document's items into the sections it renders.
 *
 * Every auto-generated reference is grouped the same way: an item whose owning
 * class belongs to a module gathers under that module's section, the modules
 * ordered by name, beneath an optional shared "Common" section holding the
 * items that belong to none. A flat application resolves every class to no
 * module, so the whole document collapses to that shared section, which a
 * generator renders without module headings as the degenerate case of the same
 * grouping rather than as a separate mode.
 *
 * The items within a section are ordered by the comparator the caller supplies,
 * or left in the order they arrived where it supplies none, so a document that
 * reads in component-name order and one that orders its own rows share the
 * grouping without sharing the ordering.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ModuleSectionGrouper
{
    /** The heading for items that belong to no module. */
    public const string COMMON = 'Common';

    /**
     * Create a new module section grouper.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver  $resolver
     * @return void
     */
    public function __construct(

        /** Resolves the module each item's owning class belongs to. */
        private ModuleResolver $resolver,
    ) {}

    /**
     * Group the given items into an ordered list of sections, the shared
     * section first followed by one section per module sorted by name.
     *
     * @template TItem
     *
     * @param  array<int, TItem>  $items
     * @param  callable(TItem): (string|null)  $subject
     * @param  (callable(TItem, TItem): int)|null  $order
     * @return list<array{heading: string, items: list<TItem>}>
     */
    public function group(array $items, callable $subject, ?callable $order = null): array
    {
        $common  = [];
        $modules = [];

        foreach ($items as $item) {

            $class  = $subject($item);
            $module = $class === null ? null : $this->resolver->resolve($class);

            if ($module === null) {
                $common[] = $item;
                continue;
            }

            $modules[$module->key] ??= ['name' => $module->name, 'items' => []];
            $modules[$module->key]['items'][] = $item;
        }

        return $this->sections($common, $modules, $order);
    }

    /**
     * Determine whether the sections collapse to the flat output carrying no
     * module headings, which holds when nothing is grouped under a module.
     *
     * @template TItem
     *
     * @param  list<array{heading: string, items: list<TItem>}>  $sections
     * @return bool
     */
    public function isCombined(array $sections): bool
    {
        return $sections === []
            || (count($sections) === 1 && $sections[0]['heading'] === self::COMMON);
    }

    /**
     * Assemble the ordered section list, sorting the modules by name and the
     * items of each section by the comparator where one was supplied.
     *
     * @template TItem
     *
     * @param  list<TItem>  $common
     * @param  array<string, array{name: string, items: list<TItem>}>  $modules
     * @param  (callable(TItem, TItem): int)|null  $order
     * @return list<array{heading: string, items: list<TItem>}>
     */
    private function sections(array $common, array $modules, ?callable $order): array
    {
        uasort($modules, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $sections = [];

        if ($common !== []) {
            $sections[] = ['heading' => self::COMMON, 'items' => $this->ordered($common, $order)];
        }

        foreach ($modules as $module) {
            $sections[] = ['heading' => $module['name'], 'items' => $this->ordered($module['items'], $order)];
        }

        return $sections;
    }

    /**
     * Order one section's items by the given comparator, leaving them as they
     * arrived where none was supplied.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @param  (callable(TItem, TItem): int)|null  $order
     * @return list<TItem>
     */
    private function ordered(array $items, ?callable $order): array
    {
        if ($order !== null) {
            usort($items, $order);
        }

        return $items;
    }
}
