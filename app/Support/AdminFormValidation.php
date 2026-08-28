<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class AdminFormValidation
{
    public static function run(callable $callback, string $statePath = 'data'): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [$statePath.'.'.$field => $messages])->all()
            );
        }
    }
}
