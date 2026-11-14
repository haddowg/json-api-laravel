<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a canonical UUID as its 16 bytes, and reads the bytes back as the canonical string.
 *
 * @implements CastsAttributes<string, string>
 */
final class BinaryUuid implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (!\is_string($value) || \strlen($value) !== 16) {
            return \is_string($value) ? $value : null;
        }

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($value), 4));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (!\is_string($value) || \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            return \is_string($value) ? $value : null;
        }

        $bytes = \hex2bin(\str_replace('-', '', $value));

        return $bytes === false ? null : $bytes;
    }
}
