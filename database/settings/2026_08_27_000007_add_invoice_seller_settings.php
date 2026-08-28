<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['invoice_seller_name', 'invoice_seller_address', 'invoice_registration_number', 'invoice_tax_number'] as $name) {
            $this->migrator->add('general.'.$name, null);
        }
    }
};
