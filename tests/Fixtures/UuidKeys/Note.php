<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use Illuminate\Database\Eloquent\Model;

/**
 * A note about a label or a writer, kept in two plain columns. The id column is a string
 * because its subjects' ids differ in kind.
 *
 * @property int         $id
 * @property string|null $subject_type
 * @property string|null $subject_id
 */
final class Note extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $table = 'uk_notes';
}
