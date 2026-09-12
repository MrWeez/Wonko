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
