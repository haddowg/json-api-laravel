<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\ForeignKey\FkGrantPersister;
use Workbench\App\ForeignKey\FkGrantProvider;
use Workbench\App\ForeignKey\FkGrantResource;
use Workbench\App\ForeignKey\FkImportResource;
use Workbench\App\ForeignKey\FkInboxResource;
use Workbench\App\ForeignKey\FkItemResource;
use Workbench\App\ForeignKey\FkMemberResource;
use Workbench\App\ForeignKey\FkNoteResource;
use Workbench\App\ForeignKey\FkProfileResource;
use Workbench\App\ForeignKey\FkRevisableGrantResource;
use Workbench\App\ForeignKey\FkSecretResource;
use Workbench\App\ForeignKey\FkTeamResource;
use Workbench\App\ForeignKey\FkUserPersister;
use Workbench\App\ForeignKey\FkUserProvider;
use Workbench\App\ForeignKey\FkUserResource;
use Workbench\App\ForeignKey\ImportSecretsAction;
use Workbench\App\ForeignKey\Models\FkItem;
use Workbench\App\ForeignKey\Models\FkMember;
use Workbench\App\ForeignKey\Models\FkNote;
use Workbench\App\ForeignKey\Models\FkProfile;
use Workbench\App\ForeignKey\Models\FkSecret;
use Workbench\App\ForeignKey\Models\FkTeam;
use Workbench\App\ForeignKey\ReviseAnyGrantAction;
use Workbench\App\ForeignKey\ReviseGrantAction;
use Workbench\App\ForeignKey\ReviseLockedItemsAction;
use Workbench\App\ForeignKey\StampGrantAction;

/**
 * The foreign-key suite with its related rows on Eloquent: users and the inbox from a custom
 * provider, everything else on the reference provider and persister, with the Eloquent load
 * state, so a lazy relation renders links only.
 */
final class ForeignKeyEloquentServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const array MODEL_BY_TYPE = [
        'fk-items' => FkItem::class,
        'fk-notes' => FkNote::class,
        'fk-profiles' => FkProfile::class,
        'fk-secrets' => FkSecret::class,
        'fk-teams' => FkTeam::class,
        'fk-members' => FkMember::class,
    ];

    public function register(): void
    {
        JsonApi::register([
            FkUserResource::class,
            FkInboxResource::class,
            FkItemResource::class,
            FkNoteResource::class,
            FkProfileResource::class,
            FkSecretResource::class,
            FkTeamResource::class,
            FkMemberResource::class,
            FkImportResource::class,
            ImportSecretsAction::class,
            FkGrantResource::class,
            FkRevisableGrantResource::class,
            StampGrantAction::class,
            ReviseGrantAction::class,
            ReviseAnyGrantAction::class,
            ReviseLockedItemsAction::class,
        ]);

        JsonApi::provider(new EloquentDataProvider(self::MODEL_BY_TYPE), priority: -128);
        JsonApi::persister(new EloquentDataPersister(self::MODEL_BY_TYPE), priority: -128);
        JsonApi::provider(new FkUserProvider(fn(): DataProviderRegistry => $this->app->make(DataProviderRegistry::class)));
        JsonApi::persister(new FkUserPersister());

        $grants = new FkGrantProvider(static fn(string $id, array $locked): \Workbench\App\ForeignKey\Models\FkGrant => \Workbench\App\ForeignKey\Models\FkGrant::seeded($id, $locked));
        JsonApi::provider($grants);
        JsonApi::persister(new FkGrantPersister($grants));

        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
