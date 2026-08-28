<?php

use App\Http\Controllers\ConfirmOAuthLinkController;
use App\Http\Middleware\EnsureOAuthProviderConfigured;
use Illuminate\Support\Facades\Route;
use JoelButcher\Socialstream\Http\Controllers\OAuthController;

Route::group(['middleware' => [...config('socialstream.middleware', ['web']), EnsureOAuthProviderConfigured::class]], function () {
    Route::get('/oauth/{provider}', [OAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::match(['get', 'post'], '/oauth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
    Route::get('/oauth/{provider}/callback/prompt', [OAuthController::class, 'prompt'])
        ->middleware('auth')->name('oauth.callback.prompt');
    Route::post('/oauth/{provider}/callback/confirm', ConfirmOAuthLinkController::class)
        ->middleware('auth')->name('oauth.callback.confirm');
});
