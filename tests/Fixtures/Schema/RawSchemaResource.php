<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Schema;

/**
 * Resource stand-in declaring whichever raw schema a test hands it, so the
 * schema compiler reads it exactly as it reads a resource's own declaration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RawSchemaResource
{
    /** @var array<string, array<string, mixed>> */
    private static array $schema = [];

    /**
     * Declare the raw schema the resource returns.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return void
     */
    public static function declare(array $schema): void
    {
        self::$schema = $schema;
    }

    /**
     * Return the declared raw schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return self::$schema;
    }
}
