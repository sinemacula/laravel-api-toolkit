<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;

/**
 * Fixture base controller declaring a header for every action it handles.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('X-Api-Version', 'The version of the API that answered.')]
#[ResponseHeader('X-Request-Id', 'The identifier the base controller assigns.')]
abstract class PathHeaderBaseController {}
