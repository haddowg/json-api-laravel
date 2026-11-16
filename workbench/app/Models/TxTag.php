<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row whose key the client names, in the write-transaction suite, over Eloquent.
 *
 * @property string $id
 * @property string $label
 */
final class TxTag extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'tx_tags';

    protected $guarded = [];
}
