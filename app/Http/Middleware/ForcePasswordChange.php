<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks authenticated users with must_change_password=true to the profile page
 * until they set a real password (see the Admin-Account bootstrapping flow).
 */
class ForcePasswordChange
{
    private const ALLOWED_ROUTES = [
        'profile.edit',
        'password.update',
        'logout',
    ];

    /**
     * Redirects users flagged with must_change_password to the profile page
     * until they set a real password, per the Admin-Account bootstrapping rule.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return redirect()->route('profile.edit')->with('status', 'password-change-required');
        }

        return $next($request);
    }
}
