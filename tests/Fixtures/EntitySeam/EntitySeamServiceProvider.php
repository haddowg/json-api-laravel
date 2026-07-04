<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the entity-seam fixture: the {@see NoteResource} over a writable, seeded in-memory
 * provider/persister pair sharing one store, plus the {@see UniqueNoteTitleTranslator}
 * (constructed with that same store) registered as an extension translator. So a create /
 * update whose hydrated note collides on title is a post-hydration `422` — proving both the
 * {@see \haddowg\JsonApiLaravel\Validation\EntityConstraintInterface} seam and the
 * class-keyed extension-translator path.
 */
final class EntitySeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([NoteResource::class]);

        $notes = new InMemoryDataProvider('notes', $this->notes(), identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($notes);
        JsonApi::persister(new InMemoryDataPersister('notes', $notes->store(), static fn(): Note => new Note()));

        // The extension translator holds the SAME store the provider/persister share, so its
        // store-scanning rule sees created/updated notes as the read side would.
        JsonApi::constraintTranslator(new UniqueNoteTitleTranslator($notes->store()));
    }

    /**
     * @return array<int|string, Note>
     */
    private function notes(): array
    {
        return [
            '1' => new Note(id: '1', title: 'First Note'),
            '2' => new Note(id: '2', title: 'Second Note'),
        ];
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
