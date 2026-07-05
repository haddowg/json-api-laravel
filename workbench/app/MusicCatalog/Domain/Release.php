<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A physical or digital release of an {@see Album} — the composite-attribute showcase's
 * plain domain twin. Property names are the storage columns the
 * {@see \Workbench\App\MusicCatalog\JsonApi\ReleaseResource} fields resolve to; each
 * composite attribute (`format` = OneOf, `packaging` = Obj, `availability`/`dimensions`
 * = ArrayHash+Shape) is a single array value.
 */
final class Release
{
    /**
     * @param array<string, mixed>|null $format
     * @param array<string, mixed>|null $packaging
     * @param array<string, mixed>|null $availability
     * @param array<string, mixed>|null $dimensions
     * @param ?Album                    $album the released album (null → `data: null` linkage)
     */
    public function __construct(
        public string $id = '',
        public string $catalog_number = '',
        public ?array $format = null,
        public ?array $packaging = null,
        public ?array $availability = null,
        public ?array $dimensions = null,
        public ?Album $album = null,
    ) {}
}
