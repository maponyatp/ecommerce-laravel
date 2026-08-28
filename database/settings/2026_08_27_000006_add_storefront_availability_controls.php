<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.storefront_enabled', true);
        $this->migrator->add('general.storefront_unavailable_message', 'Our online store is being refreshed. Please check back shortly.');
    }
};
