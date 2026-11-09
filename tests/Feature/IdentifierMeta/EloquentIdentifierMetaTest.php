<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifierMeta;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Eloquent arm also renders the stored pivot, beside the declared members.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentIdentifierMetaTest extends IdentifierMetaTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    #[Test]
    public function thePivotAndTheDeclaredMembersShareTheIdentifierMeta(): void
    {
        $linkage = $this->read('/shelves/1/relationships/books');

        self::assertEqualsCanonicalizing(['pivot', 'slot', 'featured'], \array_keys($this->arrayAt($linkage->json(), 'data', 0, 'meta')));
        self::assertSame(['position' => 1], $linkage->json('data.0.meta.pivot'));
    }
}
