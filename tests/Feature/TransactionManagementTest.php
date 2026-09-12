<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('account balance is derived from income and expense transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Transaction::factory()->forAccount($account)->income()->create([
        'amount' => '125.50',
        'account_amount' => null,
    ]);
    Transaction::factory()->forAccount($account)->expense()->create([
        'amount' => '25.25',
        'account_amount' => null,
    ]);

    $this->actingAs($user);

    expect((float) $account->fresh()->balance())->toBe(100.25);
});

test('account balance uses explicit account amount for foreign currency transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Transaction::factory()->forAccount($account)->expense()->create([
        'currency' => 'USD',
        'amount' => '10.00',
        'account_amount' => '9.20',
    ]);

    $this->actingAs($user);

    expect((float) $account->fresh()->balance())->toBe(-9.2);
});

test('users can create transactions without automatic currency conversion', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionAccountId', $account->id)
        ->set('transactionType', 'expense')
        ->set('transactionDescription', 'Coffee')
        ->set('transactionCurrency', 'USD')
        ->set('transactionAmount', '10.00')
        ->set('transactionAccountAmount', '9.20')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $transaction = Transaction::query()->firstOrFail();

    expect($transaction->currency)->toBe('USD')
        ->and($transaction->amount)->toBe('10.0000')
        ->and($transaction->account_amount)->toBe('9.2000');
});

test('foreign currency transactions require an explicit account amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionAccountId', $account->id)
        ->set('transactionType', 'expense')
        ->set('transactionCurrency', 'USD')
        ->set('transactionAmount', '10.00')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasErrors(['transactionAccountAmount']);
});

test('transaction records are isolated by user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();
    $transaction = Transaction::factory()->forAccount($account)->create();

    $this->actingAs($user);

    expect(Transaction::query()->find($transaction->id))->toBeNull();
});
