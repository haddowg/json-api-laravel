<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A deliberately broken fixture: its `model:` names a class that does not exist, so
 * discovery must fail loudly at scan time (the ADR 0019 contract guard) instead of
 * surfacing as a runtime provider error. Never placed in a scanned path — the scanner
 * unit test registers it explicitly.
 *
 * @internal
 */
/* @phpstan-ignore-next-line argument.type (the missing model class IS the fixture) */
#[AsJsonApiResource(model: 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\DoesNotExist')]
final class MissingModelResource extends AbstractResource
{
    public static string $type = 'missing-models';

    public function fields(): array
    {
        return [
            Id::make(),
        ];
    }
}
