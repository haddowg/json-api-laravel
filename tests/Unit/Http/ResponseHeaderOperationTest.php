<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Http;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiLaravel\Http\ResponseHeaderOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResponseHeaderOperation::class)]
final class ResponseHeaderOperationTest extends TestCase
{
    public function test_each_target_reads_as_its_shape(): void
    {
        self::assertSame(ResponseHeaderOperation::Collection, ResponseHeaderOperation::fromTarget(new Target('articles')));
        self::assertSame(ResponseHeaderOperation::Read, ResponseHeaderOperation::fromTarget(new Target('articles', '1')));
        self::assertSame(ResponseHeaderOperation::Related, ResponseHeaderOperation::fromTarget(new Target('articles', '1', 'author')));
        self::assertSame(ResponseHeaderOperation::Relationship, ResponseHeaderOperation::fromTarget(new Target('articles', '1', 'author', true)));
    }

    public function test_a_singleton_reads_as_one_resource_before_its_id_is_known(): void
    {
        self::assertSame(ResponseHeaderOperation::Read, ResponseHeaderOperation::fromTarget(Target::singleton('inboxes')));
        self::assertSame(ResponseHeaderOperation::Related, ResponseHeaderOperation::fromTarget(Target::singleton('inboxes', relationship: 'items')));
    }
}
