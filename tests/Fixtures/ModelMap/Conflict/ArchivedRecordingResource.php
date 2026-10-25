<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Conflict;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Pressing;

/**
 * Exposes `recordings` on a second server against a different model than
 * {@see \haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi\RecordingResource}
 * declares, which the model map rejects.
 *
 * @internal
 */
#[AsJsonApiResource(server: 'archive', model: Pressing::class)]
final class ArchivedRecordingResource extends AbstractResource
{
    public static string $type = 'recordings';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
