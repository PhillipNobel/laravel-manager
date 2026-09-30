<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

it('redirects guests to login when they open an authenticated page', function () {
    $this->get(route('apps.index'))
        ->assertRedirect(route('login'));

    $this->get(route('server'))
        ->assertRedirect(route('login'));

    $this->get(route('settings.github.repositories'))
        ->assertRedirect(route('login'));

    $this->post(route('settings.github.disconnect'))
        ->assertRedirect(route('login'));
});

it('allows an administrator to sign in', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ])
        ->assertRedirect(route('apps.index'));

    $this->assertAuthenticatedAs($user);
});

it('regenerates the session after successful login', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);
    $this->withSession(['pre-login-value' => 'present']);
    $sessionId = session()->getId();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertRedirect(route('apps.index'));

    expect(session()->getId())->not->toBe($sessionId);
});

it('limits repeated failed login attempts by normalized email and IP', function () {
    $key = 'admin@example.com|127.0.0.1';
    RateLimiter::clear($key);

    foreach (range(1, 5) as $_) {
        $this->post(route('login.store'), [
            'email' => 'Admin@Example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
    }

    $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Too many login attempts');

    RateLimiter::clear($key);
});

it('shows a useful error for invalid credentials', function () {
    $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
});

it('logs an administrator out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
