<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesApiUser
{
    protected function apiUser(Request $request): User
    {
        $user = $request->attributes->get('user');
        if (! $user instanceof User) {
            throw new ApiException('Not authenticated', 401);
        }

        return $user;
    }
}
