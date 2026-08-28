<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\StoreIntegration;
use App\Services\Payments\IkhokhaGateway;
use App\Services\StoreIntegrationService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class StoreIntegrations extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Payments & delivery';

    protected static ?string $title = 'Payments & delivery';

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class];

    protected string $view = 'filament.admin.pages.store-integrations';

    public array $ikhokhaData = [];

    public array $dsvData = [];

    public array $payfastData = [];

    public array $peachData = [];

    public array $ozowData = [];

    #[Locked]
    public array $versions = [];

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->hasRole('super_admin') && ! auth()->user()?->staff_access_disabled_at);
    }

    public function getSubheading(): ?string
    {
        return 'Securely manage payment and courier credentials in one place. Super administrators only.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetForms();
    }

    private function resetForms(?string $provider = null): void
    {
        $records = StoreIntegration::all()->keyBy('provider');
        foreach ($provider ? [$provider] : array_keys(StoreIntegrationService::FIELDS) as $name) {
            $this->versions[$name] = $records->get($name)?->version ?? 0;
        }
        // Only non-secret configuration enters Livewire state. Credentials are write-only.
        if (! $provider || $provider === 'ikhokha') {
            $this->ikhokhaForm->fill(['enabled' => $records->get('ikhokha')?->configuration['enabled'] ?? false,
                'app_id' => '', 'app_secret' => '', 'clear_credentials' => false]);
        }
        if (! $provider || $provider === 'dsv') {
            $this->dsvForm->fill(['api_product' => $records->get('dsv')?->configuration['api_product'] ?? 'unconfirmed',
                'environment' => $records->get('dsv')?->configuration['environment'] ?? 'sandbox', 'clear_credentials' => false,
                ...array_fill_keys(StoreIntegrationService::FIELDS['dsv'], '')]);
        }
        foreach (StoreIntegrationService::ADDITIONAL_PAYMENTS as $name => $definition) {
            if ($provider && $provider !== $name) {
                continue;
            }
            $form = $name.'Form';
            $this->$form->fill(['enabled' => false, 'environment' => $records->get($name)?->configuration['environment'] ?? 'sandbox',
                'clear_credentials' => false, ...array_fill_keys(StoreIntegrationService::FIELDS[$name], '')]);
        }
    }

    private function additionalPaymentForm(Schema $schema, string $provider): Schema
    {
        $definition = StoreIntegrationService::ADDITIONAL_PAYMENTS[$provider];
        $fields = [Select::make('environment')->label('Credential environment')->options(['sandbox' => 'Sandbox / testing', 'live' => 'Production / live'])->required()
            ->helperText('Use credentials issued for this environment. Switching requires replacing all credentials or removing the old ones.')->columnSpanFull()];
        foreach ($definition['fields'] as $key => $label) {
            $fields[] = $this->secret($key, $label);
        }
        $fields[] = Toggle::make('enabled')->label('Enable '.$definition['name'].' at checkout')->disabled()->dehydrated()
            ->helperText('Unavailable: checkout creation, callback verification and reconciliation still need implementation. Saving credentials does not enable customer payments.')->columnSpanFull();
        $fields[] = Checkbox::make('clear_credentials')->label('Remove saved '.$definition['name'].' credentials')->columnSpanFull();

        return $schema->statePath($provider.'Data')->components([
            Section::make($definition['name'].' settings')->description('Secure credential preparation only. This provider remains disabled at checkout until its payment integration is completed and tested.')
                ->schema($fields)->columns(2),
        ]);
    }

    public function payfastForm(Schema $schema): Schema
    {
        return $this->additionalPaymentForm($schema, 'payfast');
    }

    public function peachForm(Schema $schema): Schema
    {
        return $this->additionalPaymentForm($schema, 'peach');
    }

    public function ozowForm(Schema $schema): Schema
    {
        return $this->additionalPaymentForm($schema, 'ozow');
    }

    public function savePayfast(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->saveProvider('payfast', $this->payfastForm->getState());
    }

    public function savePeach(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->saveProvider('peach', $this->peachForm->getState());
    }

    public function saveOzow(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->saveProvider('ozow', $this->ozowForm->getState());
    }

    private function secret(string $field, string $label): TextInput
    {
        return TextInput::make($field)->label($label)->password()->revealable(false)->autocomplete('new-password')
            ->maxLength(512)->helperText('Write-only. Leave blank to keep the saved value.')->dehydrateStateUsing(fn ($state) => trim((string) $state));
    }

    public function ikhokhaForm(Schema $schema): Schema
    {
        return $schema->statePath('ikhokhaData')->components([
            Section::make('iKhokha payments')->description('Enter the Application ID and Application Secret from your iKhokha merchant account. Saving enabled credentials makes this gateway available at checkout; verify a merchant-approved payment before launch.')
                ->schema([
                    $this->secret('app_id', 'Application ID'), $this->secret('app_secret', 'Application Secret'),
                    Toggle::make('enabled')->label('Enable iKhokha at checkout')->helperText('Both credentials are required. This does not create a test charge.')->columnSpanFull(),
                    Checkbox::make('clear_credentials')->label('Remove saved iKhokha credentials')->helperText('Disable payments above before removing credentials.')->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public function dsvForm(Schema $schema): Schema
    {
        return $schema->statePath('dsvData')->components([
            Section::make('DSV delivery')->description('Save the details issued for your DSV account. Automatic quotes, bookings, labels and tracking are not yet connected. Saving these details does not activate courier services or create shipments.')
                ->schema([
                    Select::make('api_product')->label('DSV API product')->options(['unconfirmed' => 'Confirm with DSV', 'connect' => 'DSV Connect', 'xpress' => 'DSV XPress', 'generic' => 'DSV Generic APIs'])->required(),
                    Select::make('environment')->label('Credential environment')->options(['sandbox' => 'Sandbox / testing', 'live' => 'Production / live'])->required(),
                    $this->secret('account_number', 'DSV account number'), $this->secret('client_id', 'OAuth client ID'),
                    $this->secret('client_secret', 'OAuth client secret'), $this->secret('subscription_key', 'API subscription key'),
                    $this->secret('api_username', 'API username (if issued)'), $this->secret('api_password', 'API password (if issued)'),
                    Checkbox::make('clear_credentials')->label('Remove all saved DSV credentials')->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public function saveIkhokha(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->saveProvider('ikhokha', $this->ikhokhaForm->getState());
    }

    public function saveDsv(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->saveProvider('dsv', $this->dsvForm->getState());
    }

    private function saveProvider(string $provider, array $data): void
    {
        try {
            app(StoreIntegrationService::class)->save($provider, $data, $this->versions[$provider], auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()->title('Settings not saved')->body($exception->validator->errors()->first())->danger()->send();

            return;
        }
        $this->resetForms($provider);
        Notification::make()->title('Settings saved securely')->body($provider === 'dsv'
            ? 'DSV details are stored. Automatic delivery integration is still pending.'
            : ($provider === 'ikhokha' ? 'iKhokha checkout settings updated. No payment was initiated.'
                : StoreIntegrationService::ADDITIONAL_PAYMENTS[$provider]['name'].' details saved. Checkout remains disabled; payment integration is pending.'))->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['paymentConfigured' => app(IkhokhaGateway::class)->isConfigured(),
            'additionalPayments' => app(StoreIntegrationService::class)->additionalPaymentStatus(),
            'dsvSaved' => StoreIntegration::whereKey('dsv')->exists()];
    }
}
