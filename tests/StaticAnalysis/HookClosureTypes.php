<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\StaticAnalysis;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\ActionLinkBuilder;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetExpression;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Testing\JsonApiAssertions;
use haddowg\JsonApiLaravel\Validation\Constraint\LaravelRules;
use Illuminate\Testing\TestResponse;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings and
 * larastan, as an application would, so a hook declaration that rejects or narrows a
 * closure typed on an Eloquent model, or a package seam that rejects an application's
 * closure, fails the `composer phpstan` run.
 */
final class HookClosureTypes
{
    /**
     * @return list<FieldBuilderInterface|DeclaredLinkBuilder|ActionLinkBuilder>
     */
    public static function declarations(): array
    {
        return [
            Str::make('title')
                ->extractUsing(static fn(HookAlbum $album): string => $album->title)
                ->fillUsing(static fn(HookAlbum $album, mixed $value): HookAlbum => $album)
                ->hidden(static fn(HookAlbum $album, JsonApiRequestInterface $request): bool => $album->locked)
                ->readOnly(static fn(JsonApiRequestInterface $request): bool => $request->getMethod() === 'POST'),
            Str::make('label')->computedUsing(
                static fn(HookAlbum $album, JsonApiRequestInterface $request, string $name): string => $name . $album->id,
            ),
            Str::make('nullable')->extractUsing(static fn(?HookAlbum $album): ?string => $album?->title),
            Obj::make('credits')
                ->additionalProperties(Str::make('*'))
                ->extractUsing(static fn(HookAlbum $album): ?array => $album->credits),
            Map::make('totals')->fields(Integer::make('views'))
                ->extractUsing(static fn(HookAlbum $album, JsonApiRequestInterface $request, string $name): array => ['views' => $album->id]),
            HasMany::make('children', 'albums')
                ->extractUsing(static fn(HookAlbum $album): mixed => $album->children)
                ->cannotAdd(static fn(HookAlbum $album): bool => $album->locked)
                ->cannotRemove(static fn(?HookAlbum $album): bool => $album?->locked === true)
                ->cannotReplace(static fn(HookAlbum $album, JsonApiRequestInterface $request): bool => $album->locked)
                ->cannotBeIncluded(static fn(HookAlbum $album): bool => $album->locked)
                ->identifierMeta(static fn(HookAlbum $parent, HookAlbum $related): array => ['parent' => $parent->id, 'at' => $related->id]),
            BelongsTo::make('parent', 'albums')->extractUsing(static fn(HookAlbum $album): ?HookAlbum => $album->parent),
            DeclaredLink::make('store')
                ->hrefUsing(static fn(HookAlbum $album): string => '/albums/' . $album->id)
                ->metaUsing(static fn(?HookAlbum $album): array => ['title' => $album?->title]),
            ActionLink::for('publish')
                ->when(static fn(HookAlbum $album, JsonApiRequestInterface $request): bool => !$album->locked),
        ];
    }

    /**
     * The package's own seams an application passes closures to.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    public static function seams(ActionContext $context, TestResponse $response): InMemoryDataPersister
    {
        $context->afterCommit(static function (): void {});

        $provider = new InMemoryDataProvider(
            'albums',
            [],
            static function (object $item): string {
                \assert($item instanceof HookAlbum);

                return (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof HookAlbum);

                $item->id = (int) $id;
            },
        );
        $persister = new InMemoryDataPersister('albums', $provider->store(), static fn(): HookAlbum => new HookAlbum());

        EloquentKeysetExpression::make('CAST(rank AS REAL)')->castUsing(static fn(string|int|float|bool $value): float => (float) $value);
        LaravelRules::make('alpha')->schema(static fn(Schema $schema): Schema => $schema);

        JsonApiAssertions::document($response, static function (JsonApiDocument $document): void {});
        JsonApiAssertions::error($response, 422, static function (JsonApiErrors $errors): void {});

        return $persister;
    }
}
