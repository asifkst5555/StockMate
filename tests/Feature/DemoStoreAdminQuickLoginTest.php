<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class DemoStoreAdminQuickLoginTest extends TestCase
{
    public function test_demo_quick_login_is_disabled_by_default(): void
    {
        config(['app.demo_quick_login_enabled' => false]);

        $this->getJson('/api/front-cms')
            ->assertStatus(200)
            ->assertJsonPath('data.value.demo_quick_login_enabled', false);

        $this->postJson('/demo-quick-login')
            ->assertStatus(404);
    }

    public function test_demo_quick_login_authenticates_only_configured_store_admin_and_ignores_request_params(): void
    {
        config([
            'app.demo_quick_login_enabled' => true,
            'app.demo_store_admin_email' => 'admin@stockmate.com',
        ]);

        $this->getJson('/api/front-cms')
            ->assertStatus(200)
            ->assertJsonPath('data.value.demo_quick_login_enabled', true);

        // Attempt to hijack via arbitrary email/role parameters must be ignored
        $response = $this->postJson('/demo-quick-login', [
            'email' => 'superadmin@stockmate.com',
            'role' => 'superadmin',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'admin@stockmate.com')
            ->assertJsonPath('data.roles', Role::ADMIN);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // Token must work on protected Store Admin endpoints
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/front-setting')
            ->assertStatus(200);
    }

    public function test_demo_quick_login_explicitly_rejects_super_admin_target(): void
    {
        config([
            'app.demo_quick_login_enabled' => true,
            'app.demo_store_admin_email' => 'superadmin@stockmate.com',
        ]);

        $this->postJson('/demo-quick-login')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
