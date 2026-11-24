<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Server\Server;
use Illuminate\Support\Str;

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
                    $encoder = $field->encoder();

                    return $encoder !== null ? StoredIdForms::wireId($encoder, $key) : self::canonical($key);
                }
            }
        }

        return self::canonical($key);
    }

    /**
     * `$id` as a string, a UUID in its canonical lowercase form, so a UUID stored in another
     * case names the same row.
     */
    public static function canonical(string|int $id): string
    {
        return \is_string($id) && Str::isUuid($id) ? \strtolower($id) : (string) $id;
    }

    /**
     * `$ids` and, for each UUID among them, its other letter case, so a column holding a UUID
     * in either case matches it.
     *
     * @param list<mixed> $ids
     *
     * @return list<mixed>
     */
    public static function eitherCase(array $ids): array
    {
        $forms = [];
        foreach ($ids as $id) {
            $forms[] = $id;
            if (\is_string($id) && Str::isUuid($id)) {
                $forms[] = \strtolower($id);
                $forms[] = \strtoupper($id);
            }
        }

        return \array_values(\array_unique($forms, \SORT_REGULAR));
    }
}
