<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An `fk-secrets` row. A global scope hides the hidden ones from every query.
 */
final class FkSecret extends Model
{
    public $timestamps = false;

    protected $table = 'fk_secrets';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = ['hidden' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope('shown', static function (Builder $builder): void {
            $builder->where('fk_secrets.hidden', false);
        });
    }
}
