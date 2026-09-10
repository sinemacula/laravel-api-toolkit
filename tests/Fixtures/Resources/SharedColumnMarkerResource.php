<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Fixture resource whose fields name one another's columns.
 *
 * One field makes a column orderable and two other fields name that same column
 * without declaring an order of their own, which is only expressible in a
 * hand-written schema. A reader deciding an order from the resource's sortable
 * set alone would report every one of them orderable, so the fixture pins the
 * order to the field that actually declared it. One of those fields also
 * declares a filterable marker the compiled maps refuse, ahead of a column they
 * carry, so skipping the refused marker cannot be mistaken for abandoning the
 * rest of the field.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SharedColumnMarkerResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'shared_column_markers';

    /** @var array<int, string> */
    protected static array $default = ['owner'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'owner' => ['sortable' => 'shared'],
            'note'  => ['filterable' => 'refused', 'sortable' => 'shared'],
            'alias' => ['filterable' => 'shared', 'capability' => Capability::ENUM],
        ];
    }
}
