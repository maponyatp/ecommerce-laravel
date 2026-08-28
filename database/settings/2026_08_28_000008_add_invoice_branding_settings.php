<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.invoice_vat_status', 'unconfirmed');
        $this->migrator->add('general.invoice_footer_note', null);
    }
};
