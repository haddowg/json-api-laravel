<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `cursorWidgets` Eloquent model backing the reference provider in the cursor
 * (keyset) conformance suite. It shares the {@see \Workbench\App\Cursor\CursorWidgetResource}
 * declaration with the in-memory {@see \Workbench\App\Domain\CursorWidget} POPO: the
 * `priority`/`released_at` casts give the keyset the wire types it compares, so both
 * providers walk the same forced NULL=largest order.
 *
 * @property int $id
 */
final class CursorWidget extends Model
{
    protected $table = 'cursor_widgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'priority' => 'integer',
        'released_at' => 'datetime',
    ];
}
