<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Authorization;

use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Authorization\ResourceAuthorization;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\AdminDocumentPolicy;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\AdminDocumentResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\DocumentRecord;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\DocumentResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\PublicDocumentPolicy;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Each check reads the declarations of the server it names, and a missing name is the
 * `default` server (ADR 0052).
 *
 * @internal
 */
#[CoversClass(Authorizer::class)]
final class ServerScopedAuthorizerTest extends Orchestra
{
    #[Test]
    public function eachServerReadsItsOwnEntry(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Member', 'is_admin' => false]));
        $authorizer = $this->authorizer();
        $document = new DocumentRecord('1');

        self::assertFalse($authorizer->allows('documents', Operation::Delete, $document));
        self::assertFalse($authorizer->allows('documents', Operation::Delete, $document, 'default'));
        self::assertTrue($authorizer->allows('documents', Operation::Delete, $document, 'admin'));
        self::assertFalse($authorizer->allows('documents', Operation::Update, $document, 'admin'));

        // The action ability resolves through the server's own policy.
        self::assertFalse($authorizer->allowsAction('documents', 'viewRevisions', $document, 'default'));
        self::assertTrue($authorizer->allowsAction('documents', 'viewRevisions', $document, 'admin'));
    }

    #[Test]
    public function aServerThatDeclaresNothingFallsThroughToTheGate(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Member', 'is_admin' => false]));

        // No entry, no Gate policy and no defined ability: inert.
        self::assertTrue($this->authorizer()->allows('documents', Operation::Delete, new DocumentRecord('1'), 'other'));
    }

    #[Test]
    public function theRelationConventionReadsTheServersPolicy(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Member', 'is_admin' => false]));
        $authorizer = $this->authorizer();
        $relation = HasMany::make('revisions', 'notes')->build();
        $document = new DocumentRecord('1');

        self::assertFalse($authorizer->relationReadPermission('documents', $relation, $document, 'default'));
        self::assertTrue($authorizer->relationReadPermission('documents', $relation, $document, 'admin'));
        self::assertNull($authorizer->relationReadPermission('documents', $relation, $document, 'other'));
    }

    private function authorizer(): Authorizer
    {
        /** @var GateContract $gate */
        $gate = app(GateContract::class);

        return new Authorizer($gate, [], [
            'default' => ['documents' => new ResourceAuthorization(PublicDocumentPolicy::class, [], DocumentResource::class)],
            'admin' => ['documents' => new ResourceAuthorization(
                AdminDocumentPolicy::class,
                [Operation::Update->value => 'edit', Operation::Delete->value => false],
                AdminDocumentResource::class,
            )],
        ]);
    }
}
