<?php

declare(strict_types=1);

namespace Workbench\App\ResponseDecl;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The read-side async-completion witness: a read-only `widget-jobs` type whose fetch-one
 * answers `200` (still processing) or `303 See Other` (done) at runtime, driven by
 * {@see ResolvesCompletionRedirect::completionLocation()} — a completed job redirects to
 * the produced `widgets` resource. Exercised by
 * {@see \haddowg\JsonApiLaravel\Tests\Feature\AsyncCompletionRedirectTest}.
 */
#[AsJsonApiResource(readOnly: true)]
final class WidgetJobResource extends AbstractResource implements ResolvesCompletionRedirect
{
    public static string $type = 'widget-jobs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('status'),
        ];
    }

    public function completionLocation(object $entity): ?string
    {
        \assert($entity instanceof WidgetJob);

        return $entity->status === 'done' ? '/api/widgets/' . ($entity->producedId ?? '') : null;
    }
}
