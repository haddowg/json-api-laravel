<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

/**
 * A board of notes whose `notes` relation scopes a filter ending on the notes' polymorphic
 * `subject`.
 */
final class BoardResource extends AbstractResource
{
    public static string $type = 'boards';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('notes', 'notes')->withFilters(WhereThroughIn::make('noteSubject', 'subject')),
        ];
    }
}
