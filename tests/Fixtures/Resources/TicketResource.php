<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Fixture ticket resource declaring schemas on the columns a route can key on.
 *
 * `code` emits its column and `reference` transforms it. `priority` declares a
 * reference other than its enum cast, and `rank` a string-typed reference to an
 * enum component without that enum's cast.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TicketResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'tickets';

    /** @var array<int, string> */
    protected static array $default = ['id', 'code'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        $schema = Field::set(
            Field::scalar('id'),
            Field::scalar('code')->openapi()->type('string')->format('ticket-code')->nullable()->end(),
            Field::scalar('reference')
                ->transform(static fn ($resource, mixed $value): string => 'REF-' . (is_scalar($value) ? $value : ''))
                ->openapi()->type('string')->end(),
            Field::scalar('priority'),
            Field::scalar('rank'),
        );

        $schema['priority']['openapi'] = OpenApiFieldSchema::reference('#/components/schemas/Widget');
        $schema['rank']['openapi']     = new OpenApiFieldSchema(type: 'string', ref: '#/components/schemas/TicketPriority');

        return $schema;
    }
}
