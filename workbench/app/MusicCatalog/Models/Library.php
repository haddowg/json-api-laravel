<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * The `libraries` Eloquent model (storage table `mc_libraries`) — the polymorphic
 * to-many (over-parity) headline. Where Doctrine throws on a `MorphToMany`, the Eloquent
 * reference resolves the mixed `items` set (tracks + albums + artists) natively via
 * {@see morphedByMany} over ONE polymorphic pivot (`mc_library_items`), merged by
 * {@see items()} and read off the parent by the resource's `MorphToMany` `extractUsing`.
 *
 * @property int      $id
 * @property int|null $owner_id
 */
final class Library extends Model
{
    protected $table = 'mc_libraries';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return MorphToMany<Track, $this>
     */
    public function itemTracks(): MorphToMany
    {
        return $this->morphedByMany(Track::class, 'item', 'mc_library_items', 'library_id', 'item_id');
    }

    /**
     * @return MorphToMany<Album, $this>
     */
    public function itemAlbums(): MorphToMany
    {
        return $this->morphedByMany(Album::class, 'item', 'mc_library_items', 'library_id', 'item_id');
    }

    /**
     * @return MorphToMany<Artist, $this>
     */
    public function itemArtists(): MorphToMany
    {
        return $this->morphedByMany(Artist::class, 'item', 'mc_library_items', 'library_id', 'item_id');
    }

    /**
     * The mixed polymorphic `items` set — tracks AND albums AND artists — merged from the
     * three {@see morphedByMany} relations sharing the one polymorphic pivot. A native
     * Eloquent to-many is single-class, so the heterogeneous set is composed here and read
     * by the resource's `items` `extractUsing` closure.
     *
     * @return list<Track|Album|Artist>
     */
    public function libraryItems(): array
    {
        return [
            ...\array_values($this->itemTracks()->get()->all()),
            ...\array_values($this->itemAlbums()->get()->all()),
            ...\array_values($this->itemArtists()->get()->all()),
        ];
    }
}
