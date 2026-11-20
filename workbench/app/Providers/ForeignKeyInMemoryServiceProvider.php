<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\ForeignKey\Domain\FkItem;
use Workbench\App\ForeignKey\Domain\FkMember;
use Workbench\App\ForeignKey\Domain\FkNote;
use Workbench\App\ForeignKey\Domain\FkProfile;
use Workbench\App\ForeignKey\Domain\FkSecret;
use Workbench\App\ForeignKey\Domain\FkTeam;
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
use Workbench\App\ForeignKey\ForeignKeySeed;
use Workbench\App\ForeignKey\ImportSecretsAction;
use Workbench\App\ForeignKey\ReviseGrantAction;
use Workbench\App\ForeignKey\StampGrantAction;

/**
 * The foreign-key suite with its related rows in memory, over {@see ForeignKeySeed}: a member's
 * `team` holds the team object itself.
 */
final class ForeignKeyInMemoryServiceProvider extends ServiceProvider
{
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
        ]);

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);
        $rows = ForeignKeySeed::rows();

        $teams = [];
        foreach ($rows['teams'] as $row) {
            $teams[(string) $row['id']] = new FkTeam((string) $row['id'], $row['name']);
        }

        $stores = [
            'fk-items' => [static fn(): FkItem => new FkItem(), \array_map(static fn(array $row): FkItem => new FkItem((string) $row['id'], $row['title'], $row['owner_key']), $rows['items'])],
            'fk-notes' => [static fn(): FkNote => new FkNote(), \array_map(static fn(array $row): FkNote => new FkNote((string) $row['id'], $row['body'], $row['owner'], $row['archived']), $rows['notes'])],
            'fk-profiles' => [static fn(): FkProfile => new FkProfile(), \array_map(static fn(array $row): FkProfile => new FkProfile((string) $row['id'], $row['bio'], $row['owner']), $rows['profiles'])],
            'fk-secrets' => [static fn(): FkSecret => new FkSecret(), \array_map(static fn(array $row): FkSecret => new FkSecret((string) $row['id'], $row['label'], $row['owner'], $row['hidden']), $rows['secrets'])],
            'fk-teams' => [static fn(): FkTeam => new FkTeam(), \array_values($teams)],
            'fk-members' => [static fn(): FkMember => new FkMember(), \array_map(static fn(array $row): FkMember => new FkMember((string) $row['id'], $row['name'], $row['team_id'] === null ? null : $teams[(string) $row['team_id']]), $rows['members'])],
        ];

        foreach ($stores as $type => [$instantiate, $items]) {
            $byId = [];
            foreach ($items as $item) {
                $byId[$item->id] = $item;
            }

            $provider = new InMemoryDataProvider($type, $byId, identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
            JsonApi::provider($provider);
            JsonApi::persister(new InMemoryDataPersister($type, $provider->store(), $instantiate));
        }

        JsonApi::provider(new FkUserProvider(fn(): DataProviderRegistry => $this->app->make(DataProviderRegistry::class)));
        JsonApi::persister(new FkUserPersister());

        $grants = new FkGrantProvider();
        JsonApi::provider($grants);
        JsonApi::persister(new FkGrantPersister($grants));
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
