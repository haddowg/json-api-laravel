<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `composites` Eloquent model backing the composite-attribute conformance suite:
 * each composite attribute (`address` = Obj, `block` = OneOf, `contact` =
 * ArrayHash+Shape) is a single `json` column with an `array` cast — the storage
 * decision for composite types: one value in, one value out, scalar children.
 *
 * @property int                       $id
 * @property string                    $name
 * @property array<string, mixed>|null $address
 * @property array<string, mixed>|null $block
 * @property array<string, mixed>|null $contact
 */
final class CompositeWidget extends Model
{
    protected $table = 'composite_widgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'address' => 'array',
        'block' => 'array',
        'contact' => 'array',
    ];
}
