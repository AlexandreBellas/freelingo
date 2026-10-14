<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Security\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            throw new ApiException('Not authenticated', 401);
        }
        $token = trim(substr($header, 7));
        try {
            $payload = Jwt::decode($token);
            $userId = (int) $payload['sub'];
        } catch (ApiException $e) {
            throw new ApiException('Invalid token', 401);
        }
        $user = User::query()->find($userId);
        if (! $user || ! $user->is_active) {
            throw new ApiException('User not found or inactive', 401);
        }
        $request->attributes->set('user', $user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
