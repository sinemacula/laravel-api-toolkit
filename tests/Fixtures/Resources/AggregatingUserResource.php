<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Average;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Min;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture user resource declaring every relation aggregate function.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AggregatingUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'aggregating_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'aggregates'];

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
            Count::of('posts')->default(),
            Exists::of('posts', 'has_published_posts')->constrain(static function (Builder $query): void {
                $query->where('published', true);
            })->default(),
            Sum::of('posts', 'id')->default(),
            Average::of('posts', 'id')->default(),
            Min::of('posts', 'created_at')->default(),
            Max::of('posts', 'created_at', 'last_posted_at')->default(),
        );
    }
}
