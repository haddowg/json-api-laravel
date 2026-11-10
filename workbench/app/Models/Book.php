<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $title
 * @property int    $year
 * @property string $genre
 * @property int    $shelf_id
 */
final class Book extends Model
{
    protected $table = 'ro_books';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
