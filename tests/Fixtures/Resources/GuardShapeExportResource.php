<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture resource presenting the email once per guard shape consumers write.
 *
 * The row guards are refused by a tabular export, whatever they read; the
 * caller guards decide the whole column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardShapeExportResource extends ApiResource implements ProvidesTabularExport
{
    use DerivesTabularSchema;

    /** @var string */
    public const string RESOURCE_TYPE = 'guard_shape_exports';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name'];

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
            Field::accessor('typed_email', 'email')
                ->guard(static fn (ApiResource $resource, ?Request $request): bool => data_get($request?->user(), 'is_admin') === true),
            Field::accessor('concrete_email', 'email')
                ->guard(static fn (self $resource): bool => data_get($resource->resource, 'status') === UserStatus::ACTIVE), // @phpstan-ignore argument.type
            Field::accessor('checked_email', 'email')
                // @phpstan-ignore instanceof.alwaysTrue
                ->guard(static fn ($resource): bool => !$resource instanceof ApiResource || data_get($resource->resource, 'status') === UserStatus::ACTIVE),
            Field::accessor('gated_email', 'email')
                ->guard(static fn (ApiResource $resource): bool => Gate::allows('view-email', $resource->resource)),
            Field::accessor('caller_email', 'email')
                ->visibleTo(static fn (?Request $request): bool => data_get($request?->user(), 'is_admin') === true),
            Field::accessor('gated_caller_email', 'email')
                ->visibleTo(static fn (): bool => Gate::allows('view-emails')),
        );
    }
}
