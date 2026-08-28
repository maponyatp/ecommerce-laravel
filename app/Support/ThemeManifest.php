<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ThemeManifest
{
    public const FORMAT = 'flowershop-theme/v1';

    public const IMAGES = ['site_logo_path', 'hero_image_path', 'seo_share_image_path', 'favicon_path'];

    public const DESIGN = ['hero_layout' => 'split', 'content_width' => 'wide', 'corner_style' => 'square'];

    public static function rules(): array
    {
        return [
            'store_primary_color' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'store_background_color' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'store_font_style' => 'required|in:modern,editorial,classic',
            'announcement_text' => 'required|string|max:255',
            'hero_eyebrow' => 'nullable|string|max:100', 'hero_title' => 'required|string|max:255',
            'hero_description' => 'nullable|string|max:1000',
            'hero_primary_label' => 'required|string|max:80', 'hero_primary_url' => 'required|string|max:1000',
            'hero_secondary_label' => 'nullable|string|max:80', 'hero_secondary_url' => 'nullable|string|max:1000',
            'featured_categories_heading' => 'required|string|max:120', 'featured_categories_subheading' => 'nullable|string|max:255',
            'products_heading' => 'required|string|max:120', 'products_link_label' => 'required|string|max:80',
            'promo_eyebrow' => 'nullable|string|max:100', 'promo_title' => 'nullable|string|max:255',
            'promo_description' => 'nullable|string|max:1000', 'promo_button_label' => 'nullable|string|max:80',
            'promo_button_url' => 'nullable|string|max:1000', 'footer_copyright' => 'required|string|max:500',
            'show_announcement' => 'required|boolean',
            'homepage_category_limit' => 'required|integer|min:1|max:12',
            'homepage_product_limit' => 'required|integer|min:1|max:24',
            'homepage_sections' => 'required|array|size:4',
            ...array_fill_keys(self::IMAGES, 'nullable|string|max:180'),
        ];
    }

    public static function validate(array $manifest): array
    {
        if (array_diff(array_keys($manifest), ['schema', 'name', 'version', 'author', 'settings', 'design'])) {
            self::fail('Unknown manifest fields. Only the documented v1 format is accepted.');
        }
        Validator::make($manifest, [
            'schema' => 'required|in:'.self::FORMAT, 'name' => 'required|string|max:100',
            'version' => 'required|string|max:40|regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/',
            'author' => 'nullable|string|max:100', 'settings' => 'required|array', 'design' => 'required|array',
        ])->validate();
        $settings = $manifest['settings'];
        if (array_diff(array_keys($settings), array_keys(self::rules())) || array_diff(array_keys(self::rules()), array_keys($settings))) {
            self::fail('Theme settings must contain exactly the documented fields. Business, SMTP, currency, payments and security settings cannot be imported.');
        }
        Validator::make($settings, self::rules() + [
            'homepage_sections.*' => 'required|array:section,enabled',
            'homepage_sections.*.section' => 'required|in:hero,categories,products,promotion|distinct',
            'homepage_sections.*.enabled' => 'required|boolean',
        ])->validate();
        $rgb = array_map(fn ($offset) => hexdec(substr($settings['store_background_color'], $offset, 2)) / 255, [1, 3, 5]);
        $linear = array_map(fn ($value) => $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4, $rgb);
        if (.2126 * $linear[0] + .7152 * $linear[1] + .0722 * $linear[2] < .7) {
            self::fail('This renderer supports light backgrounds only. Choose a lighter background to preserve readable text.');
        }
        foreach (['hero_primary_url', 'hero_secondary_url', 'promo_button_url'] as $key) {
            if (filled($settings[$key]) && ! self::safeUrl($settings[$key])) {
                self::fail($key.' must be a local /path, #anchor or HTTPS URL without credentials.');
            }
        }
        foreach ($settings as $key => $value) {
            if (is_string($value) && (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) || $value !== strip_tags($value))) {
                self::fail('Text fields must be plain text without HTML or control characters.');
            }
        }
        foreach (self::IMAGES as $key) {
            if ($settings[$key] !== null && ! preg_match('~^assets/[a-zA-Z0-9_-]+\.(png|jpg|jpeg|webp)$~D', $settings[$key])) {
                self::fail('Images must reference local assets/*.png, jpg or webp files.');
            }
        }
        if (array_diff(array_keys($manifest['design']), array_keys(self::DESIGN))) {
            self::fail('Unsupported design token. Custom PHP, HTML, JavaScript and CSS are not accepted.');
        }
        Validator::make($manifest['design'], [
            'hero_layout' => 'required|in:split,image-left,centered',
            'content_width' => 'required|in:wide,comfortable',
            'corner_style' => 'required|in:square,soft,rounded',
        ])->validate();

        return $manifest;
    }

    public static function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', rawurldecode($url)) || str_starts_with($url, '//')) {
            return false;
        }
        if (str_starts_with($url, '#')) {
            return preg_match('/^#[a-zA-Z][a-zA-Z0-9_-]*$/D', $url) === 1;
        }
        if (str_starts_with($url, '/')) {
            return ! str_starts_with(rawurldecode($url), '//');
        }
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['package' => $message]);
    }
}
