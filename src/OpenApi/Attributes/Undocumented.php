<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Exclude a route from every OpenAPI audience.
 *
 * Placed on a controller class or action method, this blanket directive keeps
 * the route out of all documented audiences. It can be overridden for a single
 * audience by a more specific DocumentedIn at the same or a deeper level, which
 * is how "document only in audience x" is expressed (Undocumented plus
 * DocumentedIn(x)).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Undocumented {}
