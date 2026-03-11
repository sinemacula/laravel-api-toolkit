<?php

namespace SineMacula\ApiToolkit\Events;

/**
 * Dispatched after a centralized toolkit cache flush completes.
 *
 * Consumers can listen for this event to perform post-flush cleanup
 * or re-warming of application-level caches.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheFlushed {}
