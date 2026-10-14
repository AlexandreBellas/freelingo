<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Redis\ArrayRedis;
use App\Services\Security\Jwt;
use Tests\TestCase;

class RefreshIdentityTest extends TestCase
{
    public function test_cross_account_context_is_rejected_without_consuming_the_cookie(): void
    {
        [$first] = $this->makeUser();
        [$second] = $this->makeAdmin();
        foreach ([
            ['bearer' => $first],
            ['profile' => $first],
            ['bearer' => $first, 'profile' => $first],
            ['bearer' => $second, 'profile' => $first],
        ] as $context) {
            $cookie = $this->seedCookie($second);
            $headers = [];
            if (isset($context['bearer'])) {
                $headers['Authorization'] = 'Bearer '.$this->expiredAccess($context['bearer']);
            }
            if (isset($context['profile'])) {
                $headers['X-Expected-User-ID'] = (string) $context['profile']->id;
            }
            $rejected = $this->api('POST', '/api/auth/refresh', [], $headers);
            $rejected->assertStatus(409);
            $this->assertSame('auth_context_changed', $rejected->json('detail'));
            $this->assertNull($rejected->headers->get('set-cookie'));
            $this->assertSame((string) $second->id, app(ArrayRedis::class)->get('refresh:'.$cookie));

            $this->cookies['refresh_token'] = $cookie;
            $renewed = $this->api('POST', '/api/auth/refresh', [], [
                'X-Expected-User-ID' => (string) $second->id,
            ]);
            $renewed->assertOk();
            $payload = Jwt::decode($renewed->json('access_token'));
            $this->assertSame((string) $second->id, $payload['sub']);
            $this->assertNull(app(ArrayRedis::class)->get('refresh:'.$cookie));
            $this->cookies = [];
        }
    }

    public function test_expired_context_allows_same_account_rotation(): void
    {
        [$user] = $this->makeUser();
        $cookie = $this->seedCookie($user);
        $headers = [
            'Authorization' => 'Bearer '.$this->expiredAccess($user),
            'X-Expected-User-ID' => (string) $user->id,
        ];
        $this->api('GET', '/api/auth/me', [], $headers)->assertUnauthorized();
        $renewed = $this->api('POST', '/api/auth/refresh', [], $headers);
        $renewed->assertOk();
        $this->assertSame((string) $user->id, Jwt::decode($renewed->json('access_token'))['sub']);
        $this->assertNull(app(ArrayRedis::class)->get('refresh:'.$cookie));
        $replacement = $this->cookies['refresh_token'] ?? null;
        $this->assertNotNull($replacement);
        $this->assertNotSame($cookie, $replacement);
        $this->assertSame((string) $user->id, app(ArrayRedis::class)->get('refresh:'.$replacement));
    }

    public function test_invalid_bearer_context_never_consumes_the_cookie(): void
    {
        [$user] = $this->makeUser();
        $cases = [
            'Bearer invalid',
            'Bearer '.Jwt::signPayload(['sub' => (string) $user->id, 'exp' => 1], 'not-the-signing-key'),
            'Bearer '.Jwt::signPayload(['exp' => 1]),
            'Basic '.$this->expiredAccess($user),
        ];
        foreach ($cases as $authorization) {
            $cookie = $this->seedCookie($user);
            $response = $this->api('POST', '/api/auth/refresh', [], ['Authorization' => $authorization]);
            $response->assertUnauthorized();
            $this->assertNull($response->headers->get('set-cookie'));
            $this->assertSame((string) $user->id, app(ArrayRedis::class)->get('refresh:'.$cookie));
            $this->cookies = [];
        }
    }

    public function test_expectation_does_not_replace_a_missing_cookie(): void
    {
        [$user, $headers] = $this->makeUser();
        $headers['X-Expected-User-ID'] = (string) $user->id;
        $this->api('POST', '/api/auth/refresh', [], $headers)->assertUnauthorized();
    }

    public function test_invalid_profile_context_is_rejected_before_rotation(): void
    {
        [$user] = $this->makeUser();
        foreach (['0', '-1', 'other', '2147483648'] as $value) {
            $cookie = $this->seedCookie($user);
            $response = $this->api('POST', '/api/auth/refresh', [], ['X-Expected-User-ID' => $value]);
            $response->assertStatus(422);
            $this->assertSame((string) $user->id, app(ArrayRedis::class)->get('refresh:'.$cookie));
            $this->cookies = [];
        }
    }

    private function seedCookie(User $user): string
    {
        $token = 'cookie-for-'.bin2hex(random_bytes(4));
        app(ArrayRedis::class)->setex('refresh:'.$token, 3600, (string) $user->id);
        $this->cookies['refresh_token'] = $token;

        return $token;
    }

    private function expiredAccess(User $user): string
    {
        return Jwt::createAccessToken($user->id, $user->role, time() - 60);
    }
}
