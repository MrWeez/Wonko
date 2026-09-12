<?php

use App\Models\User;

test('registration routes are not available when registration is disabled', function () {
    $_SERVER['REGISTRATION_ENABLED'] = '';

    try {
        $this->refreshApplication();
        $this->artisan('migrate:fresh');

        User::factory()->create();

        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    } finally {
        unset($_SERVER['REGISTRATION_ENABLED']);
    }
});

test('existing users can still log in when registration is disabled', function () {
    $_SERVER['REGISTRATION_ENABLED'] = '';
    try {
        $this->refreshApplication();
        $this->artisan('migrate:fresh');

        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    } finally {
        unset($_SERVER['REGISTRATION_ENABLED']);
    }
});

test('the application refuses to run when registration is disabled and no users exist', function () {
    config(['auth.registration_enabled' => false]);

    $this->get('/login')->assertStatus(503);
});
