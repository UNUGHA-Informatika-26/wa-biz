<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_returns_token_and_database_organization_context(): void
    {
        $user = $this->makeUser('owner');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'organization_id' => 999999,
            'role' => 'staff',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'owner')
            ->assertJsonPath('data.organization.id', $user->organization_id)
            ->assertJsonMissingPath('data.user.password');

        $plainToken = $response->json('data.token');
        $this->assertNotEmpty($plainToken);
        $storedToken = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->first();
        $this->assertNotNull($storedToken);
        $this->assertNotSame($plainToken, $storedToken->token);
        [, $tokenSecret] = explode('|', $plainToken, 2);
        $this->assertSame(hash('sha256', $tokenSecret), $storedToken->token);
    }

    public function test_login_rate_limit_is_scoped_to_email_and_ip_and_expires(): void
    {
        $ip = '203.0.113.10';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/login', [
                'email' => 'unknown@example.test',
                'password' => 'invalid-password',
            ])->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'invalid-password',
        ])->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->postJson('/api/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'invalid-password',
        ])->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/login', [
            'email' => 'another@example.test',
            'password' => 'invalid-password',
        ])->assertUnauthorized();

        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'invalid-password',
        ])->assertUnauthorized();
    }

    public function test_invalid_credentials_have_the_same_generic_response(): void
    {
        $user = $this->makeUser('staff');

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertUnauthorized()->assertExactJson([
            'success' => false,
            'message' => 'Invalid credentials.',
        ]);
        $unknownEmail->assertUnauthorized()->assertExactJson($wrongPassword->json());
    }

    public function test_me_requires_token_and_logout_revokes_only_current_token(): void
    {
        $user = $this->makeUser('admin');
        $first = $user->createToken('first')->plainTextToken;
        $second = $user->createToken('second')->plainTextToken;

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($first)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.organization.slug', $user->organization->slug)
            ->assertJsonMissingPath('data.token');

        $this->withToken($first)->postJson('/api/auth/logout')->assertOk();
        [, $firstSecret] = explode('|', $first, 2);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', $firstSecret)]);
        Auth::forgetGuards();
        $this->withToken($first)->getJson('/api/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($second)->getJson('/api/auth/me')->assertOk();
    }

    public function test_role_gates_follow_the_authenticated_users_database_role(): void
    {
        $owner = $this->makeUser('owner');
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff');

        $this->assertTrue(Gate::forUser($owner)->allows('manage-organization'));
        $this->assertFalse(Gate::forUser($admin)->allows('manage-organization'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-organization-operations'));
        $this->assertTrue(Gate::forUser($staff)->allows('access-organization-operations'));
        $this->assertFalse(Gate::forUser($staff)->allows('manage-organization-operations'));
    }

    private function makeUser(string $role): User
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Auth test organization',
            'slug' => 'auth-test-'.bin2hex(random_bytes(6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::forceCreate([
            'organization_id' => $organizationId,
            'name' => 'Auth Test User',
            'email' => 'auth-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }
}
