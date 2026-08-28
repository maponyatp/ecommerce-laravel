<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.seo_description', 'Shop fresh flowers, thoughtful gifts, and seasonal arrangements.');
        $this->migrator->add('general.seo_keywords', null);
        $this->migrator->add('general.seo_share_image_path', null);
        $this->migrator->add('general.favicon_path', null);
    }
};
