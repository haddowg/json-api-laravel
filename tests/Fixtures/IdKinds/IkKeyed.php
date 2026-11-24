<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

/**
 * Keys a model, and casts its key-holding columns, as the current {@see IdKinds} kind says.
 */
trait IkKeyed
{
    public function initializeIkKeyed(): void
    {
        $this->incrementing = IdKinds::integerKeys();
        $this->keyType = IdKinds::integerKeys() ? 'int' : 'string';

        if (IdKinds::kind() === 'object') {
            $this->mergeCasts(\array_fill_keys([$this->getKeyName(), ...$this->keyColumns()], IkKeyCast::class));
        }
        if (IdKinds::kind() === 'binary' && \in_array('writer_ref', $this->keyColumns(), true)) {
            $this->mergeCasts(['writer_ref' => IkUuidCast::class]);
        }
    }

    /**
     * The columns besides the key that hold a key.
     *
     * @return list<string>
     */
    protected function keyColumns(): array
    {
        return [];
    }
}
