<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource declaring a prefix-only search surface.
 *
 * `email` is the one searchable column and it is declared for the leading-match
 * strategy, which every supported engine reads from an ordinary index. It gives
 * the engine suites a surface to prove the prefix match against without asking
 * an engine to combine that shape with an anywhere-match.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PrefixSearchableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'prefix_searchable_users';

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
            Field::scalar('name'),
            Field::scalar('email')->searchable(SearchStrategy::PREFIX),
        );
    }
}
