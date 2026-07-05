<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Uuid;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Hook\HookContext;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksTrait;
use haddowg\JsonApiLaravel\Operation\Operation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Workbench\App\MusicCatalog\Domain\Playlist as PlaylistDomain;
use Workbench\App\MusicCatalog\Security\PlaylistApiPolicy;

/**
 * The `playlists` resource type (music-catalog domain) — the kitchen-sink witness:
 *  - an app-minted **UUID** id (`uuid()->generated()`);
 *  - a read-only `slug` DERIVED from `title` by the {@see beforeCreate()} AND
 *    {@see beforeUpdate()} hooks (the Laravel hook seam standing in for the Symfony
 *    example's hydrator override, which the `AsJsonApiResource` attribute does not yet
 *    carry; deriving on both writes keeps a retitled playlist's slug fresh);
 *  - **per-type lifecycle hooks** (decision 10): {@see beforeCreate()} stamps an
 *    `externalId` + slug; {@see beforeDelete()} guards against deleting a non-empty playlist
 *    (a `409`);
 *  - **API-distinct ability names** (decision 7) — `update` → `curate` (an owner gate) and
 *    `delete` → `deletePlaylist` (admin-only), Gate-resolved to the {@see PlaylistApiPolicy}
 *    methods registered in the wiring provider's `boot()`. Only the mutating operations
 *    declare an ability, so — like the Symfony example's `securityUpdate`/`securityDelete`
 *    expressions — the OpenAPI projection secures PATCH/DELETE explicitly while create and
 *    the reads inherit the document-level default requirement (the byte-compat contract; a
 *    type-wide `policy:` attribute would instead project every operation as secured — that
 *    dedicated-policy-class idiom is showcased by the `Security` suite);
 *  - **per-relation security** on `owner` (`security(read: 'inspectOwner')`) beside the
 *    curated `publicOwner` (one-entity-two-types), the plain `belongsToMany` `tracks`, and
 *    the pivot-bearing `belongsToMany` `orderedTracks` (position/weight/addedAt via the
 *    `mc_playlist_track` association — the count-free pivot witness).
 */
#[AsJsonApiResource(
    // Only update + delete declare an ability — create and the reads carry no ability, so
    // (with no type-wide policy) they inherit the document-level default requirement, exactly
    // as the Symfony example's playlist (which declares only securityUpdate/securityDelete).
    abilities: [
        Operation::Update->value => 'curate',
        Operation::Delete->value => 'deletePlaylist',
    ],
    tags: ['Library'],
)]
final class PlaylistResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            Id::make()->uuid()->generated(),
            Str::make('title')->required(),
            Slug::make('slug')->readOnly(),
            Boolean::make('public'),
            Uuid::make('externalId')->storedAs('external_id')->nullable(),
            BelongsTo::make('owner', 'users')->security(read: 'inspectOwner'),
            BelongsTo::make('publicOwner', 'public-profiles')->storedAs('owner'),
            BelongsToMany::make('tracks', 'tracks')
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->countable(),
            // The pivot witness: the same `tracks` type via the `mc_playlist_track`
            // association (position/weight/added_at). Declaring fields() renders + validates
            // + upserts the join columns; `position` is required-on-create, `weight` is a
            // second writable field constrained `weight >= position`, `addedAt` is
            // server-owned (readOnly). Deliberately NOT countable — the count-free pivot
            // witness (its related endpoint paginates two-per-page, a ?withCount is a 400).
            BelongsToMany::make('orderedTracks', 'tracks')
                ->fields(
                    Integer::make('position')->required()->min(1),
                    Integer::make('weight')->compareWith('position', Comparison::GreaterThanOrEqual),
                    DateTime::make('addedAt')->storedAs('added_at')->readOnly(),
                )
                ->withFilters(
                    Where::make('position', 'pivot.position'),
                    Where::make('weight', 'pivot.weight'),
                )
                ->extractUsing(static function (mixed $playlist): array {
                    if (!$playlist instanceof PlaylistDomain) {
                        return [];
                    }

                    $tracks = [];
                    foreach ($playlist->entries as $entry) {
                        if ($entry->track !== null) {
                            $tracks[] = $entry->track;
                        }
                    }

                    return $tracks;
                })
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
        ];
    }

    /**
     * A mutating before-create hook: derive the read-only `slug` from `title` and stamp an
     * `externalId` when the create omitted one. A before hook runs before the persister
     * flush, so the values are durably persisted.
     */
    public function beforeCreate(object $entity, HookContext $context): void
    {
        $this->deriveSlug($entity);

        $externalId = Accessor::get($entity, 'external_id');
        if (!\is_string($externalId) || $externalId === '') {
            $id = Accessor::get($entity, 'id');
            Accessor::set($entity, 'external_id', 'ext-' . (\is_scalar($id) ? (string) $id : ''));
        }
    }

    /**
     * A mutating before-update hook: re-derive the read-only `slug` from `title` on every
     * write, so a PATCH that retitles a playlist updates its slug too. The Symfony example's
     * playlist hydrator runs its title→slug fan-out on create AND update; deriving only on
     * create would leave a retitled playlist with a stale slug (a wire-observable divergence).
     */
    public function beforeUpdate(object $entity, object $original, HookContext $context): void
    {
        $this->deriveSlug($entity);
    }

    /**
     * A before-delete guard: refuse to delete a playlist that still references tracks,
     * aborting with a `409` the route-scoped renderer emits. An empty playlist deletes
     * normally.
     */
    public function beforeDelete(object $entity, HookContext $context): void
    {
        $tracks = Accessor::get($entity, 'tracks');
        $count = \is_countable($tracks) ? \count($tracks) : 0;
        if ($count > 0) {
            throw new ConflictHttpException('Cannot delete a playlist that still has tracks.');
        }
    }

    /**
     * Derive the read-only `slug` from the entity's current `title` — the shared title→slug
     * fan-out {@see beforeCreate()} and {@see beforeUpdate()} both run.
     */
    private function deriveSlug(object $entity): void
    {
        $title = Accessor::get($entity, 'title');
        $slug = \is_string($title) ? \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($title)), '-') : '';
        Accessor::set($entity, 'slug', $slug);
    }
}
