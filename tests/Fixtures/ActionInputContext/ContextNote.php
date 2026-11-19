<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionInputContext;

/**
 * The input an action hydrates, starting from these values.
 *
 * @internal
 */
final class ContextNote
{
    public ?string $id = null;

    public ?string $code = 'unset';

    public ?string $note = 'unset';
}
