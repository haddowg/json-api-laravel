<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Scopes;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApi\Scope\ScopeChecker;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Scopes\ScopesServiceProvider;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\User;

/**
 * The operation catalogue lists each operation with the Gate ability guarding it and the
 * scopes it needs, and the evaluator judges a caller against that ability: `Allowed` or
 * `Denied` when the Gate can decide from the caller, `Depends` when the ability needs the
 * resource or its policy has a `before()`.
 *
 * @internal
 */
#[CoversNothing]
final class OperationCatalogTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ScopesServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.discovery.paths', []);
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.atomic_operations.scopes', ['batch']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('createBook', static fn(?User $user): bool => $user?->is_admin === true);
        Gate::define('editBook', static fn(User $user, object $book): bool => true);
    }

    public function test_it_lists_each_operation_with_its_rule_and_scopes(): void
    {
        $operations = $this->operations();

        self::assertSame('atomic.operations', \array_key_last($operations));
        self::assertSame(['batch'], $operations['atomic.operations']->requiredScopes);
        self::assertNull($operations['atomic.operations']->type);

        self::assertSame(['books:read'], $operations['fetchOne.books']->requiredScopes);
        self::assertSame(['books:write'], $operations['create.books']->requiredScopes);
        self::assertSame('createBook', $operations['create.books']->security);
        self::assertSame(SecurityLevel::Type, $operations['create.books']->securityLevel);
        self::assertSame('editBook', $operations['update.books']->security);
        self::assertSame(SecurityLevel::Entity, $operations['update.books']->securityLevel);
        self::assertFalse($operations['delete.books']->security);
        self::assertSame([], $operations['delete.books']->requiredScopes);

        // A type with no policy and no ability is inert, so its reads report no rule.
        self::assertNull($operations['fetchOne.books']->security);
        self::assertSame(SecurityLevel::None, $operations['fetchOne.books']->securityLevel);

        // The related read needs the returned type's read scope and inherits the parent's rule.
        self::assertSame(['books:read'], $operations['fetchRelated.shelves.book']->requiredScopes);
        self::assertSame('view', $operations['fetchRelated.shelves.book']->security);
        self::assertSame(SecurityLevel::Entity, $operations['fetchRelated.shelves.book']->securityLevel);
        self::assertSame('viewAny', $operations['fetchCollection.shelves']->security);

        self::assertFalse($operations['fetchOne.notes']->security);
    }

    public function test_the_evaluator_allows_denies_or_defers_to_the_resource(): void
    {
        $operations = $this->operations();
        $evaluator = $this->app?->make(SecurityEvaluatorInterface::class);
        self::assertInstanceOf(SecurityEvaluatorInterface::class, $evaluator);

        $admin = new User(['id' => 1, 'name' => 'Admin', 'is_admin' => true]);
        $member = new User(['id' => 2, 'name' => 'Member', 'is_admin' => false]);

        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['create.books'], $admin));
        self::assertSame(SecurityVerdict::Denied, $evaluator->evaluate($operations['create.books'], $member));
        self::assertSame(SecurityVerdict::Denied, $evaluator->evaluate($operations['create.books'], null));

        // The ability needs the book.
        self::assertSame(SecurityVerdict::Depends, $evaluator->evaluate($operations['update.books'], $member));

        // The shelf policy's before() may decide per shelf.
        self::assertSame(SecurityVerdict::Depends, $evaluator->evaluate($operations['fetchCollection.shelves'], $member));
        self::assertSame(SecurityVerdict::Depends, $evaluator->evaluate($operations['fetchRelated.shelves.book'], $member));

        // Public and inert operations.
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['delete.books'], null));
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['fetchOne.books'], null));
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['fetchOne.notes'], null));
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['atomic.operations'], null));
    }

    public function test_the_scope_checker_compares_a_grant_with_the_listed_scopes(): void
    {
        $operations = $this->operations();
        $checker = $this->app?->make(ScopeChecker::class);
        self::assertInstanceOf(ScopeChecker::class, $checker);

        self::assertTrue($checker->satisfies($operations['fetchOne.books'], ['books:read']));
        self::assertFalse($checker->satisfies($operations['fetchOne.books'], []));
        self::assertTrue($checker->satisfies($operations['fetchOne.books'], null));
        self::assertTrue($checker->satisfies($operations['fetchOne.notes'], []));
    }

    /**
     * @return array<string, OperationDefinition>
     */
    private function operations(): array
    {
        $catalog = $this->app?->make(OperationCatalog::class);
        self::assertInstanceOf(OperationCatalog::class, $catalog);

        $operations = [];
        foreach ($catalog->forServer('default') as $operation) {
            $operations[$operation->operationId] = $operation;
        }

        return $operations;
    }
}
