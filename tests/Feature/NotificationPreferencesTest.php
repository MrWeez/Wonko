<?php

use App\Actions\NotifyUpcomingSubscriptions;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionDueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('users can save a flexible reminder schedule and overdue settings', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('notificationReminders', [7, 5])
        ->set('notificationOverdue', true)
        ->set('notificationOverdueFrequency', 2)
        ->call('save')
        ->assertHasNoErrors();

    $stored = $user->fresh();

    expect($stored->notificationReminders())->toBe([7, 5])
        ->and($stored->notificationOverdue())->toBeTrue()
        ->and($stored->notificationOverdueFrequency())->toBe(2);
});

test('users can save an empty reminder schedule', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('notificationReminders', [])
        ->set('notificationOverdue', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->notificationReminders())->toBe([]);
});

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

test('users can configure notification timing and channel failure status is encrypted', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('notificationDaysBefore', 3)
        ->call('save')
        ->assertHasNoErrors();

    $user->fresh()->recordNotificationChannelFailure('telegram', 'Bot API unavailable');
    $stored = $user->fresh();

    expect($stored->notificationDaysBefore())->toBe(3)
        ->and($stored->notificationChannelFailureStatus()['telegram']['message'])->toBe('Bot API unavailable')
        ->and($stored->getRawOriginal('notification_channel_status'))->not->toContain('Bot API unavailable');

    $stored->clearNotificationChannelFailure('telegram');

    expect($stored->fresh()->notificationChannelFailureStatus())->toBe([]);
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

test('channel credentials are encrypted at rest and can be updated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('webhook', true)
        ->set('webhookUrl', 'https://example.test/wonko')
        ->set('webhookSecret', 'secret-value')
        ->set('telegramChatId', '-100123')
        ->set('telegramMessageThreadId', '42')
        ->call('save')
        ->assertHasNoErrors();

    $stored = $user->fresh();

    $configurations = $stored->notificationChannelConfigurations();

    expect($configurations['webhook']['url'])->toBe('https://example.test/wonko')
        ->and($configurations['webhook']['secret'])->toBe('secret-value')
        ->and($configurations['telegram']['chat_id'])->toBe('-100123')
        ->and($configurations['telegram']['message_thread_id'])->toBe(42);
    expect($stored->getRawOriginal('notification_channel_config'))->not->toContain('secret-value');
});

test('auto-post delivers the webhook payload with its configured secret', function () {
    Http::fake();

    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'webhook' => true]);
    $user->setNotificationChannelConfigurations([
        'webhook' => [
            'url' => 'https://example.test/wonko',
            'secret' => 'webhook-secret',
        ],
    ]);

    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'name' => 'Webhook plan',
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://example.test/wonko'
            && $request->header('X-Wonko-Webhook-Secret')[0] === 'webhook-secret'
            && $request['event'] === 'subscription_due'
            && $request['subscription']['name'] === 'Webhook plan';
    });
});

test('failed webhook delivery records a channel failure status', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://example.test/wonko' => Http::response(['error' => 'unavailable'], 500),
    ]);

    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'webhook' => true]);
    $user->setNotificationChannelConfigurations([
        'webhook' => ['url' => 'https://example.test/wonko'],
    ]);

    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    expect($user->fresh()->notificationChannelFailureStatus())
        ->toHaveKey('webhook');
});

test('notification includes email when the email channel is enabled', function () {
    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'email' => true]);
    $this->actingAs($user);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create();
    $occurrence = $subscription->occurrences()->create([
        'user_id' => $user->id,
        'currency' => 'EUR',
        'amount' => '10.00',
        'due_date' => now()->toDateString(),
    ]);

    $notification = new SubscriptionDueNotification($subscription, $occurrence);

    expect($notification->via($user))->toContain('mail')
        ->and($notification->toMail($user)->subject)->toBe('Subscription due: '.$subscription->name);
});

