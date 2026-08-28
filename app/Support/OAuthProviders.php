<?php

namespace App\Support;

use JoelButcher\Socialstream\Providers;
use JoelButcher\Socialstream\Socialstream;

class OAuthProviders
{
    public static function supported(string $provider): bool
    {
        return in_array($provider, Providers::all(), true) && Providers::enabled($provider);
    }

    public static function configured(string $provider): bool
    {
        if (! static::supported($provider)) {
            return false;
        }

        // Socialite checks the legacy twitter configuration first for OAuth 2.
        $settings = $provider === 'twitter-oauth-2'
            ? (config('services.twitter') ?? config('services.twitter-oauth-2'))
            : config('services.'.$provider);

        if (! is_array($settings)) {
            return false;
        }

        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    public static function available(): array
    {
        return array_values(array_filter(Socialstream::providers(), fn (array $provider) => static::configured($provider['id'])));
    }
}
