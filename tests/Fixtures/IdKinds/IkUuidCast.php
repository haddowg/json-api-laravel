<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Reads a 16-byte column as a UUID object and stores a UUID, canonical or as bytes, as its
 * bytes.
 *
 * @implements CastsAttributes<UuidInterface|null, UuidInterface|string|null>
 */
final class IkUuidCast implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?UuidInterface
    {
        return \is_string($value) && \strlen($value) === 16 ? Uuid::fromBytes($value) : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof UuidInterface) {
            return $value->getBytes();
        }
        if (\is_string($value) && Uuid::isValid($value)) {
            return Uuid::fromString($value)->getBytes();
        }

        return \is_string($value) ? $value : null;
    }
}
