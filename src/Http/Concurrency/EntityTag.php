<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Concurrency;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\Versioned;

/**
 * Derives the weak entity tag of a versioned model's stored state.
 *
 * The tag is weak because it tracks the stored row, not the bytes of any one
 * representation of it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EntityTag
{
    /**
     * Derive the model's entity tag, or null when it carries no version or no
     * key.
     *
     * The version is read through its cast, so a driver's numeric string and an
     * integer tag alike, as do equal instants in different offsets.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    public static function for(Model $model): ?string
    {
        if (!$model instanceof Versioned || !array_key_exists($model->getVersionColumn(), $model->getAttributes())) {
            return null;
        }

        $version = $model->getAttribute($model->getVersionColumn());
        $version = $version instanceof \DateTimeInterface ? $version->format('U.u') : $version;
        $key     = $model->getKey();

        if (!is_scalar($version) || !is_scalar($key)) {
            return null;
        }

        return sprintf('W/"%s"', sha1(sprintf('%s|%s|%s', $model->getMorphClass(), $key, $version)));
    }
}
