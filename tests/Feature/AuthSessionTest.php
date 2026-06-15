<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_the_authenticated_user_roles_and_jwt(): void
    {
        $role = Role::create([
            'name' => 'Salesperson',
            'slug' => 'salesperson',
            'is_system' => true,
        ]);

        $user = User::create([
            'name' => 'Sales User',
            'email' => 'sales@example.test',
            'password' => Hash::make('password'),
        ]);

        $user->roles()->attach($role);

        $response = $this->postJson('/api/login', [
            'email' => 'sales@example.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Sales User')
            ->assertJsonPath('user.roles.0.slug', 'salesperson')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure([
                'token',
                'refresh_token',
                'expires_in',
                'refresh_expires_in',
            ]);

        $this->assertGuest();
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::create([
            'name' => 'Sales User',
            'email' => 'sales@example.test',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'sales@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_me_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_bearer_token_can_access_the_api_user_profile(): void
    {
        $role = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'is_system' => true,
        ]);

        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $user->roles()->attach($role);

        $token = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.test')
            ->assertJsonPath('user.roles.0.slug', 'admin');
    }

    public function test_invalid_bearer_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_change_their_password(): void
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertUnauthorized();
    }

    public function test_logout_requires_a_valid_token(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_jwt(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_the_refresh_token(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $refreshToken = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
            'remember' => true,
        ])->json('refresh_token');

        $this->postJson('/api/logout', [
            'refresh_token' => $refreshToken,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->postJson('/api/token/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertUnauthorized();
    }

    public function test_refresh_rotates_the_refresh_token_and_revokes_the_previous_jwt_when_present(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $loginPayload = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
            'remember' => true,
        ])->json();

        $oldToken = $loginPayload['token'];
        $oldRefreshToken = $loginPayload['refresh_token'];

        $refreshPayload = $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->postJson('/api/token/refresh', [
                'refresh_token' => $oldRefreshToken,
            ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['token', 'refresh_token', 'expires_in', 'refresh_expires_in', 'user'])
            ->json();

        $newToken = $refreshPayload['token'];
        $newRefreshToken = $refreshPayload['refresh_token'];

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNotSame($oldRefreshToken, $newRefreshToken);

        $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->postJson('/api/token/refresh', [
            'refresh_token' => $oldRefreshToken,
        ])->assertUnauthorized();

        $this->withHeader('Authorization', "Bearer {$newToken}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.test');
    }

    public function test_refresh_can_issue_a_new_jwt_without_an_access_token(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $refreshToken = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
            'remember' => true,
        ])->json('refresh_token');

        $newToken = $this->postJson('/api/token/refresh', [
            'refresh_token' => $refreshToken,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'refresh_token', 'expires_in', 'refresh_expires_in', 'user'])
            ->json('token');

        $this->withHeader('Authorization', "Bearer {$newToken}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.test');
    }
}
