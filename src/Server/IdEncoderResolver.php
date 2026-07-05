<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApiLaravel\Discovery\Discovery;

/**
 * Resolves a JSON:API type's id encoder from the resource that declares it: the
 * {@see Id} field's {@see IdEncoderInterface} (storage-key ⇄ wire-id codec) — the
 * Laravel twin of the Symfony bundle's `IdEncoderResolver` (bundle ADR 0038,
 * package ADR 0014).
 *
 * The encode/decode boundary is split by where the id flows: core owns the entity's
 * own-id transform (encode on serialize, decode a client-generated id on create), and
 * the reference Eloquent layer owns the id-as-lookup-key transforms the storage-agnostic
 * {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface} /
 * {@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface} SPI passes as
 * **wire** strings — the route `{id}` (decode before the keyed fetch) and linkage ids
 * (decode before the FK/pivot write). Those SPI signatures stay wire-id; only the
 * Eloquent reference pair decodes internally, so the in-memory witness (which has no
 * encoder) is unaffected and wire == storage there.
 *
 * It resolves a type through the memoized {@see Discovery} descriptors — matched by
 * their cached `type` WITHOUT instantiating anything, exactly how route registration
 * reads the Id route pattern — and constructs the one matching resource via the
 * container resolver. A type with no resource (a bare serializer/hydrator pair), no
 * {@see Id} field, or no encoder yields `null` — i.e. wire == storage, today's
 * behaviour. Resolutions are memoised.
 */
final class IdEncoderResolver
{
    /**
     * @var array<string, IdEncoderInterface|null>
     */
    private array $cache = [];

    /**
     * @param \Closure(class-string): object $resolver the container resolver resources are constructed through
     */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly \Closure $resolver,
    ) {}

    /**
     * The id encoder declared by `$type`'s resource, or `null` when the type has no
     * resource / no {@see Id} field / no encoder (wire == storage).
     */
    public function encoderFor(string $type): ?IdEncoderInterface
    {
        if (!\array_key_exists($type, $this->cache)) {
            $this->cache[$type] = $this->resolve($type);
        }

        return $this->cache[$type];
    }

    private function resolve(string $type): ?IdEncoderInterface
    {
        foreach ($this->discovery->resources() as $descriptor) {
            if ($descriptor->type !== $type) {
                continue;
            }

            $resource = ($this->resolver)($descriptor->class);
            if (!$resource instanceof AbstractResource) {
                return null;
            }

            foreach ($resource->fields() as $field) {
                if ($field instanceof Id) {
                    return $field->encoder();
                }
            }

            return null;
        }

        return null;
    }
}
