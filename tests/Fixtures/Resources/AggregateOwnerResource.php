<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Average;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Min;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture owner resource aggregating its lines' decimal columns, the price cast
 * by the line model and the cost uncast.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AggregateOwnerResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'aggregate_owners';

    /** @var array<int, string> */
    protected static array $default = ['id', 'aggregates'];

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
            Sum::of('lines', 'price')->default(),
            Average::of('lines', 'price')->default(),
            Min::of('lines', 'price')->default(),
            Max::of('lines', 'cost')->default(),
        );
    }
}
