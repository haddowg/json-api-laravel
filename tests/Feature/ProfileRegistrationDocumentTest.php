<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The OpenAPI projection is registration-aware (Laravel ADR 0025, core ADR 0131): the
 * `jsonapi.profile` enum, the Countable `?withCount` parameter, and the Relationship
 * Queries `relatedQuery` parameter are advertised only for a profile the server
 * registered. The default `jsonapi.profiles` is the three built-ins in canonical order;
 * trimming the config drops a profile's registration and its advertisement. The
 * cursor page marker's default advertisement is witnessed end-to-end by
 * {@see CursorConformanceTestCase}; this suite pins the document-shape effects.
 *
 * @internal
 */
#[Group('openapi')]
final class ProfileRegistrationDocumentTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    public static function onlyCountableProfile(mixed $app): void
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);
        // A consumer trimming the set to only the Countable profile.
        $config->set('jsonapi.profiles', [CountableProfile::class]);
    }

    #[Test]
    public function it_advertises_the_registered_profiles_in_canonical_order(): void
    {
        // The default registration pins the jsonapi.profile enum to the three built-in
        // URIs, in the canonical order that must match the Symfony bundle for byte-parity.
        $this->assertSame(
            [CursorPaginationProfile::URI, CountableProfile::URI, RelationshipQueriesProfile::URI],
            $this->arrayAt($this->document(), 'components', 'schemas', 'JsonApi', 'properties', 'profile', 'items', 'enum'),
        );
    }

    #[Test]
    public function it_documents_the_relatedquery_parameter_reference_and_shared_component(): void
    {
        $doc = $this->document();

        // A relation-bearing type's read endpoints $ref the single shared component.
        $this->assertContains('#/components/parameters/relatedQuery', $this->parameterRefs($doc, '/albums', 'get'));
        $this->assertContains('#/components/parameters/relatedQuery', $this->parameterRefs($doc, '/albums/{id}', 'get'));
        $this->assertArrayHasKey('relatedQuery', $this->arrayAt($doc, 'components', 'parameters'));
    }

    #[Test]
    #[DefineEnvironment('onlyCountableProfile')]
    public function trimming_the_profiles_config_drops_that_profiles_registration_and_advertisement(): void
    {
        $doc = $this->document();

        // Only Countable is registered, so the enum shrinks to it, the relatedQuery
        // parameter/component disappear (Relationship Queries not registered), and
        // `?withCount` survives (Countable still registered).
        $this->assertSame(
            [CountableProfile::URI],
            $this->arrayAt($doc, 'components', 'schemas', 'JsonApi', 'properties', 'profile', 'items', 'enum'),
        );

        $this->assertNotContains('#/components/parameters/relatedQuery', $this->parameterRefs($doc, '/albums', 'get'));
        $this->assertArrayNotHasKey('parameters', $this->arrayAt($doc, 'components'));
        $this->assertContains('withCount', $this->parameterNames($doc, '/artists/{id}/albums', 'get'));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }
}
