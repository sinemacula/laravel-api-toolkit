<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input\Concerns;

/**
 * Trait fixture with no constructor.
 *
 * Lets a payload mix in a trait that promotes nothing, proving the sensitive
 * lookup tolerates traits without a constructor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait DescribesInput
{
    /**
     * Return a short description of the input.
     *
     * @return string
     */
    public function describe(): string
    {
        return static::class;
    }
}
