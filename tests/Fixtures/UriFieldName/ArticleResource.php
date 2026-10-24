<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `remarks` is served at `article-remarks` and `writer` at `written-by`.
 */
final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('title'),
            HasMany::make('remarks', 'comments')->withUriFieldName('article-remarks'),
            BelongsTo::make('writer', 'authors')->withUriFieldName('written-by'),
        ];
    }
}
