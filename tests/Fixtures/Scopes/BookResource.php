<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A book whose create ability is decided from the caller alone and whose update ability
 * needs the book, and whose delete is public.
 */
#[AsJsonApiResource(abilities: [
    Operation::Create->value => 'createBook',
    Operation::Update->value => 'editBook',
    Operation::Delete->value => false,
])]
final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
