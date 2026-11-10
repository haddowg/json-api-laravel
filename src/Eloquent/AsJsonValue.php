<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The cast for a `json` column holding a {@see \haddowg\JsonApi\Resource\Field\Union}'s
 * value. The write path sets the column to the union's stored form, and this cast encodes
 * it keeping a zero fraction, as Doctrine's `json` type does, so a `Decimal` member's `3.0`
 * reads back as a float. Laravel's `array` cast would store `3`. A `null` is stored as SQL
 * `NULL`.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class AsJsonValue implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return \is_string($value) ? \json_decode($value, true, 512, \JSON_THROW_ON_ERROR) : $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
