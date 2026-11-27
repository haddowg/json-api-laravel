<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\ProvidesResourcesInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApiLaravel\Serializer\PivotRenderingResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation declared with `identifiedBy()` passes the rendering resolver on unless one of its
 * types encodes its ids, since core encodes its stored ids only through the type's own
 * resource.
 *
 * @internal
 */
#[CoversClass(PivotRenderingResolver::class)]
final class PivotRenderingResolverTest extends TestCase
{
    #[Test]
    public function anIdentifiedRelationOverPlainIdsPassesTheResolverOn(): void
    {
        $server = $this->server(encoded: false);
        $rendering = $this->rendering($server);

        self::assertSame($rendering, $rendering->forRelation(new \stdClass(), $this->identified(), 'shelves'));
    }

    #[Test]
    public function anIdentifiedRelationOverEncodedIdsIsBuiltThroughTheServer(): void
    {
        $server = $this->server(encoded: true);

        self::assertSame($server, $this->rendering($server)->forRelation(new \stdClass(), $this->identified(), 'shelves'));
    }

    private function identified(): MorphTo
    {
        return MorphTo::make('tagged', ['crates'])
            ->identifiedBy(static fn(mixed $shelf): array => ['crates', '1'])
            ->build();
    }

    private function server(bool $encoded): SerializerResolverInterface
    {
        $id = Id::make();
        if ($encoded) {
            $id = $id->encodeUsing(new class implements IdEncoderInterface {
                public function encode(mixed $storageKey): string
                {
                    return 'c-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
                }

                public function decode(string $wireId): mixed
                {
                    return \substr($wireId, 2);
                }
            });
        }
        $resource = new class ($id->build()) extends AbstractResource {
            public static string $type = 'crates';

            public function __construct(private readonly Id $id) {}

            public function fields(): array
            {
                return [$this->id];
            }
        };

        $server = $this->createStubForIntersectionOfInterfaces([SerializerResolverInterface::class, ProvidesResourcesInterface::class]);
        $server->method('hasResourceFor')->willReturn(true);
        $server->method('resourceFor')->willReturn($resource);
        \assert($server instanceof SerializerResolverInterface);

        return $server;
    }

    private function rendering(SerializerResolverInterface $server): PivotRenderingResolver
    {
        return new PivotRenderingResolver(
            $server,
            static fn(string $type): array => [],
            static fn(object $parent, BelongsToMany $relation, string $type): ?\Closure => null,
        );
    }
}
