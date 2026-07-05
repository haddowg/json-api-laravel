<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A user — a plain mutable domain object seeded into the in-memory provider. The backing
 * object for BOTH the admin-only `users` type and the curated `public-profiles` view.
 */
final class User
{
    /**
     * @param array<string, mixed>|null $preferences the dynamic-key ArrayHash attribute
     * @param list<Playlist>            $playlists   the user's playlists
     * @param ?Library                  $library     the user's library
     */
    public function __construct(
        public string $id = '',
        public string $email = '',
        public string $display_name = '',
        public ?\DateTimeImmutable $birth_date = null,
        public ?array $preferences = null,
        public ?string $last_seen_ip = null,
        public ?string $password = null,
        public bool $is_admin = false,
        public array $playlists = [],
        public ?Library $library = null,
    ) {}
}
