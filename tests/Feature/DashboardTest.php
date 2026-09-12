<?php

use App\Models\Account;
use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('unverified users are redirected to email verification', function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('users with a forced password reset are redirected to security settings', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('dashboard shows total balances by currency and accounts list', function () {
    $user = User::factory()->create();
    $accEur = Account::factory()->for($user)->create(['name' => 'EUR Account', 'currency' => 'EUR']);
    $accUsd = Account::factory()->for($user)->create(['name' => 'USD Account', 'currency' => 'USD']);

    Transaction::factory()->forAccount($accEur)->income()->create(['amount' => '150.0000']);
    Transaction::factory()->forAccount($accUsd)->income()->create(['amount' => '75.0000']);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('150.00 EUR')
        ->assertSee('75.00 USD')
        ->assertSee('EUR Account')
        ->assertSee('USD Account');
});

test('dashboard allows quick confirmation of due subscriptions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $sub = Subscription::factory()->forAccount($account)->create([
        'name' => 'Gym Membership',
        'amount' => '30.00',
        'next_due_date' => now()->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('Gym Membership')
        ->call('quickConfirmSubscription', $sub->id)
        ->assertHasNoErrors();

    expect(SubscriptionOccurrence::query()->where('subscription_id', $sub->id)->count())->toBe(1)
        ->and(Transaction::query()->where('description', 'Gym Membership')->count())->toBe(1);
});
