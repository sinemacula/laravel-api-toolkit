<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource whose fields declare example values only.
 *
 * The `name` column keeps its inferred type, the `created_at` timestamp keeps
 * the type it declares itself, and the computed `initials` stays undocumented,
 * each carrying its example.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExampledUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'exampled_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name'];

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
            Field::scalar('name')->openapi()->example('Ada Lovelace')->end(),
            Field::timestamp('created_at')->openapi()->example('2025-01-01T00:00:00Z')->end(),
            Field::compute('initials', static fn ($resource): string => 'AL')->openapi()->example('AL')->end(),
        );
    }
}
