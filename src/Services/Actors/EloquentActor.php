<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Actors;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use SineMacula\ApiToolkit\Services\Contracts\Actor;

/**
 * Queue-serialisable actor adapting an Eloquent user/model.
 *
 * Wraps any Eloquent model that also implements Authenticatable (the standard
 * Laravel user) and exposes the Actor contract. Serialises as a morph-type +
 * identifier pair plus a snapshot of the label taken at construction time. On a
 * queue worker the model is re-resolved from the morph map via a fresh DB
 * query; the label and identifier scalars survive even when the underlying
 * record is later changed or deleted.
 *
 * Label resolution order (first non-empty string wins):
 *   1. The `name` attribute on the model.
 *   2. The `email` attribute on the model.
 *   3. The unqualified class name (class_basename).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EloquentActor implements Actor
{
    /** @var string Morph alias captured at construction time. */
    private string $morphType;

    /** @var int|string Primary key captured at construction time. */
    private int|string $identifier;

    /** @var string Human-readable label snapshotted at construction time. */
    private string $label;

    /**
     * The live model instance.
     *
     * Null after unserialisation until the first call to toAuthenticatable().
     * Also null when the underlying record has been deleted and re-resolution
     * returned nothing.
     *
     * @var (\Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model)|null
     */
    private (Authenticatable&Model)|null $model = null;

    /**
     * Whether the model has been resolved (or attempted) after unserialisation.
     * False only between __unserialize and the first toAuthenticatable() call.
     *
     * @var bool
     */
    private bool $resolved = false;

    /**
     * Create a new EloquentActor for the given model.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model  $model
     */
    public function __construct(Authenticatable&Model $model)
    {
        $key = $model->getKey();

        $this->morphType  = $model->getMorphClass();
        $this->identifier = match (true) {
            is_int($key)    => $key,
            is_string($key) => $key,
            default         => '',
        };
        $this->label    = $this->resolveLabel($model);
        $this->model    = $model;
        $this->resolved = true;
    }

    /**
     * Serialise as morph type + identifier + label snapshot only.
     *
     * The live model is intentionally excluded so the serialised payload
     * contains only re-resolvable scalars.
     *
     * @return array<string, int|string>
     */
    public function __serialize(): array
    {
        return [
            'morph_type' => $this->morphType,
            'identifier' => $this->identifier,
            'label'      => $this->label,
        ];
    }

    /**
     * Restore scalar state from the serialised payload.
     *
     * The model is left null and will be re-resolved from the morph map on the
     * first toAuthenticatable() call.
     *
     * @param  array<string, int|string>  $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $morphType  = $data['morph_type'] ?? '';
        $identifier = $data['identifier'] ?? '';
        $label      = $data['label']      ?? '';

        $this->morphType  = is_string($morphType) ? $morphType : '';
        $this->identifier = $identifier;
        $this->label      = is_string($label) ? $label : '';
        $this->model      = null;
        $this->resolved   = false;
    }

    /**
     * Create an EloquentActor for the given model.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model  $model
     * @return self
     */
    public static function for(Authenticatable&Model $model): self
    {
        return new self($model);
    }

    /**
     * Return the primary key captured at construction time.
     *
     * @return int|string
     */
    #[\Override]
    public function actorIdentifier(): int|string
    {
        return $this->identifier;
    }

    /**
     * Return the morph alias captured at construction time.
     *
     * @return string
     */
    #[\Override]
    public function actorType(): string
    {
        return $this->morphType;
    }

    /**
     * Return the label snapshotted at construction time.
     *
     * The snapshot is retained verbatim so attribution survives subsequent
     * changes to or deletion of the underlying record.
     *
     * @return string
     */
    #[\Override]
    public function actorLabel(): string
    {
        return $this->label;
    }

    /**
     * Return the live Authenticatable model.
     *
     * After unserialisation the model is re-resolved lazily from the morph map
     * on the first call. Returns null when the record cannot be found (e.g.
     * after deletion).
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    #[\Override]
    public function toAuthenticatable(): ?Authenticatable
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $class          = Relation::getMorphedModel($this->morphType);

            if ($class !== null) {
                /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
                $found = $class::query()->find($this->identifier);

                if ($found instanceof Authenticatable) {
                    $this->model = $found;
                }
            }
        }

        return $this->model;
    }

    /**
     * Resolve a human-readable label from the model's attributes.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    private function resolveLabel(Authenticatable&Model $model): string
    {
        $name = $model->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $email = $model->getAttribute('email');

        if (is_string($email) && $email !== '') {
            return $email;
        }

        return class_basename($model);
    }
}
