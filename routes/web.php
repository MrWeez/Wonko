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
});

require __DIR__.'/settings.php';
