<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A presence-only, self-applying filter that lifts the default soft-delete scope so a
 * collection returns **live and trashed** rows alike — `filter[<key>]` applies Eloquent's
 * `withTrashed()` to the query. The client-facing key is author-chosen (add
 * `WithTrashed::make('withTrashed')` to a soft-deletable resource's `filters()`), matching
 * how the ecosystem's first-class soft-delete support surfaces the toggle.
 *
 * It carries no client value (like core's `WhereNull`): its presence in the request is the
 * whole signal, so {@see constraints()} is empty. It is Eloquent-only (a
 * {@see AppliesToEloquentQueryBuilder}); on the in-memory provider the same key is
 * undeclared, so a request there is a clean `400`.
 *
 * Pair it with {@see OnlyTrashed} to also offer a trashed-only view.
 *
 * @implements AppliesToEloquentQueryBuilder<Model>
 */
final readonly class WithTrashed implements AppliesToEloquentQueryBuilder
{
    private function __construct(private string $key) {}

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }

    public function applyToQueryBuilder(Builder $query, mixed $value): void
    {
        // `withTrashed()` is a SoftDeletingScope macro (invisible to method_exists); it simply
        // lifts the soft-delete global scope, which the real Builder method does directly. On a
        // non-soft-deletable model the scope is absent, so this is a harmless no-op.
        $query->withoutGlobalScope(SoftDeletingScope::class);
    }
}
