<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\StoreIntegration;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StoreIntegrationService
{
    public const FIELDS = [
        'ikhokha' => ['app_id', 'app_secret'],
        'payfast' => ['merchant_id', 'merchant_key', 'passphrase'],
        'peach' => ['merchant_id', 'entity_id', 'client_id', 'client_secret'],
        'ozow' => ['site_code', 'private_key', 'api_key'],
        'dsv' => ['account_number', 'client_id', 'client_secret', 'subscription_key', 'api_username', 'api_password'],
    ];

    public const ADDITIONAL_PAYMENTS = [
        'payfast' => ['name' => 'PayFast', 'docs' => 'https://developers.payfast.co.za/',
            'fields' => ['merchant_id' => 'Merchant ID', 'merchant_key' => 'Merchant key', 'passphrase' => 'Security passphrase']],
        'peach' => ['name' => 'Peach Payments', 'docs' => 'https://developer.peachpayments.com/docs/checkout-embedded-authentication',
            'fields' => ['merchant_id' => 'Merchant ID', 'entity_id' => 'Entity ID', 'client_id' => 'Client ID', 'client_secret' => 'Client secret']],
        'ozow' => ['name' => 'Ozow', 'docs' => 'https://ozow.com/integrations',
            'fields' => ['site_code' => 'Site code', 'private_key' => 'Private key', 'api_key' => 'API key']],
    ];

    /** No secrets or credential fragments are returned to the admin UI. */
    public function additionalPaymentStatus(): array
    {
        $status = [];
        foreach (self::ADDITIONAL_PAYMENTS as $provider => $definition) {
            $record = StoreIntegration::find($provider);
            $readable = true;
            try {
                $credentials = $record?->credentials ?? [];
            } catch (DecryptException) {
                $credentials = [];
                $readable = false;
            }
            $complete = collect(self::FIELDS[$provider])->every(fn ($field) => filled($credentials[$field] ?? null));
            $status[$provider] = $definition + ['saved' => (bool) $record, 'credentials_complete' => $complete,
                'credentials_readable' => $readable, 'environment' => $record?->configuration['environment'] ?? 'sandbox',
                'checkout_available' => false];
        }

        return $status;
    }

    public function paymentConfiguration(): array
    {
        $base = config('services.ikhokha', []);
        if (! Schema::hasTable('store_integrations') || ! ($record = StoreIntegration::find('ikhokha'))) {
            return $base;
        }
        try {
            $credentials = ($record->configuration['enabled'] ?? false) ? $record->credentials : [];
        } catch (DecryptException $exception) {
            $credentials = []; // Never fall back to another account when stored secrets cannot be read.
        }

        return array_merge($base, ['app_id' => $credentials['app_id'] ?? null, 'app_secret' => $credentials['app_secret'] ?? null]);
    }

    public function save(string $provider, array $input, int $expectedVersion, User $actor): int
    {
        $actor = $actor->fresh();
        abort_unless($actor && ! $actor->staff_access_disabled_at && $actor->hasRole('super_admin'), 403);
        abort_unless(array_key_exists($provider, self::FIELDS), 404);
        $rules = ['clear_credentials' => 'sometimes|boolean'];
        foreach (self::FIELDS[$provider] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:512', 'not_regex:/[\x00-\x1F\x7F]/'];
        }
        $rules += match ($provider) {
            'ikhokha' => ['enabled' => 'required|boolean'],
            'dsv' => ['api_product' => 'required|in:unconfirmed,connect,xpress,generic', 'environment' => 'required|in:sandbox,live'],
            default => ['enabled' => 'required|boolean', 'environment' => 'required|in:sandbox,live'],
        };
        $data = Validator::make($input, $rules)->validate();
        if (isset(self::ADDITIONAL_PAYMENTS[$provider]) && $data['enabled']) {
            throw ValidationException::withMessages(['settings' => self::ADDITIONAL_PAYMENTS[$provider]['name'].' checkout integration is not implemented yet. You can save credentials while disabled; it cannot be enabled for customers in this release.']);
        }
        try {
            return DB::transaction(function () use ($provider, $data, $expectedVersion, $actor) {
                $record = StoreIntegration::firstOrCreate(['provider' => $provider], ['credentials' => [], 'configuration' => [], 'version' => 0]);
                $record = StoreIntegration::whereKey($provider)->lockForUpdate()->firstOrFail();
                if ($record->version !== $expectedVersion) {
                    throw ValidationException::withMessages(['settings' => 'These settings changed in another session. Reload before saving.']);
                }
                try {
                    $credentials = ($data['clear_credentials'] ?? false) ? [] : $record->credentials;
                } catch (DecryptException $exception) {
                    throw ValidationException::withMessages(['settings' => 'Stored credentials cannot be decrypted. Ask the system administrator to check the application encryption key.']);
                }
                if (isset(self::ADDITIONAL_PAYMENTS[$provider]) && $credentials
                    && ($record->configuration['environment'] ?? 'sandbox') !== $data['environment']
                    && ! ($data['clear_credentials'] ?? false)
                    && collect(self::FIELDS[$provider])->contains(fn ($field) => blank($data[$field] ?? null))) {
                    throw ValidationException::withMessages(['settings' => 'Changing between sandbox and live requires all credentials for the new environment, or explicit removal of the saved credentials.']);
                }
                $changed = [];
                if ($data['clear_credentials'] ?? false) {
                    $changed[] = 'credentials_removed';
                } else {
                    foreach (self::FIELDS[$provider] as $field) {
                        if (filled($data[$field] ?? null)) {
                            $credentials[$field] = trim($data[$field]);
                            $changed[] = $field;
                        }
                    }
                }
                $configuration = match ($provider) {
                    'ikhokha' => ['enabled' => (bool) $data['enabled']],
                    'dsv' => ['api_product' => $data['api_product'], 'environment' => $data['environment']],
                    default => ['enabled' => false, 'environment' => $data['environment']],
                };
                if ($provider === 'ikhokha' && $configuration['enabled']
                    && (blank($credentials['app_id'] ?? null) || blank($credentials['app_secret'] ?? null))) {
                    throw ValidationException::withMessages(['settings' => 'Enter both iKhokha fields before enabling payments. To remove credentials, disable payments first.']);
                }
                if ($configuration !== $record->configuration) {
                    $changed[] = 'configuration';
                }
                if ($provider !== 'dsv' && $changed
                    && PaymentTransaction::where('gateway', $provider)->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['settings' => 'Resolve pending '.$provider.' payments before changing credentials or disabling the gateway. This protects payment callbacks.']);
                }
                $version = $record->version + 1;
                if ($data['clear_credentials'] ?? false) {
                    // Explicit removal must also recover unreadable ciphertext. Eloquent's
                    // dirty comparison otherwise decrypts the old value during update.
                    $record->setRawAttributes(array_replace($record->getAttributes(), ['credentials' => null]), true);
                }
                $record->update(['credentials' => $credentials, 'configuration' => $configuration, 'version' => $version, 'updated_by' => $actor->id]);
                DB::table('store_integration_changes')->insert(['provider' => $provider, 'changed_by' => $actor->id,
                    'changed_fields' => json_encode($changed, JSON_THROW_ON_ERROR), 'version' => $version, 'created_at' => now(), 'updated_at' => now()]);

                return $version;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['settings' => 'Another session saved these settings. Reload before saving.']);
        }
    }
}
