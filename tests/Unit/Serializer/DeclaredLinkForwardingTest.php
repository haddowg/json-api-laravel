<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Serializer\RendersDeclaredLinksInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Serializer\AbstractPivotParentSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotParentSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pivot serializer decorators pass the inner serializer's declared links through, and
 * render none when the inner serializer declares none.
 *
 * @internal
 */
#[CoversClass(PivotMetaSerializer::class)]
#[CoversClass(AbstractPivotParentSerializer::class)]
final class DeclaredLinkForwardingTest extends TestCase
{
    #[Test]
    public function thePivotMetaSerializerForwardsTheDeclaredLinks(): void
    {
        $links = ['preview' => new Link('https://cdn.example.com/previews/1.mp3')];
        $request = $this->createStub(JsonApiRequestInterface::class);

        self::assertSame($links, (new PivotMetaSerializer($this->rendering($links), []))->renderDeclaredLinks(new \stdClass(), $request));
        self::assertSame([], (new PivotMetaSerializer($this->createStub(SerializerInterface::class), []))->renderDeclaredLinks(new \stdClass(), $request));
    }

    #[Test]
    public function thePivotParentSerializerForwardsTheDeclaredLinks(): void
    {
        $links = ['store' => new Link('https://store.example.com/albums/1')];
        $request = $this->createStub(JsonApiRequestInterface::class);

        self::assertSame($links, $this->parent($this->rendering($links))->renderDeclaredLinks(new \stdClass(), $request));
        self::assertSame([], $this->parent($this->createStub(SerializerInterface::class))->renderDeclaredLinks(new \stdClass(), $request));
    }

    /**
     * @param array<string, Link> $links
     */
    private function rendering(array $links): SerializerInterface
    {
        $inner = $this->createStubForIntersectionOfInterfaces([SerializerInterface::class, RendersDeclaredLinksInterface::class]);
        $inner->method('renderDeclaredLinks')->willReturn($links);
        \assert($inner instanceof SerializerInterface);

        return $inner;
    }

    private function parent(SerializerInterface $inner): PivotParentSerializer
    {
        return new PivotParentSerializer(
            $inner,
            'playlists',
            $this->createStub(RelationInterface::class),
            $this->createStub(SerializerResolverInterface::class),
            new PivotMetaSerializer($this->createStub(SerializerInterface::class), []),
        );
    }
}
