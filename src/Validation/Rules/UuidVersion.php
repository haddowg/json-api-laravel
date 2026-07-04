<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Enforces a **version-specific** core
 * {@see \haddowg\JsonApi\Resource\Constraint\UuidFormat}. The native `uuid` rule
 * accepts any RFC 4122 version, so a `uuid(4)` constraint (a specific version) ships as
 * this rule, which additionally checks the version nibble. A non-string value is left
 * to the type layer and skipped.
 */
final class UuidVersion implements ValidationRule
{
    public function __construct(private readonly int $version) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value)) {
            return;
        }

        if (!Str::isUuid($value) || !$this->isVersion($value)) {
            $fail('validation.uuid')->translate();
        }
    }

    /**
     * Whether the UUID's version nibble (the first character of the third group) equals
     * the required version.
     */
    private function isVersion(string $uuid): bool
    {
        $groups = \explode('-', $uuid);

        return isset($groups[2]) && $groups[2] !== '' && $groups[2][0] === (string) $this->version;
    }
}
