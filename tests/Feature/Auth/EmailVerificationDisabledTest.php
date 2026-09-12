<?php

use App\Models\User;

test('unverified users can access protected pages when verification is disabled', function () {
    $_SERVER['EMAIL_VERIFICATION_REQUIRED'] = '';

    try {
        $this->refreshApplication();
        $this->artisan('migrate:fresh');

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    } finally {
        unset($_SERVER['EMAIL_VERIFICATION_REQUIRED']);
    }
});
