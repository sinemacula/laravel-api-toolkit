<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource exercising a request-scoped guard and a transformer.
 *
 * The name field carries a transformer so its output can be checked in the JSON
 * body, and the email field carries a request-scoped guard so the key can be
 * proven to drop out of the response unless the request permits it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'guarded_users';

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
            Field::scalar('name')->transform(static fn ($resource, $value): string => strtoupper(is_string($value) ? $value : '')),
            Field::scalar('email')->guard(static fn ($resource, $request): bool => $request?->query('show') === 'yes'),
        );
    }
}
