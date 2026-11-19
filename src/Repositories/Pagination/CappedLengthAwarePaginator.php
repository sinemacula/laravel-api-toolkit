<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Pagination;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * An offset page whose total stops at the rows offset pagination can reach.
 *
 * More rows match than the total reports; read past it with cursor pagination.
 *
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends \Illuminate\Pagination\LengthAwarePaginator<TKey, TValue>
 *
 * @inheritable
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class CappedLengthAwarePaginator extends LengthAwarePaginator {}
