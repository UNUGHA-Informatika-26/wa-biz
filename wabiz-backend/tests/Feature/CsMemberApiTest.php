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

class CsMemberApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cs_member_routes_require_authentication(): void
    {
        $this->getJson('/api/cs-members')->assertUnauthorized();
        $this->postJson('/api/cs-members', ['user_id' => 1])->assertUnauthorized();
    }

    public function test_owner_and_admin_can_create_staff_cannot_and_request_cannot_set_tenant(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $owner = $this->makeUser($organizationA, 'owner');
        $admin = $this->makeUser($organizationA, 'admin');
        $staff = $this->makeUser($organizationA, 'staff');
        $targetUser = $this->makeUser($organizationA, 'staff');
        $department = $this->makeDepartment($organizationA, 'Support');

        $this->postWithToken($this->tokenFor($owner), [
            'user_id' => $targetUser->id,
            'department_id' => $department->id,
            'display_name' => 'Owner-created CS',
            'organization_id' => $organizationB->id,
        ])->assertCreated()
            ->assertJsonPath('data.user.id', $targetUser->id)
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.user.password');

        $adminTarget = $this->makeUser($organizationA, 'staff');
        $this->postWithToken($this->tokenFor($admin), ['user_id' => $adminTarget->id])->assertCreated();

        $staffTarget = $this->makeUser($organizationA, 'staff');
        $this->postWithToken($this->tokenFor($staff), ['user_id' => $staffTarget->id])->assertForbidden();

        $this->assertDatabaseHas('cs_members', [
            'organization_id' => $organizationA->id,
            'user_id' => $targetUser->id,
        ]);
        $this->assertDatabaseMissing('cs_members', [
            'organization_id' => $organizationB->id,
            'user_id' => $targetUser->id,
        ]);
    }

    public function test_admin_can_create_loginless_cs_contact_with_normalized_phone_and_division(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $department = $this->makeDepartment($organization, 'Pendaftaran');

        $this->postWithToken($this->tokenFor($owner), [
            'display_name' => 'Contact CS',
            'phone_number' => '0812-3456-7890',
            'department_id' => $department->id,
        ])->assertCreated()
            ->assertJsonPath('data.display_name', 'Contact CS')
            ->assertJsonPath('data.phone_number', '+6281234567890')
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.user', null);

        $this->assertDatabaseHas('cs_members', [
            'organization_id' => $organization->id,
            'user_id' => null,
            'phone_number' => '+6281234567890',
        ]);
    }

    public function test_create_rejects_cross_tenant_users_departments_inactive_departments_and_duplicate_memberships(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $owner = $this->makeUser($organizationA, 'owner');
        $sameTenantUser = $this->makeUser($organizationA, 'staff');
        $foreignUser = $this->makeUser($organizationB, 'staff');
        $activeDepartment = $this->makeDepartment($organizationA, 'Active');
        $inactiveDepartment = $this->makeDepartment($organizationA, 'Inactive', false);
        $foreignDepartment = $this->makeDepartment($organizationB, 'Foreign');
        $token = $this->tokenFor($owner);

        $this->postWithToken($token, ['user_id' => $foreignUser->id])->assertUnprocessable();
        $this->postWithToken($token, [
            'user_id' => $sameTenantUser->id,
            'department_id' => $foreignDepartment->id,
        ])->assertUnprocessable();
        $this->postWithToken($token, [
            'user_id' => $sameTenantUser->id,
            'department_id' => $inactiveDepartment->id,
        ])->assertUnprocessable();

        $this->postWithToken($token, [
            'user_id' => $sameTenantUser->id,
            'department_id' => $activeDepartment->id,
        ])->assertCreated();
        $this->postWithToken($token, [
            'user_id' => $sameTenantUser->id,
            'department_id' => $activeDepartment->id,
        ])->assertUnprocessable();

        $this->postWithToken($token, ['user_id' => $sameTenantUser->id])->assertCreated();
        $this->postWithToken($token, ['user_id' => $sameTenantUser->id])->assertUnprocessable();
    }

    public function test_list_filters_searches_and_paginates_with_department(): void
    {
        $organization = $this->makeOrganization();
        $staff = $this->makeUser($organization, 'staff');
        $department = $this->makeDepartment($organization, 'Support');
        $otherDepartment = $this->makeDepartment($organization, 'Billing');
        $memberA = $this->makeCsMember($organization, $department, 'Alice Agent', '+62 811 100 1001', true);
        $memberB = $this->makeCsMember($organization, $department, 'Bob Agent', '+62 811 100 1002', false);
        $memberC = $this->makeCsMember($organization, $otherDepartment, 'Carol Agent', '+62 811 100 1003', true);

        $token = $this->tokenFor($staff);
        $this->getWithToken($token, '/api/cs-members?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data');

        $this->getWithToken($token, '/api/cs-members?active=false')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.active', false);
        $this->getWithToken($token, '/api/cs-members?department_id='.$department->id)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getWithToken($token, '/api/cs-members?search=Alice')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $memberA->id);
        $this->getWithToken($token, '/api/cs-members?search=1003')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $memberC->id);
        $this->getWithToken($token, '/api/cs-members?per_page=101')->assertUnprocessable();
    }

    public function test_show_and_update_are_scoped_safe_and_deactivation_preserves_assignments(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $owner = $this->makeUser($organizationA, 'owner');
        $admin = $this->makeUser($organizationA, 'admin');
        $staff = $this->makeUser($organizationA, 'staff');
        $department = $this->makeDepartment($organizationA, 'Support');
        $member = $this->makeCsMember($organizationA, $department, 'Safe CS', '+62 811 200 0001', true);
        $foreignDepartment = $this->makeDepartment($organizationB, 'Foreign');
        $foreignUser = $this->makeUser($organizationB, 'staff');
        $foreignMember = $this->makeCsMember($organizationB, $foreignDepartment, 'Foreign CS', '+62 811 200 0002', true);
        $conversation = $this->makeConversation($organizationA, $this->makeClient($organizationA));
        $conversation->forceFill(['department_id' => $department->id, 'cs_member_id' => $member->id])->save();
        $uri = '/api/cs-members/'.$member->id;

        $this->getWithToken($this->tokenFor($staff), $uri)
            ->assertOk()
            ->assertJsonPath('data.user.id', $member->user_id)
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->getWithToken($this->tokenFor($owner), '/api/cs-members/'.$foreignMember->id)->assertNotFound();
        $this->putWithToken($this->tokenFor($owner), '/api/cs-members/'.$foreignMember->id, ['active' => false])->assertNotFound();
        $this->deleteWithToken($this->tokenFor($owner), '/api/cs-members/'.$foreignMember->id)->assertNotFound();

        $this->putWithToken($this->tokenFor($staff), $uri, ['display_name' => 'Blocked'])->assertForbidden();
        $this->putWithToken($this->tokenFor($owner), $uri, [
            'display_name' => 'Updated CS',
            'user_id' => $foreignUser->id,
            'organization_id' => $organizationB->id,
        ])->assertUnprocessable();
        $this->putWithToken($this->tokenFor($owner), $uri, ['display_name' => 'Updated CS'])
            ->assertOk()->assertJsonPath('data.display_name', 'Updated CS');

        $this->putWithToken($this->tokenFor($admin), $uri, ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);

        $this->assertSame($member->user_id, $member->fresh()->user_id);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
        ]);
    }

    public function test_update_rejects_foreign_or_inactive_department_and_allows_unassignment(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $owner = $this->makeUser($organizationA, 'owner');
        $member = $this->makeCsMember($organizationA, null, 'Unassigned CS', null, true);
        $foreignDepartment = $this->makeDepartment($organizationB, 'Foreign');
        $inactiveDepartment = $this->makeDepartment($organizationA, 'Inactive', false);
        $token = $this->tokenFor($owner);
        $uri = '/api/cs-members/'.$member->id;

        $this->putWithToken($token, $uri, ['department_id' => $foreignDepartment->id])->assertUnprocessable();
        $this->putWithToken($token, $uri, ['department_id' => $inactiveDepartment->id])->assertUnprocessable();

        $department = $this->makeDepartment($organizationA, 'Active');
        $this->putWithToken($token, $uri, ['department_id' => $department->id])
            ->assertOk()->assertJsonPath('data.department.id', $department->id);
        $this->putWithToken($token, $uri, ['department_id' => null])
            ->assertOk()->assertJsonPath('data.department', null);
    }

    public function test_admin_can_safely_link_an_existing_unlinked_cs_to_same_tenant_staff_user(): void
    {
        $organization = $this->makeOrganization();
        $otherOrganization = $this->makeOrganization();
        $admin = $this->makeUser($organization, 'admin');
        $member = $this->makeCsMember($organization, null, 'Unlinked CS', '+6281112223333', true);
        $member->forceFill(['user_id' => null])->save();
        $staffUser = $this->makeUser($organization, 'staff');
        $foreignUser = $this->makeUser($otherOrganization, 'staff');
        $token = $this->tokenFor($admin);
        $uri = '/api/cs-members/'.$member->id;

        $this->getWithToken($token, $uri.'/linkable-users')
            ->assertOk()
            ->assertJsonFragment(['id' => $staffUser->id, 'email' => $staffUser->email])
            ->assertJsonMissing(['id' => $foreignUser->id, 'email' => $foreignUser->email]);

        $this->putWithToken($token, $uri, ['user_id' => $foreignUser->id])->assertUnprocessable();
        $this->putWithToken($token, $uri, ['user_id' => $staffUser->id])
            ->assertOk()->assertJsonPath('data.user.id', $staffUser->id);
        $this->putWithToken($token, $uri, ['user_id' => $staffUser->id])
            ->assertOk()->assertJsonPath('data.user.id', $staffUser->id);
        $this->assertDatabaseCount('cs_members', 1);

        $replacement = $this->makeUser($organization, 'staff');
        $this->putWithToken($token, $uri, ['user_id' => $replacement->id])->assertConflict();
        $this->assertSame($staffUser->id, $member->fresh()->user_id);
    }

    public function test_linking_rejects_user_already_linked_to_same_department_and_staff_cannot_link(): void
    {
        $organization = $this->makeOrganization();
        $department = $this->makeDepartment($organization, 'Support');
        $admin = $this->makeUser($organization, 'admin');
        $staff = $this->makeUser($organization, 'staff');
        $target = $this->makeCsMember($organization, $department, 'Existing membership', '+6281110000001', true);
        $member = $this->makeCsMember($organization, $department, 'Unlinked member', '+6281110000002', true);
        $member->forceFill(['user_id' => null])->save();
        $uri = '/api/cs-members/'.$member->id;

        $this->putWithToken($this->tokenFor($admin), $uri, ['user_id' => $target->user_id])->assertUnprocessable();
        $this->putWithToken($this->tokenFor($staff), $uri, ['user_id' => $staff->id])->assertForbidden();
        $this->assertNull($member->fresh()->user_id);
    }

    public function test_unused_member_can_be_deleted_but_assigned_member_returns_conflict(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeUser($organization, 'admin');
        $unused = $this->makeCsMember($organization, null, 'Unused CS', null, true);
        $assigned = $this->makeCsMember($organization, null, 'Assigned CS', null, true);
        $conversation = $this->makeConversation($organization, $this->makeClient($organization));
        $conversation->forceFill(['cs_member_id' => $assigned->id])->save();
        $token = $this->tokenFor($admin);

        $this->deleteWithToken($token, '/api/cs-members/'.$unused->id)->assertOk();
        $this->deleteWithToken($token, '/api/cs-members/'.$assigned->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('cs_members', ['id' => $unused->id]);
        $this->assertDatabaseHas('cs_members', ['id' => $assigned->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'cs_member_id' => $assigned->id]);
    }

    private function makeOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'CS test organization',
            'slug' => 'cs-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'CS Test User',
            'email' => 'cs-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }

    private function makeDepartment(Organization $organization, string $name, bool $active = true): Department
    {
        return $organization->departments()->create(['name' => $name, 'active' => $active]);
    }

    private function makeCsMember(
        Organization $organization,
        ?Department $department,
        string $name,
        ?string $phone,
        bool $active,
    ): CsMember {
        $user = $this->makeUser($organization, 'staff');

        return CsMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'department_id' => $department?->id,
            'display_name' => $name,
            'phone_number' => $phone,
            'active' => $active,
        ]);
    }

    private function makeClient(Organization $organization): Client
    {
        return $organization->clients()->create(['name' => 'CS client', 'phone_number' => '+62 811', 'status' => 'customer']);
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
        return $user->createToken('cs-member-api-test')->plainTextToken;
    }

    private function getWithToken(string $token, string $uri = '/api/cs-members')
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /** @param array<string, mixed> $data */
    private function postWithToken(string $token, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson('/api/cs-members', $data);
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
