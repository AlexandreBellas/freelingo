<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Redis\ArrayRedis;
use Tests\TestCase;

class AuthExtraTest extends TestCase
{
    public function test_delete_me_removes_the_account(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->api('DELETE', '/api/auth/me', [], $headers)->assertNoContent();
        $this->assertNull(User::query()->find($user->id));
    }

    public function test_delete_me_requires_auth(): void
    {
        $this->api('DELETE', '/api/auth/me', [], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }

    public function test_delete_me_rejects_an_admin(): void
    {
        [, $headers] = $this->makeAdmin();
        $this->api('DELETE', '/api/auth/me', [], $headers)->assertForbidden();
    }

    public function test_delete_me_clears_the_refresh_cookie(): void
    {
        [$user, $headers] = $this->makeUser();
        app(ArrayRedis::class)->setex('refresh:faketoken', 86400, (string) $user->id);
        $this->cookies['refresh_token'] = 'faketoken';

        $response = $this->api('DELETE', '/api/auth/me', [], $headers);
        $response->assertNoContent();
        $cookie = strtolower((string) $response->headers->get('set-cookie'));
        $this->assertStringContainsString('refresh_token', $cookie);
        $this->assertTrue(str_contains($cookie, 'max-age=0') || str_contains($cookie, 'expires'));
        $this->assertNull(app(ArrayRedis::class)->get('refresh:faketoken'));
    }

    public function test_quota_returns_the_expected_initial_fields(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->api('GET', '/api/auth/quota', [], $headers);
        $response->assertOk();
        $data = $response->json();
        foreach ([
            'sessions_this_week', 'sessions_limit', 'sessions_unlimited',
            'minutes_today', 'minutes_limit', 'tokens_this_month',
            'tokens_monthly_limit', 'tokens_unlimited',
        ] as $field) {
            $this->assertArrayHasKey($field, $data);
        }
        $this->assertSame(0, $data['sessions_this_week']);
        $this->assertSame(0, $data['minutes_today']);
        $this->assertSame(0, $data['tokens_this_month']);
    }

    public function test_quota_requires_auth(): void
    {
        $this->api('GET', '/api/auth/quota', [], ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }

    public function test_register_rejects_a_blocked_email_domain(): void
    {
        config(['freelingo.blocked_email_domains' => ['blocked.com']]);
        $blocked = $this->api('POST', '/api/auth/register', $this->registration('blockeduser', 'user@blocked.com'));
        $blocked->assertStatus(422);
        $this->assertSame('Email domain not allowed', $blocked->json('detail'));

        $allowed = $this->api('POST', '/api/auth/register', $this->registration('alloweduser', 'user@allowed.com'));
        $allowed->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function registration(string $username, string $email): array
    {
        return [
            'username' => $username,
            'email' => $email,
            'password' => 'Test1234!@',
            'display_name' => 'Domain User',
            'native_language' => 'es',
        ];
    }
}
