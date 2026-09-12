<?php

use App\Models\User;

test('a locked user cannot log in with their password', function () {
    $user = User::factory()->create();
    $user->lock();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrorsIn('email');
    $this->assertGuest();
});

test('an unlocked user can still log in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('a user can be locked and unlocked through the model', function () {
    $user = User::factory()->create();

    expect($user->isLocked())->toBeFalse();

    $user->lock();
    expect($user->fresh()->isLocked())->toBeTrue();

    $user->unlock();
    expect($user->fresh()->isLocked())->toBeFalse();
});
