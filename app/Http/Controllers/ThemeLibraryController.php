<?php

namespace App\Http\Controllers;

use App\Models\StoreTheme;
use App\Services\ThemeLibraryService;
use App\Services\ThemePackageService;
use App\Settings\GeneralSettings;
use App\Support\ThemeManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ThemeLibraryController extends Controller
{
    public function export(Request $request, ?StoreTheme $theme = null)
    {
        ThemeLibraryService::authorize($request->user());
        $path = app(ThemePackageService::class)->export($theme?->exists ? $theme : null, $request->user());

        return response()->download($path, 'store-theme-'.($theme?->id ?? 'current').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function preview(Request $request, StoreTheme $theme)
    {
        ThemeLibraryService::authorize($request->user());
        $original = app(GeneralSettings::class);
        $preview = clone $original;
        $preview->fill($theme->settings);
        $replacements = [];
        if ($theme->source === 'import') {
            foreach (ThemeManifest::IMAGES as $key) {
                if ($preview->$key) {
                    // The normal renderer builds storage URLs. Replace only these
                    // exact generated URLs with authenticated draft-asset endpoints.
                    $replacements[e(asset('storage/'.$preview->$key))] = e(route('themes.asset', ['theme' => $theme, 'file' => basename($preview->$key)]));
                }
            }
        }
        app()->instance(GeneralSettings::class, $preview);
        try {
            $html = app(HomeController::class)->index()->with(['themeDesign' => $theme->design, 'themePreview' => $theme])->render();

            return response(strtr($html, $replacements))->header('Content-Security-Policy', "form-action 'none'; connect-src 'none'; frame-ancestors 'self'");
        } finally {
            app()->instance(GeneralSettings::class, $original);
        }
    }

    public function asset(Request $request, StoreTheme $theme, string $file)
    {
        ThemeLibraryService::authorize($request->user());
        abort_unless($theme->source === 'import' && preg_match('/^[a-zA-Z0-9_-]+\.(png|jpg|jpeg|webp)$/D', $file)
            && in_array('assets/'.$file, array_intersect_key($theme->settings, array_flip(ThemeManifest::IMAGES)), true), 404);
        $path = 'theme-library/'.$theme->uuid.'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);
        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'
        };

        return response(Storage::disk('local')->get($path))->header('Content-Type', $mime)->header('X-Content-Type-Options', 'nosniff');
    }
}
