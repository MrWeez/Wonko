<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthModeIsSafe
{
    /**
     * Handle an incoming request.
     *
     * While registration is disabled, the instance must already contain at
     * least one user. Without a user, nobody could ever create the first
     * account, which would permanently lock the application out. When that
     * happens, refuse to run and point the operator at `user:create`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.registration_enabled') && User::query()->doesntExist()) {
            abort(503, 'Registration is disabled and no users exist. Create the first account with `php artisan user:create`.');
        }

        return $next($request);
    }
}
