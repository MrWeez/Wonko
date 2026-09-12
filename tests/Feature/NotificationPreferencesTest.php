<?php

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

test('users can update notification channel preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertSee('Notification settings');

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('database', false)
        ->set('email', true)
        ->set('discord', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->notificationPreferences())->toMatchArray([
        'database' => false,
        'email' => true,
        'discord' => true,
    ]);
});

test('auto-post creates an in-app notification when the database channel is enabled', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'name' => 'Notify me',
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    $notification = DatabaseNotification::query()->firstOrFail();

    expect($notification->notifiable_id)->toBe($user->id)
        ->and($notification->data['type'])->toBe('subscription_due')
        ->and($notification->data['subscription_name'])->toBe('Notify me');
});

test('auto-post does not create an in-app notification when disabled', function () {
    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false]);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    expect(DatabaseNotification::query()->count())->toBe(0);
});
