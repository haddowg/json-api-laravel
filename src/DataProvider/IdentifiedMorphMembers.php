<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowKeysInterface;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQueryInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\GuardedTypes;

/**
 * Serves a `MorphToMany` declared with `identifiedBy()` from the `[type, id]` pairs its parents
 * store (ADR 0024). Core renders the linkage straight from the pairs, which is right for a member
 * type nothing scopes. Each scoped type, one with a global scope or a provider of its own that
 * hides rows, has its ids admitted in one key-only read per type across every parent before the
 * linkage renders, a page is cut or a count taken, so a hidden member is gone from all of them.
 * Members load as rows only when they render as resources: included, or on the related page.
 *
 * Each parent's members, admitted or loaded, are kept until {@see forget()}; the linkage seam hands
 * them to core as {@see IdentifiedMember} values.
 */
final class IdentifiedMorphMembers
{
    private ?Server $server = null;

    /**
     * @var \WeakMap<object, array<string, RelationshipLinkage>>
     */
    private \WeakMap $loaded;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly GuardedTypes $guarded,
    ) {
        $this->loaded = new \WeakMap();
    }

    /**
     * Whether `$relation` reads its members' identifiers off the parent.
     *
     * @phpstan-assert-if-true MorphToMany $relation
     */
    public static function identifies(RelationInterface $relation): bool
    {
        return $relation instanceof MorphToMany && $relation->storesIdentifiers();
    }

    /**
     * Sets the server whose serializers render the members, and drops every loaded member.
     */
    public function bind(?Server $server): void
    {
        $this->server = $server;
        $this->forget();
    }

    /**
     * Drops every loaded member, so a pair a write just changed is read again.
     */
    public function forget(): void
    {
        $this->loaded = new \WeakMap();
    }

    /**
     * The declared types of `$relation` a scope may hide rows of.
     *
     * @return list<string>
     */
    public function guardedTypes(MorphToMany $relation): array
    {
        return \array_values(\array_filter(
            $relation->relatedTypes(),
            fn(string $type): bool => $this->guarded->isScoped($type),
        ));
    }

    /**
     * Supplies each of `$parents` its members, returning the loaded rows by type. With `$rows`
     * every member's row loads, one read per type, and a pair whose row doesn't come back is
     * dropped; otherwise only the scoped types are admitted, and nothing is supplied when no type
     * is scoped, so core renders the pairs as stored.
     *
     * @param list<object> $parents
     *
     * @return array<string, list<object>>
     */
    public function load(array $parents, MorphToMany $relation, JsonApiRequestInterface $request, bool $rows, ?string $parentType = null): array
    {
        if ($this->server === null || $parents === [] || (!$rows && $this->guardedTypes($relation) === [])) {
            return [];
        }

        $name = $relation->name();
        $loaded = [];
        $keys = $parentType !== null && $this->providers->supportsType($parentType) ? $this->providers->forType($parentType) : null;
        foreach ($this->members($this->server, $parents, $relation, $request, $rows, keys: $keys instanceof LinkRowKeysInterface ? $keys : null) as $index => $members) {
            $entry = $this->loaded[$parents[$index]] ?? [];
            $entry[$name] = new RelationshipLinkage($members);
            $this->loaded[$parents[$index]] = $entry;

            foreach ($members as $member) {
                if ($member->target !== null) {
                    $loaded[$member->type][\spl_object_id($member->target)] = $member->target;
                }
            }
        }

        return \array_map(\array_values(...), $loaded);
    }

    /**
     * The members supplied for `$relation` on `$model`, admitting them first when a type is
     * scoped, or `null` when its pairs render as stored.
     */
    public function linkageFor(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipLinkage
    {
        if (!\is_object($model) || !self::identifies($relation)) {
            return null;
        }

        $name = $relation->name();
        if (!isset($this->loaded[$model][$name])) {
            $this->load([$model], $relation, $request, false);
        }

        return $this->loaded[$model][$name] ?? null;
    }

    /**
     * How many admitted members each of `$parents` holds, in their order.
     *
     * @param list<object> $parents
     *
     * @return list<int>
     */
    public function counts(Server $server, array $parents, MorphToMany $relation, JsonApiRequestInterface $request): array
    {
        return \array_map(\count(...), $this->members($server, $parents, $relation, $request, false));
    }

    /**
     * The page of `$parent`'s admitted members per `$criteria`, as {@see IdentifiedMember} values
     * carrying their link rows but no member row. The request's `sort` and `filter` run over the
     * link rows' pivot fields, the relation's default order applying when it names no sort, and
     * the page is cut from the admitted set, so the total is exact and a count-free page knows
     * whether more follow. A relation declared with `identifiedByRows()` is read by the parent's
     * `$rows` query, in SQL on Eloquent, each scoped type narrowed first to the keys its
     * provider admits; an `identifiedBy()` closure's members are admitted and queried in memory.
     *
     * @return CollectionResult<IdentifiedMember>
     */
    public function pageMembers(Server $server, object $parent, MorphToMany $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request, ?LinkRowQueryInterface $rows = null): CollectionResult
    {
        $query = LinkRowQuery::resolve(
            $relation,
            $criteria->queryParameters->sort,
            $criteria->queryParameters->filter,
            $request,
            $criteria->window,
            $criteria->wantsCount,
        );

        if ($query->fields !== null) {
            $rows ??= new InMemoryLinkRows();
            [$query] = $this->narrow($server, [$parent], $relation, $query, $rows, $request);

            return $rows->queryLinkRows($parent, $relation, $query, $request);
        }

        return (new InMemoryLinkRows())->apply($this->members($server, [$parent], $relation, $request, false, false)[0], $query);
    }

    /**
     * `$query` narrowed for each of `$parents` through core's one narrowing helper: one key read
     * of every parent's members of the scoped types through `$rows`, when it reads keys, else
     * in memory, then one admission per scoped type through its provider.
     *
     * @param list<object> $parents
     *
     * @return list<LinkRowQuery>
     */
    private function narrow(Server $server, array $parents, MorphToMany $relation, LinkRowQuery $query, ?LinkRowQueryInterface $rows, JsonApiRequestInterface $request): array
    {
        return $query->narrowed(
            $parents,
            $relation,
            $rows instanceof LinkRowKeysInterface ? $rows : new InMemoryLinkRows(),
            $this->guardedTypes($relation),
            fn(string $type, array $keys): array => $this->admittedKeys($server, $type, $keys),
            $request,
        );
    }

    /**
     * The storage keys among `$keys` that `$type`'s provider admits.
     *
     * @param list<string|int> $keys
     *
     * @return list<string|int>
     */
    private function admittedKeys(Server $server, string $type, array $keys): array
    {
        if (!$server->hasSerializerFor($type) || !$this->providers->supportsType($type)) {
            return [];
        }

        $byWireId = [];
        foreach ($keys as $key) {
            $byWireId[$this->wireId($server, $type, $key)] = $key;
        }

        $admitted = [];
        foreach ($this->admit($server, $type, \array_map('strval', \array_keys($byWireId))) as $id) {
            if (isset($byWireId[$id])) {
                $admitted[] = $byWireId[$id];
            }
        }

        return $admitted;
    }

    /**
     * The members of a relation declared with `identifiedByRows()` that `$parent` links but a
     * scope hides, as storage `[type, id]` pairs, for a write to keep.
     *
     * @return list<array{0: string, 1: string|int}>
     */
    public function hiddenMembers(Server $server, object $parent, MorphToMany $relation, ?LinkRowQueryInterface $rows, JsonApiRequestInterface $request): array
    {
        if ($this->guardedTypes($relation) === []) {
            return [];
        }

        [$query] = $this->narrow($server, [$parent], $relation, LinkRowQuery::resolve($relation, request: $request), $rows, $request);
        $hidden = [];
        foreach ($relation->identifiedMembers($parent, $request) as $member) {
            if (!$query->admits($member)) {
                $hidden[] = [$member->type, $member->id];
            }
        }

        return $hidden;
    }

    /**
     * The related page: the {@see pageMembers()} page with only its rows loaded, one read per
     * type. `$types` receives the type each row renders as, keyed by object id, since one class
     * may serve several, and `$pivots` the `meta.pivot` each row renders, keyed the same way.
     *
     * @param array<int, string>               $types
     * @param array<int, array<string, mixed>> $pivots
     *
     * @return CollectionResult<object>
     */
    public function page(Server $server, object $parent, MorphToMany $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request, array &$types, ?LinkRowQueryInterface $rows = null, array &$pivots = []): CollectionResult
    {
        $page = $this->pageMembers($server, $parent, $relation, $criteria, $request, $rows);
        $members = [];
        foreach ($page->items as $member) {
            if ($member instanceof IdentifiedMember) {
                $members[] = $member;
            }
        }

        $loaded = $this->rows($server, $members, $types, $relation->pivotFields() !== [] ? $relation : null, $parent, $request, $pivots);

        return new CollectionResult($loaded, $page->total, $page->windowed, $page->hasMore);
    }

    /**
     * `$parent`'s members of `$relation` with their link rows, keyed by type and wire id.
     *
     * @return array<string, IdentifiedMember>
     */
    public function membersByKey(Server $server, object $parent, MorphToMany $relation, JsonApiRequestInterface $request): array
    {
        $keyed = [];
        foreach ($relation->identifiedMembers($parent, $request) as $member) {
            $keyed[$member->type . "\0" . $this->wireId($server, $member->type, $member->id)] = $member;
        }

        return $keyed;
    }

    /**
     * The stored pairs of `$relation` on `$parent` whose type is scoped and whose row the scope
     * hides, as wire `[type, id]` pairs: a replace leaves them attached.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function hiddenPairs(Server $server, object $parent, MorphToMany $relation, JsonApiRequestInterface $request): array
    {
        $guarded = $this->guardedTypes($relation);
        $admitted = \array_map(
            fn(IdentifiedMember $member): string => $member->type . "\0" . $this->wireId($server, $member->type, $member->id),
            $this->members($server, [$parent], $relation, $request, false)[0],
        );

        $hidden = [];
        foreach ($relation->storedIdentifierList($parent, $request) as [$type, $key]) {
            $id = $this->wireId($server, $type, $key);
            if (\in_array($type, $guarded, true) && !\in_array($type . "\0" . $id, $admitted, true)) {
                $hidden[] = [$type, $id];
            }
        }

        return $hidden;
    }

    /**
     * Each parent's members, each with its link row, in the relation's default order unless
     * `$ordered` is off, else in pair order: with `$rows` loaded, otherwise with each scoped type
     * admitted and the rest kept unloaded. A type no provider serves keeps its pairs unloaded.
     *
     * @param list<object> $parents
     *
     * @return list<list<IdentifiedMember>>
     */
    private function members(Server $server, array $parents, MorphToMany $relation, JsonApiRequestInterface $request, bool $rows, bool $ordered = true, ?LinkRowKeysInterface $keys = null): array
    {
        $guarded = $this->guardedTypes($relation);

        // A declared relation's scoped types are narrowed through core's helper, one key read
        // and one admission per type for the whole batch.
        if (!$rows && $relation->linkRowFields() !== null && $guarded !== []) {
            $members = [];
            $queries = LinkRowQuery::resolve($relation, request: $request)->narrowed(
                $parents,
                $relation,
                $keys ?? new InMemoryLinkRows(),
                $guarded,
                fn(string $type, array $stored): array => $this->admittedKeys($server, $type, $stored),
                $request,
            );
            foreach ($parents as $index => $parent) {
                $query = $ordered ? $queries[$index] : new LinkRowQuery(fields: $queries[$index]->fields, admitted: $queries[$index]->admitted);
                $items = (new InMemoryLinkRows())->apply($relation->identifiedMembers($parent, $request), $query)->items;
                $members[] = \is_array($items) ? \array_values($items) : \iterator_to_array($items, false);
            }

            return $members;
        }

        $loaded = [];
        $admitted = [];
        foreach ($relation->storedIdentifiers($parents, $request) as $type => $keys) {
            if (!$server->hasSerializerFor($type) || !$this->providers->supportsType($type)) {
                continue;
            }

            $ids = \array_values(\array_unique(\array_map(fn(string|int $key): string => $this->wireId($server, $type, $key), $keys)));
            if ($rows) {
                $loaded[$type] = $this->fetch($server, $type, $ids);
            } elseif (\in_array($type, $guarded, true)) {
                $admitted[$type] = \array_flip($this->admit($server, $type, $ids));
            }
        }

        $order = $ordered ? LinkRowQuery::resolve($relation, request: $request) : null;
        $members = [];
        foreach ($parents as $parent) {
            $kept = [];
            foreach ($relation->identifiedMembers($parent, $request) as $member) {
                if (!isset($loaded[$member->type]) && !isset($admitted[$member->type])) {
                    $kept[] = $member;

                    continue;
                }

                $id = $this->wireId($server, $member->type, $member->id);
                if (isset($loaded[$member->type][$id])) {
                    $kept[] = $member->withTarget($loaded[$member->type][$id]);
                } elseif (isset($admitted[$member->type][$id])) {
                    $kept[] = $member;
                }
            }

            if ($order !== null && $order->sorts !== []) {
                $items = (new InMemoryLinkRows())->apply($kept, $order)->items;
                $kept = \is_array($items) ? \array_values($items) : \iterator_to_array($items, false);
            }
            $members[] = $kept;
        }

        return $members;
    }

    /**
     * The rows of `$members`, in their order, one read per type, leaving out a member whose row
     * doesn't come back.
     *
     * @param list<IdentifiedMember>           $members
     * @param array<int, string>               $types   receives each row's type, keyed by object id
     * @param array<int, array<string, mixed>> $pivots  receives each row's `meta.pivot` when `$pivoted` declares pivot fields
     *
     * @return list<object>
     */
    private function rows(Server $server, array $members, array &$types, ?MorphToMany $pivoted = null, ?object $parent = null, ?JsonApiRequestInterface $request = null, array &$pivots = []): array
    {
        $ids = [];
        foreach ($members as $member) {
            if ($server->hasSerializerFor($member->type) && $this->providers->supportsType($member->type)) {
                $ids[$member->type][$this->wireId($server, $member->type, $member->id)] = true;
            }
        }

        $loaded = [];
        foreach ($ids as $type => $set) {
            $loaded[$type] = $this->fetch($server, $type, \array_map('strval', \array_keys($set)));
        }

        $rows = [];
        foreach ($members as $member) {
            $row = $loaded[$member->type][$this->wireId($server, $member->type, $member->id)] ?? null;
            if ($row !== null) {
                $rows[] = $row;
                $types[\spl_object_id($row)] = $member->type;
                if ($pivoted !== null && $request !== null) {
                    $pivots[\spl_object_id($row)] = $pivoted->pivotValues($member->withTarget($row), $parent, $request, $server->outputTimezone());
                }
            }
        }

        return $rows;
    }

    /**
     * The rows of `$type` among `$ids` its provider serves, keyed by wire id.
     *
     * @param list<string> $ids
     *
     * @return array<string, object>
     */
    private function fetch(Server $server, string $type, array $ids): array
    {
        $provider = $this->providers->forType($type);
        if ($provider instanceof FetchesManyInterface) {
            $found = $provider->fetchMany($type, $ids);
        } else {
            $found = [];
            foreach ($ids as $id) {
                $row = $provider->fetchOne($type, $id);
                if ($row !== null) {
                    $found[] = $row;
                }
            }
        }

        $serializer = $server->serializerFor($type);
        $byId = [];
        foreach ($found as $row) {
            $byId[$serializer->getId($row)] = $row;
        }

        return $byId;
    }

    /**
     * The ids among `$ids` the provider of `$type` serves: one key-only read when it can, else one
     * load by id, else one `fetchOne()` per id.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function admit(Server $server, string $type, array $ids): array
    {
        $provider = $this->providers->forType($type);
        if ($provider instanceof AdmitsIdsInterface) {
            return $provider->admitIds($type, $ids);
        }

        return \array_map('strval', \array_keys($this->fetch($server, $type, $ids)));
    }

    /**
     * The storage key a wire id names for `$type`, through the id encoder its resource declares.
     */
    public function storageKey(Server $server, string $type, string $id): string|int
    {
        $serializer = $server->hasSerializerFor($type) ? $server->serializerFor($type) : null;
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    $key = $field->encoder()?->decode($id) ?? $id;

                    return \is_int($key) || \is_string($key) ? $key : $id;
                }
            }
        }

        return $id;
    }

    /**
     * A stored key as the type's serializer renders its id, through the id encoder it declares.
     */
    private function wireId(Server $server, string $type, string|int $key): string
    {
        $serializer = $server->hasSerializerFor($type) ? $server->serializerFor($type) : null;
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return $field->encoder()?->encode($key) ?? (string) $key;
                }
            }
        }

        return (string) $key;
    }
}
