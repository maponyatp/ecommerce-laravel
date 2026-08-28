<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\FirewallRule;
use App\Models\SecuritySetting;
use App\Models\StaffSecurityLog;
use App\Services\StoreFirewallService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StaffSecurity extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $title = 'Staff security & firewall';
    protected static ?string $navigationLabel = 'Security & audit';
    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class];
    protected string $view = 'filament.admin.pages.staff-security';

    public static function canAccess(): bool { return auth()->user()?->hasRole('super_admin') && !auth()->user()?->staff_access_disabled_at; }
    public function mount(): void { abort_unless(static::canAccess(),403); }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mode')->label('Firewall mode')->fillForm(fn () => ['mode' => app(StoreFirewallService::class)->mode(), 'version' => SecuritySetting::find(1)?->version ?? 0])
                ->modalDescription('Monitor records suspicious paths without blocking. Enforce applies active IP rules and sensitive-path blocking. This is an application firewall, not a replacement for server/network protection. Verify real client/proxy addresses before enforcing.')
                ->schema([TextInput::make('version')->disabled()->dehydrated()->required(),
                    Select::make('mode')->options(['disabled' => 'Disabled','monitor' => 'Monitor only','enforce' => 'Enforce rules'])->required()])
                ->action(fn (array $data) => $this->change(fn () => app(StoreFirewallService::class)->configure($data['mode'],(int)$data['version'],auth()->user()))),
            Action::make('block')->label('Block an IP address')
                ->modalDescription('Block a verified abusive client address, not a shared proxy, administrator or payment-provider address. Rules expire within seven days. Your current address and loopback are protected.')
                ->schema([TextInput::make('ip_address')->label('Exact IPv4 / IPv6 address')->required(),Textarea::make('reason')->required()->minLength(5)->maxLength(255),
                    TextInput::make('duration_hours')->label('Block duration (hours)')->integer()->minValue(1)->maxValue(168)->default(24)->required()])
                ->action(fn (array $data) => $this->change(fn () => app(StoreFirewallService::class)->block($data,auth()->user(),request()->ip()))),
            Action::make('revoke')->label('Revoke a block')->color('gray')
                ->schema([Select::make('rule_id')->label('Active rule')->options(fn () => FirewallRule::active()->orderBy('ip_address')->limit(500)->pluck('ip_address','id'))->required(),
                    TextInput::make('version')->label('Revision shown in rule list')->required()->integer()->minValue(1)])
                ->action(fn (array $data) => $this->change(fn () => app(StoreFirewallService::class)->revoke(FirewallRule::findOrFail($data['rule_id']),(int)$data['version'],auth()->user()))),
        ];
    }

    private function change(callable $action): void
    {
        abort_unless(static::canAccess(),403);
        try { $action(); }
        catch (ValidationException $e) { Notification::make()->title('No security change applied')->body($e->validator->errors()->first())->danger()->send(); return; }
        Notification::make()->title('Security change saved and audited')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(),403);
        $data = Validator::make(request()->query(),['event' => 'nullable|string|max:100','actor' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1|max:1000000','rules_page' => 'nullable|integer|min:1|max:1000000'])->validate();
        $logs = StaffSecurityLog::with('actor:id,name')->when(filled($data['event'] ?? null),fn ($q) => $q->where('event',$data['event']))
            ->when(filled($data['actor'] ?? null),fn ($q) => $q->where('actor_id',$data['actor']))->orderByDesc('id')->paginate(30)->withQueryString();
        $rules = FirewallRule::orderByDesc('id')->paginate(15,['*'],'rules_page')->withQueryString();
        return ['logs'=>$logs,'rules'=>$rules,'mode'=>app(StoreFirewallService::class)->mode(),'filters'=>$data];
    }
}
