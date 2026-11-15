<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The row a listener writes on the side in the write-transaction suite, over Eloquent.
 *
 * @property int    $id
 * @property string $body
 */
final class TxNote extends Model
{
    public $timestamps = false;

    protected $table = 'tx_notes';

    protected $guarded = [];
}
