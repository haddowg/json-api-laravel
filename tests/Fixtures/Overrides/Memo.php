<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

/**
 * The backing domain object for the `memos` fixture type (a hydrator-override witness)
 * — a plain POPO seeded into the in-memory provider. The `slug` is derived from the
 * `title` by the {@see MemoHydrator} fan-out, never written by a client.
 *
 * @internal
 */
final class Memo
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $slug = '',
    ) {}
}
