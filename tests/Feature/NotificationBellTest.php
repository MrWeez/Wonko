<?php

use App\Models\User;
use App\Notifications\SubscriptionDueNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

function bellNotification(string $name): array
{
    return [
        'id' => (string) Str::uuid(),
        'type' => SubscriptionDueNotification::class,
        'data' => [
            'type' => 'subscription_due',
            'subscription_name' => $name,
            'amount' => '12.00',
            'currency' => 'EUR',
            'due_date' => now()->addDay()->toDateString(),
        ],
    ];
}

test('the bell shows unread notifications and a badge', function () {
    $user = User::factory()->create();
    $user->notifications()->create(bellNotification('Netflix'));
    $user->notifications()->create(bellNotification('Spotify'));

    Livewire::actingAs($user)
        ->test('notification-bell')
        ->assertSee('Netflix')
        ->assertSee('Spotify')
        ->assertSee('notification-badge')
        ->assertDontSee('You are all caught up.');
});

test('the bell shows an empty state without unread notifications', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('notification-bell')
        ->assertSee('You are all caught up.')
        ->assertDontSee('notification-badge');
});

test('the bell only shows the current user notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $other->notifications()->create(bellNotification('OtherPlan'));

    Livewire::actingAs($user)
        ->test('notification-bell')
        ->assertDontSee('OtherPlan');
});

test('marking all notifications as read clears the badge', function () {
    $user = User::factory()->create();
    $user->notifications()->create(bellNotification('Netflix'));
    $user->notifications()->create(bellNotification('Spotify'));

    Livewire::actingAs($user)
        ->test('notification-bell')
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0)
        ->assertSee('You are all caught up.');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('opening a notification marks it as read and leaves the rest unread', function () {
    $user = User::factory()->create();
    $user->notifications()->create(bellNotification('Netflix'));
    $kept = $user->notifications()->create(bellNotification('Spotify'));

    Livewire::actingAs($user)
        ->test('notification-bell')
        ->call('markAsRead', $kept->id)
        ->assertRedirect(route('subscriptions.index'));

    expect($kept->fresh()->read_at)->not->toBeNull()
        ->and($user->fresh()->unreadNotifications()->count())->toBe(1);
});
