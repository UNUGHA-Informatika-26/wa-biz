<?php

namespace Tests\Feature;

use App\Models\AiAssistantSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AiAssistantSettingsApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_settings_routes_require_authentication_and_get_does_not_create_settings(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');

        $this->getJson('/api/ai/settings')->assertUnauthorized();
        $this->putJson('/api/ai/settings', ['enabled' => true])->assertUnauthorized();

        $this->getWithToken($this->tokenFor($owner))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('configured', false)
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('ai_assistant_settings', [
            'organization_id' => $organization->id,
        ]);
    }

    public function test_owner_admin_and_staff_can_read_only_their_organizations_settings(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $ownerA = $this->makeUser($organizationA, 'owner');
        $adminA = $this->makeUser($organizationA, 'admin');
        $staffA = $this->makeUser($organizationA, 'staff');
        $ownerB = $this->makeUser($organizationB, 'owner');

        $organizationA->aiAssistantSetting()->create([
            'enabled' => true,
            'institution_name' => 'Institution A',
        ]);

        foreach ([$ownerA, $adminA, $staffA] as $user) {
            $this->getWithToken($this->tokenFor($user))
                ->assertOk()
                ->assertJsonPath('configured', true)
                ->assertJsonPath('data.institution_name', 'Institution A');
        }

        $this->getWithToken($this->tokenFor($ownerB))
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('data', null);

        Auth::forgetGuards();
        $this->withToken($this->tokenFor($ownerB))
            ->getJson('/api/ai/settings?organization_id='.$organizationA->id)
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('data', null);
    }

    public function test_owner_and_admin_can_upsert_but_staff_cannot(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $admin = $this->makeUser($organization, 'admin');
        $staff = $this->makeUser($organization, 'staff');

        $this->putWithToken($this->tokenFor($owner), [
            'enabled' => true,
            'assistant_name' => 'Owner assistant',
        ])->assertOk()->assertJsonPath('data.assistant_name', 'Owner assistant');

        $this->putWithToken($this->tokenFor($admin), [
            'assistant_name' => 'Admin assistant',
        ])->assertOk()->assertJsonPath('data.assistant_name', 'Admin assistant');

        $this->putWithToken($this->tokenFor($staff), [
            'assistant_name' => 'Unauthorized change',
        ])->assertForbidden();

        $this->assertSame(1, AiAssistantSetting::query()
            ->where('organization_id', $organization->id)
            ->count());
        $this->assertSame('Admin assistant', AiAssistantSetting::query()
            ->where('organization_id', $organization->id)
            ->value('assistant_name'));
    }

    public function test_registration_department_setting_is_tenant_scoped_and_requires_active_department(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $owner = $this->makeUser($organizationA, 'owner');
        $active = $organizationA->departments()->create(['name' => 'Pendaftaran', 'active' => true]);
        $inactive = $organizationA->departments()->create(['name' => 'Lama', 'active' => false]);
        $foreign = $organizationB->departments()->create(['name' => 'Foreign', 'active' => true]);

        $this->putWithToken($this->tokenFor($owner), ['registration_department_id' => $active->id])
            ->assertOk()->assertJsonPath('data.registration_department_id', $active->id);
        $this->putWithToken($this->tokenFor($owner), ['registration_department_id' => $inactive->id])->assertUnprocessable();
        $this->putWithToken($this->tokenFor($owner), ['registration_department_id' => $foreign->id])->assertUnprocessable();

        $this->assertDatabaseHas('ai_assistant_settings', [
            'organization_id' => $organizationA->id,
            'registration_department_id' => $active->id,
        ]);
        $this->assertDatabaseMissing('ai_assistant_settings', [
            'organization_id' => $organizationB->id,
            'registration_department_id' => $active->id,
        ]);
    }

    public function test_put_creates_then_updates_the_same_row_and_ignores_external_tenant_and_secret_fields(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $ownerA = $this->makeUser($organizationA, 'owner');
        $ownerB = $this->makeUser($organizationB, 'owner');

        $created = $this->putWithToken($this->tokenFor($ownerA), [
            'enabled' => true,
            'institution_name' => 'Original A',
            'registration_enabled' => false,
            'registration_url' => 'https://register.example.test/apply',
            'student_count' => 134,
            'caregiver_count' => 8,
            'teacher_count' => 21,
            'google_maps_url' => 'https://maps.example.test/institute',
            'additional_information' => 'Accredited institution information.',
            'faqs' => [['question' => 'Where?', 'answer' => 'At the configured address.']],
            'organization_id' => $organizationB->id,
            'api_key' => 'must-not-be-returned-or-stored',
        ])->assertOk()
            ->assertJsonPath('data.institution_name', 'Original A')
            ->assertJsonPath('data.registration_url', 'https://register.example.test/apply')
            ->assertJsonPath('data.student_count', 134)
            ->assertJsonPath('data.caregiver_count', 8)
            ->assertJsonPath('data.teacher_count', 21)
            ->assertJsonPath('data.google_maps_url', 'https://maps.example.test/institute')
            ->assertJsonPath('data.additional_information', 'Accredited institution information.')
            ->assertJsonPath('data.faqs.0.question', 'Where?')
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.api_key');

        $createdId = AiAssistantSetting::query()->where('organization_id', $organizationA->id)->value('id');
        $this->assertNotNull($createdId);

        $this->putWithToken($this->tokenFor($ownerA), [
            'institution_name' => 'Updated A',
            'registration_enabled' => false,
        ])->assertOk()->assertJsonPath('data.institution_name', 'Updated A');

        $this->assertSame(1, AiAssistantSetting::query()->where('organization_id', $organizationA->id)->count());
        $this->assertSame($createdId, AiAssistantSetting::query()->where('organization_id', $organizationA->id)->value('id'));
        $this->assertSame('https://register.example.test/apply', AiAssistantSetting::query()
            ->where('organization_id', $organizationA->id)
            ->value('registration_url'));

        $this->putWithToken($this->tokenFor($ownerB), [
            'organization_id' => $organizationA->id,
            'institution_name' => 'B institution',
        ])->assertOk()->assertJsonPath('data.institution_name', 'B institution');

        $this->assertSame('Updated A', AiAssistantSetting::query()
            ->where('organization_id', $organizationA->id)
            ->value('institution_name'));
        $this->assertSame(2, AiAssistantSetting::query()->count());
    }

    public function test_invalid_urls_and_out_of_range_coordinates_are_rejected(): void
    {
        $owner = $this->makeUser($this->makeOrganization(), 'owner');

        $this->putWithToken($this->tokenFor($owner), [
            'website' => 'not-a-url',
            'latitude' => 91,
            'longitude' => -181,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['website', 'latitude', 'longitude']]);
    }

    public function test_invalid_institution_counts_maps_url_and_faqs_are_rejected(): void
    {
        $owner = $this->makeUser($this->makeOrganization(), 'owner');

        $this->putWithToken($this->tokenFor($owner), [
            'student_count' => -1,
            'caregiver_count' => 'many',
            'teacher_count' => 1.5,
            'google_maps_url' => 'not a url',
            'faqs' => [['question' => 'Missing answer']],
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => [
                'student_count', 'caregiver_count', 'teacher_count', 'google_maps_url', 'faqs.0.answer',
            ]]);
    }

    private function makeOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Settings test organization',
            'slug' => 'settings-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Settings Test User',
            'email' => 'settings-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('ai-settings-test')->plainTextToken;
    }

    /** @param array<string, mixed> $data */
    private function putWithToken(string $token, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->putJson('/api/ai/settings', $data);
    }

    private function getWithToken(string $token)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson('/api/ai/settings');
    }
}
