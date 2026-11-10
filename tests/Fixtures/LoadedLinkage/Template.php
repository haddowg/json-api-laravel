<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property \Illuminate\Database\Eloquent\Collection<int, Binding> $bindings
 * @property \Illuminate\Database\Eloquent\Collection<int, Binding> $sharedBindings
 */
final class Template extends Model
{
    protected $table = 'll_templates';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<Binding, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(Binding::class, 'template_id');
    }

    /**
     * @return BelongsToMany<Binding, $this>
     */
    public function sharedBindings(): BelongsToMany
    {
        return $this->belongsToMany(Binding::class, 'll_template_shared', 'template_id', 'binding_id');
    }
}
