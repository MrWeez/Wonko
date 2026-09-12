<?php

use App\Models\User;
use Illuminate\Support\Env;

test('registration routes are not available when registration is disabled', function () {
    $_ENV['REGISTRATION_ENABLED'] = 'false';
    $_SERVER['REGISTRATION_ENABLED'] = 'false';
    putenv('REGISTRATION_ENABLED=false');
    Env::getRepository()->set('REGISTRATION_ENABLED', 'false');

    try {
        $this->refreshApplication();
        $this->artisan('migrate:fresh');

        User::factory()->create();

        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    } finally {
        unset($_ENV['REGISTRATION_ENABLED'], $_SERVER['REGISTRATION_ENABLED']);
        putenv('REGISTRATION_ENABLED');
        Env::getRepository()->clear('REGISTRATION_ENABLED');
    }
});

test('existing users can still log in when registration is disabled', function () {
    $_ENV['REGISTRATION_ENABLED'] = 'false';
    $_SERVER['REGISTRATION_ENABLED'] = 'false';
    putenv('REGISTRATION_ENABLED=false');
    Env::getRepository()->set('REGISTRATION_ENABLED', 'false');

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
        unset($_ENV['REGISTRATION_ENABLED'], $_SERVER['REGISTRATION_ENABLED']);
        putenv('REGISTRATION_ENABLED');
        Env::getRepository()->clear('REGISTRATION_ENABLED');
    }
});

test('the application refuses to run when registration is disabled and no users exist', function () {
    config(['auth.registration_enabled' => false]);

    $this->get('/login')->assertStatus(503);
});
