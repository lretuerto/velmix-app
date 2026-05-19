<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SessionAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_operator_can_login_and_logout_with_laravel_session(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $userId = (int) DB::table('users')->where('email', 'pos-smoke@velmix.test')->value('id');

        $login = $this->postJson('/auth/session/login', [
            'email' => 'pos-smoke@velmix.test',
            'password' => 'pos-smoke-local-only',
            'tenant' => 'botica-central',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('data.auth.authenticated', true)
            ->assertJsonPath('data.auth.mode', 'session')
            ->assertJsonPath('data.auth.user.email', 'pos-smoke@velmix.test')
            ->assertJsonPath('data.tenant.selected.code', 'botica-central');

        $this->assertAuthenticatedAs(\App\Models\User::query()->findOrFail($userId));

        $this->postJson('/auth/session/logout')
            ->assertOk()
            ->assertJsonPath('data.status', 'logged_out');

        $this->assertGuest();
    }

    public function test_login_rejects_invalid_credentials_without_creating_session(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $this->postJson('/auth/session/login', [
            'email' => 'pos-smoke@velmix.test',
            'password' => 'wrong-password',
            'tenant' => 'botica-central',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_login_rate_limit_is_scoped_by_email_fingerprint_and_ip(): void
    {
        $email = 'rate-limit-'.bin2hex(random_bytes(4)).'@velmix.test';
        $limiterKey = implode(':', [
            'frontend-session-login',
            sha1(strtolower($email)),
            '127.0.0.1',
        ]);
        RateLimiter::clear($limiterKey);

        $payload = [
            'email' => $email,
            'password' => 'not-a-real-password',
            'tenant' => 'botica-central',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/auth/session/login', $payload)
                ->assertUnprocessable();
        }

        $this->postJson('/auth/session/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many login attempts. Please wait before trying again.');

        $this->assertGuest();
        RateLimiter::clear($limiterKey);
    }
}
