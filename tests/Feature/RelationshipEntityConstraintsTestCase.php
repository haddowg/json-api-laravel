<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A relationship write validates the changed resource against its entity-level
 * constraints before it commits. A violation is a 422 at the relationship and nothing
 * is saved. User 1 has company 1. User 2 has companies 1 and 2 and a stored name that
 * breaks the name's length constraint. The error documents match the Symfony
 * bundle's byte for byte.
 *
 * @internal
 */
#[CoversNothing]
abstract class RelationshipEntityConstraintsTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string AT_LEAST_ONE = '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user needs at least one company.","source":{"pointer":"\/data\/relationships\/companies"}}],"jsonapi":{"version":"1.1"}}';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    public function test_replacing_a_to_many_with_nothing_is_refused(): void
    {
        $response = $this->write('PATCH', '/api/integration-users/1/relationships/companies', []);

        $response->assertStatus(422);
        self::assertSame(self::AT_LEAST_ONE, $response->getContent());
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    public function test_replacing_a_to_many_with_valid_members_is_saved(): void
    {
        $response = $this->write('PATCH', '/api/integration-users/1/relationships/companies', [['type' => 'companies', 'id' => '2']]);

        $response->assertOk();
        self::assertSame(['2'], $this->companiesOf('integration-users', '1'));
    }

    public function test_adding_past_the_limit_is_refused(): void
    {
        $response = $this->write('POST', '/api/integration-users/1/relationships/companies', [['type' => 'companies', 'id' => '2'], ['type' => 'companies', 'id' => '3']]);

        $response->assertStatus(422);
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user may have at most two companies.","source":{"pointer":"\/data\/relationships\/companies"}}],"jsonapi":{"version":"1.1"}}',
            $response->getContent(),
        );
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    public function test_adding_within_the_limit_is_saved(): void
    {
        $response = $this->write('POST', '/api/integration-users/1/relationships/companies', [['type' => 'companies', 'id' => '2']]);

        $response->assertOk();
        self::assertSame(['1', '2'], $this->companiesOf('integration-users', '1'));
    }

    public function test_removing_the_last_member_is_refused(): void
    {
        $response = $this->write('DELETE', '/api/integration-users/1/relationships/companies', [['type' => 'companies', 'id' => '1']]);

        $response->assertStatus(422);
        self::assertSame(self::AT_LEAST_ONE, $response->getContent());
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    public function test_removing_a_member_does_not_recheck_the_attributes(): void
    {
        $response = $this->write('DELETE', '/api/integration-users/2/relationships/companies', [['type' => 'companies', 'id' => '2']]);

        $response->assertOk();
        self::assertSame(['1'], $this->companiesOf('integration-users', '2'));
    }

    public function test_clearing_a_to_one_is_refused(): void
    {
        $response = $this->write('PATCH', '/api/integration-users/1/relationships/primaryCompany', null);

        $response->assertStatus(422);
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user needs a primary company.","source":{"pointer":"\/data\/relationships\/primaryCompany"}}],"jsonapi":{"version":"1.1"}}',
            $response->getContent(),
        );
        self::assertSame('1', $this->primaryCompanyOf('1'));
    }

    public function test_replacing_a_to_one_is_saved(): void
    {
        $response = $this->write('PATCH', '/api/integration-users/1/relationships/primaryCompany', ['type' => 'companies', 'id' => '2']);

        $response->assertOk();
        self::assertSame('2', $this->primaryCompanyOf('1'));
    }

    public function test_a_type_without_entity_constraints_is_not_checked(): void
    {
        $response = $this->write('PATCH', '/api/teams/1/relationships/companies', []);

        $response->assertOk();
        self::assertSame([], $this->companiesOf('teams', '1'));
    }

    public function test_a_violation_in_an_atomic_batch_rolls_back_the_whole_batch(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'ref' => ['type' => 'integration-users', 'id' => '2', 'relationship' => 'companies'], 'data' => []],
            ['op' => 'update', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'primaryCompany'], 'data' => ['type' => 'companies', 'id' => '2']],
            ['op' => 'remove', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'companies'], 'data' => [['type' => 'companies', 'id' => '1']]],
        ]);

        $response->assertStatus(422);
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user needs at least one company.","source":{"pointer":"\/atomic:operations\/2\/data\/relationships\/companies"}}],"jsonapi":{"version":"1.1","ext":["https:\/\/jsonapi.org\/ext\/atomic"]}}',
            $response->getContent(),
        );
        self::assertSame('1', $this->primaryCompanyOf('1'));
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    public function test_an_atomic_batch_of_valid_relationship_writes_commits(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'companies'], 'data' => [['type' => 'companies', 'id' => '2']]],
            ['op' => 'remove', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'companies'], 'data' => [['type' => 'companies', 'id' => '1']]],
        ]);

        $response->assertOk();
        self::assertSame(['2'], $this->companiesOf('integration-users', '1'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, mixed $data): TestResponse
    {
        return $this->json($method, $uri, ['data' => $data], [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations): TestResponse
    {
        $mediaType = self::MEDIA_TYPE . ';ext="https://jsonapi.org/ext/atomic"';

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }

    /**
     * @return list<string>
     */
    private function companiesOf(string $type, string $id): array
    {
        $response = $this->get('/api/' . $type . '/' . $id . '/relationships/companies', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();
        /** @var list<array{type: string, id: string}> $data */
        $data = $response->json('data');
        $ids = \array_map(static fn(array $row): string => $row['id'], $data);
        \sort($ids);

        return $ids;
    }

    private function primaryCompanyOf(string $id): ?string
    {
        $response = $this->get('/api/integration-users/' . $id . '/relationships/primaryCompany', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();
        $companyId = $response->json('data.id');
        self::assertTrue($companyId === null || \is_string($companyId));

        return $companyId;
    }
}
