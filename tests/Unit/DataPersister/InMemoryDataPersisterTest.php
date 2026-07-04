<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataPersister;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use haddowg\JsonApiLaravel\Tests\Fixtures\Widget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
