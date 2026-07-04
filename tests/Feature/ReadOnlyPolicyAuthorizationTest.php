<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ReadOnly\ReadOnlyAuthorizationServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Pins the persister-less authorization path (PLAN decision 7): a read-only type has no
 * persister to mint a list/read subject, but a DECLARED `policy:` must STILL be enforced
 * on `viewAny`/`view` (never fail-open), while a type with no policy stays inert.
 *
 *  - `charts` declares a deny-all `DenyReadPolicy`: the collection AND the single-resource
 *    reads are `403`, proving the declared policy is enforced against the resource-class
 *    token even though no list instance exists.
 *  - `labels` declares no policy and no Gate policy is registered: its collection is served
 *    ungated, pinning the documented no-policy inertness.
 *
 * @internal
 */
final class ReadOnlyPolicyAuthorizationTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            ReadOnlyAuthorizationServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aDeclaredPolicyDeniesTheListOfAReadOnlyPersisterlessType(): void
    {
        // The read-only `charts` type has no persister, so the collection mints no list
        // instance — yet the declared deny-all policy's `viewAny` still runs (against the
        // resource-class token) and denies. This is the fail-open bug the fix closes: a
        // declared policy is enforced even without a subject instance.
        $this->actingAs($this->user());

        $this->getJsonApi('/api/charts')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aDeclaredPolicyDeniesTheReadOfAReadOnlyType(): void
    {
        // The single-resource read carries the loaded instance, so `view` runs as usual —
        // the symmetric denial that proves the read gate is not asymmetric with the list.
        $this->actingAs($this->user());

        $this->getJsonApi('/api/charts/1')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.status', '403');
    }

    #[Test]
    #[Group('spec:authorization')]
    public function aPolicyLessReadOnlyTypeIsInertAndServesTheList(): void
    {
        // The `labels` type declares no policy and no Gate policy is registered, so its
        // persister-less null list subject leaves the authorizer inert — the collection is
        // served ungated. This pins the documented no-policy inertness (the package adds no
        // authorization an application did not ask for).
        $this->actingAs($this->user());

        $this->getJsonApi('/api/labels')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    private function user(): User
    {
        return new User(['id' => 1, 'name' => 'Reader', 'can_write' => false, 'can_read' => true, 'is_admin' => false]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function getJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
