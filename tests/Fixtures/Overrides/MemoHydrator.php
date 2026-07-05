<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The hand-written hydrator {@see MemoResource} points at via
 * `#[AsJsonApiResource(hydrator: MemoHydrator::class)]`. Its constructor takes a scalar
 * `$slugSeparator` with no default — only a contextual container binding
 * (`when()->needs('$slugSeparator')->give(…)`) can satisfy it, so a successful write
 * proves core resolved the override through the application container. The `title`
 * fan-out (one member fills the title AND derives the read-only `slug`) is the thing a
 * field-DSL resource cannot express — the reason to hand-write a hydrator at all.
 *
 * @internal
 */
final class MemoHydrator extends AbstractHydrator
{
    public function __construct(private readonly string $slugSeparator) {}

    protected function getAcceptedTypes(): array
    {
        return ['memos'];
    }

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        $separator = $this->slugSeparator;

        return [
            'title' => static function (mixed $memo, mixed $value, array $data, string $field) use ($separator): Memo {
                \assert($memo instanceof Memo);
                $title = \is_string($value) ? \trim($value) : '';
                $memo->title = $title;
                $memo->slug = \trim(\preg_replace('/[^a-z0-9]+/', $separator, \strtolower($title)) ?? '', $separator);

                return $memo;
            },
        ];
    }

    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        return [];
    }

    protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void
    {
        // Accepted: no-op.
    }

    protected function validateRequest(JsonApiRequestInterface $request): void
    {
        // No request-level pre-checks for memos.
    }

    protected function generateId(): string
    {
        return \bin2hex(\random_bytes(8));
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        \assert($domainObject instanceof Memo);
        $domainObject->id = $id;

        return $domainObject;
    }
}
