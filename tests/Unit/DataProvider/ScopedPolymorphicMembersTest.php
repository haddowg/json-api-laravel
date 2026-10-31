<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\FetchesManyInterface;
use haddowg\JsonApiLaravel\DataProvider\ScopedPolymorphicMembers;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\Author;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\AuthorResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\Tag;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\TagResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A polymorphic relation's members are narrowed per member type, with one lookup per scoped
 * type across every parent (ADR 0032).
 *
 * @internal
 */
#[CoversClass(ScopedPolymorphicMembers::class)]
final class ScopedPolymorphicMembersTest extends TestCase
{
    public function test_each_scoped_member_type_is_looked_up_once_across_parents(): void
    {
        $authors = new class extends AbstractDataProvider implements FetchesManyInterface {
            /** @var list<list<string>> */
            public array $lookups = [];

            public function supports(string $type): bool
            {
                return $type === 'authors';
            }

            public function fetchOne(string $type, string $id): ?object
            {
                throw new \LogicException('A batched lookup is expected.');
            }

            public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
            {
                return new CollectionResult([]);
            }

            public function fetchMany(string $type, array $ids): array
            {
                $this->lookups[] = $ids;

                return \in_array('1', $ids, true) ? [new Author('1', 'Ada')] : [];
            }
        };

        $server = Server::make()->register(AuthorResource::class)->register(TagResource::class);
        $ada = new Author('1', 'Ada');
        $grace = new Author('2', 'Grace');
        $php = new Tag('1', 'php');

        $narrowed = (new ScopedPolymorphicMembers(new DataProviderRegistry([$authors])))->narrow(
            $server,
            'posts',
            MorphToMany::make('related', ['authors', 'tags'])->build(),
            ['a' => [$ada, $php], 'b' => [$grace, $ada]],
        );

        self::assertSame([['1', '2']], $authors->lookups);
        self::assertSame(['a' => [$ada, $php], 'b' => [$ada]], $narrowed);
    }
}
