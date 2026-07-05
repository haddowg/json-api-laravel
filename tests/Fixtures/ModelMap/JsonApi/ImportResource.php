<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The explicit-tier witness (ADR 0019): `imports` IS convention-mappable (the fixture
 * `Import` model exists), but {@see \haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\ModelMapServiceProvider}
 * registers an in-memory provider/persister for it at the default priority `0` — which
 * must shadow the `-256` auto pair (whose `Import` model has no table, so a leak
 * through the auto pair would error loudly rather than pass silently).
 *
 * @internal
 */
final class ImportResource extends AbstractResource
{
    public static string $type = 'imports';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
