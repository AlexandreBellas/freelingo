<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_register_success_makes_first_user_admin(): void
    {
        $response = $this->api('POST', '/api/auth/register', [
            'username' => 'newuser',
            'email' => 'new@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'New User',
            'native_language' => 'es',
        ]);

        $response->assertOk();
        $response->assertJsonPath('username', 'newuser');
        $response->assertJsonPath('role', 'admin');
        $this->assertArrayHasKey('access_token', $response->json());
        $this->assertArrayHasKey('refresh_token', $this->cookies);
    }

    public function test_register_duplicate_username(): void
    {
        $payload = [
            'username' => 'dup',
            'email' => 'dup@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Dup',
            'native_language' => 'es',
        ];
        $this->api('POST', '/api/auth/register', $payload);
        $response = $this->api('POST', '/api/auth/register', [
            'username' => 'dup',
            'email' => 'dup2@test.com',
            'password' => 'Test5678!@',
            'display_name' => 'Dup2',
            'native_language' => 'fr',
        ]);
        $response->assertStatus(409);
    }

    public function test_second_user_is_not_admin(): void
    {
        $this->api('POST', '/api/auth/register', [
            'username' => 'admin_first',
            'email' => 'adminfirst@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Admin',
            'native_language' => 'en',
        ]);
        $response = $this->api('POST', '/api/auth/register', [
            'username' => 'regular',
            'email' => 'regular@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Regular',
            'native_language' => 'es',
        ]);
        $response->assertOk();
        $response->assertJsonPath('role', 'user');
    }

    public function test_login_success_and_sets_cookie(): void
    {
        $this->api('POST', '/api/auth/register', [
            'username' => 'loginuser',
            'email' => 'loginuser@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Login',
            'native_language' => 'es',
        ]);
        $this->cookies = [];
        $response = $this->api('POST', '/api/auth/login', [
            'email' => 'loginuser@test.com',
            'password' => 'Test1234!@',
        ]);
        $response->assertOk();
        $this->assertSame('bearer', $response->json('token_type'));
        $this->assertArrayHasKey('refresh_token', $this->cookies);
    }

    public function test_login_invalid_credentials(): void
    {
        $response = $this->api('POST', '/api/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'wrongpass1',
        ]);
        $response->assertStatus(401);
    }

    public function test_login_inactive_user(): void
    {
        $this->makeUser([
            'username' => 'inactive',
            'email' => 'inactive@test.com',
            'is_active' => false,
        ], 'testpass');

        $this->api('POST', '/api/auth/login', [
            'email' => 'inactive@test.com',
            'password' => 'testpass',
        ])->assertStatus(401);
    }

    public function test_refresh_rotates_token(): void
    {
        $this->api('POST', '/api/auth/register', [
            'username' => 'refreshuser',
            'email' => 'refreshuser@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Refresh',
            'native_language' => 'en',
        ]);
        $this->cookies = [];
        $this->api('POST', '/api/auth/login', [
            'email' => 'refreshuser@test.com',
            'password' => 'Test1234!@',
        ]);
        $response = $this->api('POST', '/api/auth/refresh');
        $response->assertOk();
        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_refresh_missing_cookie(): void
    {
        $this->cookies = [];
        $this->api('POST', '/api/auth/refresh')->assertStatus(401);
    }

    public function test_refresh_replayed_token(): void
    {
        $this->api('POST', '/api/auth/register', [
            'username' => 'replayuser',
            'email' => 'replayuser@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Replay',
            'native_language' => 'en',
        ]);
        $this->cookies = [];
        $this->api('POST', '/api/auth/login', [
            'email' => 'replayuser@test.com',
            'password' => 'Test1234!@',
        ]);
        $original = $this->cookies['refresh_token'];
        $this->api('POST', '/api/auth/refresh')->assertOk();
        $this->cookies['refresh_token'] = $original;
        $this->api('POST', '/api/auth/refresh')->assertStatus(401);
    }

    public function test_logout(): void
    {
        $this->api('POST', '/api/auth/register', [
            'username' => 'logoutuser',
            'email' => 'logoutuser@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Logout',
            'native_language' => 'en',
        ]);
        $this->cookies = [];
        $this->api('POST', '/api/auth/login', [
            'email' => 'logoutuser@test.com',
            'password' => 'Test1234!@',
        ]);
        $this->api('POST', '/api/auth/logout')->assertOk();
        $this->api('POST', '/api/auth/refresh')->assertStatus(401);
    }

    public function test_register_when_closed_without_invite(): void
    {
        config(['freelingo.allow_registration' => false]);
        $this->api('POST', '/api/auth/register', [
            'username' => 'locked',
            'email' => 'locked@test.com',
            'password' => 'Test1234!@',
            'display_name' => 'Locked',
            'native_language' => 'es',
        ])->assertStatus(403);
    }

    public function test_get_me_unauthenticated(): void
    {
        $this->api('GET', '/api/auth/me')->assertStatus(401);
    }

    public function test_health(): void
    {
        $this->api('GET', '/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
