<?php

namespace App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class ReleaseSessionForUpload
{
    /**
     * Release session lock immediately after session is started
     * This allows multiple requests to proceed during long file uploads
     */
    public function handle(Request $request, Closure $next)
    {
        // Only release session for upload route
        if ($request->is('run')) {
            // Save session data
            Session::save();
            
            // Close/release session lock to allow other requests
            session_write_close();
            
            // Prevent Laravel from re-locking session in middleware
            $request->attributes->set('session_released', true);
        }

        return $next($request);
    }
}
