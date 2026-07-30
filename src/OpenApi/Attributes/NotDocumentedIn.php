<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Exclude a route from the named OpenAPI audience(s).
 *
 * Placed on a controller class or action method, this directive removes the
 * route from each audience it names while leaving membership of all other
 * audiences untouched. When the same level both includes and excludes an
 * audience, the exclusion wins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class NotDocumentedIn extends AudienceDirective {}
