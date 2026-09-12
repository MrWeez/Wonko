<?php

use App\Http\Middleware\EnsurePasswordChange;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(array_filter([
    'auth',
    config('auth.email_verification_required') ? 'verified' : null,
    EnsurePasswordChange::class,
]))->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('accounts', 'pages::accounts')->name('accounts.index');
    Route::livewire('payment-methods', 'pages::payment-methods')->name('payment-methods.index');
    Route::livewire('transactions', 'pages::transactions')->name('transactions.index');
    Route::livewire('categories', 'pages::categories')->name('categories.index');
    Route::livewire('subscriptions', 'pages::subscriptions')->name('subscriptions.index');
});

require __DIR__.'/settings.php';
