<?php

declare(strict_types=1);

namespace Workbench\App\Pivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The `playlists` resource type (Phase 3b) — the parent of the pivot + relationship-mutation
 * surface, shared by BOTH provider suites. Two `belongsToMany` relations to the same `tracks`
 * type:
 *  - `tracks` is a PLAIN join (no pivot columns) — the bare-belongsToMany witness and the
 *    relationship-existence filter target;
 *  - `orderedTracks` is the pivot-bearing variant: declaring `fields()` makes each member's
 *    stored `position`/`weight`/`addedAt` render as `meta.pivot` (Eloquent-only — the
 *    in-memory witness stores no pivot) AND upsert from the linkage `meta` on a write.
 *    `position` is REQUIRED-on-create (a new member missing it is a `422` before persist,
 *    never a DB NOT-NULL `500`); `weight` is a second writable field constrained
 *    `weight >= position` (a cross-pivot-field rule evaluated over the MERGED pivot);
 *    `addedAt` is `readOnly()` (server-owned, never written from meta).
 *
 * The type exposes fetch + update only, so its relationship-mutation routes (PATCH/POST/DELETE
 * on `…/relationships/{rel}`) are emitted and the pivot upsert runs, without a create/delete
 * surface.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class PlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(120)->sortable(),
            Boolean::make('public'),

            // The plain join — no pivot columns. Relation name IS the Eloquent relation method
            // / in-memory POPO property (`tracks`).
            BelongsToMany::make('tracks', 'tracks'),

            // A fully mutation-locked view over the SAME `tracks` join (storedAs remaps it to
            // the `tracks` relation method / POPO property): it renders its membership on the
            // read endpoints, but every mutation verb is prohibited — a PATCH is a
            // FULL_REPLACEMENT_PROHIBITED, a POST an ADDITION_PROHIBITED, a DELETE a
            // REMOVAL_PROHIBITED (all 403, request-aware, core ADR 0079) — so the relationship
            // -write conformance can referee the `cannot*` family on both providers without a
            // second backing table.
            BelongsToMany::make('lockedTracks', 'tracks')
                ->storedAs('tracks')
                ->cannotReplace()
                ->cannotAdd()
                ->cannotRemove(),

            // The pivot-bearing membership: declaring fields() renders + validates + upserts
            // the join columns.
            BelongsToMany::make('orderedTracks', 'tracks')->fields(
                // Required-on-create, >= 1. On an existing-member partial update the omitted
                // position is preserved from the MERGED stored row (no false 422).
                Integer::make('position')->required()->min(1),
                // A second writable field, `weight >= position` — a cross-pivot-field rule
                // evaluated over the merged pivot (an incoming weight vs the merged position).
                Integer::make('weight')->compareWith('position', Comparison::GreaterThanOrEqual),
                // Server-owned: never written from the linkage meta (stored as `added_at`).
                DateTime::make('addedAt')->storedAs('added_at')->readOnly(),
            ),
        ];
    }

    public function filters(): array
    {
        return [
            // Relationship-existence over the `orderedTracks` belongsToMany (Phase 3b): a
            // presence-only semi-join. `filter[withOrderedTracks]` keeps playlists with ≥1
            // track, `filter[withoutOrderedTracks]` those with none. The Eloquent provider
            // compiles an EXISTS / NOT EXISTS subquery over the join; the in-memory witness
            // runs the reference predicate over the object graph — identical sets on both.
            WhereHas::make('withOrderedTracks', 'orderedTracks'),
            WhereDoesntHave::make('withoutOrderedTracks', 'orderedTracks'),
            // Dotted-path traversal over the pivot relation: `filter[orderedTrackTitled]=<title>`
            // keeps a playlist that has SOME track with that title — an EXISTS-ANY semi-join
            // (each playlist returned once, never row-multiplied by the join fan-out).
            WhereThrough::make('orderedTrackTitled', 'orderedTracks.title'),
        ];
    }
}
