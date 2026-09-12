<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Models\User;
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
