<?php

namespace Tests;

use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Security\Jwt;
use App\Services\Security\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    /** @var array<string, string> */
    protected array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->app['db']->connection()->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('regexp_replace', function ($value, $pattern, $replacement, $flags) {
                $limit = is_string($flags) && str_contains($flags, 'g') ? -1 : 1;

                return preg_replace('/'.$pattern.'/', (string) $replacement, (string) $value, $limit);
            }, 4);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, array $payload = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $previousHeaders = $this->defaultHeaders;
        $this->defaultHeaders = [];
        $call = $this->withHeaders($headers)->withCredentials()->withUnencryptedCookies($this->cookies);
        $response = match (strtoupper($method)) {
            'GET' => $call->getJson($uri, $server),
            'POST' => $call->postJson($uri, $payload),
            'PUT' => $call->putJson($uri, $payload),
            'PATCH' => $call->patchJson($uri, $payload),
            'DELETE' => $call->deleteJson($uri, $payload),
            default => $call->json($method, $uri, $payload),
        };
        $this->defaultHeaders = $previousHeaders;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getExpiresTime() === 1) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    protected function makeUser(array $overrides = [], string $password = 'testpass'): array
    {
        $user = User::query()->create(array_merge([
            'username' => 'testuser',
            'email' => 'test@example.com',
            'display_name' => 'Test User',
            'hashed_password' => PasswordHasher::hash($password),
            'role' => 'user',
            'native_language' => 'es',
            'target_language' => 'en-US',
            'is_active' => true,
            'is_verified' => true,
            'created_at' => Carbon::now('UTC'),
        ], $overrides));
        UserLanguage::query()->create([
            'user_id' => $user->id,
            'target_language' => $user->target_language,
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
        $token = Jwt::createAccessToken($user->id, $user->role);

        return [$user, ['Authorization' => 'Bearer '.$token]];
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    protected function makeAdmin(): array
    {
        return $this->makeUser([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'native_language' => 'en',
        ], 'adminpass');
    }
}
