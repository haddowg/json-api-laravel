<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Tests\Fixtures\Action\MetaHandlerWithOutputMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ActionDescriptor::class)]
final class ActionDescriptorSnapshotTest extends TestCase
{
    #[Test]
    public function theSummaryAndDescriptionSurviveTheDiscoverySnapshot(): void
    {
        $descriptor = new ActionDescriptor(
            'albums',
            'publish',
            ['POST'],
            ActionScope::Resource,
            ActionInput::None,
            'albums',
            'albums',
            [['kind' => 'meta', 'ref' => null]],
            null,
            MetaHandlerWithOutputMeta::class,
            'default',
            summary: 'Publish an album',
            description: 'Marks the album as released.',
            accepts: ['text/csv' => 'Raw'],
        );

        $restored = ActionDescriptor::fromArray($descriptor->toArray());

        self::assertSame('Publish an album', $restored->summary);
        self::assertSame('Marks the album as released.', $restored->description);
        self::assertSame(['text/csv' => 'Raw'], $restored->accepts);
    }

    #[Test]
    public function aSnapshotWrittenBeforeTheyExistedReadsThemAsNull(): void
    {
        $data = (new ActionDescriptor('albums', 'publish', ['POST'], ActionScope::Resource, ActionInput::None, 'albums', 'albums', [['kind' => 'meta', 'ref' => null]], null, MetaHandlerWithOutputMeta::class, 'default'))->toArray();
        unset($data['summary'], $data['description']);

        $restored = ActionDescriptor::fromArray($data);

        self::assertNull($restored->summary);
        self::assertNull($restored->description);
    }
}
