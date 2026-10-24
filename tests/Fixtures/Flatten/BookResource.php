<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `authorName` flattens the hidden `author`'s name, `authorCountry` the name of that author's
 * hidden `country` (two hops), and `editorName` the name of the visible `editor`, which a write
 * body can associate in the same document.
 */
final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('authorName')->storedAs('name')->on('author'),
            Str::make('authorCountry')->storedAs('name')->on('author.country'),
            Str::make('editorName')->storedAs('name')->on('editor'),
            BelongsTo::make('author', 'authors')->hidden(),
            BelongsTo::make('editor', 'authors'),
        ];
    }
}
