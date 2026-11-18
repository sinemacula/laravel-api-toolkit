<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Models\Note;

/**
 * Fixture note resource reading its polymorphic parent and its bounded
 * polymorphic labels.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NoteResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'notes';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('title'),
            Field::accessor('subject', static function ($resource): ?string {

                $note = $resource->resource;

                assert($note instanceof Note);

                $noteable = $note->noteable;

                if ($noteable === null) {
                    return null;
                }

                $key = $noteable->getKey();

                assert(is_int($key));

                return sprintf('%s on %s %d', $note->title, class_basename($noteable), $key);
            })->needs('title')->extras('noteable'),
            Relation::to('labels', TagResource::class)->limit(2),
        );
    }
}
