<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Models\User;
use App\PaymentMethodType;
use Livewire\Livewire;

test('users can create subscriptions with custom periods and categories', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $category = Category::factory()->for($user)->create(['name' => 'Streaming']);

    Livewire::actingAs($user)
        ->test('pages::subscriptions')
        ->set('subscriptionName', 'Video service')
        ->set('subscriptionAccountId', $account->id)
        ->set('subscriptionCategoryId', $category->id)
        ->set('subscriptionCurrency', 'EUR')
        ->set('subscriptionAmount', '14.99')
        ->set('subscriptionPeriodCount', '2')
        ->set('subscriptionPeriodUnit', 'month')
        ->set('subscriptionNextDueDate', '2026-10-01')
        ->set('subscriptionAutoPost', true)
        ->call('saveSubscription')
        ->assertHasNoErrors();

    $subscription = Subscription::query()->firstOrFail();

    expect($subscription->category_id)->toBe($category->id)
        ->and($subscription->period_count)->toBe(2)
        ->and($subscription->auto_post)->toBeTrue()
        ->and($subscription->next_due_date->toDateString())->toBe('2026-10-01');
});

test('recording an occurrence preserves the override and posts an expense transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'name' => 'Cloud storage',
        'amount' => '9.99',
        'next_due_date' => '2026-10-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::subscriptions')
        ->call('startOccurrence', $subscription->id)
        ->set('occurrenceAmount', '12.49')
        ->set('occurrenceCurrency', 'EUR')
        ->set('occurrenceDueDate', '2026-10-01')
        ->set('occurrenceNotes', 'Annual price change')
        ->call('saveOccurrence')
        ->assertHasNoErrors();

    $occurrence = SubscriptionOccurrence::query()->firstOrFail();
    $transaction = Transaction::query()->findOrFail($occurrence->transaction_id);

    expect($occurrence->amount)->toBe('12.4900')
        ->and($occurrence->notes)->toBe('Annual price change')
        ->and($transaction->amount)->toBe('12.4900')
        ->and($transaction->type->value)->toBe('expense')
        ->and($subscription->fresh()->next_due_date->toDateString())->toBe('2026-11-01');
});

test('foreign currency occurrence requires explicit account amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create();

    Livewire::actingAs($user)
        ->test('pages::subscriptions')
        ->call('startOccurrence', $subscription->id)
        ->set('occurrenceAmount', '10')
        ->set('occurrenceCurrency', 'USD')
        ->set('occurrenceDueDate', now()->toDateString())
        ->call('saveOccurrence')
        ->assertHasErrors(['occurrenceAccountAmount']);
});

test('users cannot see another users subscriptions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();
    $subscription = Subscription::factory()->forAccount($account)->create(['name' => 'Private plan']);

    Livewire::actingAs($user)
        ->test('pages::subscriptions')
        ->assertDontSee('Private plan');

    expect(Subscription::query()->find($subscription->id))->toBeNull();
});

test('due auto-post subscriptions create one occurrence and are idempotent', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'name' => 'Automatic plan',
        'amount' => '5.00',
        'period_count' => 1,
        'period_unit' => 'month',
        'next_due_date' => '2026-08-01',
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')
        ->expectsOutputToContain('Posted 2 subscription occurrence(s).')
        ->assertExitCode(0);

    expect(SubscriptionOccurrence::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count())->toBe(2)
        ->and(Transaction::withoutGlobalScopes()->where('description', 'Automatic plan')->count())->toBe(2)
        ->and($subscription->fresh()->next_due_date->toDateString())->toBe('2026-10-01');

    $this->artisan('subscriptions:post-due')
        ->expectsOutputToContain('Posted 0 subscription occurrence(s).')
        ->assertExitCode(0);

    expect(SubscriptionOccurrence::withoutGlobalScopes()->where('subscription_id', $subscription->id)->count())->toBe(2);
});

