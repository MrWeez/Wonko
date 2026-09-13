<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;
use Livewire\Livewire;

test('users can create and view their banks and accounts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertSee('Accounts');

    $component = Livewire::actingAs($user)->test('pages::accounts');

    $component
        ->set('bankName', 'Example Bank')
        ->call('saveBank')
        ->assertHasNoErrors();

    $bank = Bank::query()->where('name', 'Example Bank')->firstOrFail();

    $component
        ->set('accountName', 'Daily account')
        ->set('accountCurrency', 'EUR')
        ->set('accountType', 'checking')
        ->set('accountBankId', $bank->id)
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account = Account::query()->where('name', 'Daily account')->firstOrFail();

    expect($account->user_id)->toBe($user->id)
        ->and($account->bank_id)->toBe($bank->id)
        ->and($account->type->value)->toBe('checking');
});

test('bank deletion keeps accounts and removes their bank assignment', function () {
    $user = User::factory()->create();
    $bank = Bank::factory()->for($user)->create();
    $account = Account::factory()->forBank($bank)->create();

    Livewire::actingAs($user)
        ->test('pages::accounts')
        ->call('deleteBank', $bank->id);

    expect(Bank::withoutGlobalScopes()->find($bank->id))->toBeNull();
    expect($account->fresh()->bank_id)->toBeNull();
});

test('users cannot see another users accounts', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create(['name' => 'Private account']);

    Livewire::actingAs($user)
        ->test('pages::accounts')
        ->assertDontSee('Private account');

    expect(Account::query()->find($otherAccount->id))->toBeNull();
});

test('account currency must be an uppercase ISO-style three letter code', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::accounts')
        ->set('accountName', 'Invalid account')
        ->set('accountCurrency', 'euro')
        ->set('accountType', 'checking')
        ->call('saveAccount')
        ->assertHasErrors(['accountCurrency']);

});

test('the first account is automatically marked as primary', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::accounts')
        ->set('accountName', 'First account')
        ->set('accountCurrency', 'EUR')
        ->set('accountType', 'checking')
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect(Account::query()->where('name', 'First account')->value('is_primary'))->toBeTrue();
});

test('marking an account as primary demotes the previous one', function () {
    $user = User::factory()->create();
    $first = Account::factory()->for($user)->create(['is_primary' => true]);
    $second = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::accounts')
        ->call('makePrimary', $second->id)
        ->assertHasNoErrors();

    expect($second->fresh()->is_primary)->toBeTrue()
        ->and($first->fresh()->is_primary)->toBeFalse();
});

test('the primary account becomes the default in the transaction form', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Alpha', 'currency' => 'EUR']);

    $primary = Account::factory()->for($user)->create(['name' => 'Beta', 'currency' => 'USD']);
    $primary->markAsPrimary();

    Livewire::actingAs($user)
        ->test('pages::transactions')
        ->assertSet('transactionAccountId', $primary->id)
        ->assertSet('transactionCurrency', 'USD');
});

test('a new account becomes primary after the previous one is deleted', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Old primary', 'is_primary' => true])->delete();

    Livewire::actingAs($user)
        ->test('pages::accounts')
        ->set('accountName', 'New primary')
        ->set('accountCurrency', 'EUR')
        ->set('accountType', 'checking')
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect(Account::query()->where('name', 'New primary')->value('is_primary'))->toBeTrue();
});
