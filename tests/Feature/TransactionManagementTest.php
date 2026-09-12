<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransferDirection;
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

test('transfers create linked source and destination transactions with independent amounts', function () {
    $user = User::factory()->create();
    $source = Account::factory()->for($user)->create(['name' => 'EUR source', 'currency' => 'EUR']);
    $destination = Account::factory()->for($user)->create(['name' => 'USD destination', 'currency' => 'USD']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionAccountId', $source->id)
        ->set('transactionType', 'transfer')
        ->set('transactionDescription', 'Savings transfer')
        ->set('transactionCurrency', 'EUR')
        ->set('transactionAmount', '100.00')
        ->set('transferDestinationAccountId', $destination->id)
        ->set('transferDestinationCurrency', 'USD')
        ->set('transferDestinationAmount', '110.00')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $transfers = Transaction::query()->orderBy('id')->get();

    expect($transfers)->toHaveCount(2)
        ->and($transfers[0]->transfer_direction)->toBe(TransferDirection::Source)
        ->and($transfers[1]->transfer_direction)->toBe(TransferDirection::Destination)
        ->and($transfers[0]->related_transaction_id)->toBe($transfers[1]->id)
        ->and($transfers[1]->related_transaction_id)->toBe($transfers[0]->id)
        ->and((float) $source->fresh()->balance())->toBe(-100.0)
        ->and((float) $destination->fresh()->balance())->toBe(110.0);
});

test('cross-currency transfers require explicit account amounts on both sides', function () {
    $user = User::factory()->create();
    $source = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $destination = Account::factory()->for($user)->create(['currency' => 'GBP']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionAccountId', $source->id)
        ->set('transactionType', 'transfer')
        ->set('transactionCurrency', 'USD')
        ->set('transactionAmount', '100.00')
        ->set('transferDestinationAccountId', $destination->id)
        ->set('transferDestinationCurrency', 'CHF')
        ->set('transferDestinationAmount', '90.00')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasErrors(['transactionAccountAmount', 'transferDestinationAccountAmount']);
});

test('transfers cannot use the same source and destination account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionAccountId', $account->id)
        ->set('transactionType', 'transfer')
        ->set('transactionCurrency', $account->currency)
        ->set('transactionAmount', '10.00')
        ->set('transferDestinationAccountId', $account->id)
        ->set('transferDestinationCurrency', $account->currency)
        ->set('transferDestinationAmount', '10.00')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasErrors(['transferDestinationAccountId']);
});

test('income and expense transactions can be assigned to owned categories', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $category = Category::factory()->for($user)->create(['name' => 'Food']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionAccountId', $account->id)
        ->set('transactionType', 'expense')
        ->set('transactionCategoryId', $category->id)
        ->set('transactionCurrency', 'EUR')
        ->set('transactionAmount', '12.50')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $transaction = Transaction::query()->firstOrFail();

    expect($transaction->category_id)->toBe($category->id);

    $category->delete();

    expect($transaction->fresh()->category_id)->toBeNull();
});
