<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The per-type authorization overrides read off a resource's
 * {@see \haddowg\JsonApiLaravel\Attribute\AsJsonApiResource} attribute (PLAN decision 7):
 * an optional dedicated API policy class invoked directly, and a per-operation ability
 * map that renames (a `string`) or disables (`false`) the Gate ability checked for an
 * operation. A resource that declares neither carries the inert default — the model's
 * Gate-registered policy, or no check at all.
 */
final readonly class ResourceAuthorization
{
    /**
     * @param class-string|null           $policy       a dedicated API policy class invoked directly (null = the model's Gate-registered policy path)
     * @param array<string, string|false> $abilities    per-operation ability override keyed by {@see Operation} case value
     * @param class-string|null           $subjectClass a stable class token for enforcing a declared `policy:` on a class-level
     *                                                   operation (`viewAny`/`create`) when no instance is available — a read-only
     *                                                   type with no persister mints no list subject, and a class-level policy
     *                                                   ability never receives one anyway. The resource class is used, purely as a
     *                                                   scoping key for the throwaway Gate's policy map, so a declared policy is
     *                                                   still enforced (no fail-open). Null leaves the pre-instance behaviour.
     */
    public function __construct(
        public ?string $policy,
        public array $abilities,
        public ?string $subjectClass = null,
    ) {}

    /**
     * The ability override for an operation: a renamed ability (`string`), `false` to
     * disable the check, or `null` when the operation carries no override (the caller
     * falls back to the policy-convention default).
     */
    public function ability(Operation $operation): string|false|null
    {
        return $this->abilities[$operation->value] ?? null;
    }
}
