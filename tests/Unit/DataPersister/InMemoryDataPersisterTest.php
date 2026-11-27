<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use haddowg\JsonApiLaravel\Tests\Fixtures\Widget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The in-memory write witness executed against a shared {@see InMemoryStore}: create /
 * update / delete commit straight into the store a read provider would read from, and the
 * segregated transactional capability (snapshot on begin, discard on commit, restore on
 * rollback) is the in-memory analogue of the Eloquent persister's connection transaction —
 * the two share the dual-provider conformance contract, so their transactional semantics
 * must match. A `rollback()` undoes even an in-place update (the store deep-clones its
 * snapshot), which is what keeps the atomic-batch rollback honest.
 *
 * @internal
 */
#[CoversClass(InMemoryDataPersister::class)]
final class InMemoryDataPersisterTest extends TestCase
{
    #[Test]
    public function createSavesTheEntityIntoTheSharedStore(): void
    {
        $store = $this->store();
        $persister = $this->persister($store);

        $persister->create('widgets', new Widget(2, 'Two'));

        self::assertInstanceOf(Widget::class, $store->find('2'));
    }

    #[Test]
    public function updateCommitsTheMutatedEntity(): void
    {
        $store = $this->store(['1' => new Widget(1, 'One')]);
        $persister = $this->persister($store);

        $widget = $store->find('1');
        \assert($widget instanceof Widget);
        $widget->name = 'Renamed';
        $persister->update('widgets', $widget);

        $stored = $store->find('1');
        \assert($stored instanceof Widget);
        self::assertSame('Renamed', $stored->name);
    }

    #[Test]
    public function deleteRemovesTheEntity(): void
    {
        $store = $this->store(['1' => new Widget(1, 'One')]);
        $persister = $this->persister($store);

        $widget = $store->find('1');
        \assert($widget instanceof Widget);
        $persister->delete('widgets', $widget);

        self::assertNull($store->find('1'));
    }

    #[Test]
    public function rollbackDiscardsACreateMadeSinceBegin(): void
    {
        $store = $this->store();
        $persister = $this->persister($store);

        $persister->beginTransaction();
        $persister->create('widgets', new Widget(2, 'Two'));
        self::assertInstanceOf(Widget::class, $store->find('2'));

        $persister->rollback();

        self::assertNull($store->find('2'));
    }

    #[Test]
    public function anOuterRollbackUndoesAWriteItsInnerTransactionCommitted(): void
    {
        $store = $this->store(['1' => new Widget(1, 'One')]);
        $persister = $this->persister($store);

        // An atomic batch opens the outer transaction, and each write in it an inner one.
        $persister->beginTransaction();
        $persister->beginTransaction();
        $widget = $store->find('1');
        \assert($widget instanceof Widget);
        $widget->name = 'Changed';
        $persister->update('widgets', $widget);
        $persister->commit();

        // A later operation fails, so the batch rolls back past the committed inner write.
        $persister->rollback();

        $restored = $store->find('1');
        \assert($restored instanceof Widget);
        self::assertSame('One', $restored->name);
    }

    #[Test]
    public function rollbackUndoesAnInPlaceUpdate(): void
    {
        $store = $this->store(['1' => new Widget(1, 'One')]);
        $persister = $this->persister($store);

        $persister->beginTransaction();
        $widget = $store->find('1');
        \assert($widget instanceof Widget);
        $widget->name = 'Changed';
        $persister->update('widgets', $widget);

        $persister->rollback();

        $restored = $store->find('1');
        \assert($restored instanceof Widget);
        self::assertSame('One', $restored->name);
    }

    #[Test]
    public function commitKeepsTheWritesMadeSinceBegin(): void
    {
        $store = $this->store();
        $persister = $this->persister($store);

        $persister->beginTransaction();
        $persister->create('widgets', new Widget(2, 'Two'));
        $persister->commit();

        self::assertInstanceOf(Widget::class, $store->find('2'));
    }

    #[Test]
    public function instantiateBuildsAFreshInstanceFromTheFactory(): void
    {
        $persister = $this->persister($this->store());

        self::assertInstanceOf(Widget::class, $persister->instantiate('widgets'));
    }

    #[Test]
    public function aMemberWhoseIdIsAnObjectIsRemovedAndAddedOnceByItsWireId(): void
    {
        $owner = new \stdClass();
        $owner->id = Uuid::fromString('0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a61');
        $first = new \stdClass();
        $first->id = Uuid::fromString('0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a62');
        $second = new \stdClass();
        $second->id = Uuid::fromString('0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a63');
        $owner->peers = [$first, $second];
        $byWireId = ['own-' . $first->id->toString() => $first, 'own-' . $second->id->toString() => $second];

        $persister = new InMemoryDataPersister(
            'owners',
            $this->store(),
            static fn(): \stdClass => new \stdClass(),
            static fn(string $type, string $id): ?object => $byWireId[$id] ?? null,
        );
        $peers = BelongsToMany::make('peers', 'owners')->build();
        $linkage = static fn(\stdClass $member): ToManyRelationship => new ToManyRelationship([new ResourceIdentifier('owners', 'own-' . $member->id->toString())]);

        $persister->mutateRelationship('owners', $owner, $peers, $linkage($first), Mode::Add, flush: false);
        self::assertSame([$first, $second], $owner->peers);

        $persister->mutateRelationship('owners', $owner, $peers, $linkage($first), Mode::Remove, flush: false);
        self::assertSame([$second], $owner->peers);
    }

    /**
     * @param array<int|string, Widget> $items
     */
    private function store(array $items = []): InMemoryStore
    {
        return new InMemoryStore(
            $items,
            identify: static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            },
        );
    }

    private function persister(InMemoryStore $store): InMemoryDataPersister
    {
        return new InMemoryDataPersister('widgets', $store, static fn(): Widget => new Widget(0, ''));
    }
}
