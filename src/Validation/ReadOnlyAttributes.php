<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Exception\AttributeWriteProhibited;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RejectsReadOnlyAttributesInterface;

/**
 * Refuses a write body that sends an attribute the client cannot write in this request, when
 * the server rejects read-only attributes. It is the check core runs while hydrating, run
 * before validation so the `403` wins over a `422` for the same body.
 *
 * @internal
 */
final class ReadOnlyAttributes
{
    /**
     * @throws AttributeWriteProhibited
     */
    public static function reject(RejectsReadOnlyAttributesInterface $server, AbstractResource $resource, JsonApiRequestInterface $request, bool $creating): void
    {
        if (!$server->rejectsReadOnlyAttributes()) {
            return;
        }

        $data = $request->getResource();
        $attributes = \is_array($data) ? ($data['attributes'] ?? null) : null;
        if (!\is_array($attributes)) {
            return;
        }

        $prohibited = [];
        foreach ($resource->allFields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface || $field->isHidden()) {
                continue;
            }
            if (\array_key_exists($field->name(), $attributes) && $field->isReadOnlyFor($creating, $request)) {
                $prohibited[] = $field->name();
            }
        }

        if ($prohibited !== []) {
            throw new AttributeWriteProhibited($prohibited);
        }
    }
}
