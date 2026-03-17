<?php

namespace SineMacula\ApiToolkit\Services\Contracts;

use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Service interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceInterface
{
    /**
     * Run the service.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceResult
     */
    public function run(): ServiceResult;
}
