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
     * account, which would permanently lock the application out. The one
     * exception is a deployment with SSO enabled: there the first account can
     * legitimately arrive through the identity provider. When neither path
     * exists, refuse to run and point the operator at `user:create`.
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
