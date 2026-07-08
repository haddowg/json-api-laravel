<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

/**
 * The soft-delete configuration a resource opts into via
 * {@see AsJsonApiResource::$softDeletes} — `softDeletes: true` is shorthand for a
 * `new SoftDeletes()` with every default (the `new` form is attribute-legal, so this VO
 * may be written inline in the attribute).
 *
 * When a resource is soft-deletable the package **synthesizes** two custom actions for its
 * type at discovery time (Model B): a `restore` and a `force-delete` action, mounted at
 * `POST /{uriType}/{id}/-actions/{restorePath|forcePath}`, wired to the package-shipped
 * generic handlers. The ordinary `DELETE /{uriType}/{id}` stays a **soft delete**
 * (recoverable) — the reference Eloquent persister already calls `$model->delete()`, which
 * a `SoftDeletes` model soft-deletes — so permanent removal is only ever reached through
 * the explicit `force-delete` action. Restore/force-delete resolve their (trashed) target
 * through a trashed-inclusive fetch (normal endpoints stay strict → a trashed id still
 * `404`s), and each is gated by its own Gate ability, dispatched through the model's policy
 * (`restore()` / `forceDelete()` — Laravel's own soft-delete policy convention). The restore
 * action is exposed as an ability-aware `links` member that renders only on a trashed
 * resource (its handler is {@see \haddowg\JsonApiLaravel\Action\ConditionallyLinked}).
 *
 * The two endpoints are independently toggleable: expose restore without force-delete (or
 * vice versa) by flipping the flags. The `trashed` read signal and the
 * `withTrashed`/`onlyTrashed` collection filters are author-declared building blocks
 * (a one-line `getMeta()` override; {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\WithTrashed}
 * / {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\OnlyTrashed}), matching how the
 * ecosystem's first-class soft-delete support declares its surface.
 */
final readonly class SoftDeletes
{
    /**
     * @param bool   $restore       expose the `restore` action (a `200` returning the untrashed resource)
     * @param bool   $forceDelete   expose the `force-delete` action (a `204` permanent removal)
     * @param string $restoreAbility the Gate ability the restore action checks (the model's policy `restore()` method by convention)
     * @param string $forceAbility  the Gate ability the force-delete action checks (the model's policy `forceDelete()` method by convention)
     * @param string $restorePath   the `{action}` URL segment for the restore action
     * @param string $forcePath     the `{action}` URL segment for the force-delete action
     */
    public function __construct(
        public bool $restore = true,
        public bool $forceDelete = true,
        public string $restoreAbility = 'restore',
        public string $forceAbility = 'forceDelete',
        public string $restorePath = 'restore',
        public string $forcePath = 'force-delete',
    ) {}
}
