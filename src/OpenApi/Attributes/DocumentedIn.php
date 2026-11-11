<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Include a route in the named OpenAPI audience(s).
 *
 * Overrides a blanket Undocumented for the named audiences; an exclusion at the
 * same level wins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class DocumentedIn extends AudienceDirective {}
