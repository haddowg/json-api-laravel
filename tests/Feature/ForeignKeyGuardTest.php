<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\ComputedSecretsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\ForeignKeyGuardServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\ImportsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\KeyedItemsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\MisnamedKeyUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\PivotItemsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\UnkeyedItemsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\UnkeyedProfileUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\UnkeyedSecretsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\UnreadableKeyUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\UpdatableWriteOnlySecretsUserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\WriteOnlySecretsUserResource;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\ForeignKey\FkImportResource;
use Workbench\App\ForeignKey\ImportSecretsAction;

/**
 * A relation declared with a foreign key must name a member of its related type holding the
 * parent's id, read by a provider that reads through one, or route registration and
 * `jsonapi:optimize` fail. A to-many from a parent no model serves into a type a global scope
 * narrows has no way to apply that scope to the parent's members, so `jsonapi:optimize` refuses
 * one that names no foreign key and isn't computed, as it does a stored-pivot `BelongsToMany`
 * on such a parent. Into an unscoped type, and for a to-one, the model's value is read, so it
 * passes.
 *
 * @internal
 */
final class ForeignKeyGuardTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    protected function tearDown(): void
    {
        ForeignKeyGuardServiceProvider::$subject = KeyedItemsUserResource::class;
        ForeignKeyGuardServiceProvider::$extras = [];
        ForeignKeyGuardServiceProvider::$persistsUsers = false;
        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ForeignKeyGuardServiceProvider::class];
    }

    #[Test]
    public function aMisnamedForeignKeyFailsRouteRegistrationAndOptimize(): void
    {
        $this->boot(MisnamedKeyUserResource::class);
        $problem = 'reads through the foreign key "missing" on type "fk-items", but it declares no such field or relation';
        self::assertStringContainsString($problem, \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm()));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($problem);
        $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
    }

    #[Test]
    public function aForeignKeyAProviderCantReadThroughFailsRouteRegistration(): void
    {
        $this->boot(UnreadableKeyUserResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "labels" on resource type "fk-users" reads through the foreign key "owner", but the haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard\LabelProvider serving type "fk-labels" can\'t read through one.');
        $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
    }

    #[Test]
    public function aToManyIntoAScopedTypeWithNoForeignKeyFailsOptimize(): void
    {
        $this->boot(UnkeyedSecretsUserResource::class);

        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());
        self::assertStringContainsString('Relation "secrets" on resource type "fk-users" reads type "fk-secrets", whose model', $problems);
        self::assertStringContainsString('as the foreign key argument, HasMany::make(\'secrets\', \'fk-secrets\', \'<foreign key>\'), or compute it with extractUsing(). If only a request document ever sets it, mark it writeOnly() and leave Update out of the operations of "fk-users". A type that only an action takes as input needs none of these once it is declared relatedOnly', $problems);
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }

    #[Test]
    public function aWriteOnlyToManyOfATypeThatTakesAnUpdateFailsOptimize(): void
    {
        ForeignKeyGuardServiceProvider::$persistsUsers = true;
        $this->boot(UpdatableWriteOnlySecretsUserResource::class);

        self::assertStringContainsString(
            'Relation "secrets" on resource type "fk-users" reads type "fk-secrets", whose model',
            \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm()),
        );
    }

    #[Test]
    public function aToManyOfARelatedOnlyTypeARelationReachesFailsOptimize(): void
    {
        ForeignKeyGuardServiceProvider::$extras = [FkImportResource::class, ImportSecretsAction::class];
        $this->boot(ImportsUserResource::class);

        self::assertStringContainsString(
            'Relation "secrets" on resource type "fk-imports" reads type "fk-secrets", whose model',
            \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm()),
        );
    }

    #[Test]
    public function aToManyNoRequestReadsPassesOptimize(): void
    {
        $this->boot(WriteOnlySecretsUserResource::class);
        self::assertSame([], $this->resolve(ServableResourceWarmer::class)->warm());

        ForeignKeyGuardServiceProvider::$extras = [FkImportResource::class, ImportSecretsAction::class];
        $this->boot(KeyedItemsUserResource::class);
        self::assertSame([], $this->resolve(ServableResourceWarmer::class)->warm());
    }

    #[Test]
    public function aStoredPivotBelongsToManyOnAProviderBackedParentFailsOptimize(): void
    {
        $this->boot(PivotItemsUserResource::class);

        self::assertStringContainsString(
            'Relation "items" on resource type "fk-users" is a BelongsToMany with stored pivot fields',
            \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm()),
        );
    }

    #[Test]
    public function relationsThatReachTheirRowsPass(): void
    {
        foreach ([KeyedItemsUserResource::class, UnkeyedItemsUserResource::class, UnkeyedProfileUserResource::class, ComputedSecretsUserResource::class] as $subject) {
            $this->boot($subject);

            self::assertSame([], $this->resolve(ServableResourceWarmer::class)->warm(), $subject);
            $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
        }
    }

    /**
     * @param class-string $subject
     */
    private function boot(string $subject): void
    {
        ForeignKeyGuardServiceProvider::$subject = $subject;
        $this->refreshApplication();
    }
}
