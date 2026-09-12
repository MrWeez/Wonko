<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\PaymentMethodType;
use App\TransferDirection;
use Illuminate\Support\Str;
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

test('users can create custom split transactions across multiple accounts', function () {
    $user = User::factory()->create();
    $accountA = Account::factory()->for($user)->create(['name' => 'Account A', 'currency' => 'EUR']);
    $accountB = Account::factory()->for($user)->create(['name' => 'Account B', 'currency' => 'EUR']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionType', 'expense')
        ->set('paymentSourceMode', 'split')
        ->set('transactionAccountId', $accountA->id)
        ->set('splitSecondaryAccountId', $accountB->id)
        ->set('transactionCurrency', 'EUR')
        ->set('transactionAmount', '50.00')
        ->set('splitPrimaryAmount', '20.00')
        ->set('splitSecondaryAmount', '30.00')
        ->set('transactionDescription', 'Dinner split')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $transactions = Transaction::query()->orderBy('id')->get();

    expect($transactions)->toHaveCount(2)
        ->and($transactions[0]->account_id)->toBe($accountA->id)
        ->and($transactions[0]->amount)->toBe('20.0000')
        ->and($transactions[1]->account_id)->toBe($accountB->id)
        ->and($transactions[1]->amount)->toBe('30.0000')
        ->and($transactions[0]->split_group_id)->not->toBeNull()
        ->and($transactions[0]->split_group_id)->toBe($transactions[1]->split_group_id)
        ->and((float) $accountA->fresh()->balance())->toBe(-20.0)
        ->and((float) $accountB->fresh()->balance())->toBe(-30.0);
});

test('split transactions validate that split amounts sum to total amount', function () {
    $user = User::factory()->create();
    $accountA = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $accountB = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionType', 'expense')
        ->set('paymentSourceMode', 'split')
        ->set('transactionAccountId', $accountA->id)
        ->set('splitSecondaryAccountId', $accountB->id)
        ->set('transactionCurrency', 'EUR')
        ->set('transactionAmount', '50.00')
        ->set('splitPrimaryAmount', '20.00')
        ->set('splitSecondaryAmount', '20.00') // sum is 40 != 50
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasErrors(['splitPrimaryAmount']);
});

test('composite payment method automatically splits transaction based on primary account balance', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create(['name' => 'PayPal', 'currency' => 'USD']);
    $backup = Account::factory()->for($user)->create(['name' => 'Credit Card', 'currency' => 'USD']);

    // Seed $15 into PayPal balance
    Transaction::factory()->forAccount($primary)->income()->create(['amount' => '15.0000']);

    $method = PaymentMethod::factory()->for($user)->create([
        'name' => 'PayPal Wallet',
        'type' => PaymentMethodType::Composite,
        'primary_account_id' => $primary->id,
        'secondary_account_id' => $backup->id,
        'auto_split_balance' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->set('transactionType', 'expense')
        ->set('paymentSourceMode', 'payment_method')
        ->set('paymentMethodId', $method->id)
        ->set('transactionAmount', '50.00')
        ->set('transactionDate', now()->toDateString())
        ->call('saveTransaction')
        ->assertHasNoErrors();

    // 1 income + 2 split expenses = 3 transactions
    $splits = Transaction::query()->where('type', 'expense')->orderBy('id')->get();

    expect($splits)->toHaveCount(2)
        ->and($splits[0]->account_id)->toBe($primary->id)
        ->and($splits[0]->amount)->toBe('15.0000')
        ->and($splits[1]->account_id)->toBe($backup->id)
        ->and($splits[1]->amount)->toBe('35.0000')
        ->and($splits[0]->payment_method_id)->toBe($method->id)
        ->and((float) $primary->fresh()->balance())->toBe(0.0)
        ->and((float) $backup->fresh()->balance())->toBe(-35.0);
});

test('deleting a split transaction removes all linked split records', function () {
    $user = User::factory()->create();
    $accountA = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $accountB = Account::factory()->for($user)->create(['currency' => 'EUR']);

    $splitId = (string) Str::uuid();

    $t1 = Transaction::factory()->forAccount($accountA)->expense()->create([
        'split_group_id' => $splitId,
        'amount' => '20.0000',
    ]);
    Transaction::factory()->forAccount($accountB)->expense()->create([
        'split_group_id' => $splitId,
        'amount' => '30.0000',
    ]);

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->call('deleteTransaction', $t1->id);

    expect(Transaction::query()->where('split_group_id', $splitId)->count())->toBe(0)
        ->and((float) $accountA->fresh()->balance())->toBe(0.0)
        ->and((float) $accountB->fresh()->balance())->toBe(0.0);
});
