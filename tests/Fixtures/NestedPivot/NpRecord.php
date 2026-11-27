<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds\IkKeyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class NpRecord extends Model
{
    protected $table = 'np_records';

    public $timestamps = false;

    protected $guarded = [];

    public function getKeyType(): string
    {
        return NpKeys::stringKeys() ? 'string' : 'int';
    }

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return NpKeys::kind() === 'object' ? [...parent::getCasts(), 'id' => IkKeyCast::class] : parent::getCasts();
    }

    public function getIncrementing(): bool
    {
        return !NpKeys::stringKeys();
    }

    /**
     * @return BelongsToMany<NpCrate, $this>
     */
    public function crates(): BelongsToMany
    {
        return $this->belongsToMany(NpCrate::class, 'np_crate_record', 'record_id', 'crate_id')
            ->withPivot('position', 'added_at');
    }
}
