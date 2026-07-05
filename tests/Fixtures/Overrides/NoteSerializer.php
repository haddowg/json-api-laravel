<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;

/**
 * The hand-written serializer {@see NoteResource} points at via
 * `#[AsJsonApiResource(serializer: NoteSerializer::class)]`. Its constructor takes a
 * scalar `$stamp` with no default — only a contextual container binding
 * (`when()->needs('$stamp')->give(…)`) can satisfy it, so the `stamp` attribute (or
 * this type rendering at all) proves core resolved the override through the
 * application container, not a plain `new`.
 *
 * @internal
 */
final class NoteSerializer extends AbstractSerializer
{
    public function __construct(private readonly string $stamp) {}

    public function getType(mixed $object): string
    {
        return 'notes';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Note);

        return $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['served_by' => $this->stamp];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [
            'title' => static fn(mixed $note): string => $note instanceof Note ? $note->title : '',
            'stamp' => fn(mixed $note): string => $this->stamp,
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
