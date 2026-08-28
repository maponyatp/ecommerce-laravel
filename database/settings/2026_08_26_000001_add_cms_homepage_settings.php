<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.announcement_text', 'Fresh flowers, thoughtfully chosen');
        $this->migrator->add('general.hero_eyebrow', 'New in: seasonal collections');
        $this->migrator->add('general.hero_title', 'Blooms crafted by nature');
        $this->migrator->add('general.hero_description', 'Explore hand-tied bouquets, premium orchids, and seasonal arrangements.');
        $this->migrator->add('general.hero_primary_label', 'Shop the collection');
        $this->migrator->add('general.hero_primary_url', '/products');
        $this->migrator->add('general.hero_secondary_label', 'View categories');
        $this->migrator->add('general.hero_secondary_url', '#featured-categories');
        $this->migrator->add('general.hero_image_path', null);
        $this->migrator->add('general.featured_categories_heading', 'Shop by category');
        $this->migrator->add('general.featured_categories_subheading', 'Selected styles and collections');
        $this->migrator->add('general.products_heading', 'Trending now');
        $this->migrator->add('general.products_link_label', 'View all products');
        $this->migrator->add('general.promo_eyebrow', 'Limited time only');
        $this->migrator->add('general.promo_title', 'A little extra for every occasion');
        $this->migrator->add('general.promo_description', 'Create a promotion in the admin panel and send customers to the collection you choose.');
        $this->migrator->add('general.promo_button_label', 'Shop now');
        $this->migrator->add('general.promo_button_url', '/products');
    }
};
