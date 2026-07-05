<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `memos` resource — the hydrator-override witness (ADR 0014). Writes hydrate
 * through the hand-written {@see MemoHydrator} (which takes a container-bound
 * constructor argument and fans one `title` member out to `title` + a derived `slug`),
 * while reads stay field-driven: this resource's fields render the hydrated result.
 *
 * @internal
 */
#[AsJsonApiResource(hydrator: MemoHydrator::class)]
final class MemoResource extends AbstractResource
{
    public static string $type = 'memos';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('slug')->computed()->readOnly(),
        ];
    }
}
