<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('unverified users are redirected to email verification', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('users with a forced password reset are redirected to security settings', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});
