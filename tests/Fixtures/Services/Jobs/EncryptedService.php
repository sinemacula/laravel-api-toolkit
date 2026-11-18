<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service whose queued payload is encrypted.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EncryptedService extends Service implements ShouldBeEncrypted
{
    /**
     * Complete the run.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): null
    {
        return null;
    }
}
