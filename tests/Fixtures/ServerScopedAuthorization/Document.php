<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $title
 */
final class Document extends Model
{
    protected $table = 'ssa_documents';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'document_id');
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Note::class, 'document_id');
    }
}
