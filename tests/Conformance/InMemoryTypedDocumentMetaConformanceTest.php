<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\TypedMetaInMemoryServiceProvider;

/**
 * {@see TypedDocumentMetaConformanceTestCase} on the in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryTypedDocumentMetaConformanceTest extends TypedDocumentMetaConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TypedMetaInMemoryServiceProvider::class;
    }
}
