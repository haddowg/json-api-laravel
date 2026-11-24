<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners\MoBareIdPostResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners\MorphOwnersGuardServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners\MoUnmodelledPostResource;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A foreign key naming the bare id field of a polymorphic owner would read another type's rows
 * with the same id, and one naming an Eloquent `morphTo` needs a model to know the stored morph
 * class, so route registration refuses both.
 */
#[CoversNothing]
final class MorphOwnersBootGuardTest extends Orchestra
{
    protected function tearDown(): void
    {
        MorphOwnersGuardServiceProvider::$subject = MoBareIdPostResource::class;
        MorphOwnersGuardServiceProvider::$modelledPosts = true;
        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, MorphOwnersGuardServiceProvider::class];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function resolve(string $class): object
    {
        $app = $this->app;
        self::assertNotNull($app);
        $resolved = $app->make($class);
        self::assertInstanceOf($class, $resolved);

        return $resolved;
    }

    #[Test]
    public function aForeignKeyNamingThePolymorphicIdFieldFailsRouteRegistration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Name the MorphTo "owner" as the foreign key instead');
        $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
    }

    #[Test]
    public function aForeignKeyNamingAnEloquentMorphToOfAnUnmodelledParentFailsRouteRegistration(): void
    {
        MorphOwnersGuardServiceProvider::$subject = MoUnmodelledPostResource::class;
        MorphOwnersGuardServiceProvider::$modelledPosts = false;
        $this->refreshApplication();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stores the morph class of an Eloquent model, but no Eloquent model serves "moPosts"');
        $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
    }
}
