<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\StoreTheme;
use App\Services\ThemeLibraryService;
use App\Services\ThemePackageService;
use App\Support\ThemeManifest;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class ThemeLibrary extends Page
{
    use WithPagination;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|\UnitEnum|null $navigationGroup = 'Online store';

    protected static ?string $navigationLabel = 'Theme library';

    protected string $view = 'filament.admin.pages.theme-library';

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class, 'throttle:30,1'];

    #[Locked]
    public int $storeVersion = 0;

    #[Locked]
    public string $settingsFingerprint = '';

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->hasRole('super_admin') && ! auth()->user()?->staff_access_disabled_at);
    }

    public function mount(): void
    {
        ThemeLibraryService::authorize(auth()->user());
        $this->refreshBaseline();
    }

    private function refreshBaseline(): void
    {
        $service = app(ThemeLibraryService::class);
        $this->storeVersion = (int) $service->state()->version;
        $this->settingsFingerprint = $service->fingerprint();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCurrent')->label('Export current design')->icon('heroicon-o-arrow-down-tray')->url(route('themes.export')),
            Action::make('uploadTheme')->label('Upload theme')->icon('heroicon-o-arrow-up-tray')->schema([
                FileUpload::make('package')->label('Theme ZIP')->disk('local')->directory('theme-uploads')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->maxSize(10240)->required(),
            ])->modalDescription('Import a flowershop-theme/v1 ZIP as an inactive version. It cannot change products, business details, payments or credentials.')
                ->action(function (array $data) {
                    ThemeLibraryService::authorize(auth()->user());
                    $path = $data['package'] ?? '';
                    if (! is_string($path) || ! preg_match('~^theme-uploads/[a-zA-Z0-9._-]+\.zip$~iD', $path) || ! Storage::disk('local')->exists($path)) {
                        $this->validateAction(fn () => ThemeManifest::fail('Select a valid uploaded ZIP.'), 'package');
                    }
                    try {
                        $this->validateAction(fn () => app(ThemePackageService::class)->import(Storage::disk('local')->path($path), auth()->user()), 'package');
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                    Notification::make()->success()->title('Theme uploaded; live store unchanged')->send();
                }),
            Action::make('activateTheme')->label('Activate / restore')->color('gray')->schema([
                Select::make('theme')->label('Theme version')->options(fn () => StoreTheme::latest('id')->limit(50)->get()->mapWithKeys(fn ($theme) => [$theme->id => '#'.$theme->id.' '.$theme->name.' · '.$theme->version]))
                    ->getSearchResultsUsing(fn (string $search) => StoreTheme::where('name', 'like', '%'.$search.'%')->orWhere('id', ctype_digit($search) ? $search : 0)->latest('id')->limit(50)->get()->mapWithKeys(fn ($theme) => [$theme->id => '#'.$theme->id.' '.$theme->name.' · '.$theme->version]))
                    ->getOptionLabelUsing(fn ($value) => ($theme = StoreTheme::find($value)) ? '#'.$theme->id.' '.$theme->name.' · '.$theme->version : null)->searchable()->required(),
                Toggle::make('confirmed')->label('I have previewed this design and understand that activation changes the live store and shared branding.')->accepted()->required(),
            ])->modalDescription('The current design is saved as a restorable version first. Existing invoices, products, orders, menus and business settings are not changed.')
                ->action(function (array $data) {
                    $this->validateAction(fn () => app(ThemeLibraryService::class)->activate(StoreTheme::findOrFail($data['theme']), $this->storeVersion, $this->settingsFingerprint, auth()->user()), 'theme');
                    $this->refreshBaseline();
                    Notification::make()->success()->title('Theme activated; previous design saved')->send();
                }),
        ];
    }

    private function validateAction(callable $callback, string $field): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                $this->getMountedActionSchema()->getStatePath().'.'.$field => array_merge(...array_values($e->errors())),
            ]);
        }
    }

    protected function getViewData(): array
    {
        ThemeLibraryService::authorize(auth()->user());

        return ['themes' => StoreTheme::latest('id')->paginate(12), 'state' => app(ThemeLibraryService::class)->state(),
            'events' => DB::table('store_theme_events')->latest('id')->limit(10)->get()];
    }
}
