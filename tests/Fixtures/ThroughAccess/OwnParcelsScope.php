<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\User;

/**
 * Limits parcels to the signed-in user's own, or every parcel for an admin.
 *
 * @implements Scope<Model>
 */
final class OwnParcelsScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if ($user instanceof User && $user->is_admin) {
            return;
        }

        $builder->where($model->qualifyColumn('owner_id'), Auth::id());
    }
}
