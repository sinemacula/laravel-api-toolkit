<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Raised when a failed service result is thrown but captured no exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ServiceFailedException extends \RuntimeException
{
    /**
     * Create a new service failed exception instance.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceResult<mixed>  $result
     * @return void
     */
    public function __construct(

        /** The failed result that was thrown */
        private readonly ServiceResult $result,
    ) {
        parent::__construct('The service failed without capturing an exception.');
    }

    /**
     * Return the failed result that was thrown.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<mixed>
     */
    public function result(): ServiceResult
    {
        return $this->result;
    }
}
