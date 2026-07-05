<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A resource with **no** registered data provider or persister — the unservable
 * configuration the {@see \haddowg\JsonApiLaravel\Server\ServableResourceWarmer} must
 * flag at `jsonapi:optimize` (a read op needs a provider, a write op a persister). It
 * defaults to all five operations, so it exercises both the provider and persister
 * guards. Registered explicitly by {@see OrphanServiceProvider}, never scanned.
 *
 * @internal
 */
final class OrphanResource extends AbstractResource
{
    public static string $type = 'orphans';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
