<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing\Attributes;

/**
 * Declare an action deliberately unauthorized beyond authentication.
 *
 * The action leaves the resource gate and the authorization guard; a gate the
 * route declares itself still runs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class SkipAuthorization {}
