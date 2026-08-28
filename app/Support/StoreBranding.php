<?php

namespace App\Support;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Storage;

class StoreBranding
{
    public function current(): array
    {
        $settings = app(GeneralSettings::class);
        $colour = preg_match('/^#[0-9a-f]{6}$/i', $settings->store_primary_color) ? $settings->store_primary_color : '#18181b';
        $rgb = array_map(fn ($offset) => hexdec(substr($colour, $offset, 2)) / 255, [1, 3, 5]);
        $linear = array_map(fn ($value) => $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4, $rgb);
        $luminance = .2126 * $linear[0] + .7152 * $linear[1] + .0722 * $linear[2];
        $email = filter_var($settings->site_email, FILTER_VALIDATE_EMAIL) && ! preg_match('/@example\.(com|org|net)$/i', $settings->site_email) ? $settings->site_email : null;

        return [
            'name' => $settings->site_name,
            'url' => rtrim(config('app.url'), '/'),
            'email' => $email,
            'phone' => $settings->site_phone === '+44 208 050 5865' ? null : $settings->site_phone,
            'address' => $settings->site_address === '123 Ecommerce St, London, UK' ? null : $settings->site_address,
            'colour' => $colour,
            'ink' => $luminance > .179 ? '#000000' : '#ffffff',
            'logo_url' => $this->logoUrl($settings->site_logo_path),
        ];
    }

    public function logoUrl(?string $path): ?string
    {
        // Use only uploaded local raster files. Email clients often reject SVG/WebP.
        // Never fetch a user-supplied URL or resolve paths outside the public disk.
        if (! $path || ! preg_match('~^cms/branding/[a-zA-Z0-9/_-]+\.(png|jpe?g|gif)$~i', $path)
            || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return rtrim(config('app.url'), '/').'/storage/'.$path;
    }

    public function invoiceSnapshot(): array
    {
        $brand = $this->current();
        $path = app(GeneralSettings::class)->site_logo_path;
        // Embed the original bytes in the immutable document: replacing a logo later
        // must not rebrand an already-issued invoice. No network requests are made.
        $brand['logo_data'] = null;
        if ($brand['logo_url'] && Storage::disk('public')->size($path) <= 2 * 1024 * 1024) {
            $bytes = Storage::disk('public')->get($path);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $brand['logo_data'] = 'data:'.$mime.';base64,'.base64_encode($bytes);
            }
        }
        unset($brand['logo_url']);

        return $brand;
    }
}
