<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.store_primary_color', '#18181b');
        $this->migrator->add('general.store_background_color', '#fafafa');
        $this->migrator->add('general.store_font_style', 'modern');
        $this->migrator->add('general.show_announcement', true);
        $this->migrator->add('general.show_featured_categories', true);
        $this->migrator->add('general.show_latest_products', true);
        $this->migrator->add('general.show_promotion', true);
        $this->migrator->add('general.homepage_category_limit', 4);
        $this->migrator->add('general.homepage_product_limit', 6);
    }
};
