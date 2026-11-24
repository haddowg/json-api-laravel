<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Posts and videos whose integer ids collide, and the notes each owns through a `MorphTo`
 * identified by columns, served from Eloquent or, when `morph_owners.store` is `memory`, from
 * memory, over the rows {@see seed()} lists.
 */
final class MorphOwnersServiceProvider extends ServiceProvider
{
    /**
     * Each note as `[id, body, owner type, owner id, author type, author id]`.
     *
     * @return array{posts: list<array{int, string}>, videos: list<array{int, string}>, notes: list<array{int, string, ?string, ?int, ?string, ?int}>}
     */
    public static function seed(): array
    {
        return [
            'posts' => [[1, 'P1'], [2, 'P2']],
            'videos' => [[1, 'V1'], [2, 'V2']],
            'notes' => [
                [1, 'n1', 'post', 1, 'video', 1],
                [2, 'n2', 'post', 1, 'post', 1],
                [3, 'n3', 'video', 1, 'post', 2],
                [4, 'n4', 'video', 2, 'video', 2],
                [5, 'hidden', 'post', 2, 'post', 2],
                [6, 'n6', null, null, null, null],
            ],
        ];
    }

    public function register(): void
    {
        JsonApi::register([MoPostResource::class, MoVideoResource::class, MoNoteResource::class]);

        if (config('morph_owners.store') !== 'memory') {
            Relation::morphMap(['post' => MoPost::class, 'video' => MoVideo::class]);
            $models = ['moPosts' => MoPost::class, 'moVideos' => MoVideo::class, 'moNotes' => MoNote::class];
            JsonApi::provider(new EloquentDataProvider($models), priority: -128);
            JsonApi::persister(new EloquentDataPersister($models), priority: -128);

            return;
        }

        $seed = self::seed();
        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $owners = [];
        foreach (['moPosts' => 'posts', 'moVideos' => 'videos'] as $type => $key) {
            $rows = [];
            foreach ($seed[$key] as [$id, $title]) {
                $rows[(string) $id] = $type === 'moPosts' ? new MoPostRecord($type, (string) $id, $title) : new MoVideoRecord($type, (string) $id, $title);
            }
            $owners[$type] = $rows;
            $provider = new InMemoryDataProvider($type, $rows, identify: $identify);
            JsonApi::provider($provider);
            JsonApi::persister(new InMemoryDataPersister($type, $provider->store(), static fn(): MoOwnerRecord => $type === 'moPosts' ? new MoPostRecord($type, '', '') : new MoVideoRecord($type, '', '')));
        }
        $notes = [];
        foreach ($seed['notes'] as [$id, $body, $ownerType, $ownerId, $authorType, $authorId]) {
            if ($body !== 'hidden') {
                $author = $authorType !== null ? $owners[$authorType === 'post' ? 'moPosts' : 'moVideos'][(string) $authorId] ?? null : null;
                $notes[(string) $id] = new MoNoteRecord((string) $id, $body, $ownerType, $ownerId !== null ? (string) $ownerId : null, $author);
                if ($author instanceof MoVideoRecord) {
                    $author->authoredNotes[] = $notes[(string) $id];
                }
            }
        }
        $noteProvider = new InMemoryDataProvider('moNotes', $notes, identify: $identify);
        JsonApi::provider($noteProvider);
        $resolver = static fn(string $type, string $id): ?object => $type === 'moNotes' ? $noteProvider->store()->find($id) : ($owners[$type][$id] ?? null);
        JsonApi::persister(new InMemoryDataPersister('moNotes', $noteProvider->store(), static fn(): MoNoteRecord => new MoNoteRecord(), $resolver));
    }
}
