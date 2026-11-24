<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Serves the id-kinds fixture from Eloquent under the {@see IdKinds} kind `id_kinds.kind`
 * names, or from memory when `id_kinds.store` is `memory`, over the rows {@see seed()} lists.
 */
final class IdKindsServiceProvider extends ServiceProvider
{
    /**
     * The seeded rows, each id a row reference {@see IdKinds::wire()} resolves.
     *
     * @return array{
     *     writers: list<array{string, string}>,
     *     labels: list<array{string, string, bool}>,
     *     books: list<array{id: string, title: string, writer: ?string, featuredBy: ?string, labels: list<string>, subject: ?array{string, string}, origin: ?array{string, string}, pins: list<array{string, string}>}>
     * }
     */
    public static function seed(): array
    {
        return [
            'writers' => [['w1', 'Ada'], ['w2', 'Bronte'], ['w3', 'Cleo']],
            'labels' => [['l1', 'classic', false], ['l2', 'scifi', false], ['l3', 'secret', true]],
            'books' => [
                ['id' => 'b1', 'title' => 'Dune', 'writer' => 'w1', 'featuredBy' => 'w1', 'labels' => ['l1', 'l2'], 'subject' => ['ikWriters', 'w2'], 'origin' => ['ikLabels', 'l1'], 'pins' => [['ikWriters', 'w1'], ['ikLabels', 'l2']]],
                ['id' => 'b2', 'title' => 'Emma', 'writer' => 'w1', 'featuredBy' => null, 'labels' => ['l1', 'l3'], 'subject' => ['ikLabels', 'l1'], 'origin' => ['ikWriters', 'w2'], 'pins' => [['ikLabels', 'l3']]],
                ['id' => 'b3', 'title' => 'Ulysses', 'writer' => 'w2', 'featuredBy' => 'w2', 'labels' => [], 'subject' => ['ikLabels', 'l3'], 'origin' => ['ikLabels', 'l3'], 'pins' => []],
            ],
        ];
    }

    public function register(): void
    {
        JsonApi::register([IkWriterResource::class, IkBookResource::class, IkLabelResource::class]);

        if (config('id_kinds.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        Relation::morphMap(['ikWriters' => IdKinds::model('writer'), 'ikBooks' => IdKinds::model('book'), 'ikLabels' => IdKinds::model('label')]);
        $models = ['ikWriters' => IdKinds::model('writer'), 'ikBooks' => IdKinds::model('book'), 'ikLabels' => IdKinds::model('label')];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }

    private function registerInMemory(): void
    {
        $seed = self::seed();

        $writers = [];
        foreach ($seed['writers'] as [$ref, $name]) {
            $writers[IdKinds::wire($ref)] = new IkWriterRecord(IdKinds::wire($ref), $name);
        }
        $labels = [];
        foreach ($seed['labels'] as [$ref, $name, $hidden]) {
            if (!$hidden) {
                $labels[IdKinds::wire($ref)] = new IkLabelRecord(IdKinds::wire($ref), $name);
            }
        }
        $books = [];
        foreach ($seed['books'] as $row) {
            $id = IdKinds::wire($row['id']);
            $book = new IkBookRecord($id, $row['title']);
            if ($row['writer'] !== null) {
                $book->writer = $writers[IdKinds::wire($row['writer'])];
                $book->writer_ref = IdKinds::wire($row['writer']);
                $book->writer->books[] = $book;
            }
            if ($row['featuredBy'] !== null) {
                $writers[IdKinds::wire($row['featuredBy'])]->featured = $book;
            }
            foreach ($row['labels'] as $ref) {
                $label = $labels[IdKinds::wire($ref)] ?? null;
                if ($label !== null) {
                    $book->labels[] = $label;
                    $label->books[] = $book;
                }
            }
            if ($row['subject'] !== null) {
                [$type, $ref] = $row['subject'];
                $book->subject = $type === 'ikWriters' ? $writers[IdKinds::wire($ref)] : ($labels[IdKinds::wire($ref)] ?? null);
            }
            // The witness's targets are the visible ones, so an origin the scope hides is none.
            if ($row['origin'] !== null && ($row['origin'][0] === 'ikWriters' || isset($labels[IdKinds::wire($row['origin'][1])]))) {
                [$book->origin_type, $ref] = $row['origin'];
                $book->origin_id = IdKinds::wire($ref);
            }
            foreach ($row['pins'] as [$type, $ref]) {
                if ($type === 'ikLabels' && !isset($labels[IdKinds::wire($ref)])) {
                    continue;
                }
                $book->pins[] = new IkPinRecord($type, IdKinds::wire($ref));
            }
            $books[$id] = $book;
        }

        $resolver = static function (string $type, string $id) use (&$writers, &$books, &$labels): ?object {
            return match ($type) {
                'ikWriters' => $writers[$id] ?? null,
                'ikBooks' => $books[$id] ?? null,
                'ikLabels' => $labels[$id] ?? null,
                default => null,
            };
        };
        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $assignId = static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };

        $writerProvider = new InMemoryDataProvider('ikWriters', $writers, identify: $identify, assignId: $assignId);
        $bookProvider = new InMemoryDataProvider('ikBooks', $books, identify: $identify, assignId: $assignId);
        $labelProvider = new InMemoryDataProvider('ikLabels', $labels, identify: $identify);
        JsonApi::provider($writerProvider);
        JsonApi::provider($bookProvider);
        JsonApi::provider($labelProvider);
        JsonApi::persister(new InMemoryDataPersister('ikWriters', $writerProvider->store(), static fn(): IkWriterRecord => new IkWriterRecord(), $resolver));
        JsonApi::persister(new InMemoryDataPersister('ikBooks', $bookProvider->store(), static fn(): IkBookRecord => new IkBookRecord(), $resolver));
    }
}
