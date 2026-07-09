<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelatedCursorConformanceInMemoryServiceProvider;

/**
 * {@see CursorIncludeConformanceTestCase} against the in-memory provider — the
 * batched-include keyset witness (the ground truth the Eloquent per-parent push-down
 * matches). No database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryCursorIncludeConformanceTest extends CursorIncludeConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelatedCursorConformanceInMemoryServiceProvider::class;
    }
}
