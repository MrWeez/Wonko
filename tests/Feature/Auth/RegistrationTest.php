<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('registration marks users verified when email verification is disabled', function () {
    config(['auth.email_verification_required' => false]);
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'No Mail User',
        'email' => 'no-mail@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'no-mail@example.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});
