<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An `fk-notes` row, whose `owner` can't be cleared.
 */
final class FkNote extends Model
{
    public $timestamps = false;

    protected $table = 'fk_notes';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = ['archived' => 'boolean'];
}