test('due auto-post subscriptions with composite payment method generate split transactions', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create(['name' => 'PayPal', 'currency' => 'EUR']);
    $backup = Account::factory()->for($user)->create(['name' => 'Bank Account', 'currency' => 'EUR']);

    // Seed 10 EUR on PayPal
    Transaction::factory()->forAccount($primary)->income()->create(['amount' => '10.0000']);

    $method = PaymentMethod::factory()->for($user)->create([
        'name' => 'PayPal Composite',
        'type' => PaymentMethodType::Composite,
        'primary_account_id' => $primary->id,
        'secondary_account_id' => $backup->id,
        'auto_split_balance' => true,
    ]);

    $subscription = Subscription::factory()->forAccount($primary)->create([
        'name' => 'Netflix',
        'payment_method_id' => $method->id,
        'currency' => 'EUR',
        'amount' => '25.00',
        'period_count' => 1,
        'period_unit' => 'month',
        'next_due_date' => '2026-09-01',
        'auto_post' => true,
    ]);

    $this->artisan('subscriptions:post-due')
        ->expectsOutputToContain('Posted 1 subscription occurrence(s).')
        ->assertExitCode(0);

    // 1 income (10 EUR) + 1 primary split expense (10 EUR) + 1 secondary split expense (15 EUR)
    $expenses = Transaction::withoutGlobalScopes()->where('type', 'expense')->orderBy('id')->get();

    expect($expenses)->toHaveCount(2)
        ->and($expenses[0]->account_id)->toBe($primary->id)
        ->and($expenses[0]->amount)->toBe('10.0000')
        ->and($expenses[1]->account_id)->toBe($backup->id)
        ->and($expenses[1]->amount)->toBe('15.0000')
        ->and($expenses[0]->split_group_id)->toBe($expenses[1]->split_group_id)
        ->and((float) $primary->fresh()->balance())->toBe(0.0)
        ->and((float) $backup->fresh()->balance())->toBe(-15.0);
});

test('deleting a subscription payment transaction also removes its occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'name' => 'Spotify',
        'amount' => '9.99',
        'currency' => 'EUR',
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('quickConfirmSubscription', $subscription->id)
        ->assertHasNoErrors();

    $occurrence = SubscriptionOccurrence::query()->withoutGlobalScopes()->firstOrFail();
    $transactionId = $occurrence->transaction_id;

    expect($occurrence->transaction_id)->not->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->call('deleteTransaction', $transactionId)
        ->assertHasNoErrors();

    expect(Transaction::withoutGlobalScopes()->find($transactionId))->toBeNull()
        ->and(SubscriptionOccurrence::withoutGlobalScopes()->find($occurrence->id))->toBeNull();
});

test('subscriptions can override the default notification schedule', function () {
    $user = User::factory()->create();
    $user->setNotificationReminders([7]);
    $user->setNotificationOverdue(true);
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Livewire::actingAs($user)
        ->test('pages::subscriptions')
        ->set('subscriptionName', 'Custom')
        ->set('subscriptionAccountId', $account->id)
        ->set('subscriptionCurrency', 'EUR')
        ->set('subscriptionAmount', '10.00')
        ->set('subscriptionNextDueDate', now()->addMonth()->toDateString())
        ->set('subscriptionOverrideNotifications', true)
        ->set('subscriptionReminders', [2])
        ->set('subscriptionOverdue', false)
        ->set('subscriptionOverdueFrequency', 5)
        ->call('saveSubscription')
        ->assertHasNoErrors();

    $subscription = Subscription::query()->where('name', 'Custom')->firstOrFail();

    expect($subscription->reminderDays())->toBe([2])
        ->and($subscription->overdueEnabled())->toBeFalse()
        ->and($subscription->overdueFrequency())->toBe(5);

    // A subscription without overrides falls back to the user's defaults.
    $plain = Subscription::factory()->forAccount($account)->create();

    expect($plain->reminderDays())->toBe([7])
        ->and($plain->overdueEnabled())->toBeTrue();
});
