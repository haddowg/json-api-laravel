<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `cursorGroups` Eloquent model backing the reference provider in the
 * RELATED-collection cursor (keyset) conformance suite. Its `widgets()` HasMany is the
 * parent-scoped relation query the keyset push-down composes on top of; the in-memory
 * {@see \Workbench\App\Domain\CursorGroup} POPO shares the
 * {@see \Workbench\App\CursorRelated\CursorGroupResource} declaration.
 *
 * @property int $id
 */
final class CursorGroup extends Model
{
    protected $table = 'cursor_groups';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<CursorWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(CursorWidget::class, 'group_id');
    }
}
