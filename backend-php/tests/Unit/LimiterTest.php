<?php

namespace Tests\Unit;

use App\Services\Security\ClientIp;
use App\Support\Settings;
use Tests\TestCase;

class LimiterTest extends TestCase
{
    public function test_x_real_ip_wins_over_forwarded_for(): void
    {
        $this->assertSame('10.0.0.1', ClientIp::resolve('10.0.0.1', '192.168.1.1', '127.0.0.1'));
    }

    public function test_forwarded_for_uses_the_first_address(): void
    {
        $this->assertSame('192.168.1.100', ClientIp::resolve(null, '192.168.1.100', null));
        $this->assertSame(
            '192.168.1.100',
            ClientIp::resolve(null, '192.168.1.100, 10.0.0.2, 172.16.0.1', null),
        );
        $this->assertSame('10.0.0.1', ClientIp::resolve(null, '  10.0.0.1 , 192.168.1.1  ', null));
    }

    public function test_missing_headers_fall_back_to_the_client_or_unknown(): void
    {
        $this->assertSame('127.0.0.1', ClientIp::resolve(null, null, '127.0.0.1'));
        $this->assertSame('unknown', ClientIp::resolve(null, '   ', null));
        $this->assertSame('unknown', ClientIp::resolve('', '', ''));
    }

    public function test_limiter_is_disabled_while_testing(): void
    {
        $this->assertFalse(Settings::bool('rate_limit_enabled'));
    }
}