test('auto-post delivers a Telegram message to its configured topic', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/bot123:token/sendMessage' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'telegram' => true]);
    $user->setNotificationChannelConfigurations([
        'telegram' => [
            'bot_token' => '123:token',
            'chat_id' => '-100123',
            'message_thread_id' => 42,
        ],
    ]);

    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'name' => 'Telegram plan',
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.telegram.org/bot123:token/sendMessage'
            && $request['chat_id'] === '-100123'
            && $request['message_thread_id'] === 42
            && str_contains($request['text'], 'Telegram plan');
    });
});

test('auto-post delivers a Discord embed to its configured webhook', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://discord.example.test/hooks/wonko' => Http::response(['id' => 'message-id']),
    ]);

    $user = User::factory()->create();
    $user->setNotificationPreferences(['database' => false, 'discord' => true]);
    $user->setNotificationChannelConfigurations([
        'discord' => ['url' => 'https://discord.example.test/hooks/wonko'],
    ]);

    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    Subscription::factory()->forAccount($account)->create([
        'name' => 'Discord plan',
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://discord.example.test/hooks/wonko'
            && $request['username'] === 'Wonko'
            && $request['embeds'][0]['title'] === 'Subscription due: Discord plan'
            && str_ends_with($request['embeds'][0]['fields'][0]['value'], ' EUR');
    });
});

test('upcoming subscription notifications fire per reminder day and are idempotent', function () {
    $user = User::factory()->create();
    $user->setNotificationReminders([7, 3, 1]);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->addDays(3)->toDateString(),
        'auto_post' => false,
    ]);

    // Due in 3 days: the 7- and 3-day reminder dates have arrived, the 1-day one has not.
    $this->artisan('subscriptions:notify-upcoming')->assertExitCode(0);

    expect(DatabaseNotification::query()->count())->toBe(1);

    $state = $subscription->fresh()->notificationStateFor($subscription->next_due_date->toDateString());

    expect($state['days'])->toContain(7, 3)
        ->and($state['days'])->not->toContain(1);

    // Day before due: the 1-day reminder fires.
    (new NotifyUpcomingSubscriptions)->handle(CarbonImmutable::today()->addDays(2));

    expect(DatabaseNotification::query()->count())->toBe(2);

    // And running again never duplicates.
    (new NotifyUpcomingSubscriptions)->handle(CarbonImmutable::today()->addDays(3));

    expect(DatabaseNotification::query()->count())->toBe(2);
});

test('overdue notifications repeat on the configured frequency', function () {
    $user = User::factory()->create();
    $user->setNotificationOverdue(true);
    $user->setNotificationOverdueFrequency(3);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->subDay()->toDateString(),
        'auto_post' => false,
        'notification_reminders' => [],
    ]);

    $this->artisan('subscriptions:notify-upcoming')->assertExitCode(0);
    $this->artisan('subscriptions:notify-upcoming')->assertExitCode(0);

    expect(DatabaseNotification::query()->count())->toBe(1);

    // Three days later the nudge should fire again.
    (new NotifyUpcomingSubscriptions)->handle(CarbonImmutable::today()->addDays(3));

    expect(DatabaseNotification::query()->count())->toBe(2);
});

test('subscriptions can override the reminder schedule', function () {
    $user = User::factory()->create();
    $user->setNotificationReminders([7]);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    $overridden = Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->addDays(2)->toDateString(),
        'notification_reminders' => [2],
    ]);
    $plain = Subscription::factory()->forAccount($account)->create([
        'next_due_date' => now()->addDays(2)->toDateString(),
    ]);

    $this->artisan('subscriptions:notify-upcoming')->assertExitCode(0);

    expect(DatabaseNotification::query()->count())->toBe(2)
        ->and($overridden->fresh()->overdueEnabled())->toBeFalse();
});

test('subscription channel overrides take precedence over global preferences', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'notification_channel_overrides' => ['database' => false],
    ]);

    expect((new SubscriptionDueNotification($subscription))->via($user))->not->toContain('database');
});
