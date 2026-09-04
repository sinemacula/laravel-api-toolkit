<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Fixture resource whose one field names a different column in each marker.
 *
 * Written as a raw schema array because the field builder cannot express it:
 * every marker a built field emits names the field's own column. A hand-written
 * schema may name one column filterable and another sortable on the same field,
 * so a reader collapsing the two into one key would attribute a capability to a
 * column that never declared it. The sortable column is exempted from index
 * backing, so the recorded reason has a sibling column it must not travel to.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SplitColumnMarkerResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'split_column_markers';

    /** @var array<int, string> */
    protected static array $default = ['label'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'label' => [
                'filterable' => 'label_filter',
                'capability' => Capability::EXACT,
                'sortable'   => 'label_sort',
                'unindexed'  => 'the label table is a fixed lookup',
            ],
        ];
    }
}
