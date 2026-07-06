<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Primary\Contracts;

/**
 * Fixture interface sorted before the discoverable resources; the scan must
 * pass over a file that declares no class and still discover the rest.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface AaContract {}
