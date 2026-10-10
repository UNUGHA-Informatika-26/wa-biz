<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_client_routes_require_authentication(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
        $this->postJson('/api/clients', $this->clientPayload())->assertUnauthorized();
    }

    public function test_list_and_individual_access_are_tenant_scoped(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $userA = $this->makeUser($organizationA, 'owner');
        $userB = $this->makeUser($organizationB, 'owner');
        $clientA = $this->makeClient($organizationA, 'Client A');
        $clientB = $this->makeClient($organizationB, 'Client B');

        $this->getWithToken($this->tokenFor($userA), '/api/clients?organization_id='.$organizationB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $clientA->id)
            ->assertJsonMissing(['name' => 'Client B']);

        $this->getWithToken($this->tokenFor($userB))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $clientB->id);

        $this->getWithToken($this->tokenFor($userA), '/api/clients/'.$clientB->id)->assertNotFound();
        $this->putWithToken($this->tokenFor($userA), '/api/clients/'.$clientB->id, [
            'name' => 'Should not change',
        ])->assertNotFound();
        $this->deleteWithToken($this->tokenFor($userA), '/api/clients/'.$clientB->id)->assertNotFound();

        $this->assertSame('Client B', $clientB->fresh()->name);
    }

    public function test_create_uses_authenticated_organization_and_ignores_requested_organization(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $userA = $this->makeUser($organizationA, 'staff');

        $response = $this->postWithToken(
            $this->tokenFor($userA),
            '/api/clients',
            $this->clientPayload(['organization_id' => $organizationB->id]),
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New client')
            ->assertJsonMissingPath('data.organization_id');

        $this->assertDatabaseHas('clients', [
            'id' => $response->json('data.id'),
            'organization_id' => $organizationA->id,
        ]);
        $this->assertDatabaseMissing('clients', [
            'id' => $response->json('data.id'),
            'organization_id' => $organizationB->id,
        ]);
    }

    public function test_owner_admin_and_staff_operational_permissions_match_the_role_rules(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $admin = $this->makeUser($organization, 'admin');
        $staff = $this->makeUser($organization, 'staff');

        foreach ([$owner, $admin, $staff] as $user) {
            $client = $this->makeClient($organization, 'Managed by '.$user->role);
            $token = $this->tokenFor($user);

            $this->getWithToken($token)->assertOk();
            $this->postWithToken($token, '/api/clients', $this->clientPayload())
                ->assertCreated();
            $this->putWithToken($token, '/api/clients/'.$client->id, [
                'name' => 'Updated by '.$user->role,
            ])->assertOk()->assertJsonPath('data.name', 'Updated by '.$user->role);

            $deleteResponse = $this->deleteWithToken($token, '/api/clients/'.$client->id);
            if ($user->role === 'staff') {
                $deleteResponse->assertForbidden();
                $this->assertDatabaseHas('clients', ['id' => $client->id]);
            } else {
                $deleteResponse->assertOk();
                $this->assertDatabaseMissing('clients', ['id' => $client->id]);
            }
        }
    }

    public function test_clients_with_conversations_are_not_deleted_or_cascaded(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeUser($organization, 'admin');
        $client = $this->makeClient($organization, 'Has conversation');
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id,
            'channel' => 'whatsapp',
        ]);
        $message = $conversation->messages()->create([
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'body' => 'Keep this conversation history',
        ]);

        $this->deleteWithToken($this->tokenFor($admin), '/api/clients/'.$client->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Client cannot be deleted because it has conversations.');

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    public function test_client_list_paginates_with_a_bounded_page_size(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $this->makeClient($organization, 'First');
        $this->makeClient($organization, 'Second');
        $this->makeClient($organization, 'Third');

        $this->getWithToken($this->tokenFor($owner), '/api/clients?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(2, 'data');

        $this->getWithToken($this->tokenFor($owner), '/api/clients?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    private function makeOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Client test organization',
            'slug' => 'client-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Client Test User',
            'email' => 'client-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }

    private function makeClient(Organization $organization, string $name): Client
    {
        return $organization->clients()->create($this->clientPayload(['name' => $name]));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function clientPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New client',
            'phone_number' => '+62 812-3456-7890',
            'email' => 'client@example.test',
            'group' => 'General',
            'status' => 'new',
            'notes' => 'Client test note',
        ], $overrides);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('client-api-test')->plainTextToken;
    }

    private function getWithToken(string $token, string $uri = '/api/clients')
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /** @param array<string, mixed> $data */
    private function postWithToken(string $token, string $uri, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson($uri, $data);
    }

    /** @param array<string, mixed> $data */
    private function putWithToken(string $token, string $uri, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->putJson($uri, $data);
    }

    private function deleteWithToken(string $token, string $uri)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->deleteJson($uri);
    }
}
