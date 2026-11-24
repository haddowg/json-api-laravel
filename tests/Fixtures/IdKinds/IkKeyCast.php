<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a text key as an {@see IkKey} and stores its text.
 *
 * @implements CastsAttributes<IkKey|null, IkKey|string|null>
 */
final class IkKeyCast implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?IkKey
    {
        return \is_string($value) || \is_int($value) ? new IkKey((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof IkKey ? $value->value : (\is_string($value) ? $value : null);
    }
}
