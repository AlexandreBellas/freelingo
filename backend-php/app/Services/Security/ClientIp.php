<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

class ClientIp
{
    public static function fromRequest(Request $request): string
    {
        return self::resolve(
            $request->headers->get('X-Real-IP'),
            $request->headers->get('X-Forwarded-For'),
            $request->ip(),
        );
    }

    public static function resolve(?string $realIp, ?string $forwardedFor, ?string $clientHost): string
    {
        if ($realIp !== null && $realIp !== '') {
            return $realIp;
        }
        if ($forwardedFor !== null && trim($forwardedFor) !== '') {
            return trim(explode(',', $forwardedFor)[0]);
        }

        return ($clientHost !== null && $clientHost !== '') ? $clientHost : 'unknown';
    }
}
