<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The `cursorBoards` Eloquent model backing the reference provider in the
 * pivot-bearing related-collection cursor (keyset) conformance suite. Its `widgets()`
 * belongsToMany joins `cursor_board_widget` (carrying `position`), so the keyset
 * push-down composes on top of the pivot INNER JOIN and the handler's `meta.pivot`
 * wrap reads the stored position per member; the in-memory
 * {@see \Workbench\App\Domain\CursorBoard} POPO shares the
 * {@see \Workbench\App\CursorPivot\CursorBoardResource} declaration.
 *
 * @property int $id
 */
final class CursorBoard extends Model
{
    protected $table = 'cursor_boards';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsToMany<CursorWidget, $this>
     */
    public function widgets(): BelongsToMany
    {
        return $this->belongsToMany(CursorWidget::class, 'cursor_board_widget', 'board_id', 'widget_id')
            ->withPivot('position');
    }
}
