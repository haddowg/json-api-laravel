<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Leaves out the agencies marked hidden.
 *
 * @implements Scope<Model>
 */
final class HidesHiddenAgencies implements Scope
{
    /**
     * @param Builder<covariant Model> $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('hidden'), false);
    }
}
