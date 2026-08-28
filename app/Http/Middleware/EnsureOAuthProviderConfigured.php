<?php

namespace App\Http\Middleware;

use App\Support\OAuthProviders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOAuthProviderConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->route('provider');
        abort_unless(is_string($provider) && OAuthProviders::supported($provider), 404);

        if (! OAuthProviders::configured($provider)) {
            $message = 'This social sign-in option is not configured. Please use email and password.';

            return ($request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : response($message, 503)->header('Content-Type', 'text/plain; charset=UTF-8'))
                ->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
