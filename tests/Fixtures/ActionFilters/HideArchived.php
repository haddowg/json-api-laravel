<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
final class HideArchived implements Scope
{
    /**
     * @param Builder<covariant Model> $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('archived'), false);
    }
}
