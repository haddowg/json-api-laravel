<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslator;
use haddowg\JsonApiLaravel\Validation\JsonPointerBuilder;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;
use haddowg\JsonApiLaravel\Validation\ValidationFailed;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relation shapes the conformance suite does not reach: a required `HasOne` and
 * `MorphTo`, a nullable required to-one, a required to-many that also bounds its
 * members, and a relation read-only on create.
 *
 * @internal
 */
#[CoversClass(ResourceValidator::class)]
final class RequiredRelationTest extends Orchestra
{
    #[Test]
    public function aCreateLeavingOutARequiredHasOneOrMorphToIsRefused(): void
    {
        self::assertSame([
            ['The profile field is required.', '/data/relationships/profile'],
            ['The subject field is required.', '/data/relationships/subject'],
        ], $this->violations(['team' => ['data' => null], 'members' => $this->members()]));
    }

    #[Test]
    public function aNullHasOneOrMorphToIsRefused(): void
    {
        self::assertSame([
            ['The profile field is required.', '/data/relationships/profile/data'],
            ['The subject field is required.', '/data/relationships/subject/data'],
        ], $this->violations([
            'profile' => ['data' => null],
            'subject' => ['data' => null],
            'team' => ['data' => ['type' => 'teams', 'id' => '1']],
            'members' => $this->members(),
        ]));
    }

    #[Test]
    public function aNullableRequiredToOneMustBeSentButMayBeNull(): void
    {
        self::assertSame([['The team field is required.', '/data/relationships/team']], $this->violations($this->required() + ['members' => $this->members()]));
        self::assertSame([], $this->violations($this->required() + ['team' => ['data' => null], 'members' => $this->members()]));
    }

    #[Test]
    public function aRequiredToManyBoundingItsMembersReportsAnEmptySetOnce(): void
    {
        $violations = $this->violations($this->required() + ['team' => ['data' => null], 'members' => ['data' => []]]);

        self::assertCount(1, $violations);
        self::assertSame('/data/relationships/members/data', $violations[0][1]);
    }

    #[Test]
    public function aRelationshipEndpointMayNotEmptyARequiredRelation(): void
    {
        $resource = new RequiredRelationResource();
        $profile = $resource->relationNamed('profile');
        self::assertNotNull($profile);
        $members = $resource->relationNamed('members');
        self::assertNotNull($members);

        try {
            $this->validator()->validateRelationshipLinkage($profile, new ToOneRelationship(null), request: $this->request([]));
            self::fail('Expected a ValidationFailed.');
        } catch (ValidationFailed $exception) {
            self::assertSame('/data', $exception->getErrors()[0]->source?->pointer);
        }

        // An empty set breaks the bound, which reports it on its own.
        try {
            $this->validator()->validateRelationshipLinkage($members, new ToManyRelationship([]), request: $this->request([]));
            self::fail('Expected a ValidationFailed.');
        } catch (ValidationFailed $exception) {
            self::assertCount(1, $exception->getErrors());
        }
    }

    /**
     * @return array{data: list<array{type: string, id: string}>}
     */
    private function members(): array
    {
        return ['data' => [['type' => 'members', 'id' => '1']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function required(): array
    {
        return [
            'profile' => ['data' => ['type' => 'profiles', 'id' => '1']],
            'subject' => ['data' => ['type' => 'notes', 'id' => '1']],
        ];
    }

    /**
     * @param array<string, mixed> $relationships
     */
    private function request(array $relationships): JsonApiRequest
    {
        $psr = new Psr17Factory();

        return new JsonApiRequest(
            $psr->createServerRequest('POST', 'http://localhost/api/players')
                ->withHeader('Content-Type', 'application/vnd.api+json')
                ->withBody($psr->createStream(\json_encode(
                    ['data' => ['type' => 'players', 'relationships' => $relationships]],
                    \JSON_THROW_ON_ERROR,
                ))),
        );
    }

    /**
     * @param array<string, mixed> $relationships
     *
     * @return list<array{0: string|null, 1: string|null}>
     */
    private function violations(array $relationships): array
    {
        try {
            $this->validator()->validate(new RequiredRelationResource(), $this->request($relationships), true);
        } catch (ValidationFailed $failed) {
            return \array_map(static fn($error): array => [$error->detail, $error->source?->pointer], $failed->getErrors());
        }

        return [];
    }

    private function validator(): ResourceValidator
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var ValidationFactory $factory */
        $factory = $app->make(ValidationFactory::class);

        return new ResourceValidator($factory, new ConstraintTranslator(), new JsonPointerBuilder());
    }
}

final class RequiredRelationResource extends AbstractResource
{
    public static string $type = 'players';

    public function fields(): array
    {
        return [
            Id::make(),
            HasOne::make('profile', 'profiles')->required(),
            MorphTo::make('subject', ['notes', 'images'])->requiredOnCreate(),
            BelongsTo::make('team', 'teams')->required()->nullable(),
            HasMany::make('members', 'members')->required()->minItems(1),
            BelongsTo::make('coach', 'coaches')->readOnlyOnCreate()->required(),
        ];
    }
}
