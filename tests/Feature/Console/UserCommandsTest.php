<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('user:create creates a user, hashed password, optionally verified', function () {
    $this->artisan('user:create', [
        '--name' => 'Alice',
        '--email' => 'alice@example.com',
        '--password' => 'secret123',
        '--verify' => true,
    ])->assertExitCode(0);

    $user = User::where('email', 'alice@example.com')->first();

    expect($user)->not->toBeNull();
    expect(Hash::check('secret123', $user->password))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
});

test('user:create rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'bob@example.com']);

    $this->artisan('user:create', [
        '--name' => 'Bob',
        '--email' => 'bob@example.com',
        '--password' => 'secret123',
    ])->assertExitCode(1);

    expect(User::where('email', 'bob@example.com')->count())->toBe(1);
});

test('user:list outputs the registered users', function () {
    User::factory()->create(['name' => 'Carol', 'email' => 'carol@example.com']);

    $this->artisan('user:list')
        ->expectsOutputToContain('carol@example.com')
        ->assertExitCode(0);
});

test('user:reset-password forces a new password', function () {
    $user = User::factory()->create();

    $this->artisan('user:reset-password', [
        'user' => $user->email,
        '--password' => 'new-secret',
    ])->assertExitCode(0);

    expect(Hash::check('new-secret', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->must_change_password)->toBeTrue();
});

test('user:lock and user:unlock toggle account access', function () {
    $user = User::factory()->create();

    $this->artisan('user:lock', ['user' => $user->id])->assertExitCode(0);
    expect($user->fresh()->isLocked())->toBeTrue();

    $this->artisan('user:unlock', ['user' => $user->id])->assertExitCode(0);
    expect($user->fresh()->isLocked())->toBeFalse();
});

test('user:delete removes the user', function () {
    $user = User::factory()->create();

    $this->artisan('user:delete', ['user' => $user->id])->assertExitCode(0);

    expect(User::find($user->id))->toBeNull();
});

test('user:delete with --anonymize keeps the record but strips identity', function () {
    $user = User::factory()->create();

    $this->artisan('user:delete', ['user' => $user->email, '--anonymize' => true])->assertExitCode(0);

    $anonymized = $user->fresh();

    expect($anonymized)->not->toBeNull();
    expect($anonymized->name)->toBe('Anonymized User');
    expect($anonymized->email)->not->toBe($user->email);
    expect((string) str($anonymized->email))->toContain('anonymized');
});

test('user commands fail for an unknown user', function () {
    $this->artisan('user:lock', ['user' => 'nobody@example.com'])->assertExitCode(1);
    $this->artisan('user:reset-password', ['user' => 999])->assertExitCode(1);
    $this->artisan('user:delete', ['user' => 999])->assertExitCode(1);
});
