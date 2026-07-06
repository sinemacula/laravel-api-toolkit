<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Primary;

/**
 * Fixture class without the ForModel attribute; discovery must ignore it.
 *
 * The anonymous class exercises the tokenizer's anonymous-class skip when the
 * file is scanned.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PlainHelper
{
    /**
     * Return a throwaway anonymous object.
     *
     * @return object
     */
    public function anonymous(): object
    {
        return new class {};
    }
}
