<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CheckAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow access to login routes without authentication
        if ($request->is('login') || $request->is('auth/login')) {
            return $next($request);
        }

        // Check if user is authenticated
        if (!Session::has('user')) {
            return redirect('/login')
                ->with('warning', 'Please log in to access this page.');
        }

        return $next($request);
    }
}
