<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource whose email field is guarded per-item.
 *
 * The guard inspects the row, so the tabular trait cannot honour it on a flat
 * column and must refuse to derive the schema. Used to prove the dedicated
 * build-time exception is thrown.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PerItemGuardedExportResource extends ApiResource implements ProvidesTabularExport
{
    use DerivesTabularSchema;

    /** @var string */
    public const string RESOURCE_TYPE = 'per_item_guarded_exports';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
            Field::scalar('email')->guard(static function ($resource): bool {
                $model = $resource->resource;

                return $model instanceof User && $model->status === 'active';
            }),
        );
    }
}
