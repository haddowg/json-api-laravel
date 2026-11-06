<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

/**
 * Where one OpenAPI document is served: its JSON URL, and its YAML URL or null when YAML
 * isn't served. {@see OpenApiDocumentUrls} resolves them for a request.
 */
final readonly class DocumentUrls
{
    public function __construct(
        public string $json,
        public ?string $yaml = null,
    ) {}

    /**
     * The value of Redoc's `downloadUrls` option: JSON, then YAML when it is served.
     *
     * @return list<array{title: string, url: string}>
     */
    public function downloadUrls(): array
    {
        $urls = [['title' => 'JSON', 'url' => $this->json]];
        if ($this->yaml !== null) {
            $urls[] = ['title' => 'YAML', 'url' => $this->yaml];
        }

        return $urls;
    }
}
