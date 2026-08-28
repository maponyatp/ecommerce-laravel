<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Services\StoreReadinessService;
use App\Support\StoreSetupGuide;
use Filament\Pages\Page;

class StoreReadiness extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Launch checks';

    protected static ?string $navigationLabel = 'Setup & launch';

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class];

    protected string $view = 'filament.admin.pages.store-readiness';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Follow the setup guide, then review the technical and manual checks before launch.';
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        $report = app(StoreReadinessService::class)->report();
        $guide = app(StoreSetupGuide::class);

        return ['report' => $report, 'guide' => $guide->summary($report),
            'checkLinks' => collect($report['checks'])->mapWithKeys(fn ($check) => [$check['id'] => $guide->checkLink($check['id'])])->all()];
    }
}
