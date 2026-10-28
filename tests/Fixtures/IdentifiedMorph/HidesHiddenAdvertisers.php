<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Leaves out the advertisers marked hidden.
 *
 * @implements Scope<Model>
 */
final class HidesHiddenAdvertisers implements Scope
{
    /**
     * @param Builder<covariant Model> $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('hidden'), false);
    }
}
