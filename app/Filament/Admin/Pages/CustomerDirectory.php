<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Services\CustomerDirectoryService;
use App\Services\CustomerProfileService;
use App\Support\AdminFormValidation;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class CustomerDirectory extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $title = 'Customers';

    protected static ?int $navigationSort = 8;

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class, 'throttle:60,1'];

    protected string $view = 'filament.admin.pages.customer-directory';

    #[Locked]
    public ?int $profileId = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(request()->query(), ['profile' => 'nullable|integer|min:1']);
        abort_if($validator->fails(), 422, 'Invalid customer profile.');
        $this->profileId = filled(request()->query('profile')) ? (int) request()->query('profile') : null;
    }

    protected function getHeaderActions(): array
    {
        if (! $this->profileId) {
            return [];
        }

        return [Action::make('editPrivateProfile')->label('Edit internal profile')->icon('heroicon-o-pencil-square')
            ->visible(fn () => Gate::allows('update', Order::findOrFail($this->profileId)))
            ->modalDescription('Staff-only name, labels and notes. This does not change login details, ownership, delivery instructions or marketing consent. Changes retain an audit history; do not store passwords, card data or unnecessary sensitive information.')
            ->fillForm(function () {
                $anchor = Order::findOrFail($this->profileId);
                $service = app(CustomerProfileService::class);
                $profile = $service->find($anchor);

                return [...$service->values($profile), 'version' => $profile?->version ?? 0, 'identity_key' => $service->identityKey($anchor)];
            })
            ->schema([
                Hidden::make('identity_key')->required(), Hidden::make('version')->required(),
                TextInput::make('preferred_name')->label('Internal display name')->maxLength(120),
                TagsInput::make('labels')->label('Staff labels')->helperText('Up to 10 labels, 30 characters each. Letters, numbers, spaces, hyphens and underscores only.'),
                Textarea::make('staff_notes')->label('Private staff notes')->maxLength(4000)->rows(5),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);
                AdminFormValidation::run(fn () => app(CustomerProfileService::class)->update(Order::findOrFail($this->profileId), $data, auth()->user()),
                    $this->getMountedActionSchema()->getStatePath());
                Notification::make()->title('Internal profile saved')->body('No customer message or account change was made.')->success()->send();
            })];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin'])
            && Gate::allows('viewAny', Order::class) && Gate::allows('view', new Order);
    }

    protected function getViewData(): array
    {
        // Also authorize subsequent renders; hiding navigation alone is not access control.
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(request()->query(), [
            'search' => 'nullable|string|max:160',
            'label' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\pN][\pL\pN _-]*$/u'],
            'kind' => ['nullable', Rule::in(array_keys(CustomerDirectoryService::KINDS))],
            'profile' => 'nullable|integer|min:1',
            'contacts_page' => 'nullable|integer|min:1|max:1000000',
            'orders_page' => 'nullable|integer|min:1|max:1000000',
            'cases_page' => 'nullable|integer|min:1|max:1000000',
            'profile_audit_page' => 'nullable|integer|min:1|max:1000000',
        ]);
        abort_if($validator->fails(), 422, 'Invalid customer search or page filters.');
        $data = $validator->validated();
        $directory = app(CustomerDirectoryService::class);
        $search = trim($data['search'] ?? '');
        $kind = $data['kind'] ?? 'all';
        $label = mb_strtolower(trim($data['label'] ?? ''));
        $kinds = CustomerDirectoryService::KINDS;
        $anchor = $this->profileId ? Order::findOrFail($this->profileId) : null;
        if (! $anchor) {
            $contacts = $directory->profiles($search, $kind, $label)->paginate(25, ['*'], 'contacts_page')->withQueryString();

            return compact('contacts', 'search', 'kind', 'kinds', 'anchor', 'label');
        }
        Gate::authorize('view', $anchor);
        $orders = $directory->ordersFor($anchor)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15, ['*'], 'orders_page')->withQueryString()->appends(['profile' => $this->profileId]);
        $paidTotals = $directory->paidTotals($anchor);
        $contactKind = $directory->kind($anchor);
        $privateProfile = app(CustomerProfileService::class)->find($anchor);
        $profileChanges = $privateProfile?->changes()->with('actor:id,name')->orderByDesc('version')
            ->paginate(10, ['*'], 'profile_audit_page')->withQueryString()->appends(['profile' => $this->profileId]);
        $cases = Gate::allows('viewAny', OrderIssue::class)
            ? OrderIssue::whereIn('order_id', $directory->ordersFor($anchor)->select('id'))
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(10, ['*'], 'cases_page')->withQueryString()->appends(['profile' => $this->profileId])
            : null;

        return compact('anchor', 'orders', 'paidTotals', 'contactKind', 'cases', 'privateProfile', 'profileChanges');
    }
}
