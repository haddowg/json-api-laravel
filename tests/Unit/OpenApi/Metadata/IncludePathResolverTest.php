<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Metadata;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\OpenApi\Metadata\IncludePathResolver;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncludePathResolver::class)]
final class IncludePathResolverTest extends TestCase
{
    #[Test]
    public function anExcludedPathAndEverythingBelowItIsNotAdvertised(): void
    {
        // editor.company sits below the excluded editor, so it goes too.
        self::assertEqualsCanonicalizing(
            ['author', 'author.company', 'comments'],
            $this->pathsFor('guarded-articles', IncGuardedArticle::class),
        );
    }

    #[Test]
    public function anExcludedDeepPathLeavesItsAncestorsAdvertised(): void
    {
        self::assertEqualsCanonicalizing(
            ['author', 'comments'],
            $this->pathsFor('author-guarded-articles', IncAuthorGuardedArticle::class),
        );
    }

    /**
     * @param class-string<AbstractResource> $root
     *
     * @return list<string>
     */
    private function pathsFor(string $type, string $root): array
    {
        $server = Server::make()
            ->withMaxIncludeDepth(3)
            ->register($root)
            ->register(IncPerson::class)
            ->register(IncCompany::class)
            ->register(IncComment::class);

        return (new IncludePathResolver(new TypeMetadataResolver()))->pathsFor($server, $type);
    }
}

final class IncGuardedArticle extends AbstractResource
{
    public static string $type = 'guarded-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('author', 'people'),
            BelongsTo::make('editor', 'people'),
            HasMany::make('comments', 'comments'),
        ];
    }

    public function getExcludedIncludePaths(): array
    {
        return ['editor'];
    }
}

final class IncAuthorGuardedArticle extends AbstractResource
{
    public static string $type = 'author-guarded-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('author', 'people'),
            HasMany::make('comments', 'comments'),
        ];
    }

    public function getExcludedIncludePaths(): array
    {
        return ['author.company'];
    }
}

final class IncPerson extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [Id::make(), BelongsTo::make('company', 'companies')];
    }
}

final class IncCompany extends AbstractResource
{
    public static string $type = 'companies';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}

final class IncComment extends AbstractResource
{
    public static string $type = 'comments';

    public function fields(): array
    {
        return [Id::make(), Str::make('body')];
    }
}
