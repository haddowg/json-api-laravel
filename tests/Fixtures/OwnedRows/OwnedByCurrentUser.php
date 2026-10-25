<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limits a model's rows to the ones the signed-in user owns.
 *
 * @implements Scope<Model>
 */
final class OwnedByCurrentUser implements Scope
{
    /**
     * @param Builder<covariant Model> $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('owner_id'), Auth::id());
    }
}
