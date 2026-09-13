<?php

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionDueNotification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use NotificationChannels\WebPush\WebPushChannel;

function webPushKeyPair(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

    if (! $key) {
        throw new RuntimeException('Unable to generate an EC test key.');
    }

    $details = openssl_pkey_get_details($key);

    if (! $details) {
        throw new RuntimeException('Unable to read the EC test key.');
    }

    $public = "\x04"
        .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
        .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return [
        'p256dh' => base64_encode($public),
        'auth' => base64_encode(random_bytes(16)),
    ];
}

test('users can subscribe a device and the push keys are encrypted at rest', function () {
    $user = User::factory()->create();
    $keys = webPushKeyPair();
    $endpoint = 'https://example.test/push/device-a';

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->call('subscribeDevice', $endpoint, $keys['p256dh'], $keys['auth'])
        ->assertHasNoErrors();

    $user = $user->fresh();
    $subscription = $user->pushSubscriptions()->firstOrFail();

    expect($user->notificationPreferences()['browser'])->toBeTrue()
        ->and($subscription->endpoint)->toBe($endpoint)
        ->and($subscription->public_key)->toBe($keys['p256dh'])
        ->and($subscription->auth_token)->toBe($keys['auth'])
        ->and($subscription->getRawOriginal('public_key'))->not->toBe($keys['p256dh'])
        ->and($subscription->getRawOriginal('auth_token'))->not->toBe($keys['auth']);
});

test('users can unsubscribe a device', function () {
    $user = User::factory()->create();
    $keys = webPushKeyPair();

    $user->updatePushSubscription('https://example.test/push/a', $keys['p256dh'], $keys['auth']);
    $user->updatePushSubscription('https://example.test/push/b', $keys['p256dh'], $keys['auth']);

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->call('unsubscribeDevice', 'https://example.test/push/a')
        ->assertHasNoErrors();

    $remaining = $user->fresh()->pushSubscriptions()->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->endpoint)->toBe('https://example.test/push/b');
});

test('the browser channel is used only when the preference is enabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create();

    expect((new SubscriptionDueNotification($subscription))->via($user))->not->toContain(WebPushChannel::class);

    $user->setNotificationPreferences(['browser' => true]);

    expect((new SubscriptionDueNotification($subscription))->via($user))->toContain(WebPushChannel::class);
});

test('auto-post delivers a web push to every subscribed device', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://example.test/push*' => Http::response(['ok' => true], 201),
    ]);

    $keys = webPushKeyPair();
    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'browser' => true]);
    $user->updatePushSubscription('https://example.test/push/device-1', $keys['p256dh'], $keys['auth']);
    $user->updatePushSubscription('https://example.test/push/device-2', $keys['p256dh'], $keys['auth']);

    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'name' => 'Push plan',
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        return str_starts_with($request->url(), 'https://example.test/push/device-')
            && $request->header('Content-Encoding')[0] === 'aes128gcm'
            && $request->header('TTL')[0] === '86400'
            && $request->body() !== '';
    });
});

test('failed web push delivery records a channel status and removes expired subscriptions', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://example.test/push/expired' => Http::response(['ok' => false], 410),
    ]);

    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'browser' => true]);
    $user->updatePushSubscription('https://example.test/push/expired', ...array_values(webPushKeyPair()));

    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    expect($user->fresh()->pushSubscriptions()->count())->toBe(0)
        ->and($user->fresh()->notificationChannelFailureStatus())->toHaveKey('browser');
});
