<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture comment resource with the flag the API documentation's examples
 * filter by.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ManualCommentResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'comment';

    /** @var array<int, string> */
    protected static array $default = ['id', 'body'];

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
            Field::scalar('body'),
            Field::scalar('flagged')->filterable(Capability::EXACT),
        );
    }
}
