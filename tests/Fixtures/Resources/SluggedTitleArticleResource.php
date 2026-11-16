<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource exposing a real column whose accessor reads another column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SluggedTitleArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'slugged_title_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

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
            Field::scalar('title'),
        );
    }
}
