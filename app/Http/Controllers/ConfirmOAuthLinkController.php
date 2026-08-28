<?php

namespace App\Http\Controllers;

use DomainException;
use Illuminate\Validation\ValidationException;
use JoelButcher\Socialstream\Http\Controllers\OAuthController;

class ConfirmOAuthLinkController extends Controller
{
    public function __invoke(string $provider, OAuthController $controller): mixed
    {
        try {
            return $controller->confirm($provider);
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'Could not retrieve social provider information.') {
                throw $exception;
            }

            throw ValidationException::withMessages(['socialstream' => 'This account-link request has expired. Please start again.']);
        }
    }
}
