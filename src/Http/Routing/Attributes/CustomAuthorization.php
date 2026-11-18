<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing\Attributes;

/**
 * Declare that an action authorizes itself through a check the router cannot
 * see, such as $this->authorize() or a form request.
 *
 * The action leaves the resource gate and the authorization guard, and is still
 * documented as able to answer 403. Nothing verifies the check.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class CustomAuthorization {}
