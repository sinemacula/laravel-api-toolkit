<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Query;

use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * The page size bounds a read is held to: the configured pair, narrowed by the
 * resource's own ceiling.
 *
 * A ceiling of zero is disabled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PageSize
{
    /**
     * Constructor.
     *
     * @param  int  $ceiling
     * @param  int  $default
     * @return void
     */
    public function __construct(

        /** The largest page size a client may request, 0 for no ceiling */
        public int $ceiling,

        /** The page size of a read that names none */
        public int $default,
    ) {}

    /**
     * Resolve the bounds of the given resource, or the configured ones where it
     * declares none.
     *
     * @param  string|null  $resourceClass
     * @return self
     *
     * @throws \LogicException
     */
    public static function for(?string $resourceClass): self
    {
        $ceiling  = QuerySettings::maxLimit();
        $default  = QuerySettings::defaultLimit();
        $declared = $resourceClass !== null && is_subclass_of($resourceClass, ApiResource::class) ? $resourceClass::maxLimit() : null;

        if ($declared !== null) {

            if ($declared < 1) {
                throw new \LogicException(sprintf('%s::maxLimit() must return null or an integer of at least 1; [%d] given.', $resourceClass, $declared));
            }

            $ceiling = $ceiling > 0 ? min($ceiling, $declared) : $declared;
        }

        return new self($ceiling, $ceiling > 0 ? min($default, $ceiling) : $default);
    }

    /**
     * Resolve the page size of the given request, refusing one above the
     * ceiling.
     *
     * @param  int|null  $requested
     * @return int
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function resolve(?int $requested): int
    {
        if ($requested === null) {
            return $this->default;
        }

        if ($this->ceiling > 0 && $requested > $this->ceiling) {
            throw QueryTooExpensiveException::exceeded('limit', '', QueryParameterValidator::MAX_LIMIT, $this->ceiling, $requested);
        }

        return $requested;
    }

    /**
     * Determine whether these bounds differ from the given ones.
     *
     * @param  self  $other
     * @return bool
     */
    public function differsFrom(self $other): bool
    {
        return $this->ceiling !== $other->ceiling || $this->default !== $other->default;
    }
}
