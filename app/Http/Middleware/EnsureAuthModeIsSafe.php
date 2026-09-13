<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthModeIsSafe
{
    /**
     * With registration closed, refuse to run until a first user exists,
     * unless SSO is enabled (the IdP can still create the first account).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.registration_enabled')
            && ! config('sso.enabled')
            && User::query()->doesntExist()) {
            abort(503, 'Registration is disabled and no users exist. Enable SSO or create the first account with `php artisan user:create`.');
        }

        return $next($request);
    }
}
