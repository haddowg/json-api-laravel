<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Authorization;

use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Authorization\ResourceAuthorization;
use haddowg\JsonApiLaravel\Operation\Operation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Genre;
use Workbench\App\Models\User;
use Workbench\App\Security\Policies\AlbumApiPolicy;

/**
 * The {@see Authorizer} resolution branches (PLAN decision 7) exercised against a real
 * Gate in isolation from the HTTP pipeline: inertness, the `false` disable, the dedicated
 * `policy:` class (with its `before()` bypass), and a renamed ability resolved through
 * `Gate::define()`. The end-to-end lifecycle wiring is covered by the security
 * conformance + gate-policy feature suites.
 *
 * @internal
 */
#[CoversClass(Authorizer::class)]
final class AuthorizerTest extends Orchestra
{
    #[Test]
    public function it_is_inert_for_a_type_with_no_policy_and_no_defined_ability(): void
    {
        $this->actingAs($this->reader());

        // No config for `genres`, no Gate policy, no defined ability → no check at all.
        $this->authorizer([])->authorize('genres', Operation::Create, new Genre());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_false_ability_disables_the_check_even_when_the_policy_would_deny(): void
    {
        $this->actingAs($this->reader());

        // AlbumApiPolicy::update denies a non-write user — but the `false` override skips
        // the check entirely before the policy is ever consulted.
        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [Operation::Update->value => false]),
        ])->authorize('albums', Operation::Update, new Album());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_dedicated_policy_denies_a_non_write_user_through_the_renamed_ability(): void
    {
        $this->actingAs($this->reader());

        $this->expectException(AuthorizationException::class);

        // create is renamed to `publish`; AlbumApiPolicy::publish denies a non-write user.
        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [Operation::Create->value => 'publish']),
        ])->authorize('albums', Operation::Create, new Album());
    }

    #[Test]
    public function the_dedicated_policy_allows_a_write_user_through_the_renamed_ability(): void
    {
        $this->actingAs($this->writer());

        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [Operation::Create->value => 'publish']),
        ])->authorize('albums', Operation::Create, new Album());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_dedicated_policy_before_hook_bypasses_a_denial_for_an_admin(): void
    {
        // The admin is not write-capable (publish would deny) but before() returns true.
        $this->actingAs($this->admin());

        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [Operation::Create->value => 'publish']),
        ])->authorize('albums', Operation::Create, new Album());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_dedicated_policy_leaves_the_application_gate_mapping_untouched(): void
    {
        $this->actingAs($this->writer());

        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [Operation::Create->value => 'publish']),
        ])->authorize('albums', Operation::Create, new Album());

        // The dedicated policy is mapped only on a throwaway (cloned) Gate, so the
        // application's own Gate never learns of the Album → AlbumApiPolicy binding
        // (PLAN decision 7: "leaving the app's Gate::policy() mapping untouched").
        self::assertNull(app(GateContract::class)->getPolicyFor(new Album()));
    }

    #[Test]
    public function a_declared_policy_is_enforced_on_a_null_class_level_subject(): void
    {
        // A read-only type mints no list instance, so the list subject is null — but a
        // DECLARED policy must still gate viewAny (against the resource-class token), never
        // fail-open. The deny-all read user is refused.
        $this->actingAs(new User(['id' => 2, 'name' => 'NoRead', 'can_write' => false, 'can_read' => false, 'is_admin' => false]));

        $this->expectException(AuthorizationException::class);

        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [], Album::class),
        ])->authorize('albums', Operation::FetchCollection, null);
    }

    #[Test]
    public function a_declared_policy_allows_a_class_level_subject_when_null_via_the_token(): void
    {
        // The allow side of the null-subject class-level path: a read-capable user passes
        // viewAny even though no list instance exists.
        $this->actingAs(new User(['id' => 1, 'name' => 'Reader', 'can_write' => false, 'can_read' => true, 'is_admin' => false]));

        $this->authorizer([
            'albums' => new ResourceAuthorization(AlbumApiPolicy::class, [], Album::class),
        ])->authorize('albums', Operation::FetchCollection, null);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_null_subject_with_no_policy_and_no_defined_ability_stays_inert(): void
    {
        // The documented no-policy inertness on a persister-less list subject: with no
        // declared policy and no defined ability, no check runs (the Gate path cannot
        // resolve a model policy without an instance).
        $this->actingAs($this->reader());

        $this->authorizer([
            'labels' => new ResourceAuthorization(null, [], Album::class),
        ])->authorize('labels', Operation::FetchCollection, null);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_renamed_ability_resolves_through_gate_define(): void
    {
        Gate::define('browse', static fn(User $user): bool => $user->can_write);

        $authorizer = $this->authorizer([
            'albums' => new ResourceAuthorization(null, [Operation::FetchCollection->value => 'browse']),
        ]);

        $this->actingAs($this->writer());
        $authorizer->authorize('albums', Operation::FetchCollection, new Album());
        $this->addToAssertionCount(1);

        $this->actingAs($this->reader());
        $this->expectException(AuthorizationException::class);
        $authorizer->authorize('albums', Operation::FetchCollection, new Album());
    }

    /**
     * @param array<string, ResourceAuthorization> $config
     */
    private function authorizer(array $config): Authorizer
    {
        return new Authorizer(app(GateContract::class), $config);
    }

    private function writer(): User
    {
        return new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'is_admin' => false]);
    }

    private function reader(): User
    {
        return new User(['id' => 2, 'name' => 'Reader', 'can_write' => false, 'is_admin' => false]);
    }

    private function admin(): User
    {
        return new User(['id' => 3, 'name' => 'Admin', 'can_write' => false, 'is_admin' => true]);
    }
}
