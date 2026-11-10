<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\PositionedInMemoryServiceProvider;

/**
 * {@see PositionedConformanceTestCase} against the in-memory witness. It holds no join pivot,
 * so its queues declare no positioned `favourites`.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryPositionedConformanceTest extends PositionedConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return PositionedInMemoryServiceProvider::class;
    }


    public static function relations(): iterable
    {
        yield 'hasMany' => ['songs'];
        yield 'declared MorphToMany' => ['mix'];
        yield 'closure MorphToMany' => ['looseMix'];
    }
}
