<?php

namespace Tests\Feature\Common;

use App\Providers\RouteServiceProvider;
use Database\Seeders\Roles\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions required for user registration
        $this->seed(RolesSeeder::class);
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        \Illuminate\Support\Facades\Cache::put('register_email_code:'.md5('test@example.com'), ['type'=>'email','email'=>'test@example.com','code'=>'123456','expires_at'=>now()->addMinutes(5)->toIso8601String()],300);
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'email_code' => '123456',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }
}
