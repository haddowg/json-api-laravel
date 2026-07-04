<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A `clock-stamps` resource whose constructor takes a container-resolvable {@see Clock}
 * — the whole point of the fixture. Its `stamp` attribute is computed off that injected
 * dependency (the extractor closure captures `$this`), so the value can only reach the
 * rendered document if core built this resource through the application container
 * (PLAN decision 3: "construct via container on first use"). A plain-`new` fallback
 * could not satisfy the interface-typed required parameter, so this resource rendering
 * at all is the proof.
 *
 * @internal
 */
#[AsJsonApiResource(readOnly: true)]
final class ClockStampResource extends AbstractResource
{
    public static string $type = 'clock-stamps';

    public function __construct(private readonly Clock $clock) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('stamp')
                ->computed()
                ->readOnly()
                ->extractUsing(fn(mixed $model): string => $this->clock->label()),
        ];
    }
}
