<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Pagination\OffsetWindow;
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
    public function load(array $parents, MorphToMany $relation, JsonApiRequestInterface $request, bool $rows): array
    {
        if ($this->server === null || $parents === [] || (!$rows && $this->guardedTypes($relation) === [])) {
            return [];
        }

        $name = $relation->name();
        $loaded = [];
        foreach ($this->members($this->server, $parents, $relation, $request, $rows) as $index => $members) {
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
     * The page of `$parent`'s admitted pairs, windowed in pair order per `$criteria`, as
     * {@see IdentifiedMember} values with no row loaded. The admitted pairs are the whole set, so
     * the total is exact and a count-free page knows whether more follow without loading anything
     * past it.
     *
     * @return CollectionResult<IdentifiedMember>
     */
    public function pageMembers(Server $server, object $parent, MorphToMany $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        $members = $this->members($server, [$parent], $relation, $request, false)[0];

        $window = $criteria->window;
        if ($window instanceof OffsetWindow) {
            $page = \array_slice($members, $window->offset, $window->limit);

            return $criteria->wantsCount
                ? new CollectionResult($page, \count($members), windowed: true)
                : new CollectionResult($page, null, windowed: true, hasMore: $window->offset + $window->limit < \count($members));
        }

        return (new WindowExecutor())->run(
            $window,
            countable: $criteria->wantsCount,
            all: static fn(): array => $members,
            count: static fn(): int => \count($members),
            page: static fn(int $offset, int $limit): array => \array_slice($members, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($members, $offset, $limit),
        );
    }

    /**
     * The related page: the {@see pageMembers()} page with only its rows loaded, one read per
     * type. `$types` receives the type each row renders as, keyed by object id, since one class
     * may serve several.
     *
     * @param array<int, string> $types
     *
     * @return CollectionResult<object>
     */
    public function page(Server $server, object $parent, MorphToMany $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request, array &$types): CollectionResult
    {
        $page = $this->pageMembers($server, $parent, $relation, $criteria, $request);
        $members = [];
        foreach ($page->items as $member) {
            if ($member instanceof IdentifiedMember) {
                $members[] = $member;
            }
        }

        return new CollectionResult($this->rows($server, $members, $types), $page->total, $page->windowed, $page->hasMore);
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
     * Each parent's members in pair order: with `$rows` loaded, otherwise with each scoped type
     * admitted and the rest kept unloaded. A type no provider serves keeps its pairs unloaded.
     *
     * @param list<object> $parents
     *
     * @return list<list<IdentifiedMember>>
     */
    private function members(Server $server, array $parents, MorphToMany $relation, JsonApiRequestInterface $request, bool $rows): array
    {
        $guarded = $this->guardedTypes($relation);
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

        $members = [];
        foreach ($parents as $parent) {
            $kept = [];
            foreach ($relation->storedIdentifierList($parent, $request) as $pair) {
                [$type, $key] = $pair;
                if (!isset($loaded[$type]) && !isset($admitted[$type])) {
                    $kept[] = IdentifiedMember::of($pair);

                    continue;
                }

                $id = $this->wireId($server, $type, $key);
                if (isset($loaded[$type][$id])) {
                    $kept[] = IdentifiedMember::of($pair, $loaded[$type][$id]);
                } elseif (isset($admitted[$type][$id])) {
                    $kept[] = IdentifiedMember::of($pair);
                }
            }
            $members[] = $kept;
        }

        return $members;
    }

    /**
     * The rows of `$members`, in their order, one read per type, leaving out a member whose row
     * doesn't come back.
     *
     * @param list<IdentifiedMember> $members
     * @param array<int, string>     $types   receives each row's type, keyed by object id
     *
     * @return list<object>
     */
    private function rows(Server $server, array $members, array &$types): array
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
