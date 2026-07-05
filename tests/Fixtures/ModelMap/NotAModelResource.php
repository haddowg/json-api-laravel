<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A deliberately broken fixture: its `model:` names an existing class that is not an
 * Eloquent model, so discovery must fail loudly at scan time (the ADR 0019 contract
 * guard). Never placed in a scanned path — the scanner unit test registers it
 * explicitly.
 *
 * @internal
 */
/* @phpstan-ignore-next-line argument.type (the non-Model class IS the fixture) */
#[AsJsonApiResource(model: \stdClass::class)]
final class NotAModelResource extends AbstractResource
{
    public static string $type = 'not-models';

    public function fields(): array
    {
        return [
            Id::make(),
        ];
    }
}
