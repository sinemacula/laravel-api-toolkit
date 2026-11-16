<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Fixture resource whose raw schema cannot be compiled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UncompilableResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'uncompilable';

    /** @var array<int, string> */
    protected static array $default = ['id'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'id'   => ['accessor' => 'id'],
            'name' => ['accessor' => 'name', 'guards' => ['not_a_function']],
        ];
    }
}
