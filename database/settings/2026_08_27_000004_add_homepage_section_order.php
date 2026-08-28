<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.homepage_sections', [
            ['section' => 'hero', 'enabled' => true],
            ['section' => 'categories', 'enabled' => true],
            ['section' => 'products', 'enabled' => true],
            ['section' => 'promotion', 'enabled' => true],
        ]);
    }
};
