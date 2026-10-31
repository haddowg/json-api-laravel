<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Authorization;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Authorization\RelationAuthorizer;
use haddowg\JsonApiLaravel\Authorization\ViewableRows;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\Author;
use haddowg\JsonApiLaravel\Tests\Fixtures\Relations\Tag;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A to-many renders its data only when the caller may list every type it holds, through the
 * same collection gate `GET /{type}` runs (ADR 0044).
 *
 * @internal
 */
#[CoversClass(RelationAuthorizer::class)]
#[CoversClass(ViewableRows::class)]
final class RelationAuthorizerTest extends TestCase
{
    public function test_a_to_many_is_withheld_when_its_type_may_not_be_listed(): void
    {
        $this->denyListingAuthors();

        self::assertFalse($this->authorizer()->canReadRelationship(new \stdClass(), HasMany::make('members', 'authors')->build(), $this->request()));
        self::assertTrue($this->authorizer()->canReadRelationship(new \stdClass(), HasMany::make('labels', 'tags')->build(), $this->request()));
    }

    public function test_a_polymorphic_to_many_is_withheld_when_any_member_type_may_not_be_listed(): void
    {
        $relation = MorphToMany::make('related', ['tags', 'authors'])->build();

        self::assertTrue($this->authorizer()->canReadRelationship(new \stdClass(), $relation, $this->request()));

        $this->denyListingAuthors();
        self::assertFalse($this->authorizer()->canReadRelationship(new \stdClass(), $relation, $this->request()));
    }

    private function denyListingAuthors(): void
    {
        $gate = $this->app?->make(Gate::class);
        self::assertInstanceOf(Gate::class, $gate);
        $gate->define('viewAny', static fn(?Authenticatable $user, mixed $subject = null): bool => $subject !== Author::class);
    }

    private function authorizer(): RelationAuthorizer
    {
        $gate = $this->app?->make(Gate::class);
        self::assertInstanceOf(Gate::class, $gate);
        $authorizer = new Authorizer($gate, []);
        $persisters = new DataPersisterRegistry([
            new InMemoryDataPersister('authors', new InMemoryStore(), static fn(): Author => new Author('0', '')),
            new InMemoryDataPersister('tags', new InMemoryStore(), static fn(): Tag => new Tag('0', '')),
        ]);

        return new RelationAuthorizer(
            $authorizer,
            static fn(): never => throw new \LogicException('No server is needed to decide a list gate.'),
            new ViewableRows($authorizer, $persisters),
        );
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }
}
