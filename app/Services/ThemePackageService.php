<?php

namespace App\Services;

use App\Models\StoreTheme;
use App\Models\User;
use App\Support\ThemeManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ThemePackageService
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function inspect(string $path): array
    {
        if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            ThemeManifest::fail('Upload a ZIP no larger than 10 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            ThemeManifest::fail('The upload is not a readable ZIP.');
        }
        try {
            if ($zip->numFiles > 32) {
                ThemeManifest::fail('A package may contain at most 32 entries.');
            }
            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (isset($entries[strtolower($name)]) || ! preg_match('~^(?:theme\.json|DESIGNER\.md|assets/|assets/[a-zA-Z0-9_-]+\.(?:png|jpg|jpeg|webp))$~D', $name)) {
                    ThemeManifest::fail('Unsafe, duplicate or unsupported ZIP entry. Use theme.json and raster assets only.');
                }
                $opsys = $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if (($opsys === 3 && (($attributes >> 16) & 0170000) === 0120000) || ($stat['encryption_method'] ?? 0) !== 0) {
                    ThemeManifest::fail('Symbolic links and encrypted ZIP entries are not accepted.');
                }
                $total += $stat['size'];
                if ($total > self::MAX_BYTES || $stat['size'] > 5 * 1024 * 1024 || ($stat['size'] > 1024 * 1024 && $stat['size'] > max(1, $stat['comp_size']) * 200)) {
                    ThemeManifest::fail('Package exceeds decompressed-size or compression-ratio limits.');
                }
                if ($name === 'theme.json' && $stat['size'] > 65536) {
                    ThemeManifest::fail('theme.json must be smaller than 64 KB.');
                }
                $entries[strtolower($name)] = $name;
            }
            $json = $zip->getFromName('theme.json', 65537);
            try {
                $manifest = json_decode($json ?: '', true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                ThemeManifest::fail('theme.json is missing or invalid JSON.');
            }
            if (! is_array($manifest)) {
                ThemeManifest::fail('theme.json must be an object.');
            }
            $manifest = ThemeManifest::validate($manifest);
            $images = [];
            $encodedTotal = 0;
            foreach ($entries as $name) {
                if (! str_starts_with($name, 'assets/') || $name === 'assets/') {
                    continue;
                }
                $bytes = $zip->getFromName($name, 5 * 1024 * 1024 + 1);
                $images[$name] = $this->raster($bytes ?: '', pathinfo($name, PATHINFO_EXTENSION));
                $encodedTotal += strlen($images[$name]);
                if ($encodedTotal > self::MAX_BYTES) {
                    ThemeManifest::fail('Images exceed 20 MB after safe decoding. Optimize the package.');
                }
            }
            foreach (ThemeManifest::IMAGES as $key) {
                if ($manifest['settings'][$key] && ! isset($images[$manifest['settings'][$key]])) {
                    ThemeManifest::fail('A referenced image is missing from assets/.');
                }
            }
        } finally {
            $zip->close();
        }

        return ['manifest' => $manifest, 'images' => $images];
    }

    public function import(string $path, User $actor): StoreTheme
    {
        ThemeLibraryService::authorize($actor);
        ['manifest' => $manifest, 'images' => $images] = $this->inspect($path);

        $uuid = (string) Str::uuid();
        $written = [];
        try {
            foreach ($images as $name => $bytes) {
                $destination = 'theme-library/'.$uuid.'/'.basename($name);
                if (! Storage::disk('local')->put($destination, $bytes)) {
                    throw new \RuntimeException('Unable to store a theme asset.');
                }
                $written[] = $destination;
            }

            return DB::transaction(function () use ($manifest, $actor, $uuid, $path) {
                $theme = StoreTheme::create([
                    'uuid' => $uuid, 'name' => $manifest['name'], 'version' => $manifest['version'], 'author' => $manifest['author'] ?? null,
                    'source' => 'import', 'settings' => $manifest['settings'], 'design' => $manifest['design'],
                    'package_hash' => hash_file('sha256', $path), 'created_by' => $actor->id,
                ]);
                DB::table('store_theme_events')->insert(['actor_id' => $actor->id, 'theme_id' => $theme->id, 'action' => 'imported', 'created_at' => now()]);

                return $theme;
            });
        } catch (\Throwable $e) {
            foreach ($written as $file) {
                Storage::disk('local')->delete($file);
            }
            throw $e;
        }
    }

    public function raster(string $bytes, string $extension): string
    {
        $info = @getimagesizefromstring($bytes);
        $expected = match ($extension) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', default => null
        };
        if (! $info || $info['mime'] !== $expected || $info[0] > 4000 || $info[1] > 4000 || $info[0] * $info[1] > 4000000) {
            ThemeManifest::fail('Use genuine PNG, JPEG or WebP images up to 4 megapixels and 4000 pixels per edge.');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            ThemeManifest::fail('An image could not be decoded.');
        }
        ob_start();
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $ok = match ($extension) {
                'png' => imagepng($image), 'jpg', 'jpeg' => imagejpeg($image, null, 92), 'webp' => imagewebp($image, null, 90)
            };
            $encoded = ob_get_contents();
            if (! $ok || ! $encoded) {
                ThemeManifest::fail('An image could not be safely re-encoded.');
            }
            if (strlen($encoded) > 5 * 1024 * 1024) {
                ThemeManifest::fail('An image exceeds 5 MB after safe re-encoding. Optimize it before uploading.');
            }

            return $encoded;
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    public function export(?StoreTheme $theme, User $actor): string
    {
        ThemeLibraryService::authorize($actor);
        $library = app(ThemeLibraryService::class);
        $settings = $theme?->settings ?? $library->currentSettings();
        $assets = [];
        foreach (ThemeManifest::IMAGES as $key) {
            $path = $settings[$key];
            if (! $path) {
                $settings[$key] = null;

                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($theme?->source === 'import') {
                $bytes = Storage::disk('local')->get('theme-library/'.$theme->uuid.'/'.basename($path));
            } else {
                if (! preg_match('~^cms/[a-zA-Z0-9/_-]+\.(png|jpg|jpeg|webp)$~D', $path) || ! Storage::disk('public')->exists($path)) {
                    ThemeManifest::fail('An existing image is missing or unsupported. Replace GIF/SVG/ICO or legacy images with PNG/JPEG/WebP before exporting.');
                }
                if (Storage::disk('public')->size($path) > 5 * 1024 * 1024) {
                    ThemeManifest::fail('An existing image exceeds 5 MB.');
                }
                $bytes = Storage::disk('public')->get($path);
            }
            $bytes = $this->raster($bytes ?? '', $extension);
            $name = 'assets/'.$key.'.'.$extension;
            $assets[$name] = $bytes;
            $settings[$key] = $name;
        }
        $manifest = ThemeManifest::validate(['schema' => ThemeManifest::FORMAT, 'name' => $theme?->name ?? 'Current store design',
            'version' => $theme?->version ?? '1.0.0', 'author' => $theme?->author, 'settings' => $settings, 'design' => $theme?->design ?? $library->design()]);
        $path = tempnam(sys_get_temp_dir(), 'store-theme-');
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create export.');
            }
            $zip->addFromString('theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $zip->addFromString('DESIGNER.md', file_get_contents(resource_path('themes/DESIGNER.md')));
            foreach ($assets as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Could not finish export.');
            }
            if (filesize($path) > 10 * 1024 * 1024) {
                ThemeManifest::fail('The exported assets exceed the 10 MB upload limit. Optimize your images first.');
            }
            $this->inspect($path);

            return $path;
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            } throw $e;
        }
    }
}
