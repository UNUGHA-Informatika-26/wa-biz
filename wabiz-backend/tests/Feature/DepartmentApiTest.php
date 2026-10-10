<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DepartmentApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_department_routes_require_authentication(): void
    {
        $this->getJson('/api/departments')->assertUnauthorized();
        $this->postJson('/api/departments', ['name' => 'Support'])->assertUnauthorized();
    }

    public function test_owner_and_admin_can_create_staff_cannot_and_names_are_unique_per_organization(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $owner = $this->makeUser($organizationA, 'owner');
        $admin = $this->makeUser($organizationA, 'admin');
        $staff = $this->makeUser($organizationA, 'staff');
        $ownerB = $this->makeUser($organizationB, 'owner');

        $this->postWithToken($this->tokenFor($owner), ['name' => 'Support'])
            ->assertCreated()
            ->assertJsonMissingPath('data.organization_id');
        $this->postWithToken($this->tokenFor($admin), ['name' => 'Billing'])->assertCreated();
        $this->postWithToken($this->tokenFor($staff), ['name' => 'Blocked'])->assertForbidden();
        $this->postWithToken($this->tokenFor($owner), ['name' => 'Support'])->assertUnprocessable();
        $this->postWithToken($this->tokenFor($ownerB), ['name' => 'Support'])->assertCreated();
    }

    public function test_department_list_filters_searches_orders_and_paginates(): void
    {
        $organization = $this->makeOrganization();
        $staff = $this->makeUser($organization, 'staff');
        $this->makeDepartment($organization, 'Zulu inactive', false);
        $this->makeDepartment($organization, 'Beta active', true);
        $this->makeDepartment($organization, 'Alpha active', true);

        $token = $this->tokenFor($staff);
        $this->getWithToken($token, '/api/departments?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.name', 'Alpha active')
            ->assertJsonPath('data.1.name', 'Beta active');

        $this->getWithToken($token, '/api/departments?active=false')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.active', false);

        $this->getWithToken($token, '/api/departments?search=beta')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Beta active');

        $this->getWithToken($token, '/api/departments?per_page=101')->assertUnprocessable();
    }

    public function test_department_detail_is_scoped_and_includes_usage_counts(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $staffA = $this->makeUser($organizationA, 'staff');
        $ownerA = $this->makeUser($organizationA, 'owner');
        $departmentA = $this->makeDepartment($organizationA, 'A Support');
        $departmentB = $this->makeDepartment($organizationB, 'B Support');
        $this->makeCsMember($organizationA, $departmentA, 'Active CS', true);
        $this->makeCsMember($organizationA, $departmentA, 'Inactive CS', false);
        $conversation = $this->makeConversation($organizationA, $this->makeClient($organizationA));
        $conversation->forceFill(['department_id' => $departmentA->id])->save();

        $this->getWithToken($this->tokenFor($staffA), '/api/departments/'.$departmentA->id)
            ->assertOk()
            ->assertJsonPath('data.active_cs_members_count', 1)
            ->assertJsonPath('data.conversation_count', 1)
            ->assertJsonMissingPath('data.organization_id');

        $this->getWithToken($this->tokenFor($ownerA), '/api/departments/'.$departmentB->id)->assertNotFound();
        $this->putWithToken($this->tokenFor($ownerA), '/api/departments/'.$departmentB->id, ['name' => 'Changed'])->assertNotFound();
        $this->deleteWithToken($this->tokenFor($ownerA), '/api/departments/'.$departmentB->id)->assertNotFound();
    }

    public function test_owner_admin_update_and_deactivation_preserve_cs_and_conversation_assignment(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $admin = $this->makeUser($organization, 'admin');
        $staff = $this->makeUser($organization, 'staff');
        $department = $this->makeDepartment($organization, 'Support');
        $member = $this->makeCsMember($organization, $department, 'Assigned CS', true);
        $conversation = $this->makeConversation($organization, $this->makeClient($organization));
        $conversation->forceFill([
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
        ])->save();
        $uri = '/api/departments/'.$department->id;

        $this->putWithToken($this->tokenFor($staff), $uri, ['name' => 'Unauthorized'])->assertForbidden();
        $this->putWithToken($this->tokenFor($owner), $uri, ['name' => 'Customer Care'])
            ->assertOk()->assertJsonPath('data.name', 'Customer Care');
        $this->putWithToken($this->tokenFor($admin), $uri, ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('cs_members', ['id' => $member->id, 'department_id' => $department->id]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
        ]);
    }

    public function test_unused_department_can_be_deleted_but_referenced_department_returns_conflict(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeUser($organization, 'admin');
        $unused = $this->makeDepartment($organization, 'Unused');
        $usedByMember = $this->makeDepartment($organization, 'Used by CS');
        $this->makeCsMember($organization, $usedByMember, 'Referencing CS', true);
        $usedByConversation = $this->makeDepartment($organization, 'Used by conversation');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization));
        $conversation->forceFill(['department_id' => $usedByConversation->id])->save();
        $token = $this->tokenFor($admin);

        $this->deleteWithToken($token, '/api/departments/'.$unused->id)->assertOk();
        $this->deleteWithToken($token, '/api/departments/'.$usedByMember->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->deleteWithToken($token, '/api/departments/'.$usedByConversation->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('departments', ['id' => $usedByMember->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'department_id' => $usedByConversation->id]);
    }

    private function makeOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Department test organization',
            'slug' => 'department-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Department Test User',
            'email' => 'department-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }

    private function makeDepartment(Organization $organization, string $name, bool $active = true): Department
    {
        return $organization->departments()->create(['name' => $name, 'active' => $active]);
    }

    private function makeCsMember(Organization $organization, Department $department, string $name, bool $active): CsMember
    {
        $user = $this->makeUser($organization, 'staff');

        return CsMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'department_id' => $department->id,
            'display_name' => $name,
            'active' => $active,
        ]);
    }

    private function makeClient(Organization $organization): Client
    {
        return $organization->clients()->create(['name' => 'Department client', 'phone_number' => '+62 811', 'status' => 'customer']);
    }

    private function makeConversation(Organization $organization, Client $client): Conversation
    {
        return Conversation::forceCreate([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'channel' => 'whatsapp',
            'status' => 'AI_ACTIVE',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('department-api-test')->plainTextToken;
    }

    private function getWithToken(string $token, string $uri = '/api/departments')
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /** @param array<string, mixed> $data */
    private function postWithToken(string $token, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson('/api/departments', $data);
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
