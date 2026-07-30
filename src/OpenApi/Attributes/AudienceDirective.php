<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Base for the audience inclusion and exclusion directives.
 *
 * Holds the shared list of audiences named by a directive. The concrete
 * subclasses stay distinct types so the resolver can tell inclusion from
 * exclusion by the attribute class alone.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract readonly class AudienceDirective
{
    /** @var list<string> The audiences named by the directive. */
    public array $audiences;

    /**
     * Create a new audience directive.
     *
     * @param  string  ...$audiences
     */
    public function __construct(string ...$audiences)
    {
        $this->audiences = array_values($audiences);
    }
}
