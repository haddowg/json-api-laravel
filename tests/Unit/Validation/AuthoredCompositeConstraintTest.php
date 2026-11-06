<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
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
 * A hand-written composite constraint, one whose `contribute()` adds a
 * `oneOf`/`anyOf`/`allOf`, documents its shape without a translator registered,
 * while a scalar constraint the bridge can't translate still fails loud.
 *
 * @internal
 */
#[CoversClass(ConstraintTranslator::class)]
final class AuthoredCompositeConstraintTest extends Orchestra
{
    #[Test]
    public function aWriteToAFieldCarryingAnAuthoredCompositeNeedsNoTranslator(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(
            $this->resource(new AuthoredGeometry()),
            $this->request(['name' => 'Home', 'location' => ['type' => 'Point', 'coordinates' => [0, 0]]]),
            true,
        );
    }

    #[Test]
    public function theFieldsOtherConstraintsStillRun(): void
    {
        try {
            $this->validator()->validate(
                $this->resource(new AuthoredGeometry()),
                $this->request(['location' => ['type' => 'Point']]),
                true,
            );
            self::fail('Expected a ValidationFailed for the missing required name.');
        } catch (ValidationFailed $failed) {
            self::assertSame('/data/attributes/name', $failed->getErrors()[0]->source?->pointer);
        }
    }

    #[Test]
    public function anUntranslatableScalarConstraintStillFailsLoud(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(AuthoredHexFormat::class);

        $this->validator()->validate(
            $this->resource(new AuthoredHexFormat()),
            $this->request(['name' => 'Home', 'location' => ['a' => 1]]),
            true,
        );
    }

    private function resource(ProvidesJsonSchema $constraint): AbstractResource
    {
        return new class ($constraint) extends AbstractResource {
            public static string $type = 'places';

            public function __construct(private readonly ProvidesJsonSchema $constraint) {}

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('name')->required(),
                    ArrayHash::make('location')->nullable()->constrain($this->constraint),
                ];
            }
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes): JsonApiRequest
    {
        $psr = new Psr17Factory();

        return new JsonApiRequest(
            $psr->createServerRequest('POST', 'http://localhost/api/places')
                ->withHeader('Content-Type', 'application/vnd.api+json')
                ->withBody($psr->createStream(\json_encode(
                    ['data' => ['type' => 'places', 'attributes' => $attributes]],
                    \JSON_THROW_ON_ERROR,
                ))),
        );
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

/**
 * @internal
 */
final readonly class AuthoredGeometry implements ProvidesJsonSchema
{
    public function __construct(public Context $context = new Context()) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withOneOf([
            Schema::ofType('object')
                ->withProperties(['type' => Schema::ofType('string')->withConst('Point')])
                ->withRequired(['type']),
            Schema::ofType('object')
                ->withProperties(['type' => Schema::ofType('string')->withConst('LineString')])
                ->withRequired(['type']),
        ])->withDiscriminator('type');
    }
}

/**
 * @internal
 */
final readonly class AuthoredHexFormat implements ProvidesJsonSchema
{
    public function __construct(public Context $context = new Context()) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withPattern('^[0-9a-f]+$');
    }
}
