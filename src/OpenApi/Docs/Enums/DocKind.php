<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs\Enums;

/**
 * The kinds of keyed description file, each backed by its directory name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum DocKind: string
{
    /** Operation descriptions, keyed by operationId */
    case OPERATION = 'operations';

    /** Tag descriptions, keyed by tag name */
    case TAG = 'tags';

    /**
     * Name what a file of the kind describes, for failure messages.
     *
     * @return string
     */
    public function noun(): string
    {
        return match ($this) {
            self::OPERATION => 'operation',
            self::TAG       => 'tag',
        };
    }
}
