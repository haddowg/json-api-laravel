<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A reference-data country — no Eloquent model, no Resource. Its rows are sourced from
 * `symfony/intl`'s `Countries` (id = ISO 3166-1 alpha-2 code, name = the localized
 * country name) by the {@see \Workbench\App\MusicCatalog\Provider\CountryProvider} and
 * rendered by the standalone {@see \Workbench\App\MusicCatalog\Serializer\CountrySerializer}
 * — the simplest "expose arbitrary, non-database data as JSON:API" path: a static custom
 * provider + a standalone serializer, read-only (PLAN decision 3, bundle ADR 0024).
 */
final class Country
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
