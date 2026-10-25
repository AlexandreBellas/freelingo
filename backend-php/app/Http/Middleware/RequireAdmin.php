<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->attributes->get('user');
        if (! $user || $user->role !== 'admin') {
            throw new ApiException('Admin access required', 403);
        }

        return $next($request);
    }
}
