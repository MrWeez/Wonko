<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\Transaction;
use App\Models\User;
use App\ReportData;
use Livewire\Livewire;

test('the reports page requires authentication and renders for a user', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['currency' => 'EUR']);

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Reports');
});

test('report totals are aggregated strictly per currency', function () {
    $user = User::factory()->create();
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $usd = Account::factory()->for($user)->create(['currency' => 'USD']);

    Transaction::factory()->expense()->forAccount($eur)->create(['amount' => '10.00', 'transaction_date' => now()]);
    Transaction::factory()->expense()->forAccount($eur)->create(['amount' => '5.00', 'transaction_date' => now()]);
    Transaction::factory()->income()->forAccount($eur)->create(['amount' => '50.00', 'transaction_date' => now()]);
    Transaction::factory()->expense()->forAccount($usd)->create(['amount' => '3.00', 'transaction_date' => now()]);

    $data = new ReportData($user, now()->subDays(30), now(), 'EUR');

    expect($data->totals())->toEqual([
        'income' => '50.0000',
        'expense' => '15.0000',
        'net' => '35.0000',
    ]);
});

test('the period presets filter the transaction range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Transaction::factory()->expense()->forAccount($account)->create([
        'amount' => '20.00',
        'transaction_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
    ]);

    $month = new ReportData($user, now()->startOfMonth(), now()->endOfMonth(), 'EUR');
    expect($month->totals()['expense'])->toBe('0.0000');

    $year = new ReportData($user, now()->startOfYear(), now()->endOfYear(), 'EUR');
    expect($year->totals()['expense'])->toBe('20.0000');
});

test('category breakdowns include uncategorized spending and shares', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $food = Category::factory()->for($user)->create(['name' => 'Food', 'color' => '#FF0000']);
    $fun = Category::factory()->for($user)->create(['name' => 'Fun', 'color' => '#00FF00']);

    Transaction::factory()->expense()->forAccount($account)->create([
        'amount' => '10.00',
        'category_id' => $food->id,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->expense()->forAccount($account)->create([
        'amount' => '30.00',
        'category_id' => $fun->id,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->expense()->forAccount($account)->create([
        'amount' => '10.00',
        'transaction_date' => now(),
    ]);

    $categories = (new ReportData($user, now()->subDays(7), now(), 'EUR'))->byCategory();

    expect(reset($categories)['name'])->toBe('Fun')
        ->and(reset($categories)['share'])->toBe('60.0')
        ->and(array_column($categories, 'name'))->toContain('Food', 'Uncategorized');
});

test('account and bank breakdowns group transactions correctly', function () {
    $user = User::factory()->create();
    $bank = Bank::factory()->for($user)->create(['name' => 'Acme Bank']);
    $banked = Account::factory()->for($user)->forBank($bank)->create(['currency' => 'EUR', 'name' => 'Banked']);
    $loose = Account::factory()->for($user)->create(['currency' => 'EUR', 'name' => 'Loose']);

    Transaction::factory()->income()->forAccount($banked)->create(['amount' => '100.00', 'transaction_date' => now()]);
    Transaction::factory()->expense()->forAccount($banked)->create(['amount' => '40.00', 'transaction_date' => now()]);
    Transaction::factory()->expense()->forAccount($loose)->create(['amount' => '10.00', 'transaction_date' => now()]);

    $data = new ReportData($user, now()->subDays(7), now(), 'EUR');
    $byAccount = $data->byAccount();
    $byBank = $data->byBank();

    $byAccountKeyed = array_column($byAccount, null, 'name');

    expect($byAccountKeyed)->toHaveKeys(['Banked', 'Loose'])
        ->and($byAccountKeyed['Banked']['net'])->toBe('60.0000');
    expect(array_column($byBank, 'expense', 'bank'))->toBe([
        'Acme Bank' => '40.0000',
        'No bank' => '10.0000',
    ]);
});

test('the time series buckets transactions in the selected granularity', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Transaction::factory()->income()->forAccount($account)->create([
        'amount' => '20.00',
        'transaction_date' => now()->subDays(2)->toDateString(),
    ]);
    Transaction::factory()->expense()->forAccount($account)->create([
        'amount' => '6.00',
        'transaction_date' => now()->toDateString(),
    ]);

    $series = (new ReportData($user, now()->subDays(6), now(), 'EUR'))->timeSeries();

    expect(count($series))->toBe(7)
        ->and(array_sum(array_map(fn (array $bucket): float => (float) $bucket['income'], $series)))->toBe(20.0)
        ->and(end($series)['expense'])->toBe('6.0000')
        ->and(end($series)['net'])->toBe('-6.0000');
});

test('subscription reporting sums occurrences and forecasts the next period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $subscription = Subscription::factory()->forAccount($account)->create([
        'name' => 'Netflix',
        'amount' => '10.00',
        'next_due_date' => now()->addDays(5)->toDateString(),
    ]);

    SubscriptionOccurrence::factory()->forSubscription($subscription)->create([
        'amount' => '10.00',
        'due_date' => now()->subDays(2)->toDateString(),
        'paid_at' => now()->subDays(2),
    ]);

    $data = (new ReportData($user, now()->subDays(30), now(), 'EUR'))->subscriptions();

    expect($data['recurring'])->toBe('10.0000')
        ->and($data['recurring_count'])->toBe(1)
        ->and($data['forecast'])->toBe('10.0000')
        ->and(reset($data['by_subscription'])['name'])->toBe('Netflix')
        ->and($data['discretionary'])->toBe('0.0000');
});

test('the reports page switches currency through the dropdown', function () {
    $user = User::factory()->create();
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $usd = Account::factory()->for($user)->create(['currency' => 'USD']);

    Transaction::factory()->expense()->forAccount($eur)->create(['amount' => '11.00', 'transaction_date' => now()]);
    Transaction::factory()->expense()->forAccount($usd)->create(['amount' => '7.00', 'transaction_date' => now()]);

    $page = Livewire::actingAs($user)->test('pages::reports');

    $page->set('currency', 'EUR')->assertSee('11.00');
    $page->set('currency', 'USD')->assertSee('7.00');
});
