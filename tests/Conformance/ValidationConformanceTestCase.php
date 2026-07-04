<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The always-on validation bridge (PLAN decision 6) end to end: a resource's declared
 * constraints (see {@see \Workbench\App\Validation\ArticleResource}) are translated to
 * `illuminate/validation` rules, run against the create/update document BEFORE
 * hydration, and rendered as `422`s with `source.pointer`s. The identical assertions run
 * against the in-memory witness ({@see InMemoryValidationConformanceTest}) and the
 * reference Eloquent provider ({@see EloquentValidationConformanceTest}), so the bridge's
 * behaviour is provider-agnostic — the Laravel port of the Symfony bundle's
 * `ValidationConformanceTestCase`.
 */
abstract class ValidationConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithoutARequiredAttributeReturns422WithAPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => ['body' => 'No title.', 'category' => 'news']],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithATooShortValueReturns422AtThatPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'ab', 'category' => 'news']],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAValueOutsideTheEnumReturns422AtThatPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'A fine title', 'category' => 'sports']],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/category'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function multipleViolationsAllRenderUnderASingle422(): void
    {
        // Missing required title AND an out-of-enum category: a uniform bag of two 422s
        // must render as 422 (the core status-fidelity fix), not a rounded 400.
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => ['category' => 'sports']],
        ]);

        $response->assertStatus(422);
        $pointers = $this->pointers($response);
        self::assertContains('/data/attributes/title', $pointers);
        self::assertContains('/data/attributes/category', $pointers);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingWithAnInvalidValueReturns422(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'ab']],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingMayOmitARequiredAttribute(): void
    {
        // On update a required attribute may be absent (a partial update); only an
        // explicitly invalid supplied value fails.
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['category' => 'opinion']],
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValidDocumentPassesValidation(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'Perfectly valid', 'category' => 'guide']],
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithADateThatViolatesAClosureBoundReturns422AtThatPointer(): void
    {
        // The clock is frozen so the resource's before(now) bound is deterministic: a
        // publish date a day in the future must fail "not in the future".
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00+00:00'));

        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title',
                'category' => 'news',
                'publishedAt' => '2026-06-09T12:00:00+00:00',
            ]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/publishedAt'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAnUnparseableDateReturns422AtThatPointer(): void
    {
        // A DateTime attribute carries no implicit format rule, so a calendar-garbage value
        // ("banana") would otherwise pass document validation and 500 in core's hydration
        // (`new \DateTimeImmutable('banana')` throws). The bridge's ParsableDate guard makes
        // it a clean 422 at the field pointer — the write-body twin of the filter validator's
        // date-range check, identical on both providers.
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news', 'publishedAt' => 'banana',
            ]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/publishedAt'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingWithAnUnparseableDateReturns422AtThatPointer(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['publishedAt' => 'not-a-date']],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/publishedAt'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithADateWithinAClosureBoundPasses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00+00:00'));

        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title',
                'category' => 'guide',
                'publishedAt' => '2026-06-01T12:00:00+00:00',
            ]],
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCrossFieldRuleComparesAgainstTheSiblingValue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00+00:00'));

        // expiresAt must be after publishedAt: an earlier expiry fails at its pointer...
        $expiresBeforePublished = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news',
                'publishedAt' => '2026-06-06T12:00:00+00:00',
                'expiresAt' => '2026-06-05T12:00:00+00:00',
            ]],
        ]);

        $expiresBeforePublished->assertStatus(422);
        self::assertSame(['/data/attributes/expiresAt'], $this->pointers($expiresBeforePublished));

        // ...while a later expiry is accepted.
        $expiresAfterPublished = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide',
                'publishedAt' => '2026-06-06T12:00:00+00:00',
                'expiresAt' => '2026-06-07T12:00:00+00:00',
            ]],
        ]);

        $expiresAfterPublished->assertStatus(201);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aConditionalConstraintIsEnforcedOnlyWhenItsConditionHolds(): void
    {
        // couponCode is length-checked only when it looks like a promo code, so a short
        // "PROMO-X" fails the when()-declared rule at its pointer...
        $promoTooShort = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news', 'couponCode' => 'PROMO-X',
            ]],
        ]);

        $promoTooShort->assertStatus(422);
        self::assertSame(['/data/attributes/couponCode'], $this->pointers($promoTooShort));

        // ...while an equally short non-promo code skips the rule and is accepted.
        $nonPromoShort = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide', 'couponCode' => 'FREE',
            ]],
        ]);

        $nonPromoShort->assertStatus(201);
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithANestedChildViolatingItsPatternReturns422AtTheNestedPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news',
                'address' => ['street' => '1 High Street', 'postcode' => 'ABCDE'],
            ]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/address/postcode'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithATooShortNestedChildReturns422AtTheNestedPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news',
                'address' => ['street' => 'ab', 'postcode' => '12345'],
            ]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/address/street'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAMissingRequiredNestedChildReturns422AtTheNestedPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news',
                'address' => ['postcode' => '12345'],
            ]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/address/street'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAValidNestedObjectPasses(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide',
                'address' => ['street' => '1 High Street', 'postcode' => '12345'],
            ]],
        ]);

        $response->assertStatus(201);
        // The nested object round-trips through its single storage member.
        self::assertSame(['street' => '1 High Street', 'postcode' => '12345'], $response->json('data.attributes.address'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingMayOmitTheNestedObjectEntirely(): void
    {
        // The address Map is optional on update, so omitting it does not fire its
        // required children.
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['category' => 'opinion']],
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingWithAnInvalidNestedChildReturns422AtTheNestedPointer(): void
    {
        // A supplied nested object IS validated on update: a pattern-violating postcode
        // fails at its nested pointer even on PATCH.
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => [
                'address' => ['street' => '1 High Street', 'postcode' => 'nope'],
            ]],
        ]);

        $response->assertStatus(422);
        self::assertSame(['/data/attributes/address/postcode'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingValidatesACrossFieldRuleAgainstAStoredSiblingNotInTheBody(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08T12:00:00+00:00'));

        // Seed a stored publishedAt the partial PATCH below will NOT re-send.
        $seed = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => [
                'publishedAt' => '2026-06-06T12:00:00+00:00',
            ]],
        ]);
        $seed->assertStatus(200);

        // An expiry AFTER the stored publishedAt passes — proving the merge folds the
        // stored sibling in (the body alone carries no publishedAt to compare).
        $valid = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => [
                'expiresAt' => '2026-06-07T12:00:00+00:00',
            ]],
        ]);
        $valid->assertStatus(200);

        // An expiry BEFORE the stored publishedAt violates the merged result — a 422 at
        // the owner pointer, even though publishedAt is absent from the body.
        $invalid = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => [
                'expiresAt' => '2026-06-05T12:00:00+00:00',
            ]],
        ]);
        $invalid->assertStatus(422);
        self::assertSame(['/data/attributes/expiresAt'], $this->pointers($invalid));
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingLeavesAnUntouchedStoredConditionalFieldBenign(): void
    {
        // Seed a stored (valid) promo code; a later PATCH that does not re-send it must
        // not spuriously fail the field the client did not touch.
        $seed = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => [
                'couponCode' => 'PROMO-LONG-ENOUGH',
            ]],
        ]);
        $seed->assertStatus(200);

        $later = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['category' => 'opinion']],
        ]);
        $later->assertStatus(200);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingDoesNotResendARequiredAttributePresentInStoredStateAndPasses(): void
    {
        // title is required and present (valid) in stored state. A partial PATCH that does
        // NOT re-send it must stay a 200: the merge folds the stored title into the
        // validated map.
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['body' => 'Edited body only.']],
        ]);

        $response->assertStatus(200);
        // The untouched required title is preserved (the seeded value).
        self::assertSame('JSON:API in PHP', $response->json('data.attributes.title'));
    }

    /**
     * The `source.pointer` of every error in the response document, asserting each is a
     * `422`.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function pointers(TestResponse $response): array
    {
        /** @var list<array<string, mixed>> $errors */
        $errors = $response->json('errors');
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertSame('422', $error['status'] ?? null);
            /** @var array<string, mixed> $source */
            $source = $error['source'] ?? [];
            $pointer = $source['pointer'] ?? null;
            self::assertIsString($pointer);
            $detail = $error['detail'] ?? null;
            self::assertIsString($detail);
            self::assertNotSame('', $detail, 'a validation error carries a non-empty detail');
            $pointers[] = $pointer;
        }

        return $pointers;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }
}
