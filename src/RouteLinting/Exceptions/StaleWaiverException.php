<?php

namespace SineMacula\ApiToolkit\RouteLinting\Exceptions;

/**
 * Exception thrown when an allowlist entry carries an empty reason at config-read time.
 *
 * Every exemption entry must supply a non-empty written justification; the
 * config adapter (task 12) validates this invariant and throws this exception
 * when the requirement is not met. This class is declared here alongside the
 * allowlist so both artefacts live in the same domain task.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StaleWaiverException extends \RuntimeException {}
