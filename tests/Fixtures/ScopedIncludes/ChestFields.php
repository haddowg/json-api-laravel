<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `chests` fields: `holdings` is a polymorphic to-many over both scoped types.
 */
abstract class ChestFields extends AbstractResource
{
    public static string $type = 'chests';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            MorphToMany::make('holdings', ['coins', 'marks'])
                ->countable()
                ->extractUsing(static fn(mixed $chest): array => match (true) {
                    $chest instanceof Chest => $chest->holdings(),
                    $chest instanceof ChestRecord => $chest->holdings,
                    default => [],
                }),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return null;
    }
}
