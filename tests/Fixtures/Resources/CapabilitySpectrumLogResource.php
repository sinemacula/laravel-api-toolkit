<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture log resource declaring one column per query capability.
 *
 * Each of the five cases answers a different operator set, so a contract test
 * putting the operators the document advertises to the request-time gate covers
 * every row of the matrix from a single resource. Every declaration names a
 * real column of the logs table: the key is read by equality, the level is a
 * closed set, the timestamp is ordered, the context is a JSON document, and the
 * message vouches for no access path at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CapabilitySpectrumLogResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'capability_spectrum_logs';

    /** @var array<int, string> */
    protected static array $default = ['id', 'level', 'message'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable(Capability::EXACT),
            Field::scalar('level')->filterable(Capability::ENUM),
            Field::scalar('message')->filterable(Capability::OPAQUE),
            Field::scalar('context')->filterable(Capability::DOCUMENT),
            Field::timestamp('created_at')->filterable(Capability::RANGE),
        );
    }
}
