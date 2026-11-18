<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture article resource flagging whether the caller may restore each row.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RestorableArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'restorable_articles';

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
            Field::can('restore')->needs('user_id'),
        );
    }
}
