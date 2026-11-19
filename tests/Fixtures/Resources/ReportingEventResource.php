<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource over events kept in a schema of their own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ReportingEventResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'reporting_events';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'body'];

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
            Field::scalar('title')->sortable(),
            Field::scalar('body'),
        );
    }
}
