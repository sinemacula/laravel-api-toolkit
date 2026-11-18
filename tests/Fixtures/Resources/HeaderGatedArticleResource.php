<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture article resource that opts in to trashed visibility per request.
 *
 * The gate opens only when the request carries the trashed-access header, so
 * two requests for the same URL can reach opposite decisions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class HeaderGatedArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'header_gated_articles';

    /** @var string The header that opens the trashed gate */
    public const string HEADER = 'X-Trashed-Access';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

    /**
     * Open the trashed gate only for requests carrying the access header.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    #[\Override]
    public static function allowsTrashed(Request $request): bool
    {
        return $request->hasHeader(self::HEADER);
    }

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
