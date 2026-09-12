<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
