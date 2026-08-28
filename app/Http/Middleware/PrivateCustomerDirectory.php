<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PrivateCustomerDirectory
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // Streamed downloads use Symfony responses without Laravel's header() helper.
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
