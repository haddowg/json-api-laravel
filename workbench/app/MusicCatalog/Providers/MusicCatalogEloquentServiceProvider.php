<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Providers;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User as AuthUser;
use Workbench\App\MusicCatalog\Action\ReissueAlbum;
use Workbench\App\MusicCatalog\Action\SummarizeAlbums;
use Workbench\App\MusicCatalog\Action\UploadAlbumArtwork;
use Workbench\App\MusicCatalog\JsonApi\AlbumResource;
use Workbench\App\MusicCatalog\JsonApi\ArtistResource;
use Workbench\App\MusicCatalog\JsonApi\CatalogExportResource;
use Workbench\App\MusicCatalog\JsonApi\DeviceResource;
use Workbench\App\MusicCatalog\JsonApi\ExportJobResource;
use Workbench\App\MusicCatalog\JsonApi\FavoriteResource;
use Workbench\App\MusicCatalog\JsonApi\GenreResource;
use Workbench\App\MusicCatalog\JsonApi\LibraryResource;
use Workbench\App\MusicCatalog\JsonApi\PlaylistResource;
use Workbench\App\MusicCatalog\JsonApi\ProductResource;
use Workbench\App\MusicCatalog\JsonApi\PublicProfileResource;
use Workbench\App\MusicCatalog\JsonApi\ReleaseResource;
use Workbench\App\MusicCatalog\JsonApi\TrackResource;
use Workbench\App\MusicCatalog\JsonApi\UserResource;
use Workbench\App\MusicCatalog\Listeners\AuditLogSubscriber;
use Workbench\App\MusicCatalog\Models\Album;
use Workbench\App\MusicCatalog\Models\Artist;
use Workbench\App\MusicCatalog\Models\Device;
use Workbench\App\MusicCatalog\Models\Favorite;
use Workbench\App\MusicCatalog\Models\Genre;
use Workbench\App\MusicCatalog\Models\Library;
use Workbench\App\MusicCatalog\Models\Playlist;
use Workbench\App\MusicCatalog\Models\Product;
use Workbench\App\MusicCatalog\Models\Release;
use Workbench\App\MusicCatalog\Models\Track;
use Workbench\App\MusicCatalog\Models\User;
use Workbench\App\MusicCatalog\Provider\CatalogExportPersister;
use Workbench\App\MusicCatalog\Provider\CatalogExportProvider;
use Workbench\App\MusicCatalog\Provider\ChartProvider;
use Workbench\App\MusicCatalog\Provider\CountryProvider;
use Workbench\App\MusicCatalog\Provider\ExportJobProvider;
use Workbench\App\MusicCatalog\Query\EloquentFullTextSearchArm;
use Workbench\App\MusicCatalog\Security\PlaylistApiPolicy;
use Workbench\App\MusicCatalog\Serializer\ChartSerializer;
use Workbench\App\MusicCatalog\Serializer\CountrySerializer;
use Workbench\App\MusicCatalog\Support\AuditLog;

/**
 * The Eloquent half of the unified music-catalog wiring (decision 14): it registers the
 * full domain's resources + the `reissue` action explicitly (they live outside the scanned
 * `app/JsonApi` inventory, so they never disturb the per-phase suites), maps every JSON:API
 * type to its `mc_`-prefixed Eloquent model, and serves them through the reference
 * {@see EloquentDataProvider} / {@see EloquentDataPersister} pair at `-128`.
 *
 * A morph map with aliases (`mc_track`/`mc_album`/`mc_artist`) distinct from the JSON:API
 * types backs the polymorphic `favorites.favoritable` (morphTo) and the over-parity
 * `libraries.items` (morphedByMany) — core resolves the wire type from each member's
 * `getType()`, decoupled from the storage alias.
 */
final class MusicCatalogEloquentServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const array MORPH_MAP = [
        'mc_track' => Track::class,
        'mc_album' => Album::class,
        'mc_artist' => Artist::class,
    ];

    public function register(): void
    {
        // Registered in ascending type order so the projected OpenAPI `paths` (emitted in
        // discovery order) match the bundle's alphabetical-by-type descriptor order — the
        // byte-compat contract (decision 11). Actions attach to their mount type, so their
        // position among the resources is immaterial; their order *among each other*
        // (reissue → summary → artwork) is what the document reflects.
        JsonApi::register([
            AlbumResource::class,
            ArtistResource::class,
            CatalogExportResource::class,
            DeviceResource::class,
            ExportJobResource::class,
            FavoriteResource::class,
            GenreResource::class,
            LibraryResource::class,
            PlaylistResource::class,
            ProductResource::class,
            PublicProfileResource::class,
            ReleaseResource::class,
            TrackResource::class,
            UserResource::class,
            ReissueAlbum::class,
            SummarizeAlbums::class,
            UploadAlbumArtwork::class,
            // The two standalone-serializer types (no resource, no model), registered
            // last so their paths follow the resources in the projected document — the
            // byte-compat contract (decision 11, decision 3, bundle ADR 0024).
            ChartSerializer::class,
            CountrySerializer::class,
        ]);

        Relation::morphMap(self::MORPH_MAP);

        $modelByType = [
            'artists' => Artist::class,
            'albums' => Album::class,
            'tracks' => Track::class,
            'genres' => Genre::class,
            'devices' => Device::class,
            'products' => Product::class,
            'users' => User::class,
            'public-profiles' => User::class,
            'favorites' => Favorite::class,
            'libraries' => Library::class,
            'playlists' => Playlist::class,
            'releases' => Release::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType, filterArms: [new EloquentFullTextSearchArm()]), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);

        // The two standalone-serializer types are served by custom providers (no DB), the
        // SAME providers the in-memory wiring uses — charts/countries are storage-orthogonal
        // reference data, so both provider arms read them identically (decision 3).
        JsonApi::provider(new ChartProvider());
        JsonApi::provider(new CountryProvider());

        // The async-write witness types (catalog-exports + its export-jobs job resource):
        // resource-less like charts/countries, served by custom providers on both arms, plus a
        // custom persister that accepts a create for asynchronous processing (a 202).
        JsonApi::provider(new CatalogExportProvider());
        JsonApi::provider(new ExportJobProvider());
        JsonApi::persister(new CatalogExportPersister());

        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);

        // The audit trail is a singleton so every listener invocation appends to the one
        // store the feature test reads back (see AuditLogSubscriber).
        $this->app->singleton(AuditLog::class);
    }

    public function boot(): void
    {
        // The cross-cutting listener set (audit trail + read-only gate) — one concern
        // spanning every type from a single subscriber, no resource touched. Runtime-only:
        // listeners never project to the OpenAPI document (byte-compat pins it).
        Event::subscribe(AuditLogSubscriber::class);

        // The album `reissue` action ability (albums declares no dedicated policy). A
        // write-capable user may reissue; a read-only user / guest is a 403.
        Gate::define('reissueAlbum', static fn(?AuthUser $user, object $album): bool => $user?->can_write === true);

        // The API-distinct playlist abilities (decision 7), resolved to the PlaylistApiPolicy
        // methods via Gate callbacks — the policy's logic without a type-wide policy mapping,
        // so create + the reads stay inert (and inherit the document security, byte-compat).
        Gate::define('curate', [PlaylistApiPolicy::class, 'curate']);
        Gate::define('deletePlaylist', [PlaylistApiPolicy::class, 'deletePlaylist']);
        Gate::define('inspectOwner', [PlaylistApiPolicy::class, 'inspectOwner']);
    }
}
