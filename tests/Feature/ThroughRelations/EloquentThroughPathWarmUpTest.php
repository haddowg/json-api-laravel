<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughRelations;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Server\ThroughPathGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * On a type an Eloquent model serves, `jsonapi:optimize` refuses a filter or sort reaching
 * through relations that the Eloquent provider can't walk, naming the type, the server and the
 * declaration.
 *
 * @internal
 */
#[CoversClass(ThroughPathGuard::class)]
final class EloquentThroughPathWarmUpTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, MisfitServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('thr_misfits', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('target_id')->nullable();
            $table->unsignedInteger('tag_id')->nullable();
            $table->unsignedInteger('secret_id')->nullable();
        });
        Schema::create('thr_misfit_targets', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('misfit_id')->nullable();
            $table->string('name');
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusals(): iterable
    {
        yield 'a hop the model has no relation for' => ['the filter "ghostName" reaching through "ghost", which the Eloquent provider cannot run: "ghost" is not a relation of'];
        yield 'a leaf the reached table has no column for' => ['the filter "slogan" reaching through "target", which the Eloquent provider cannot run: "slogan" is not a column of'];
        yield 'a hop into a type no model serves' => ['the filter "tagName" reaching through "tag", which the Eloquent provider cannot run: the relation "tag" reaches the type "thrMisfitTags", which no Eloquent model serves.'];
        yield 'a hop into a type another provider scopes' => ['the filter "secretName" reaching through "secret", which the Eloquent provider cannot run: the type "thrMisfitSecrets" is scoped by a provider other than Eloquent'];
        yield 'a sort through a relation that holds many rows' => ['the sort "latestName" reaching through "latest", which the Eloquent provider cannot run: "latest" is not a to-one relation of'];
    }

    #[Test]
    public function theWarmerRefusesAPathTheEloquentProviderCannotWalk(): void
    {
        $warmer = $this->app?->make(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);
        $problems = \implode("\n", $warmer->warm());

        foreach (self::refusals() as $case => [$message]) {
            self::assertStringContainsString('The JSON:API resource for type "thrMisfits" on server "default" declares ' . $message, $problems, $case);
        }
        self::assertStringNotContainsString('"targetName"', $problems);
    }
}

/**
 * @internal
 */
final class MisfitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([MisfitResource::class, MisfitTargetResource::class, MisfitTagResource::class, MisfitSecretResource::class]);
        JsonApi::provider(new EloquentDataProvider(['thrMisfits' => Misfit::class, 'thrMisfitTargets' => MisfitTarget::class]), priority: -128);
        JsonApi::provider(new InMemoryDataProvider('thrMisfitTags', []));
        JsonApi::provider(new MisfitSecretProvider());
    }
}

/**
 * @internal
 */
final class Misfit extends Model
{
    protected $table = 'thr_misfits';

    public $timestamps = false;

    /**
     * @return EloquentBelongsTo<MisfitTarget, $this>
     */
    public function target(): EloquentBelongsTo
    {
        return $this->belongsTo(MisfitTarget::class, 'target_id');
    }

    /**
     * @return EloquentBelongsTo<MisfitTarget, $this>
     */
    public function tag(): EloquentBelongsTo
    {
        return $this->belongsTo(MisfitTarget::class, 'tag_id');
    }

    /**
     * @return EloquentBelongsTo<MisfitTarget, $this>
     */
    public function secret(): EloquentBelongsTo
    {
        return $this->belongsTo(MisfitTarget::class, 'secret_id');
    }

    /**
     * @return EloquentHasMany<MisfitTarget, $this>
     */
    public function latest(): EloquentHasMany
    {
        return $this->hasMany(MisfitTarget::class, 'misfit_id');
    }
}

/**
 * @internal
 */
final class MisfitTarget extends Model
{
    protected $table = 'thr_misfit_targets';

    public $timestamps = false;
}

/**
 * @internal
 */
final class MisfitResource extends AbstractResource
{
    public static string $type = 'thrMisfits';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            BelongsTo::make('ghost', 'thrMisfitTargets')->nullable(),
            BelongsTo::make('target', 'thrMisfitTargets')->nullable(),
            BelongsTo::make('tag', 'thrMisfitTags')->nullable(),
            BelongsTo::make('secret', 'thrMisfitSecrets')->nullable(),
            HasOne::make('latest', 'thrMisfitTargets')->nullable(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('ghostName', 'name')->through('ghost'),
            Where::make('targetName', 'name')->through('target'),
            Where::make('slogan', 'slogan')->through('target'),
            Where::make('tagName', 'name')->through('tag'),
            Where::make('secretName', 'name')->through('secret'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('latestName', 'name')->through('latest'),
        ];
    }
}

/**
 * @internal
 */
final class MisfitTargetResource extends AbstractResource
{
    public static string $type = 'thrMisfitTargets';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            Str::make('slogan'),
        ];
    }
}

/**
 * @internal
 */
final class MisfitTagResource extends AbstractResource
{
    public static string $type = 'thrMisfitTags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

/**
 * @internal
 */
final class MisfitSecretResource extends AbstractResource
{
    public static string $type = 'thrMisfitSecrets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

/**
 * Serves no secrets, as a provider that owns its type's rows does.
 *
 * @internal
 *
 * @extends AbstractDataProvider<object>
 */
final class MisfitSecretProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'thrMisfitSecrets';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult([]);
    }
}
