<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture string-backed enum serialising its cases to their upper-case names.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum SerialisedStatus: string implements \JsonSerializable
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';

    /**
     * Serialise the case as its name rather than its backing value.
     *
     * @return string
     */
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->name;
    }
}
