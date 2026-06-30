<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;

/**
 * Fixture resource exercising the tabular trait's transformer and
 * request-scoped guard handling.
 *
 * The name field carries a transformer so the export can be checked against the
 * JSON value. The secret field carries a request-scoped guard so the column can
 * be proven to drop out of the export when the guard hides the field.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardedExportResource extends ApiResource implements ProvidesTabularExport
{
    use DerivesTabularSchema;

    /** @var string */
    public const string RESOURCE_TYPE = 'guarded_exports';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email', 'secret'];

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
            Field::scalar('name')->transform(static fn ($resource, $value): string => strtoupper(is_string($value) ? $value : '')),
            Field::scalar('email'),
            Field::scalar('secret')->guard(static fn ($resource, $request): bool => $request?->query('show') === 'yes'),
        );
    }
}
