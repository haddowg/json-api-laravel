<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A presence-only, self-applying filter that narrows a collection to **only trashed**
 * (soft-deleted) rows — `filter[<key>]` applies Eloquent's `onlyTrashed()`. The trashed-only
 * twin of {@see WithTrashed}: use it to list the recycle bin (from which a client then hits
 * the `restore` / `force-delete` actions). The client-facing key is author-chosen (add
 * `OnlyTrashed::make('onlyTrashed')` to a soft-deletable resource's `filters()`).
 *
 * It carries no client value (its presence is the whole signal), so {@see constraints()} is
 * empty, and it is Eloquent-only (a {@see AppliesToEloquentQueryBuilder}).
 *
 * @implements AppliesToEloquentQueryBuilder<Model>
 */
final readonly class OnlyTrashed implements AppliesToEloquentQueryBuilder
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
        // `onlyTrashed()` is a SoftDeletingScope macro (invisible to method_exists); replicate
        // its real behaviour — lift the soft-delete scope, then keep only tombstoned rows. The
        // deleted-at column is read off the SoftDeletes trait (a real method, unlike the macro);
        // a non-soft-deletable model has none, so the filter is inert rather than fatal.
        $model = $query->getModel();
        if (!\method_exists($model, 'getDeletedAtColumn')) {
            return;
        }

        $query->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull($model->qualifyColumn($model->getDeletedAtColumn()));
    }
}
