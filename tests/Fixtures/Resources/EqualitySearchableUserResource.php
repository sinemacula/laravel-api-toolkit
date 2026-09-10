<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource declaring an equality-only search surface.
 *
 * `name` is the one searchable column and it is declared for the equality
 * strategy, which every supported engine answers from an ordinary index leading
 * with that column. It gives the engine suites a surface to prove the equality
 * match against without asking an engine to combine it with a pattern.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EqualitySearchableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'equality_searchable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

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
            Field::scalar('name')->searchable(SearchStrategy::EXACT),
            Field::scalar('email'),
        );
    }
}
