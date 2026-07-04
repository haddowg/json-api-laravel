<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `articles` Eloquent model backing the validation-conformance suite and the
 * `Rule::unique` (UniqueEntity) witness. Casts give the wire types the
 * {@see \Workbench\App\Validation\ArticleResource} fields expect: `published_at`/
 * `expires_at` are Carbon (serialized by the DateTime fields), `address` a JSON array
 * (round-tripped through the Map field's serialize/fill hooks).
 *
 * @property int                             $id
 * @property string                          $title
 * @property string|null                     $body
 * @property string|null                     $category
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null                     $coupon_code
 * @property array<string, mixed>|null       $address
 * @property string|null                     $slug
 */
final class Article extends Model
{
    protected $table = 'articles';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'address' => 'array',
    ];
}
