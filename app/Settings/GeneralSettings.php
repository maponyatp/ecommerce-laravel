<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $site_name;

    public ?string $invoice_seller_name = null;

    public ?string $invoice_seller_address = null;

    public ?string $invoice_registration_number = null;

    public ?string $invoice_tax_number = null;

    public string $invoice_vat_status = 'unconfirmed';

    public ?string $invoice_footer_note = null;

    public ?string $site_logo_path;

    public string $store_primary_color;

    public string $store_background_color;

    public string $store_font_style;

    public string $site_email;

    public ?string $site_phone;

    public ?string $site_address;

    public ?string $site_country;

    public string $site_currency;

    public string $site_default_language;

    public ?string $facebook_url;

    public ?string $twitter_url;

    public ?string $github_url;

    public ?string $youtube_url;

    public string $footer_copyright;

    public string $announcement_text;

    public ?string $hero_eyebrow;

    public string $hero_title;

    public ?string $hero_description;

    public string $hero_primary_label;

    public string $hero_primary_url;

    public ?string $hero_secondary_label;

    public ?string $hero_secondary_url;

    public ?string $hero_image_path;

    public string $featured_categories_heading;

    public ?string $featured_categories_subheading;

    public string $products_heading;

    public string $products_link_label;

    public ?string $promo_eyebrow;

    public ?string $promo_title;

    public ?string $promo_description;

    public ?string $promo_button_label;

    public ?string $promo_button_url;

    public bool $show_announcement;

    public bool $show_featured_categories;

    public bool $show_latest_products;

    public bool $show_promotion;

    public int $homepage_category_limit;

    public int $homepage_product_limit;

    public array $homepage_sections;

    public ?string $seo_description;

    public ?string $seo_keywords;

    public ?string $seo_share_image_path;

    public ?string $favicon_path;

    public bool $storefront_enabled;

    public string $storefront_unavailable_message;

    public static function group(): string
    {
        return 'general';
    }
}
