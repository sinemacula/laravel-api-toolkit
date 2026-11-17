<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture measurement resource declaring every column filterable.
 *
 * The creation time is emitted as an ISO-8601 timestamp and the update time in
 * the date's own JSON form, so each emitted form can be sent back as a filter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MeasurementResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'measurements';

    /** @var array<int, string> */
    protected static array $default = ['id', 'created_at', 'updated_at'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable(Capability::RANGE),
            Field::scalar('count')->filterable(Capability::RANGE),
            Field::scalar('total')->filterable(Capability::RANGE),
            Field::scalar('ratio')->filterable(Capability::RANGE),
            Field::scalar('active')->filterable(Capability::EXACT),
            Field::scalar('day')->filterable(Capability::RANGE),
            Field::scalar('token')->filterable(Capability::EXACT),
            Field::scalar('label')->filterable(Capability::EXACT),
            Field::timestamp('created_at')->filterable(Capability::RANGE),
            Field::scalar('updated_at')->filterable(Capability::RANGE),
        );
    }
}
