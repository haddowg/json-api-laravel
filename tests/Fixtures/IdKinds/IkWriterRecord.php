<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

/**
 * The in-memory twin of {@see IkWriter}.
 */
final class IkWriterRecord
{
    /**
     * @param list<IkBookRecord>   $books
     * @param list<IkWriterRecord> $peers
     * @param list<IkWriterRecord> $followers
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $books = [],
        public ?IkBookRecord $featured = null,
        public array $peers = [],
        public array $followers = [],
    ) {}
}
