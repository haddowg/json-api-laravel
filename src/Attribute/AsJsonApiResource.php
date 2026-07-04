<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Optional metadata for a JSON:API resource. Discovery is zero-config by default
 * (any {@see \haddowg\JsonApi\Resource\AbstractResource} under a scanned path is
 * auto-registered); this attribute is only needed to carry extras — assigning the
 * resource to one or more named servers, overriding the exposed operation set, or
 * marking a type read-only.
 *
 * `server` names the server(s) this resource is exposed on: a single server name,
 * a list of names (the same type may join several servers at once), or `null` for
 * the implicit `default` server.
 *
 * The resource `type` is normally read from the class's static `$type`; the optional
 * `type` here is a declaration-site override for the rare case that differs.
 *
 * `operations` is the exposed operation allow-list: the {@see Operation} cases this
 * type serves, one route emitted per case. An empty array means the default — all
 * five operations.
 *
 * `readOnly` is an intent-named shorthand for the common "suppress every write"
 * case: `readOnly: true` restricts the type to the two fetch operations
 * ({@see Operation::FetchCollection} and {@see Operation::FetchOne}) without
 * importing the enum. It is mutually exclusive with a non-empty `operations` list;
 * declaring both is a constructor {@see \LogicException}.
 *
 * **Authorization** (PLAN decision 7, policy-first). By default a type is authorized
 * through the model's Gate-registered policy at each lifecycle point
 * (list→`viewAny`, read→`view`, create→`create`, update→`update`, delete→`delete`);
 * a type with no policy is inert (no check). Two additive overrides tune this:
 *  - `policy` names a dedicated API policy class invoked directly (container-resolved,
 *    honouring its `before()`), leaving the application's `Gate::policy()` mapping
 *    untouched — the provider-agnostic seam a POPO-backed type uses.
 *  - `abilities` renames the ability for one or more operations, keyed by
 *    {@see Operation} case value: a `string` is the Gate ability name checked for that
 *    operation (so `Gate::define()` works too), and `false` disables the check for
 *    that operation entirely.
 *
 * Further metadata (custom serializer/hydrator overrides, cache headers, deprecation
 * signalling, OpenAPI tags/descriptions) is added in later phases, mirroring the
 * Symfony bundle's attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /**
     * @param string|list<string>|null   $server     the server name(s) exposing this type (null = the implicit `default`)
     * @param list<Operation>            $operations the exposed operation allow-list (empty = all five); mutually exclusive with `readOnly`
     * @param bool                       $readOnly   shorthand restricting the type to the two fetch operations; mutually exclusive with a non-empty `operations`
     * @param class-string|null          $policy     a dedicated API policy class invoked directly for every operation (null = the model's Gate-registered policy, or inert if none)
     * @param array<string, string|false> $abilities per-operation ability override keyed by {@see Operation} case value: a string renames the Gate ability, `false` disables the check for that operation
     */
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
        public array $operations = [],
        public bool $readOnly = false,
        public ?string $policy = null,
        public array $abilities = [],
    ) {
        if ($readOnly && $operations !== []) {
            throw new \LogicException(
                'AsJsonApiResource declares both readOnly: true and a non-empty operations list; '
                . 'they are mutually exclusive — drop one. Use readOnly for the two fetch operations, '
                . 'or operations for a precise allow-list.',
            );
        }
    }
}
