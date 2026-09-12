<?php

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\PaymentMethodType;
use Livewire\Livewire;

test('users can create a composite payment method with primary and backup accounts', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create(['name' => 'PayPal Balance', 'currency' => 'USD']);
    $backup = Account::factory()->for($user)->create(['name' => 'Visa Card', 'currency' => 'USD']);

    Livewire::actingAs($user)
        ->test('pages::payment-methods')
        ->set('name', 'PayPal')
        ->set('type', 'composite')
        ->set('primaryAccountId', $primary->id)
        ->set('secondaryAccountId', $backup->id)
        ->set('autoSplitBalance', true)
        ->set('description', 'Main PayPal account')
        ->call('savePaymentMethod')
        ->assertHasNoErrors();

    $method = PaymentMethod::query()->firstOrFail();

    expect($method->name)->toBe('PayPal')
        ->and($method->type)->toBe(PaymentMethodType::Composite)
        ->and($method->primary_account_id)->toBe($primary->id)
        ->and($method->secondary_account_id)->toBe($backup->id)
        ->and($method->auto_split_balance)->toBeTrue()
        ->and($method->description)->toBe('Main PayPal account');
});

test('composite payment method requires different primary and backup accounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::payment-methods')
        ->set('name', 'PayPal')
        ->set('type', 'composite')
        ->set('primaryAccountId', $account->id)
        ->set('secondaryAccountId', $account->id)
        ->call('savePaymentMethod')
        ->assertHasErrors(['secondaryAccountId']);
});

test('payment methods are isolated by user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $account = Account::factory()->for($otherUser)->create();
    $method = PaymentMethod::factory()->for($otherUser)->create([
        'primary_account_id' => $account->id,
        'secondary_account_id' => null,
    ]);

    $this->actingAs($user);

    expect(PaymentMethod::query()->find($method->id))->toBeNull();
});

test('calculate split splits between available primary balance and remainder to backup', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create(['currency' => 'USD']);
    $backup = Account::factory()->for($user)->create(['currency' => 'USD']);

    Transaction::factory()->forAccount($primary)->income()->create(['amount' => '20.0000']);

    $this->actingAs($user);

    $method = PaymentMethod::factory()->for($user)->create([
        'type' => PaymentMethodType::Composite,
        'primary_account_id' => $primary->id,
        'secondary_account_id' => $backup->id,
        'auto_split_balance' => true,
    ]);

    // Total expense of $50 should draw $20 from primary and $30 from backup
    $split = $method->calculateSplit('50.00');

    expect($split['primary_amount'])->toBe('20.0000')
        ->and($split['secondary_amount'])->toBe('30.0000');
});

test('calculate split uses 100% primary when primary balance covers total', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create(['currency' => 'USD']);
    $backup = Account::factory()->for($user)->create(['currency' => 'USD']);

    Transaction::factory()->forAccount($primary)->income()->create(['amount' => '100.0000']);

    $this->actingAs($user);

    $method = PaymentMethod::factory()->for($user)->create([
        'type' => PaymentMethodType::Composite,
        'primary_account_id' => $primary->id,
        'secondary_account_id' => $backup->id,
        'auto_split_balance' => true,
    ]);

    $split = $method->calculateSplit('50.00');

    expect($split['primary_amount'])->toBe('50.0000')
        ->and($split['secondary_amount'])->toBe('0.0000');
});

test('calculate split uses 100% backup when primary balance is zero or negative', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create(['currency' => 'USD']);
    $backup = Account::factory()->for($user)->create(['currency' => 'USD']);

    $this->actingAs($user);

    $method = PaymentMethod::factory()->for($user)->create([
        'type' => PaymentMethodType::Composite,
        'primary_account_id' => $primary->id,
        'secondary_account_id' => $backup->id,
        'auto_split_balance' => true,
    ]);

    $split = $method->calculateSplit('50.00');

    expect($split['primary_amount'])->toBe('0.0000')
        ->and($split['secondary_amount'])->toBe('50.0000');
});

test('users can update and delete payment methods', function () {
    $user = User::factory()->create();
    $primary = Account::factory()->for($user)->create();
    $backup = Account::factory()->for($user)->create();

    $method = PaymentMethod::factory()->for($user)->create([
        'name' => 'Old Name',
        'primary_account_id' => $primary->id,
        'secondary_account_id' => $backup->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::payment-methods')
        ->call('editPaymentMethod', $method->id)
        ->set('name', 'Updated Name')
        ->call('savePaymentMethod')
        ->assertHasNoErrors();

    expect($method->fresh()->name)->toBe('Updated Name');

    Livewire::actingAs($user)
        ->test('pages::payment-methods')
        ->call('deletePaymentMethod', $method->id);

    expect(PaymentMethod::query()->find($method->id))->toBeNull();
});
