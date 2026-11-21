<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\User;

/**
 * Hides secret tags from everyone but admins.
 *
 * @implements Scope<Model>
 */
final class PublicTagsScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if ($user instanceof User && $user->is_admin) {
            return;
        }

        $builder->where($model->qualifyColumn('secret'), false);
    }
}
