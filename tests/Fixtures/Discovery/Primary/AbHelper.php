<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Primary;

/**
 * Fixture class sorted before the discoverable resources; the scan must pass
 * over a class without the ForModel attribute and still discover the rest.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AbHelper {}
