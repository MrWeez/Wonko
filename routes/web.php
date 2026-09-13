<?php

use App\Http\Controllers\SsoController;
use App\Http\Middleware\EnsurePasswordChange;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('sso')
    ->name('sso.')
    ->group(function () {
        Route::get('{provider}', [SsoController::class, 'redirect'])
            ->where('provider', '[A-Za-z0-9_-]+')
            ->name('redirect');

        Route::get('{provider}/callback', [SsoController::class, 'callback'])
            ->where('provider', '[A-Za-z0-9_-]+')
            ->middleware('throttle:10,1')
            ->name('callback');
    });

Route::middleware(array_filter([
    'auth',
    config('auth.email_verification_required') ? 'verified' : null,
    EnsurePasswordChange::class,
]))->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('accounts', 'pages::accounts')->name('accounts.index');
    Route::livewire('payment-methods', 'pages::payment-methods')->name('payment-methods.index');
    Route::livewire('transactions', 'pages::transactions')->name('transactions.index');
    Route::livewire('categories', 'pages::categories')->name('categories.index');
    Route::livewire('subscriptions', 'pages::subscriptions')->name('subscriptions.index');
    Route::livewire('reports', 'pages::reports')->name('reports.index');
});

require __DIR__.'/settings.php';
