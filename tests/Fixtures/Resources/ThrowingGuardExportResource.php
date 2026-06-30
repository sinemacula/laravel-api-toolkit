<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;

/**
 * Fixture resource whose secret field carries a guard that errors when it is
 * evaluated against a probe row.
 *
 * A guard that cannot be evaluated without a real row cannot be classified as
 * request-scoped, so the trait fails closed and refuses to derive the schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ThrowingGuardExportResource extends ApiResource implements ProvidesTabularExport
{
    use DerivesTabularSchema;

    /** @var string */
    public const string RESOURCE_TYPE = 'throwing_guard_exports';

    /** @var array<int, string> */
    protected static array $default = ['id', 'secret'];

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
            Field::scalar('secret')->guard(self::erroringGuard(...)),
        );
    }

    /**
     * A guard that cannot run without a real row.
     *
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return never
     *
     * @throws \RuntimeException
     */
    private static function erroringGuard(mixed $resource, ?Request $request): never
    {
        throw new \RuntimeException('guard needs a real row');
    }
}
