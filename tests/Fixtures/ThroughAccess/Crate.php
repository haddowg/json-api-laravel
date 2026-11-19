<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Crate extends Model
{
    protected $table = 'ta_crates';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<Parcel, $this>
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class, 'crate_id');
    }

    /**
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'vault_id');
    }

    /**
     * @return BelongsTo<Safe, $this>
     */
    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'safe_id');
    }

    /**
     * @return BelongsTo<Ledger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }

    /**
     * @return BelongsTo<Ledger, $this>
     */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'auditor_id');
    }
}
