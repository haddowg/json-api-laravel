<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Server\Server;

/**
 * The wire id a stored reference names, for every place that reads a polymorphic pair or a
 * link row: the storage key or the wire id, as the type's serializer renders it through the
 * id encoder it declares.
 *
 * @internal
 */
final class StoredIds
{
    /**
     * A stored id of `$type`, the storage key or the wire id, as its wire id. A key held as a
     * value object is its string form.
     */
    public static function wireId(Server $server, string $type, string|int|\Stringable $key): string
    {
        if ($key instanceof \Stringable) {
            $key = (string) $key;
        }
        $serializer = $server->hasSerializerFor($type) ? $server->serializerFor($type) : null;
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return StoredIdForms::wireId($field->encoder(), $key);
                }
            }
        }

        return (string) $key;
    }
}
