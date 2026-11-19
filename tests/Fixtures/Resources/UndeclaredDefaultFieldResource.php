<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource whose default fieldset names a field it does not declare.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UndeclaredDefaultFieldResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'undeclared_default_field';

    /** @var array<int, string> */
    protected static array $default = ['id', 'nickname'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(Field::scalar('id'));
    }
}
