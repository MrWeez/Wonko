<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Sso;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class SsoController extends Controller
{
    /**
     * Begin the OAuth/OIDC authorization round-trip.
     */
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($this->resolveDriver($provider))->redirect();
    }

    /**
     * Handle the provider's callback, logging in or creating the user.
     */
    public function callback(string $provider): RedirectResponse
    {
        $driver = $this->resolveDriver($provider);

        if (Auth::check()) {
            return redirect()->to(config('fortify.home'));
        }

        try {
            $ssoUser = Socialite::driver($driver)->user();
        } catch (Throwable $exception) {
            Log::warning('SSO callback failed.', [
                'provider' => $provider,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('login')
                ->withErrors(['email' => __('Unable to sign you in with :provider. Please try again.', ['provider' => ucfirst($provider)])]);
        }

        $email = Str::lower((string) $ssoUser->getEmail());

        if ($email === '') {
            return redirect()->route('login')
                ->withErrors(['email' => __('The identity provider did not return an email address.')]);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if ($user === null) {
            $name = trim((string) $ssoUser->getName());

            $user = User::create([
                'name' => $name !== '' ? $name : Str::ucfirst(Str::before($email, '@')),
                'email' => $email,
                'password' => Str::password(48),
                'email_verified_at' => now(),
            ]);
        } elseif ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * Resolve the Socialite driver for a provider route parameter.
     */
    private function resolveDriver(string $provider): string
    {
        abort_unless(Sso::isEnabled(), 404);

        $driver = Sso::driverFor($provider);

        abort_unless($driver !== null && Sso::isConfigured($driver), 404);

        return $driver;
    }
}
