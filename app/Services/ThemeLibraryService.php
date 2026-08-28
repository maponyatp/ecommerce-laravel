<?php

namespace App\Services;

use App\Models\StoreTheme;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\ThemeManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ThemeLibraryService
{
    public static function authorize(?User $user): void
    {
        $user = $user?->fresh();
        abort_unless($user && ! $user->staff_access_disabled_at && $user->hasRole('super_admin'), 403);
    }

    public function state(): object
    {
        return Schema::hasTable('store_theme_state') ? DB::table('store_theme_state')->find(1) : (object) ['version' => 0, 'active_theme_id' => null];
    }

    public function design(): array
    {
        $id = $this->state()->active_theme_id;

        return $id ? StoreTheme::find($id)?->design ?? ThemeManifest::DESIGN : ThemeManifest::DESIGN;
    }

    public function currentSettings(): array
    {
        return array_intersect_key(app(GeneralSettings::class)->refresh()->toArray(), ThemeManifest::rules());
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(app(GeneralSettings::class)->refresh()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function lock(int $version, string $fingerprint): object
    {
        $state = DB::table('store_theme_state')->where('id', 1)->lockForUpdate()->first();
        if (! $state || (int) $state->version !== $version || ! hash_equals($this->fingerprint(), $fingerprint)) {
            throw ValidationException::withMessages(['theme' => 'The store design or settings changed. Reload before saving or activating.']);
        }

        return $state;
    }

    public function snapshot(User $actor): StoreTheme
    {
        return StoreTheme::create(['uuid' => (string) Str::uuid(), 'name' => 'Previous design · '.now()->format('Y-m-d H:i:s'),
            'version' => '1.0.0', 'source' => 'snapshot', 'settings' => $this->currentSettings(), 'design' => $this->design(), 'created_by' => $actor->id]);
    }

    public function activate(StoreTheme $theme, int $version, string $fingerprint, User $actor): void
    {
        self::authorize($actor);
        $published = [];
        try {
            DB::transaction(function () use ($theme, $version, $fingerprint, $actor, &$published) {
                $this->lock($version, $fingerprint);
                $theme = $theme->fresh();
                $previous = $this->snapshot($actor);
                $settings = $theme->settings;
                if ($theme->source === 'import') {
                    foreach (ThemeManifest::IMAGES as $key) {
                        if (! $settings[$key]) {
                            continue;
                        }
                        $source = 'theme-library/'.$theme->uuid.'/'.basename($settings[$key]);
                        if (! Storage::disk('local')->exists($source)) {
                            ThemeManifest::fail('A theme image is missing. Re-upload the package.');
                        }
                        $target = 'cms/branding/themes/'.$theme->uuid.'/'.basename($settings[$key]);
                        if (! Storage::disk('public')->exists($target)) {
                            if (! Storage::disk('public')->put($target, Storage::disk('local')->get($source))) {
                                throw new \RuntimeException('Could not publish the theme image.');
                            }
                            $published[] = $target;
                        }
                        $settings[$key] = $target;
                    }
                } else {
                    foreach (ThemeManifest::IMAGES as $key) {
                        if ($settings[$key] && ! Storage::disk('public')->exists($settings[$key])) {
                            ThemeManifest::fail('A saved design image has been removed. Restore the image before activating this snapshot.');
                        }
                    }
                }
                app(GeneralSettings::class)->refresh()->fill($settings)->save();
                DB::table('store_theme_state')->where('id', 1)->update(['active_theme_id' => $theme->id, 'version' => $version + 1]);
                DB::table('store_theme_events')->insert(['actor_id' => $actor->id, 'theme_id' => $theme->id,
                    'previous_theme_id' => $previous->id, 'action' => 'activated', 'version' => $version + 1, 'created_at' => now()]);
            });
        } catch (\Throwable $e) {
            foreach ($published as $path) {
                Storage::disk('public')->delete($path);
            }
            app(GeneralSettings::class)->refresh();
            throw $e;
        }
    }
}
