<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Include a route in the named OpenAPI audience(s).
 *
 * Placed on a controller class or action method, this directive adds the route
 * to each audience it names. It is additive: it opts the route into an
 * allowlist audience without removing it from any other, and it overrides a
 * blanket Undocumented for the audiences it names. When the same level both
 * includes and excludes an audience, the exclusion wins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class DocumentedIn extends AudienceDirective {}
