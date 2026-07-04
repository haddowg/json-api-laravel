<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

/**
 * An article — the plain mutable domain object seeded into the in-memory provider for
 * the validation-conformance suite. Property names are the storage **columns** the
 * {@see ArticleResource} fields resolve to, shared with the Eloquent
 * {@see \Workbench\App\Models\Article} model so one resource declaration drives the
 * always-on validator bridge on BOTH providers (blueprint §5.4).
 */
final class Article
{
    /**
     * @param array<string, mixed>|null $address
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?string $body = null,
        public ?string $category = null,
        public ?\DateTimeImmutable $published_at = null,
        public ?\DateTimeImmutable $expires_at = null,
        public ?string $coupon_code = null,
        public ?array $address = null,
        public ?string $slug = null,
    ) {}
}
