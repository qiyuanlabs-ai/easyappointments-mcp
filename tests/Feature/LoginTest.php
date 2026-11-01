<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guesses_against_one_account_are_limited_even_from_many_ips(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.test', 'password' => 'right-password-1']);

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                ->post('/login', ['email' => 'Anna@example.test', 'password' => "wrong-$i"])
                ->assertSessionHasErrors(['email' => 'Those details do not match an account.']);
        }

        // A new IP, and even the right password, now waits for the account's limit to pass.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', ['email' => 'anna@example.test', 'password' => 'right-password-1'])
            ->assertSessionHasErrors(['email' => 'Too many attempts. Try again in 10 minutes.']);
        $this->assertGuest();

        $this->travel(11)->minutes();
        $this->post('/login', ['email' => 'anna@example.test', 'password' => 'right-password-1'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_one_ip_is_limited_across_accounts(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post('/login', ['email' => "person$i@example.test", 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'someone@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Too many attempts. Try again in 1 minute.']);
    }

    public function test_strangers_cannot_lock_the_published_demo_account(): void
    {
        config(['app.demo_login' => ['email' => 'frontdesk@demo.test', 'password' => 'demo-password-1']]);
        $user = User::factory()->create(['email' => 'frontdesk@demo.test', 'password' => 'demo-password-1']);

        foreach (range(1, 25) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                ->post('/login', ['email' => 'frontdesk@demo.test', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', ['email' => 'frontdesk@demo.test', 'password' => 'demo-password-1'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
