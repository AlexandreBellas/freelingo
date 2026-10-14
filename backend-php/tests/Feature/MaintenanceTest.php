<?php

namespace Tests\Feature;

use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    public function test_maintenance_mode_round_trip(): void
    {
        [, $admin] = $this->makeAdmin();
        [, $user] = $this->makeUser();

        $this->api('GET', '/api/admin/maintenance', [], $admin)
            ->assertOk()
            ->assertExactJson(['maintenance_mode' => false]);

        $this->api('PATCH', '/api/admin/maintenance', [], $admin)
            ->assertOk()
            ->assertExactJson(['maintenance_mode' => true]);
        $this->api('PATCH', '/api/admin/maintenance', [], $admin)
            ->assertExactJson(['maintenance_mode' => false]);

        $this->api('PUT', '/api/admin/maintenance', ['maintenance_mode' => true], $admin)
            ->assertExactJson(['maintenance_mode' => true]);
        $this->api('PUT', '/api/admin/maintenance', ['maintenance_mode' => true], $admin)
            ->assertExactJson(['maintenance_mode' => true]);
        $this->api('PUT', '/api/admin/maintenance', ['maintenance_mode' => false], $admin)
            ->assertExactJson(['maintenance_mode' => false]);

        $this->api('GET', '/api/admin/maintenance', [], $user)->assertForbidden();
        $this->api('PATCH', '/api/admin/maintenance', [], $user)->assertForbidden();
        $this->api('PUT', '/api/admin/maintenance', ['maintenance_mode' => true], $user)->assertForbidden();
        $this->api('GET', '/api/admin/maintenance')->assertUnauthorized();
        $this->api('PATCH', '/api/admin/maintenance')->assertUnauthorized();
        $this->api('PUT', '/api/admin/maintenance', ['maintenance_mode' => true])->assertUnauthorized();
    }
}
